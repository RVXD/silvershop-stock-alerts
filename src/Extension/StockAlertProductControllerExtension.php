<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\ORM\FieldType\ShopCurrency;
use SilverShop\Page\AccountPage;
use SilverShop\Page\AccountPageController;
use SilverShop\Page\Product;
use SilverShop\StockAlerts\Control\StockAlertController;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Exposes the subscribe endpoint + form helpers on the product page, for the storefront buy-box / sticky-bar
 * back-in-stock notify form (`.silvershop-stock-alert #notify-form`) AND the price-drop "watch price" control
 * (`#watch-price-form`, {@see WatchPriceForm.ss}). Mirrors silvershop/wishlist's WishlistProductControllerExtension.
 *
 * @extends Extension<\SilverShop\Page\ProductController>
 */
class StockAlertProductControllerExtension extends Extension
{
    /**
     * Memoised no-JS success flash for this request (so the template can read it several times). false = not yet
     * resolved; null = no flash; otherwise the ArrayData.
     *
     * @var ArrayData|false|null
     */
    protected $stockAlertFlash = false;

    /**
     * Root-relative subscribe endpoint (e.g. "/stock-alert/subscribe"), safe as a form action on the product page
     * where a relative link would resolve against the product URL.
     */
    public function StockAlertSubscribeLink(): string
    {
        return Controller::join_links('/', StockAlertController::singleton()->Link('subscribe'));
    }

    /**
     * Hidden CSRF field for the no-JS subscribe POST.
     */
    public function StockAlertSecurityField(): DBHTMLText
    {
        $token = SecurityToken::inst();
        $field = DBHTMLText::create();
        $field->setValue(sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            Convert::raw2att($token->getName()),
            Convert::raw2att($token->getValue())
        ));

        return $field;
    }

    /**
     * The current product's ID, for the form's hidden ProductID field.
     */
    public function StockAlertProductID(): int
    {
        $product = $this->getOwner()->data();

        return $product instanceof Product ? (int) $product->ID : 0;
    }

    /**
     * Prefill the email input for a logged-in member.
     */
    public function StockAlertEmailPrefill(): string
    {
        $member = Security::getCurrentUser();

        return ($member && $member->exists()) ? (string) $member->Email : '';
    }

    /**
     * Extra hidden form fields a shop-supplied spam protector (or any module) wants on the subscribe forms, via the
     * `updateStockAlertFormFields` extension hook — the attach point for e.g. xddesigners/silverstripe-honeypotfield
     * without hard-wiring the global SpamProtector. Empty by default (the built-in honeypot is in the template).
     */
    public function StockAlertExtraFields(): DBHTMLText
    {
        $html = '';
        foreach ($this->getOwner()->extend('updateStockAlertFormFields') as $part) {
            $html .= (string) $part;
        }
        $field = DBHTMLText::create();
        $field->setValue($html);

        return $field;
    }

    /**
     * The product's current selling price as a shop-currency field, for the "watch price" resting hint
     * ($StockAlertSellingPrice.Nice — "we'll email you if it drops below €X"). Uses ShopCurrency (not plain
     * DBCurrency, which defaults to the $ symbol) so it matches the storefront's PriceTag formatting.
     */
    public function StockAlertSellingPrice(): ShopCurrency
    {
        $product = $this->getOwner()->data();
        $price = ($product && $product->hasMethod('sellingPrice')) ? (float) $product->sellingPrice() : 0.0;

        return ShopCurrency::create('StockAlertSellingPrice')->setValue($price);
    }

    /**
     * Server-side default label for the watched subject (the product title); JS replaces it with the selected
     * variation's label ("Charcoal · M") on a variation change. Keeps the no-JS copy sensible.
     */
    public function StockAlertSelectedVariantLabel(): string
    {
        $product = $this->getOwner()->data();

        return $product instanceof Product ? (string) $product->getTitle() : '';
    }

    /**
     * Link to the member "My alerts" account section, for the "Stop any time under My alerts" copy.
     */
    public function AccountAlertsLink(): string
    {
        try {
            $page = AccountPage::get()->first();
            if ($page && $page->exists()) {
                return Controller::join_links((string) $page->Link(), 'alerts');
            }
        } catch (\Throwable) {
            // fall through to the config-derived default
        }

        return Controller::join_links('/', (string) AccountPageController::config()->get('url_segment'), 'alerts');
    }

    /**
     * The current member's active (Pending/Verified) price-drop watch on THIS product (product-level, since the
     * server render has no selected variation — JS swaps to a variation watch client-side against
     * {@see ActivePriceWatches()}). Null for guests or when there is no watch. Drives the "Watching below €X" row.
     */
    public function CurrentPriceWatch(): ?StockAlert
    {
        $member = Security::getCurrentUser();
        $product = $this->getOwner()->data();
        if (!$member || !$member->exists() || !$product instanceof Product) {
            return null;
        }

        return StockAlert::get()
            ->filter([
                'Type' => StockAlert::TYPE_PRICE_DROP,
                'Status' => [StockAlert::STATUS_PENDING, StockAlert::STATUS_VERIFIED],
                'BuyableClass' => $product->ClassName,
                'BuyableID' => (int) $product->ID,
            ])
            ->filterAny([
                'MemberID' => (int) $member->ID,
                'Email' => trim(mb_strtolower((string) $member->Email)),
            ])
            ->sort('Created', 'DESC')
            ->first();
    }

    /**
     * Comma-separated list of VariationIDs the current member is actively watching the price of on this product
     * (0 = the product itself), for the control's data-watch-active-ids attribute — JS flips the row to "Watching"
     * when the shopper selects a variation they already watch. Empty for guests / no watches.
     */
    public function ActivePriceWatches(): string
    {
        $member = Security::getCurrentUser();
        $product = $this->getOwner()->data();
        if (!$member || !$member->exists() || !$product instanceof Product) {
            return '';
        }

        // The buyable (class,id) pairs that belong to this product: the product (→ 0) and each of its variations.
        $map = [$product->ClassName . '|' . (int) $product->ID => 0];
        if ($product->hasMethod('Variations')) {
            foreach ($product->Variations() as $variation) {
                if ($variation && $variation->exists()) {
                    $map[$variation->ClassName . '|' . (int) $variation->ID] = (int) $variation->ID;
                }
            }
        }

        $alerts = StockAlert::get()
            ->filter([
                'Type' => StockAlert::TYPE_PRICE_DROP,
                'Status' => [StockAlert::STATUS_PENDING, StockAlert::STATUS_VERIFIED],
            ])
            ->filterAny([
                'MemberID' => (int) $member->ID,
                'Email' => trim(mb_strtolower((string) $member->Email)),
            ]);

        $ids = [];
        foreach ($alerts as $alert) {
            $key = $alert->BuyableClass . '|' . (int) $alert->BuyableID;
            if (array_key_exists($key, $map)) {
                $ids[$map[$key]] = true;
            }
        }

        return implode(',', array_keys($ids));
    }

    /**
     * The no-JS success flash for a BACK-IN-STOCK subscribe on this product, or null. Set by
     * {@link StockAlertController::subscribe()} after a plain POST. Type-scoped so the right form reveals its panel.
     */
    public function StockAlertFlash(): ?ArrayData
    {
        $flash = $this->resolveFlash();

        return ($flash && !(bool) $flash->IsPriceDrop) ? $flash : null;
    }

    /**
     * The no-JS success flash for a PRICE-DROP watch on this product, or null (the watch-price form's counterpart
     * to {@see StockAlertFlash()}).
     */
    public function PriceWatchFlash(): ?ArrayData
    {
        $flash = $this->resolveFlash();

        return ($flash && (bool) $flash->IsPriceDrop) ? $flash : null;
    }

    /**
     * Resolve (once) the one-shot session flash for this product, clearing it so it shows only on the first render.
     */
    protected function resolveFlash(): ?ArrayData
    {
        if ($this->stockAlertFlash !== false) {
            return $this->stockAlertFlash ?: null;
        }
        $this->stockAlertFlash = null;

        $product = $this->getOwner()->data();
        if (!$product instanceof Product) {
            return null;
        }

        try {
            $session = $this->getOwner()->getRequest()->getSession();
            $flash = $session->get('StockAlertFlash');
            if (is_array($flash) && (int) ($flash['ProductID'] ?? 0) === (int) $product->ID) {
                $session->clear('StockAlertFlash');
                $pending = (bool) ($flash['Pending'] ?? false);
                $this->stockAlertFlash = ArrayData::create([
                    'Done' => true,
                    'Email' => (string) ($flash['Email'] ?? ''),
                    'Pending' => $pending,
                    // Split flags so the form shows the info-blue "pending" notice vs the green "confirmed" notice
                    // (never the green one for a guest still awaiting double-opt-in). Both notify forms read these.
                    'ShowPending' => $pending,
                    'ShowConfirmed' => !$pending,
                    'Type' => (string) ($flash['Type'] ?? StockAlert::TYPE_BACK_IN_STOCK),
                    'IsPriceDrop' => ($flash['Type'] ?? '') === StockAlert::TYPE_PRICE_DROP,
                ]);
            }
        } catch (\Throwable) {
            return null;
        }

        return $this->stockAlertFlash ?: null;
    }
}
