<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;

/**
 * Applied to {@link \SilverShop\Page\Product}. Provides the per-product notify gate the storefront buy box reads,
 * and surfaces the "N waiting" demand count in the CMS.
 *
 *  - {@see getStockAlertsEnabled()} is the template gate ($StockAlertsEnabled): true only when back-in-stock is
 *    enabled, silvershop/stock is installed (the restock trigger needs it, so the control is never dead), and this
 *    product is currently not purchasable. A dedicated per-product accessor, not $ModuleExists — the design chose it
 *    so the notify UI shows exactly when it can work.
 *  - {@see updateCMSFields()} adds a small "N customers waiting" note to the product edit form.
 *
 * Everything is guarded so a product without the stock module (or before a dev/build) can never fatal the PDP or
 * the CMS — the notify UI simply stays hidden and the theme falls back to its plain "Sold out".
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class StockAlertProductExtension extends Extension
{
    /**
     * Template gate for the buy-box / sticky-bar notify UI.
     */
    public function getStockAlertsEnabled(): bool
    {
        if (!(bool) StockAlertService::config()->get('back_in_stock_enabled')) {
            return false;
        }
        // Back-in-stock alerts need silvershop/stock (the restock trigger watches it); stay dormant without it,
        // so we never render a "notify me" control that can never fire.
        if (!self::stockModuleInstalled()) {
            return false;
        }

        try {
            return !$this->getOwner()->canPurchase();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Template gate for the PDP "watch price" control: price-drop alerts are enabled and this product carries a
     * sellable price to watch. The control itself renders only in the purchasable branch of the buy box (the
     * template also checks $canPurchase), so a sold-out PDP keeps the back-in-stock bell and never the price watch.
     * Core-only — unlike the back-in-stock gate it does not require silvershop/stock (price-drop watches the price).
     */
    public function getPriceDropAlertsEnabled(): bool
    {
        if (!(bool) StockAlertService::config()->get('price_drop_enabled')) {
            return false;
        }

        try {
            $owner = $this->getOwner();

            return $owner->hasMethod('sellingPrice') && (float) $owner->sellingPrice() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateCMSFields(FieldList $fields): void
    {
        try {
            $count = StockAlertService::create()->waitingCount($this->getOwner());
            if ($count < 1) {
                return;
            }

            $message = _t(
                self::class . '.NWaiting',
                '{count} customer(s) are waiting for a back-in-stock alert on this product.',
                ['count' => $count]
            );
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'StockAlertWaiting',
                '<p class="message notice" style="display:flex;align-items:flex-start;gap:.5em">'
                . '<span class="font-icon-bell" aria-hidden="true"></span><span>' . $message . '</span></p>'
            ));
        } catch (\Throwable) {
            // Non-fatal: the demand note is informational only.
        }
    }

    /**
     * Whether silvershop/stock is installed (the back-in-stock feature's hard dependency at runtime).
     */
    public static function stockModuleInstalled(): bool
    {
        try {
            return ModuleLoader::inst()->getManifest()->moduleExists('silvershop/stock');
        } catch (\Throwable) {
            return false;
        }
    }
}
