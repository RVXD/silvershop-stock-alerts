<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Job;

use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Recurring safety-net scan — the backstop for any restock the write-hook missed (bulk imports, direct SQL, seeded
 * stock). Re-checks every buyable with Verified waiters against hasAvailableStock(), dispatches the matches, prunes
 * expired Pending rows, then re-queues itself `scan_interval_mins` later (one scan scheduled at a time).
 *
 * Strictly better than Magento's pure cron-scan: the instant {@link NotifyBackInStockJob} handles the common case,
 * this only sweeps up the stragglers. Kick it off with `SendStockAlertsTask --schedule` (or it is a no-op until then).
 */
class ScanStockAlertsJob extends AbstractQueuedJob
{
    use Configurable;

    /**
     * Minutes between scan runs (the job re-queues itself this far in the future).
     */
    private static int $scan_interval_mins = 30;

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Scan for back-in-stock alerts to send');
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $result = StockAlertService::create()->scanAndNotify();

        $this->addMessage(sprintf(
            'Scanned %d buyable(s), sent %d email(s), expired %d stale alert(s)',
            (int) $result['checked'],
            (int) $result['notified'],
            (int) $result['expired']
        ));
        $this->isComplete = true;

        // Re-arm the recurring scan.
        self::scheduleNext();
    }

    /**
     * Schedule the next scan `scan_interval_mins` from now, unless one is already queued/running.
     */
    public static function scheduleNext(): void
    {
        if (self::isScheduled()) {
            return;
        }

        $mins = max(1, (int) static::config()->get('scan_interval_mins'));
        $startAfter = date('Y-m-d H:i:s', strtotime('+' . $mins . ' minutes', strtotime(DBDatetime::now()->getValue())));

        QueuedJobService::singleton()->queueJob(new ScanStockAlertsJob(), $startAfter);
    }

    /**
     * Whether a scan job is already queued or running (so we never pile up duplicates).
     */
    public static function isScheduled(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => self::class,
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                    QueuedJob::STATUS_PAUSED,
                ],
            ])
            ->exists();
    }
}
