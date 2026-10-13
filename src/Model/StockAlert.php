<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Model;

use SilverShop\Model\Buyable;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * A single customer's subscription to be told when a {@link Buyable} (a whole {@link \SilverShop\Page\Product}
 * or a specific {@link \SilverShop\Model\Variation\Variation}) is available again — "email me when it's back".
 *
 * It mirrors {@link \SilverShop\Newsletter\Model\NewsletterSubscription}'s consent shape (explicit
 * {@link $Consent} + {@link $ConsentAt} + {@link $Source}) and {@link \SilverShop\Reviews\Model\ReviewInvitation}'s
 * tokenised landing idiom: the {@link $Token} backs the one-click double-opt-in confirm and unsubscribe links, so
 * no login is needed. The subject is a manual polymorph ({@link $BuyableClass} + {@link $BuyableID}), resolved
 * exactly like {@link \SilverShop\Stock\Model\ProductWarehouseStock::getBuyable()} (Variation ?: Product).
 *
 * Lifecycle: Pending (guest, awaiting confirm) → Verified (armed) → Notified (one-shot sent). Expired (unconfirmed
 * too long) and Unsubscribed are terminal. v1 is re-subscribe (no auto re-arm); {@link $SubscribedPrice} is captured
 * for the v2 price-drop type.
 *
 * @property string $Email
 * @property string $Type
 * @property string $Status
 * @property bool $Consent
 * @property ?string $ConsentAt
 * @property string $Source
 * @property float $SubscribedPrice
 * @property string $Token
 * @property ?string $ConfirmedAt
 * @property ?string $NotifiedAt
 * @property string $BuyableClass
 * @property int $BuyableID
 * @property int $MemberID
 * @method Member Member()
 */
class StockAlert extends DataObject
{
    public const TYPE_BACK_IN_STOCK = 'BackInStock';

    public const TYPE_PRICE_DROP = 'PriceDrop';

    public const STATUS_PENDING = 'Pending';

    public const STATUS_VERIFIED = 'Verified';

    public const STATUS_NOTIFIED = 'Notified';

    public const STATUS_EXPIRED = 'Expired';

    public const STATUS_UNSUBSCRIBED = 'Unsubscribed';

    private static string $table_name = 'SilverShop_StockAlert';

    private static string $singular_name = 'Stock alert';

    private static string $plural_name = 'Stock alerts';

    private static array $db = [
        'Email' => 'Varchar(254)',
        'Type' => "Enum('BackInStock,PriceDrop','BackInStock')",
        'Status' => "Enum('Pending,Verified,Notified,Expired,Unsubscribed','Pending')",
        'Consent' => 'Boolean',
        'ConsentAt' => 'Datetime',
        'Source' => "Enum('pdp,sticky,account,api','pdp')",
        'SubscribedPrice' => 'Currency(19,4)',
        'Token' => 'Varchar(64)',
        'ConfirmedAt' => 'Datetime',
        'NotifiedAt' => 'Datetime',
        // Manual polymorph to the subject buyable (Variation or Product), as silvershop/stock does.
        'BuyableClass' => 'Varchar(255)',
        'BuyableID' => 'Int',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'Token' => ['type' => 'unique', 'columns' => ['Token']],
        // "Who is waiting on this buyable" — the hot restock-dispatch lookup.
        'BuyableWaiting' => ['type' => 'index', 'columns' => ['BuyableClass', 'BuyableID', 'Type', 'Status']],
        'EmailStatus' => ['type' => 'index', 'columns' => ['Email', 'Status']],
    ];

    private static string $default_sort = 'Created DESC';

    private static array $summary_fields = [
        'Email' => 'Email',
        'BuyableSummary' => 'Product',
        'Type' => 'Type',
        'Status' => 'Status',
        'Created.Nice' => 'Subscribed',
        'NotifiedAt.Nice' => 'Notified',
    ];

    private static array $searchable_fields = [
        'Email',
        'Type',
        'Status',
    ];

    private static array $field_labels = [
        'Email' => 'Email address',
        'BuyableSummary' => 'Product',
        'ConsentAt' => 'Consent given at',
        'ConfirmedAt' => 'Confirmed at',
        'NotifiedAt' => 'Notified at',
        'SubscribedPrice' => 'Price at subscribe',
    ];

    /**
     * Cache for the resolved buyable (false = looked up and not found, mirroring the stock model's idiom).
     *
     * @var Buyable|DataObject|false|null
     */
    protected $resolvedBuyable = null;

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->Token) {
            $this->Token = bin2hex(random_bytes(16));
        }
        if (!$this->Status) {
            $this->Status = self::STATUS_PENDING;
        }
        $this->Email = trim(mb_strtolower((string) $this->Email));
    }

    /**
     * Resolve the subject buyable (Variation or Product) from the manual polymorph, exactly like
     * {@link \SilverShop\Stock\Model\ProductWarehouseStock::getBuyable()}. Returns null when it cannot be resolved.
     *
     * Typed mixed: the buyable's stock/variation methods (hasAvailableStock, isVariation, Product, Link) are reached
     * via hasMethod()/__call, so a concrete type would make static analysis miss them. Mirrors StorefrontStockExtension.
     *
     * @return mixed
     */
    public function getBuyable()
    {
        if ($this->resolvedBuyable === null) {
            $this->resolvedBuyable = false;
            if ($this->BuyableClass && $this->BuyableID && is_subclass_of($this->BuyableClass, DataObject::class)) {
                $class = Injector::inst()->get($this->BuyableClass, true);
                $record = $class::get()->byID((int) $this->BuyableID);
                if ($record && $record->exists()) {
                    $this->resolvedBuyable = $record;
                }
            }
        }

        return $this->resolvedBuyable ?: null;
    }

    /**
     * The owning product for this alert — the buyable itself when it is a Product, or the variation's Product.
     *
     * @return mixed
     */
    public function getProduct()
    {
        $buyable = $this->getBuyable();
        if (!$buyable) {
            return null;
        }
        if ($buyable->hasMethod('isVariation') && $buyable->isVariation() && $buyable->hasMethod('Product')) {
            $product = $buyable->Product();

            return ($product && $product->exists()) ? $product : null;
        }

        return $buyable;
    }

    /**
     * Human title for the subject: the variation's title ("Charcoal · M") when the buyable is a variation, else the
     * product title.
     */
    public function getBuyableTitle(): string
    {
        $buyable = $this->getBuyable();

        return $buyable ? (string) $buyable->getTitle() : '';
    }

    /**
     * Admin grid label: product title, with the variation title appended when the subject is a variation.
     */
    public function getBuyableSummary(): string
    {
        $buyable = $this->getBuyable();
        if (!$buyable) {
            return _t(self::class . '.UnknownProduct', '(removed product)');
        }
        $product = $this->getProduct();
        if ($product && $product !== $buyable) {
            return sprintf('%s — %s', (string) $product->getTitle(), (string) $buyable->getTitle());
        }

        return (string) $buyable->getTitle();
    }

    /**
     * Absolute deep-link to the subject buyable's product page.
     */
    public function BuyableLink(): string
    {
        $product = $this->getProduct();
        if ($product && $product->hasMethod('AbsoluteLink')) {
            return (string) $product->AbsoluteLink();
        }
        $buyable = $this->getBuyable();
        if ($buyable && $buyable->hasMethod('Link')) {
            return Director::absoluteURL((string) $buyable->Link());
        }

        return Director::absoluteBaseURL();
    }

    /**
     * Absolute one-click URL that confirms (double-opt-in) this alert.
     */
    public function ConfirmLink(): string
    {
        return Director::absoluteURL('stock-alert/confirm/' . $this->Token);
    }

    /**
     * Absolute one-click URL that unsubscribes this alert.
     */
    public function UnsubscribeLink(): string
    {
        return Director::absoluteURL('stock-alert/unsubscribe/' . $this->Token);
    }

    /**
     * Whether the alert is still waiting to fire (armed or awaiting confirmation).
     */
    public function isActive(): bool
    {
        return in_array($this->Status, [self::STATUS_PENDING, self::STATUS_VERIFIED], true);
    }

    /**
     * Confirm (double-opt-in): arm the alert and stamp the consent / confirm timestamps when first set.
     */
    public function confirm(): void
    {
        $now = DBDatetime::now()->getValue();
        $this->Status = self::STATUS_VERIFIED;
        $this->Consent = true;
        if (!$this->ConsentAt) {
            $this->ConsentAt = $now;
        }
        if (!$this->ConfirmedAt) {
            $this->ConfirmedAt = $now;
        }
    }

    /**
     * Mark the one-shot notification sent.
     */
    public function markNotified(): void
    {
        $this->Status = self::STATUS_NOTIFIED;
        $this->NotifiedAt = DBDatetime::now()->getValue();
    }

    public function markUnsubscribed(): void
    {
        $this->Status = self::STATUS_UNSUBSCRIBED;
    }

    public function markExpired(): void
    {
        $this->Status = self::STATUS_EXPIRED;
    }

    public function canView($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canEdit($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return $this->canModerate($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canModerate($member = null): bool
    {
        return Permission::check('CMS_ACCESS_SilverShop\StockAlerts\Admin\StockAlertsAdmin', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }
}
