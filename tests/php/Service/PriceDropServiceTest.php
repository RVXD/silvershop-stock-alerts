<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Service;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * The core-only price-drop dispatch logic (floors, one-shot claim, the scan backstop) and the auto re-arm, both
 * exercised at the service level with a settable-price test buyable so no CMS/Versioned machinery is needed. The
 * publish-hook detection is covered end-to-end in PriceDropDetectionTest + the live site.
 */
class PriceDropServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        PriceTestBuyable::class,
    ];

    public function testSubscribeCapturesBaselinePrice(): void
    {
        $buyable = $this->buyable(20.00);
        $alert = StockAlertService::create()->subscribe($buyable, 'watch@example.com', [
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);

        $this->assertNotNull($alert);
        $this->assertSame(StockAlert::TYPE_PRICE_DROP, $alert->Type);
        $this->assertEquals(20.00, (float) $alert->SubscribedPrice, 'The selling price is captured at subscribe.');
    }

    public function testNotifyPriceDropIsOneShotBelowBaseline(): void
    {
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'drop@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);
        $alert->confirm();
        $alert->write();

        // Price drops below what they watched at.
        $buyable->Price = 15.00;
        $buyable->write();

        $this->assertSame(1, $service->notifyPriceDrop($buyable), 'The watcher is notified of the drop.');

        $alert = StockAlert::get()->byID($alert->ID);
        $this->assertSame(StockAlert::STATUS_NOTIFIED, $alert->Status);
        $this->assertNotEmpty($alert->NotifiedAt);

        // One-shot: a second run sends nothing.
        $this->assertSame(0, $service->notifyPriceDrop($buyable));
    }

    public function testNoNotifyWhenPriceNotLowerThanBaseline(): void
    {
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'same@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);
        $alert->confirm();
        $alert->write();

        // Price unchanged (== baseline): no drop.
        $this->assertSame(0, $service->notifyPriceDrop($buyable));
        // Price went up: no drop.
        $buyable->Price = 25.00;
        $buyable->write();
        $this->assertSame(0, $service->notifyPriceDrop($buyable));
    }

    public function testAbsoluteFloorSuppressesTinyDrops(): void
    {
        Config::modify()->set(StockAlertService::class, 'price_drop_min_absolute', 5.00);
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'floor@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);
        $alert->confirm();
        $alert->write();

        // Drop of 2.00 — below the 5.00 floor.
        $buyable->Price = 18.00;
        $buyable->write();
        $this->assertSame(0, $service->notifyPriceDrop($buyable), 'A drop under the absolute floor does not notify.');

        // Drop of 6.00 — clears the floor.
        $buyable->Price = 14.00;
        $buyable->write();
        $this->assertSame(1, $service->notifyPriceDrop($buyable));
    }

    public function testPercentFloorSuppressesTinyDrops(): void
    {
        Config::modify()->set(StockAlertService::class, 'price_drop_min_percent', 20.0);
        $buyable = $this->buyable(100.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'pct@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);
        $alert->confirm();
        $alert->write();

        // 10% drop — below the 20% floor.
        $buyable->Price = 90.00;
        $buyable->write();
        $this->assertSame(0, $service->notifyPriceDrop($buyable));

        // 25% drop — clears the floor.
        $buyable->Price = 75.00;
        $buyable->write();
        $this->assertSame(1, $service->notifyPriceDrop($buyable));
    }

    public function testScanNotifiesPriceDropBackstop(): void
    {
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'scan@example.com', ['Type' => StockAlert::TYPE_PRICE_DROP]);
        $alert->confirm();
        $alert->write();

        $buyable->Price = 12.00;
        $buyable->write();

        $result = $service->scanAndNotify();
        $this->assertSame(1, (int) $result['priceDropNotified'], 'The scan backstop catches the price drop.');
    }

    public function testEnqueueOnlyWhenAQualifyingWaiterExists(): void
    {
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        // No waiter yet → notify is a no-op.
        $buyable->Price = 10.00;
        $buyable->write();
        $this->assertSame(0, $service->notifyPriceDrop($buyable));
    }

    public function testRearmFlipsNotifiedBackToVerified(): void
    {
        Config::modify()->set(StockAlertService::class, 'auto_rearm', true);
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'rearm@example.com');
        $alert->confirm();
        $alert->markNotified();
        $alert->write();
        $this->assertSame(StockAlert::STATUS_NOTIFIED, $alert->Status);

        $this->assertSame(1, $service->rearm($buyable));
        $alert = StockAlert::get()->byID($alert->ID);
        $this->assertSame(StockAlert::STATUS_VERIFIED, $alert->Status);
        $this->assertEmpty($alert->NotifiedAt, 'Re-arm clears the sent stamp so it can fire again.');
    }

    public function testRearmIsNoopWhenDisabled(): void
    {
        Config::modify()->set(StockAlertService::class, 'auto_rearm', false);
        $buyable = $this->buyable(20.00);
        $service = StockAlertService::create();

        $alert = $service->subscribe($buyable, 'norearm@example.com');
        $alert->confirm();
        $alert->markNotified();
        $alert->write();

        $this->assertSame(0, $service->rearm($buyable));
        $this->assertSame(
            StockAlert::STATUS_NOTIFIED,
            StockAlert::get()->byID($alert->ID)->Status,
            'With auto_rearm off the notified alert is left alone.'
        );
    }

    private function buyable(float $price): PriceTestBuyable
    {
        $buyable = PriceTestBuyable::create();
        $buyable->Title = 'Priced buyable';
        $buyable->Price = $price;
        $buyable->write();

        return $buyable;
    }
}

/**
 * A Buyable stand-in with a settable price (plain DataObject, not a SiteTree Product) for the service-level
 * price-drop tests. Co-located per the reviews TestOnly pattern.
 *
 * @property string $Title
 * @property float $Price
 */
class PriceTestBuyable extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_PriceTestBuyable';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Price' => 'Currency(19,4)',
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
