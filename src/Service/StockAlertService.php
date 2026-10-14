<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Service;

use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\StockAlerts\Job\NotifyBackInStockJob;
use SilverShop\StockAlerts\Job\NotifyPriceDropJob;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBCurrency;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The orchestration layer for stock alerts: it upserts a {@link StockAlert} for an email + buyable, drives the
 * double-opt-in / unsubscribe lifecycle, sends the tokenised confirm and "back in stock" emails (sender identity
 * via silvershop/store-profile when present), and does the one-shot restock dispatch — gathering the Verified
 * waiters for a buyable, claiming each atomically (flip to Notified *before* sending, so a crashed/retried job
 * never double-sends), then emailing.
 *
 * It is the single place the opt-in policy and the send logic live, shared by the controller, the instant
 * {@link NotifyBackInStockJob}, the recurring {@link \SilverShop\StockAlerts\Job\ScanStockAlertsJob} backstop and
 * the {@link \SilverShop\StockAlerts\Task\SendStockAlertsTask} CLI runner. Mirrors silvershop/newsletter's
 * NewsletterService (DOI decision table + sender identity) and silvershop/reviews' service shape.
 */
class StockAlertService
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Back-in-stock alerts on/off.
     */
    private static bool $back_in_stock_enabled = true;

    /**
     * Price-drop alerts on/off (v2). Works without silvershop/stock — it watches the buyable's own price.
     */
    private static bool $price_drop_enabled = true;

    /**
     * Minimum absolute drop (in the shop's base currency) below the subscribed-at price before a price-drop
     * notification fires — a floor to avoid spamming on a €0.01 wobble. 0 disables the absolute floor.
     */
    private static float $price_drop_min_absolute = 0.0;

    /**
     * Minimum percentage drop below the subscribed-at price before a price-drop notification fires. 0 disables it.
     */
    private static float $price_drop_min_percent = 0.0;

    /**
     * Auto re-arm a fired one-shot alert when the buyable goes out-of-stock again, so the next restock notifies
     * the same subscribers once more (instead of requiring them to re-subscribe).
     */
    private static bool $auto_rearm = true;

    /**
     * Require guests to confirm via a double-opt-in email before the alert is armed (GDPR-friendly).
     */
    private static bool $double_opt_in = true;

    /**
     * Logged-in members (identity established) are auto-confirmed instead of being emailed a confirm link.
     */
    private static bool $member_auto_confirm = true;

    /**
     * Unconfirmed Pending alerts older than this many days are expired by the scan.
     */
    private static int $verify_ttl_days = 7;

    /**
     * Suppress a second notify to the same alert within this many minutes (a multi-write restock notifies once).
     */
    private static int $renotify_window_mins = 60;

    /**
     * Safety cap on how many alerts a single dispatch run sends.
     */
    private static int $max_per_run = 100;

    /**
     * Sender address for the alert emails; falls back to store-profile contact email, then the framework admin.
     */
    private static string $from_email = '';

    /**
     * Upsert an alert for an email + buyable and start the appropriate opt-in flow. Returns the record, or null
     * when the email or buyable is invalid.
     *
     * @param Buyable|DataObject $buyable
     * @param array{Source?: string, Member?: ?Member, Consent?: bool, Type?: string} $context
     */
    public function subscribe($buyable, string $email, array $context = []): ?StockAlert
    {
        $email = trim(mb_strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return null;
        }

        $type = (string) ($context['Type'] ?? StockAlert::TYPE_BACK_IN_STOCK);
        $alert = $this->findOrMake($buyable, $email, $type);

        $alert->Source = (string) ($context['Source'] ?? 'pdp');
        if ($buyable->hasMethod('sellingPrice')) {
            $alert->SubscribedPrice = (float) $buyable->sellingPrice();
        }
        $member = $context['Member'] ?? null;
        if ($member instanceof Member && $member->exists()) {
            $alert->MemberID = $member->ID;
        }

        // Already armed and waiting: nothing to re-send, just keep it.
        if ($alert->Status === StockAlert::STATUS_VERIFIED) {
            $alert->write();

            return $alert;
        }

        $isMember = $member instanceof Member && $member->exists();
        $autoConfirm = ($isMember && (bool) static::config()->get('member_auto_confirm'))
            || !(bool) static::config()->get('double_opt_in')
            || !empty($context['Consent']);

        if ($autoConfirm) {
            $alert->confirm();
            $alert->write();
        } else {
            $alert->Status = StockAlert::STATUS_PENDING;
            $alert->write();
            $this->sendConfirmationEmail($alert);
        }

        $this->extend('onAfterSubscribe', $alert, $context);

        return $alert;
    }

    /**
     * Confirm (double-opt-in) a pending alert by its token.
     */
    public function confirmByToken(string $token): ?StockAlert
    {
        $alert = $this->byToken($token);
        if (!$alert) {
            return null;
        }
        if ($alert->Status === StockAlert::STATUS_PENDING) {
            $alert->confirm();
            $alert->write();
        }

        return $alert;
    }

    /**
     * Unsubscribe an alert by its token.
     */
    public function unsubscribeByToken(string $token): ?StockAlert
    {
        $alert = $this->byToken($token);
        if (!$alert) {
            return null;
        }
        if ($alert->Status !== StockAlert::STATUS_UNSUBSCRIBED) {
            $alert->markUnsubscribed();
            $alert->write();
        }

        return $alert;
    }

    /**
     * Queue an instant back-in-stock dispatch for a buyable — but only when there is a Verified waiter, so the
     * queue stays clean on the (common) restock with nobody waiting.
     *
     * @param Buyable|DataObject $buyable
     */
    public function enqueueBackInStock($buyable): void
    {
        if (!(bool) static::config()->get('back_in_stock_enabled')) {
            return;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return;
        }
        if (!$this->matchingAlerts($buyable, [StockAlert::STATUS_VERIFIED])->exists()) {
            return;
        }

        NotifyBackInStockJob::enqueue($buyable);
    }

    /**
     * Send the one-shot "back in stock" email to every Verified waiter on a buyable (buyable-level and, for a
     * variation, its product-level waiters). Each row is claimed (flipped to Notified) *before* sending, so a
     * retried job never double-sends. Returns the number of emails sent.
     *
     * @param Buyable|DataObject $buyable
     */
    public function notifyBackInStock($buyable): int
    {
        if (!(bool) static::config()->get('back_in_stock_enabled')) {
            return 0;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return 0;
        }

        $cap = max(1, (int) static::config()->get('max_per_run'));
        $windowMins = (int) static::config()->get('renotify_window_mins');
        $windowCutoff = $windowMins > 0
            ? date('Y-m-d H:i:s', strtotime('-' . $windowMins . ' minutes'))
            : null;

        $sent = 0;
        foreach ($this->matchingAlerts($buyable, [StockAlert::STATUS_VERIFIED])->limit($cap) as $candidate) {
            // Re-fetch to claim atomically: skip anything another run already took.
            $alert = StockAlert::get()->byID((int) $candidate->ID);
            if (!$alert || $alert->Status !== StockAlert::STATUS_VERIFIED) {
                continue;
            }
            // Defensive dedupe window (a Verified row normally has no NotifiedAt).
            if ($windowCutoff && $alert->NotifiedAt && $alert->NotifiedAt > $windowCutoff) {
                continue;
            }

            $alert->markNotified();
            $alert->write();

            if ($this->sendBackInStock($alert)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Queue an instant price-drop dispatch for a buyable — only when a Verified price-drop waiter exists whose
     * subscribed-at price now qualifies (so the queue stays clean on a price change nobody is watching for).
     *
     * @param Buyable|DataObject $buyable
     */
    public function enqueuePriceDrop($buyable): void
    {
        if (!(bool) static::config()->get('price_drop_enabled')) {
            return;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return;
        }
        if (!$this->hasQualifyingPriceDropWaiter($buyable)) {
            return;
        }

        NotifyPriceDropJob::enqueue($buyable);
    }

    /**
     * Send the one-shot price-drop email to every Verified price-drop waiter whose subscribed-at price is now
     * above the buyable's current selling price by the configured floor. When the buyable is a product its
     * variations are swept too, so a base-price drop that cascades (PriceVariationsFromBase / price fallback)
     * reaches the variation-level waiters as well. Each row is claimed before sending (one-shot, retry-safe).
     *
     * @param Buyable|DataObject $buyable
     */
    public function notifyPriceDrop($buyable): int
    {
        if (!(bool) static::config()->get('price_drop_enabled')) {
            return 0;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return 0;
        }

        self::ensureRequest();

        $sent = 0;
        foreach ($this->priceDropSubjects($buyable) as $subject) {
            $sent += $this->dispatchPriceDropFor($subject);
        }

        return $sent;
    }

    /**
     * Re-arm the fired one-shot alerts on a buyable so they will notify again — used when a product goes
     * out-of-stock again after a restock (an out→in→out cycle). Flips Notified back to Verified for the
     * matching back-in-stock alerts (buyable-level and, for a variation, its product-level waiters). Returns
     * the number re-armed. A no-op unless auto_rearm is on.
     *
     * @param Buyable|DataObject $buyable
     */
    public function rearm($buyable, string $type = StockAlert::TYPE_BACK_IN_STOCK): int
    {
        if (!(bool) static::config()->get('auto_rearm')) {
            return 0;
        }
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return 0;
        }

        $count = 0;
        foreach ($this->matchingAlerts($buyable, [StockAlert::STATUS_NOTIFIED], $type) as $alert) {
            if (!$alert instanceof StockAlert) {
                continue;
            }
            $alert->rearm();
            $alert->write();
            $count++;
        }

        return $count;
    }

    /**
     * Claim + send the price-drop email for each qualifying Verified waiter on a single buyable. Shared by the
     * per-buyable and per-variation sweep.
     *
     * @param mixed $buyable
     */
    protected function dispatchPriceDropFor($buyable): int
    {
        if (!$buyable instanceof DataObject || !$buyable->hasMethod('sellingPrice')) {
            return 0;
        }
        $newPrice = (float) $buyable->sellingPrice();

        $cap = max(1, (int) static::config()->get('max_per_run'));
        $windowMins = (int) static::config()->get('renotify_window_mins');
        $windowCutoff = $windowMins > 0
            ? date('Y-m-d H:i:s', strtotime('-' . $windowMins . ' minutes'))
            : null;

        $sent = 0;
        $candidates = StockAlert::get()->filter([
            'BuyableClass' => $buyable->ClassName,
            'BuyableID' => (int) $buyable->ID,
            'Type' => StockAlert::TYPE_PRICE_DROP,
            'Status' => StockAlert::STATUS_VERIFIED,
        ])->limit($cap);

        foreach ($candidates as $candidate) {
            if (!$this->priceDropQualifies((float) $candidate->SubscribedPrice, $newPrice)) {
                continue;
            }
            // Re-fetch to claim atomically: skip anything another run already took.
            $alert = StockAlert::get()->byID((int) $candidate->ID);
            if (!$alert || $alert->Status !== StockAlert::STATUS_VERIFIED) {
                continue;
            }
            if ($windowCutoff && $alert->NotifiedAt && $alert->NotifiedAt > $windowCutoff) {
                continue;
            }

            $alert->markNotified();
            $alert->write();

            if ($this->sendPriceDrop($alert)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Whether a drop from the subscribed-at price to the new price clears both configured floors.
     */
    public function priceDropQualifies(float $subscribedPrice, float $newPrice): bool
    {
        // The new price must actually be lower than the price watched at subscribe.
        if (!($newPrice < $subscribedPrice)) {
            return false;
        }

        $drop = $subscribedPrice - $newPrice;
        $minAbsolute = (float) static::config()->get('price_drop_min_absolute');
        if ($minAbsolute > 0 && $drop < $minAbsolute) {
            return false;
        }

        $minPercent = (float) static::config()->get('price_drop_min_percent');
        if ($minPercent > 0 && $subscribedPrice > 0 && ($drop / $subscribedPrice * 100) < $minPercent) {
            return false;
        }

        return true;
    }

    /**
     * The buyables to sweep for a price-drop dispatch: the buyable itself, plus (when it is a product with
     * variations) each of its variations, since a base-price drop can cascade to variation selling prices.
     *
     * @param mixed $buyable
     * @return list<DataObject>
     */
    protected function priceDropSubjects($buyable): array
    {
        $subjects = [$buyable];

        $isVariation = class_exists(Variation::class) && $buyable instanceof Variation;
        $dynamic = $this->asDynamic($buyable);
        if (!$isVariation && $dynamic->hasMethod('Variations')) {
            foreach ($dynamic->Variations() as $variation) {
                if ($variation && $variation->exists()) {
                    $subjects[] = $variation;
                }
            }
        }

        return $subjects;
    }

    /**
     * Whether any Verified price-drop waiter on the buyable (or, for a product, its variations) now qualifies.
     *
     * @param mixed $buyable
     */
    protected function hasQualifyingPriceDropWaiter($buyable): bool
    {
        foreach ($this->priceDropSubjects($buyable) as $subject) {
            if (!$subject instanceof DataObject || !$subject->hasMethod('sellingPrice')) {
                continue;
            }
            $newPrice = (float) $subject->sellingPrice();
            $waiters = StockAlert::get()->filter([
                'BuyableClass' => $subject->ClassName,
                'BuyableID' => (int) $subject->ID,
                'Type' => StockAlert::TYPE_PRICE_DROP,
                'Status' => StockAlert::STATUS_VERIFIED,
            ]);
            foreach ($waiters as $waiter) {
                if ($this->priceDropQualifies((float) $waiter->SubscribedPrice, $newPrice)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Send the "price dropped" email. Returns false (without throwing) when sending fails.
     */
    public function sendPriceDrop(StockAlert $alert): bool
    {
        try {
            Email::create()
                ->setHTMLTemplate('SilverShop\\StockAlerts\\Email\\PriceDropEmail')
                ->setPlainTemplate('SilverShop\\StockAlerts\\Email\\PriceDropEmailPlain')
                ->setData($this->emailData($alert))
                ->setTo($alert->Email)
                ->setFrom($this->senderEmail(), $this->senderName())
                ->setSubject(_t(
                    self::class . '.PriceDropSubject',
                    'The price dropped on {product}',
                    ['product' => $alert->getBuyableTitle()]
                ))
                ->send();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Safety-net scan: for every buyable with Verified waiters, re-check and dispatch anything the write/publish
     * hooks missed (bulk imports, direct SQL, seeded stock, scripted price changes); also prune expired Pending
     * rows. Covers both back-in-stock (availability flip) and price-drop (current selling price vs the subscribed
     * baseline). Returns a counts summary.
     *
     * @return array{checked:int, notified:int, priceDropNotified:int, expired:int}
     */
    public function scanAndNotify(): array
    {
        $expired = $this->pruneExpired();

        $seen = [];
        $checked = 0;
        $notified = 0;
        $priceDropNotified = 0;

        if ((bool) static::config()->get('back_in_stock_enabled')) {
            $waiting = StockAlert::get()->filter([
                'Type' => StockAlert::TYPE_BACK_IN_STOCK,
                'Status' => StockAlert::STATUS_VERIFIED,
            ]);
            foreach ($waiting as $alert) {
                $key = $alert->BuyableClass . '|' . $alert->BuyableID;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $checked++;

                $buyable = $alert->getBuyable();
                if (!$buyable || !$buyable->hasMethod('hasAvailableStock')) {
                    continue;
                }
                if ($buyable->hasAvailableStock()) {
                    $notified += $this->notifyBackInStock($buyable);
                }
            }
        }

        if ((bool) static::config()->get('price_drop_enabled')) {
            $seenPrice = [];
            $watching = StockAlert::get()->filter([
                'Type' => StockAlert::TYPE_PRICE_DROP,
                'Status' => StockAlert::STATUS_VERIFIED,
            ]);
            foreach ($watching as $alert) {
                $key = $alert->BuyableClass . '|' . $alert->BuyableID;
                if (isset($seenPrice[$key])) {
                    continue;
                }
                $seenPrice[$key] = true;
                $checked++;

                $buyable = $alert->getBuyable();
                if ($buyable) {
                    $priceDropNotified += $this->notifyPriceDrop($buyable);
                }
            }
        }

        return [
            'checked' => $checked,
            'notified' => $notified,
            'priceDropNotified' => $priceDropNotified,
            'expired' => $expired,
        ];
    }

    /**
     * Expire unconfirmed Pending alerts older than the verify TTL. Returns the number expired.
     */
    public function pruneExpired(): int
    {
        $days = (int) static::config()->get('verify_ttl_days');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $stale = StockAlert::get()
            ->filter('Status', StockAlert::STATUS_PENDING)
            ->filter('Created:LessThan', $cutoff);

        $count = 0;
        foreach ($stale as $alert) {
            $alert->markExpired();
            $alert->write();
            $count++;
        }

        return $count;
    }

    /**
     * The alerts waiting on a buyable: its own, plus — when the buyable is a variation — the product-level waiters
     * (who subscribed to the whole product). Returns a DataList filtered to the given statuses.
     *
     * @param mixed $buyable
     * @param list<string> $statuses
     * @return \SilverStripe\ORM\DataList
     */
    public function matchingAlerts($buyable, array $statuses, string $type = StockAlert::TYPE_BACK_IN_STOCK)
    {
        $pairs = [[$buyable->ClassName, (int) $buyable->ID]];

        // A variation also matches its product-level waiters. Detect via instanceof (core Variation is always
        // present) rather than silvershop/stock's isVariation() helper, so this resolves for the core-only
        // price-drop path too.
        if (class_exists(Variation::class) && $buyable instanceof Variation) {
            $product = $buyable->Product();
            if ($product && $product->exists()) {
                $pairs[] = [$product->ClassName, (int) $product->ID];
            }
        }

        $ids = [];
        foreach ($pairs as [$class, $id]) {
            $matches = StockAlert::get()
                ->filter([
                    'BuyableClass' => $class,
                    'BuyableID' => $id,
                    'Type' => $type,
                    'Status' => $statuses,
                ])
                ->column('ID');
            foreach ($matches as $matchId) {
                $ids[(int) $matchId] = true;
            }
        }

        return StockAlert::get()->filter('ID', array_keys($ids) ?: [0]);
    }

    /**
     * How many customers are waiting (Pending + Verified) on a product — counting its variation waiters too.
     * Powers the admin "N waiting" count.
     *
     * @param DataObject $product
     */
    public function waitingCount($product): int
    {
        if (!is_object($product) || !$product instanceof DataObject || !$product->exists()) {
            return 0;
        }

        $pairs = [[$product->ClassName, (int) $product->ID]];
        $dynamic = $this->asDynamic($product);
        if ($dynamic->hasMethod('Variations')) {
            foreach ($dynamic->Variations() as $variation) {
                $pairs[] = [$variation->ClassName, (int) $variation->ID];
            }
        }

        $ids = [];
        foreach ($pairs as [$class, $id]) {
            $matches = StockAlert::get()
                ->filter([
                    'BuyableClass' => $class,
                    'BuyableID' => $id,
                    'Status' => [StockAlert::STATUS_PENDING, StockAlert::STATUS_VERIFIED],
                ])
                ->column('ID');
            foreach ($matches as $matchId) {
                $ids[(int) $matchId] = true;
            }
        }

        return count($ids);
    }

    /**
     * Send the double-opt-in confirmation email. Returns false (without throwing) when sending fails.
     */
    public function sendConfirmationEmail(StockAlert $alert): bool
    {
        try {
            Email::create()
                ->setHTMLTemplate('SilverShop\\StockAlerts\\Email\\ConfirmStockAlertEmail')
                ->setPlainTemplate('SilverShop\\StockAlerts\\Email\\ConfirmStockAlertEmailPlain')
                ->setData($this->emailData($alert))
                ->setTo($alert->Email)
                ->setFrom($this->senderEmail(), $this->senderName())
                ->setSubject(_t(
                    self::class . '.ConfirmSubject',
                    'Confirm your back-in-stock alert for {product}',
                    ['product' => $alert->getBuyableTitle()]
                ))
                ->send();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send the "back in stock" email. Returns false (without throwing) when sending fails.
     */
    public function sendBackInStock(StockAlert $alert): bool
    {
        try {
            Email::create()
                ->setHTMLTemplate('SilverShop\\StockAlerts\\Email\\BackInStockEmail')
                ->setPlainTemplate('SilverShop\\StockAlerts\\Email\\BackInStockEmailPlain')
                ->setData($this->emailData($alert))
                ->setTo($alert->Email)
                ->setFrom($this->senderEmail(), $this->senderName())
                ->setSubject(_t(
                    self::class . '.BackInStockSubject',
                    '{product} is back in stock',
                    ['product' => $alert->getBuyableTitle()]
                ))
                ->send();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Shared template data for both emails.
     */
    public function emailData(StockAlert $alert): ArrayData
    {
        $product = $alert->getProduct();
        $buyable = $alert->getBuyable();
        $currentPrice = ($buyable && $buyable->hasMethod('sellingPrice')) ? (float) $buyable->sellingPrice() : null;

        $data = ArrayData::create([
            'ShopName' => $this->senderName(),
            'Email' => $alert->Email,
            'BuyableTitle' => $alert->getBuyableTitle(),
            'ProductTitle' => $product ? (string) $product->getTitle() : $alert->getBuyableTitle(),
            'BuyableLink' => $alert->BuyableLink(),
            'ConfirmLink' => $alert->ConfirmLink(),
            'UnsubscribeLink' => $alert->UnsubscribeLink(),
            // Price-drop figures (null-safe; only the PriceDropEmail template reads them).
            'SubscribedPrice' => $alert->dbObject('SubscribedPrice'),
            'CurrentPrice' => $currentPrice !== null ? DBCurrency::create()->setValue($currentPrice) : null,
        ]);
        $this->extend('updateEmailData', $data, $alert);

        return $data;
    }

    public function senderEmail(): string
    {
        $configured = (string) static::config()->get('from_email');
        if ($configured) {
            return $configured;
        }

        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasField('ContactEmail') && $siteConfig->ContactEmail) {
            return (string) $siteConfig->ContactEmail;
        }

        $admin = Email::config()->get('admin_email');
        if (is_array($admin)) {
            $admin = array_key_first($admin) ?: '';
        }

        return $admin ?: ('no-reply@' . (parse_url(Director::absoluteBaseURL(), PHP_URL_HOST) ?: 'example.com'));
    }

    public function senderName(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasMethod('StoreProfileName')) {
            $name = $siteConfig->StoreProfileName();
            if ($name) {
                return (string) $name;
            }
        }

        return (string) $siteConfig->Title;
    }

    /**
     * Find the active (Pending/Verified) alert for this email + buyable + type, or make a fresh one. A
     * Notified/Expired/Unsubscribed row is not reused — the customer re-subscribes (v1: no auto re-arm).
     *
     * @param Buyable|DataObject $buyable
     */
    protected function findOrMake($buyable, string $email, string $type): StockAlert
    {
        $existing = StockAlert::get()
            ->filter([
                'Email' => $email,
                'BuyableClass' => $buyable->ClassName,
                'BuyableID' => (int) $buyable->ID,
                'Type' => $type,
                'Status' => [StockAlert::STATUS_PENDING, StockAlert::STATUS_VERIFIED],
            ])
            ->first();
        if ($existing) {
            return $existing;
        }

        $alert = StockAlert::create();
        $alert->Email = $email;
        $alert->Type = $type;
        $alert->BuyableClass = (string) $buyable->ClassName;
        $alert->BuyableID = (int) $buyable->ID;

        return $alert;
    }

    protected function byToken(string $token): ?StockAlert
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return StockAlert::get()->filter('Token', $token)->first();
    }

    /**
     * Ensure a current HTTPRequest is registered. Price-drop dispatch calls sellingPrice(), and some shops hook
     * updateSellingPrice() with request-dependent code (e.g. silvershop/discounts' member-specific pricing calls
     * Security::getCurrentUser()), which fatals from the CLI (the task or a queued job) where no request exists.
     * Registering a minimal request there makes getCurrentUser() resolve to no member instead of crashing. In a
     * web context a controller is already current, so this is a no-op.
     */
    public static function ensureRequest(): void
    {
        if (!Director::is_cli()) {
            return;
        }
        try {
            $request = new HTTPRequest('GET', '/');
            $request->setSession(new Session([]));
            Injector::inst()->registerService($request, HTTPRequest::class);
        } catch (\Throwable) {
            // Best-effort — if the request cannot be set up, the per-buyable dispatch still guards its own calls.
        }
    }

    /**
     * Launder a buyable to mixed so its optional silvershop/stock / Variation methods (hasAvailableStock,
     * isVariation, Product, ...), reached via hasMethod()/__call, analyse cleanly whether or not those modules are
     * present. Mirrors silvershop/storefront's StorefrontStockExtension.
     *
     * @param mixed $buyable
     * @return mixed
     */
    protected function asDynamic($buyable)
    {
        return $buyable;
    }
}
