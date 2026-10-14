<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Job;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Instant price-drop dispatch for one buyable, enqueued by {@link \SilverShop\StockAlerts\Extension\ProductPriceAlertExtension}
 * the moment a published price drops below what a waiter subscribed at. Loads the Verified price-drop waiters and
 * sends their one-shot emails via {@link StockAlertService::notifyPriceDrop()} (which claims each row atomically,
 * so a retry never double-sends, and sweeps a product's variations for a base-price cascade).
 *
 * Mirrors {@link NotifyBackInStockJob}. The recurring {@link ScanStockAlertsJob} is the backstop and
 * {@link \SilverShop\StockAlerts\Task\SendStockAlertsTask} the CLI runner over the same service.
 *
 * @property string $BuyableClass
 * @property int $BuyableID
 */
class NotifyPriceDropJob extends AbstractQueuedJob
{
    public function __construct(string $buyableClass = '', int $buyableID = 0)
    {
        if ($buyableClass !== '' && $buyableID > 0) {
            $this->BuyableClass = $buyableClass;
            $this->BuyableID = $buyableID;
        }
    }

    public function getTitle(): string
    {
        return _t(
            self::class . '.TITLE',
            'Notify price-drop waiters for {class} #{id}',
            ['class' => (string) $this->BuyableClass, 'id' => (int) $this->BuyableID]
        );
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $buyable = $this->resolveBuyable();
        if (!$buyable) {
            // Subject gone (deleted between enqueue and run) — a successful no-op.
            $this->isComplete = true;
            return;
        }

        $sent = StockAlertService::create()->notifyPriceDrop($buyable);

        $this->addMessage(sprintf(
            'Sent %d price-drop email(s) for %s #%d',
            $sent,
            (string) $this->BuyableClass,
            (int) $this->BuyableID
        ));
        $this->isComplete = true;
    }

    /**
     * Resolve the stored buyable polymorph, or null when it no longer exists.
     *
     * @return DataObject|null
     */
    protected function resolveBuyable()
    {
        $class = (string) $this->BuyableClass;
        $id = (int) $this->BuyableID;
        if ($class === '' || $id < 1 || !is_subclass_of($class, DataObject::class)) {
            return null;
        }
        /** @var DataObject|null $record */
        $record = $class::get()->byID($id);

        return ($record && $record->exists()) ? $record : null;
    }

    /**
     * Queue an instant price-drop dispatch for a buyable.
     *
     * @param DataObject $buyable
     */
    public static function enqueue($buyable): void
    {
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return;
        }

        QueuedJobService::singleton()->queueJob(
            new NotifyPriceDropJob((string) $buyable->ClassName, (int) $buyable->ID)
        );
    }
}
