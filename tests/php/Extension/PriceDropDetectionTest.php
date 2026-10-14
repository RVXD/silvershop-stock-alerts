<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Extension;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverShop\StockAlerts\Extension\ProductPriceAlertExtension;
use SilverShop\StockAlerts\Job\NotifyPriceDropJob;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * The price-drop trigger ({@link ProductPriceAlertExtension}). Core-only — no silvershop/stock — so it runs in the
 * standalone clean-room. Uses lightweight buyable stand-ins (not a SiteTree Product, which the bare test webroot
 * cannot write) to exercise both detection paths and the product→variation sweep:
 *  - onAfterWrite (the non-versioned path) via a real write;
 *  - onAfterPublish (the path real Versioned Product/Variation use) via invokeWithExtensions() with a stand-in
 *    prior record — the same way framework's Versioned fires the hook.
 * The real Versioned + PriceVariationsFromBase cascade is verified live.
 */
class PriceDropDetectionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        PriceWatchProduct::class,
        PriceWatchVariation::class,
    ];

    private function jobCount(): int
    {
        return QueuedJobDescriptor::get()->filter('Implementation', NotifyPriceDropJob::class)->count();
    }

    public function testWriteHookDetectsDrop(): void
    {
        $product = PriceWatchProduct::create();
        $product->Title = 'Drop me';
        $product->BasePrice = 100.00;
        $product->write();

        $alert = StockAlertService::create()->subscribe($product, 'writer@example.com', [
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);
        $alert->confirm();
        $alert->write();

        $before = $this->jobCount();

        $product->BasePrice = 80.00;
        $product->write();

        $this->assertSame($before + 1, $this->jobCount(), 'A written price drop enqueues a NotifyPriceDropJob.');
    }

    public function testPublishHookDetectsDrop(): void
    {
        $product = PriceWatchProduct::create();
        $product->Title = 'Publish drop';
        $product->BasePrice = 100.00;
        $product->write();

        $alert = StockAlertService::create()->subscribe($product, 'publisher@example.com', [
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);
        $alert->confirm();
        $alert->write();

        $before = $this->jobCount();

        // Simulate a Versioned publish: the new live price is lower than the previously-published record ($original),
        // exactly as framework hands onAfterPublish the prior Live version.
        $original = PriceWatchProduct::create();
        $original->BasePrice = 100.00;
        $product->BasePrice = 80.00; // the newly published price (in memory)
        $product->invokeWithExtensions('onAfterPublish', $original);

        $this->assertSame($before + 1, $this->jobCount(), 'A published price drop enqueues a NotifyPriceDropJob.');
    }

    public function testNoEnqueueOnPriceIncrease(): void
    {
        $product = PriceWatchProduct::create();
        $product->Title = 'Hold price';
        $product->BasePrice = 50.00;
        $product->write();

        StockAlertService::create()->subscribe($product, 'hold@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);

        $before = $this->jobCount();

        $product->BasePrice = 60.00;
        $product->write();

        $this->assertSame($before, $this->jobCount(), 'A price increase enqueues nothing.');
    }

    public function testProductDispatchSweepsVariationWaiters(): void
    {
        $product = PriceWatchProduct::create();
        $product->Title = 'Parent';
        $product->BasePrice = 100.00;
        $product->write();

        $variation = PriceWatchVariation::create();
        $variation->Price = 100.00;
        $variation->ProductID = $product->ID;
        $variation->write();

        // Watcher is on the VARIATION.
        $alert = StockAlertService::create()->subscribe($variation, 'cascade@example.com', [
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);
        $alert->confirm();
        $alert->write();
        $this->assertEquals(100.00, (float) $alert->SubscribedPrice);

        // The variation's price falls; a product-level dispatch must still reach the variation watcher.
        $variation->Price = 70.00;
        $variation->write();

        $sent = StockAlertService::create()->notifyPriceDrop($product);
        $this->assertSame(1, $sent, 'A product dispatch sweeps its variations and notifies the variation watcher.');
        $this->assertSame(StockAlert::STATUS_NOTIFIED, StockAlert::get()->byID($alert->ID)->Status);
    }
}

/**
 * A buyable stand-in with a base price and a variations relation — a plain DataObject (not a SiteTree Product,
 * which the bare test webroot cannot write), carrying the price-drop extension so the write/publish hooks fire.
 *
 * @property string $Title
 * @property float $BasePrice
 * @method \SilverStripe\ORM\HasManyList<PriceWatchVariation> Variations()
 */
class PriceWatchProduct extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_PriceWatchProduct';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'BasePrice' => 'Currency(19,4)',
    ];

    private static array $has_many = [
        'Variations' => PriceWatchVariation::class . '.Product',
    ];

    private static array $extensions = [
        ProductPriceAlertExtension::class,
    ];

    public function createItem(int $quantity = 1, array $filter = []): OrderItem
    {
        return OrderItem::create();
    }

    public function canPurchase(?Member $member = null, int $quantity = 1): bool
    {
        return true;
    }

    public function sellingPrice(): float
    {
        return (float) $this->BasePrice;
    }
}

/**
 * A variation stand-in with its own price and a product link.
 *
 * @property float $Price
 * @property int $ProductID
 * @method PriceWatchProduct Product()
 */
class PriceWatchVariation extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_PriceWatchVariation';

    private static array $db = [
        'Price' => 'Currency(19,4)',
    ];

    private static array $has_one = [
        'Product' => PriceWatchProduct::class,
    ];

    private static array $extensions = [
        ProductPriceAlertExtension::class,
    ];

    public function createItem(int $quantity = 1, array $filter = []): OrderItem
    {
        return OrderItem::create();
    }

    public function canPurchase(?Member $member = null, int $quantity = 1): bool
    {
        return true;
    }

    public function sellingPrice(): float
    {
        return (float) $this->Price;
    }
}
