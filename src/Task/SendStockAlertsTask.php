<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Task;

use SilverShop\StockAlerts\Job\ScanStockAlertsJob;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI runner / backstop for back-in-stock alerts — the same scan the recurring {@link ScanStockAlertsJob} performs,
 * runnable from cron without a job worker. Dry-run by default (lists what is waiting); pass --run to actually
 * re-check availability and send. --schedule (re-)arms the recurring scan job. Mirrors
 * silvershop/reviews' SendReviewInvitationsTask.
 */
class SendStockAlertsTask extends BuildTask
{
    protected string $title = 'Send stock alerts';

    protected static string $description =
        'Re-checks buyables with waiting back-in-stock alerts and sends the one-shot emails. Dry-run unless --run.';

    public function getOptions(): array
    {
        return [
            new InputOption('run', null, InputOption::VALUE_NONE, 'Actually send alerts (otherwise just list what is waiting).'),
            new InputOption('schedule', null, InputOption::VALUE_NONE, 'Queue the recurring scan job if it is not already scheduled.'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        // Make request-dependent sellingPrice() extensions (e.g. member pricing) safe to call from the CLI.
        StockAlertService::ensureRequest();

        $service = StockAlertService::create();

        if ($input->getOption('schedule')) {
            ScanStockAlertsJob::scheduleNext();
            $output->writeln('Recurring scan job scheduled (if it was not already).');
        }

        if (!$input->getOption('run')) {
            $waiting = StockAlert::get()->filter([
                'Type' => StockAlert::TYPE_BACK_IN_STOCK,
                'Status' => StockAlert::STATUS_VERIFIED,
            ]);
            $output->writeln('Dry run — ' . $waiting->count() . ' armed back-in-stock alert(s) waiting:');
            $seen = [];
            foreach ($waiting as $alert) {
                $key = $alert->BuyableClass . '|' . $alert->BuyableID;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $buyable = $alert->getBuyable();
                $available = ($buyable && $buyable->hasMethod('hasAvailableStock') && $buyable->hasAvailableStock())
                    ? 'available now'
                    : 'still out';
                $output->writeln(sprintf('  %s — %s', $alert->getBuyableSummary(), $available));
            }

            $watching = StockAlert::get()->filter([
                'Type' => StockAlert::TYPE_PRICE_DROP,
                'Status' => StockAlert::STATUS_VERIFIED,
            ]);
            $output->writeln($watching->count() . ' armed price-drop alert(s) watching:');
            $seenPrice = [];
            foreach ($watching as $alert) {
                $key = $alert->BuyableClass . '|' . $alert->BuyableID;
                if (isset($seenPrice[$key])) {
                    continue;
                }
                $seenPrice[$key] = true;
                $buyable = $alert->getBuyable();
                $now = ($buyable && $buyable->hasMethod('sellingPrice'))
                    ? number_format((float) $buyable->sellingPrice(), 2)
                    : '?';
                $output->writeln(sprintf('  %s — now %s (watched below %s)', $alert->getBuyableSummary(), $now, number_format((float) $alert->SubscribedPrice, 2)));
            }
            $output->writeln('Pass --run to re-check and send.');

            return Command::SUCCESS;
        }

        $result = $service->scanAndNotify();
        $output->writeln(sprintf(
            'Checked %d buyable(s); sent %d back-in-stock + %d price-drop email(s); expired %d stale alert(s).',
            (int) $result['checked'],
            (int) $result['notified'],
            (int) $result['priceDropNotified'],
            (int) $result['expired']
        ));

        return Command::SUCCESS;
    }
}
