<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * The price-drop trigger. Applied to {@link \SilverShop\Page\Product} and {@link \SilverShop\Model\Variation\Variation}
 * (both Versioned), it detects when the effective selling price falls and enqueues a
 * {@link \SilverShop\StockAlerts\Job\NotifyPriceDropJob} for that buyable.
 *
 * Detection is **publish-based** for Versioned buyables (the correct "the price dropped on the live site" signal):
 * {@link onAfterPublish()} receives the previously-published record ($original) from framework's Versioned, so the
 * old vs new {@link sellingPrice()} can be compared directly — an unpublished draft price never mis-fires. A
 * non-versioned custom buyable is instead detected on write ({@link onAfterWrite()}).
 *
 * A Product base-price drop can lower its variations' selling prices with no Variation row written
 * (PriceVariationsFromBase, or a variation with Price<=0 falling back to BasePrice), so the job sweeps a product's
 * variations too — only the Product hook sees such a cascade. The recurring
 * {@link \SilverShop\StockAlerts\Job\ScanStockAlertsJob} is the backstop. Price-drop needs no silvershop/stock.
 *
 * @extends Extension<DataObject>
 */
class ProductPriceAlertExtension extends Extension
{
    /**
     * Currency comparisons are on Currency(19,4) values; ignore sub-tenth-of-a-cent float noise.
     */
    private const EPSILON = 0.00001;

    /**
     * Publish-based detection for Versioned buyables (Product via SiteTree, Variation via its Versioned extension).
     * Framework passes the previously-published record, or null on a first publish.
     *
     * @param DataObject|null $original
     */
    public function onAfterPublish($original = null): void
    {
        try {
            $owner = $this->getOwner();
            if (!$owner->hasMethod('sellingPrice')) {
                return;
            }
            if (!$original instanceof DataObject || !$original->hasMethod('sellingPrice')) {
                // First publish (no prior live record) — nothing to compare against.
                return;
            }

            $old = (float) $original->sellingPrice();
            $new = (float) $owner->sellingPrice();
            if ($new < $old - self::EPSILON) {
                StockAlertService::create()->enqueuePriceDrop($owner);
            }
        } catch (\Throwable) {
            // Detection is best-effort: a publish must never fail because of the alerts module. The recurring
            // scan job is the backstop for anything missed here.
        }
    }

    /**
     * Write-based detection for a non-versioned buyable (a Versioned buyable changes its live price only on
     * publish, so its draft writes are skipped to avoid notifying on an unpublished price).
     */
    public function onAfterWrite(): void
    {
        try {
            $owner = $this->getOwner();
            if ($owner->hasExtension(Versioned::class)) {
                return;
            }

            $changed = $owner->getChangedFields(true, DataObject::CHANGE_VALUE);
            foreach (['BasePrice', 'Price'] as $field) {
                if (!isset($changed[$field])) {
                    continue;
                }
                $old = (float) ($changed[$field]['before'] ?? 0);
                $new = (float) ($changed[$field]['after'] ?? 0);
                if ($old > 0 && $new < $old - self::EPSILON) {
                    StockAlertService::create()->enqueuePriceDrop($owner);
                    return;
                }
            }
        } catch (\Throwable) {
            // Best-effort; the scan job is the backstop.
        }
    }
}
