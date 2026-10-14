<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * The restock trigger. Applied to {@link \SilverShop\Stock\Model\ProductWarehouseStock} (guarded on
 * silvershop/stock in _config), it watches every stock-row write — the single chokepoint every stock mutation
 * funnels through (CMS inline grid, order-driven increment/decrement, seeded stock) — and enqueues a
 * {@link \SilverShop\StockAlerts\Job\NotifyBackInStockJob} when a buyable flips from out-of-stock to available.
 *
 * It fires only on a genuine 0→in-stock restock of this row (not a 5→6 wobble, not a draft, not a brand-new row),
 * then re-confirms the flip against the buyable's real {@link hasAvailableStock()} (which sums across warehouses and
 * subtracts cart reservations) so multi-warehouse / reserved-stock cases never produce a false "back in stock". The
 * recurring {@link \SilverShop\StockAlerts\Job\ScanStockAlertsJob} is the backstop for anything this misses.
 *
 * silvershop/stock is only a `suggest`, so this is typed against DataObject and the stock-specific members
 * (Quantity, Unlimited, getBuyable) are reached through {@see asDynamic()} — the module needs no hard dependency
 * on the stock package.
 *
 * @extends Extension<DataObject>
 */
class ProductWarehouseStockExtension extends Extension
{
    public function onAfterWrite(): void
    {
        $owner = $this->getOwner();

        try {
            $changed = $owner->getChangedFields(true, DataObject::CHANGE_VALUE);
            if (!isset($changed['Quantity']) && !isset($changed['Unlimited'])) {
                return;
            }

            $stock = $this->asDynamic($owner);

            // Old quantity (null for a brand-new row — which is never a "restock", so it won't fire below).
            $oldQuantity = isset($changed['Quantity']['before']) ? (int) $changed['Quantity']['before'] : null;
            $wasUnlimited = !empty($changed['Unlimited']['before']) || ($oldQuantity !== null && $oldQuantity < 0);
            $nowUnlimited = (bool) $stock->Unlimited || (string) $stock->Quantity === '-1';
            $nowQuantity = (int) $stock->Quantity;

            // Restock: a row explicitly at 0 now has stock. A brand-new row (oldQuantity null) is never a restock.
            $wasOut = ($oldQuantity === 0) && !$wasUnlimited;
            $nowStocked = $nowQuantity > 0 || $nowUnlimited;

            // Out again: a row that had stock (or was unlimited) is now explicitly at 0 — the auto re-arm trigger.
            $wasStocked = $wasUnlimited || ($oldQuantity !== null && $oldQuantity > 0);
            $nowOut = !$nowUnlimited && $nowQuantity === 0;

            if ($wasOut && $nowStocked) {
                $buyable = $stock->getBuyable();
                if (!$buyable) {
                    return;
                }
                // Re-confirm the flip at the buyable level (multi-warehouse sum − cart reservations).
                if ($buyable->hasMethod('hasAvailableStock') && !$buyable->hasAvailableStock()) {
                    return;
                }
                StockAlertService::create()->enqueueBackInStock($buyable);
            } elseif ($wasStocked && $nowOut) {
                $buyable = $stock->getBuyable();
                if (!$buyable) {
                    return;
                }
                // Re-arm fired one-shot alerts only once the buyable is truly unavailable across all warehouses
                // (another warehouse may still hold stock, so this write is not a real "out of stock" for it).
                if ($buyable->hasMethod('hasAvailableStock') && $buyable->hasAvailableStock()) {
                    return;
                }
                StockAlertService::create()->rearm($buyable);
            }
        } catch (\Throwable) {
            // Detection is best-effort: a stock write must never fail because of the alerts module.
            // The recurring scan job is the backstop for anything missed here.
        }
    }

    /**
     * Launder the stock row to mixed so its silvershop/stock members (Quantity, Unlimited, getBuyable), reached via
     * __get/__call, analyse cleanly without a hard dependency on the stock package.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function asDynamic($value)
    {
        return $value;
    }
}
