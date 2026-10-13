<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Job;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Instant back-in-stock dispatch for one buyable, enqueued by {@link \SilverShop\StockAlerts\Extension\ProductWarehouseStockExtension}
 * the moment a restock flips availability false→true. Loads the Verified waiters and sends their one-shot emails via
 * {@link StockAlertService::notifyBackInStock()} (which claims each row atomically, so a retry never double-sends).
 * Retryable + visible in QueuedJobAdmin — the reason symbiote/silverstripe-queuedjobs is a hard requirement.
 *
 * Mirrors silvershop/newsletter's NewsletterSyncJob. The recurring {@link ScanStockAlertsJob} is the backstop and
 * {@link \SilverShop\StockAlerts\Task\SendStockAlertsTask} the CLI runner over the same service.
 *
 * @property string $BuyableClass
 * @property int $BuyableID
 */
class NotifyBackInStockJob extends AbstractQueuedJob
{
    // retry_max_attempts / retry_initial_delay / retry_falloff_multiplier (inherited queuedjobs config) are set
    // in _config/stock-alerts.yml so a transient mail/DB failure is retried a few times with a growing delay.

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
            'Notify back-in-stock waiters for {class} #{id}',
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

        $sent = StockAlertService::create()->notifyBackInStock($buyable);

        $this->addMessage(sprintf(
            'Sent %d back-in-stock email(s) for %s #%d',
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
     * Queue an instant dispatch for a buyable.
     *
     * @param DataObject $buyable
     */
    public static function enqueue($buyable): void
    {
        if (!is_object($buyable) || !$buyable instanceof DataObject || !$buyable->exists()) {
            return;
        }

        QueuedJobService::singleton()->queueJob(
            new NotifyBackInStockJob((string) $buyable->ClassName, (int) $buyable->ID)
        );
    }
}
