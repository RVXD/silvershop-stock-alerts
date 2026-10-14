<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Extension;

use SilverShop\Page\Product;
use SilverShop\StockAlerts\Job\NotifyBackInStockJob;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * The restock trigger needs silvershop/stock (only a `suggest`), so this is skipped in the standalone clean-room
 * and runs where the stock module is present (the full shop). The end-to-end restock→notify flow is also verified
 * live on the dev site.
 */
class RestockDetectionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('SilverShop\\Stock\\Model\\ProductWarehouseStock')) {
            $this->markTestSkipped('silvershop/stock is not installed.');
        }
    }

    public function testRestockEnqueuesDispatchForVerifiedWaiter(): void
    {
        $warehouseClass = 'SilverShop\\Stock\\Model\\ProductWarehouse';
        $stockClass = 'SilverShop\\Stock\\Model\\ProductWarehouseStock';

        $product = Product::create();
        $product->Title = 'Restock me';
        $product->write();

        $warehouse = $warehouseClass::create();
        $warehouse->Title = 'Test warehouse';
        $warehouse->write();

        // Out of stock to begin with.
        $stock = $stockClass::create();
        $stock->ProductID = $product->ID;
        $stock->ProductClass = Product::class;
        $stock->WarehouseID = $warehouse->ID;
        $stock->Quantity = '0';
        $stock->write();

        // An armed waiter.
        $alert = StockAlertService::create()->subscribe($product, 'waiter@example.com');
        $alert->confirm();
        $alert->write();

        $before = QueuedJobDescriptor::get()->filter('Implementation', NotifyBackInStockJob::class)->count();

        // Restock: 0 -> 5 should flip availability and enqueue a dispatch job.
        $stock->Quantity = '5';
        $stock->write();

        $after = QueuedJobDescriptor::get()->filter('Implementation', NotifyBackInStockJob::class)->count();
        $this->assertSame($before + 1, $after, 'A restock enqueues a NotifyBackInStockJob.');
    }

    public function testOutOfStockAgainRearmsNotifiedAlert(): void
    {
        $warehouseClass = 'SilverShop\\Stock\\Model\\ProductWarehouse';
        $stockClass = 'SilverShop\\Stock\\Model\\ProductWarehouseStock';

        $product = Product::create();
        $product->Title = 'Cycle me';
        $product->write();

        $warehouse = $warehouseClass::create();
        $warehouse->Title = 'Test warehouse';
        $warehouse->write();

        // In stock to begin with.
        $stock = $stockClass::create();
        $stock->ProductID = $product->ID;
        $stock->ProductClass = Product::class;
        $stock->WarehouseID = $warehouse->ID;
        $stock->Quantity = '5';
        $stock->write();

        // A subscriber who was already notified on the previous restock.
        $alert = StockAlertService::create()->subscribe($product, 'cycle@example.com');
        $alert->confirm();
        $alert->markNotified();
        $alert->write();

        // Goes out of stock again (5 → 0): the fired alert is re-armed for the next restock.
        $stock->Quantity = '0';
        $stock->write();

        $this->assertSame(
            StockAlert::STATUS_VERIFIED,
            StockAlert::get()->byID($alert->ID)->Status,
            'Going out of stock again re-arms the notified alert.'
        );
    }
}
