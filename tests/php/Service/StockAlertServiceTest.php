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
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

class StockAlertServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        StockAlertTestBuyable::class,
    ];

    public function testGuestSubscribeIsPendingWithDoubleOptIn(): void
    {
        Config::modify()->set(StockAlertService::class, 'double_opt_in', true);

        $alert = StockAlertService::create()->subscribe($this->buyable(), 'guest@example.com');

        $this->assertNotNull($alert);
        $this->assertSame(StockAlert::STATUS_PENDING, $alert->Status);
        $this->assertFalse((bool) $alert->Consent);
        $this->assertEquals(12.50, (float) $alert->SubscribedPrice, 'The buyable price is captured at subscribe.');
    }

    public function testMemberSubscribeIsAutoConfirmed(): void
    {
        Config::modify()->set(StockAlertService::class, 'double_opt_in', true);
        Config::modify()->set(StockAlertService::class, 'member_auto_confirm', true);

        $member = Member::create();
        $member->Email = 'member@example.com';
        $member->write();

        $alert = StockAlertService::create()->subscribe($this->buyable(), 'member@example.com', ['Member' => $member]);

        $this->assertNotNull($alert);
        $this->assertSame(StockAlert::STATUS_VERIFIED, $alert->Status);
        $this->assertTrue((bool) $alert->Consent);
        $this->assertSame((int) $member->ID, (int) $alert->MemberID);
    }

    public function testPriceDropGuestSubscribeCapturesBaselineAndType(): void
    {
        Config::modify()->set(StockAlertService::class, 'double_opt_in', true);

        $alert = StockAlertService::create()->subscribe($this->buyable(), 'watcher@example.com', [
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);

        $this->assertNotNull($alert);
        $this->assertSame(StockAlert::TYPE_PRICE_DROP, $alert->Type);
        $this->assertSame(StockAlert::STATUS_PENDING, $alert->Status, 'A guest price-drop watch is double-opt-in.');
        $this->assertEqualsWithDelta(12.50, (float) $alert->SubscribedPrice, 0.001, 'The subscribe-time price is the drop baseline.');
    }

    public function testPriceDropMemberSubscribeIsAutoConfirmed(): void
    {
        Config::modify()->set(StockAlertService::class, 'member_auto_confirm', true);

        $member = Member::create();
        $member->Email = 'pdmember@example.com';
        $member->write();

        $alert = StockAlertService::create()->subscribe($this->buyable(), 'pdmember@example.com', [
            'Member' => $member,
            'Type' => StockAlert::TYPE_PRICE_DROP,
        ]);

        $this->assertNotNull($alert);
        $this->assertSame(StockAlert::STATUS_VERIFIED, $alert->Status, 'A member price-drop watch is auto-confirmed.');
        $this->assertSame(StockAlert::TYPE_PRICE_DROP, $alert->Type);
    }

    public function testInvalidEmailReturnsNull(): void
    {
        $this->assertNull(StockAlertService::create()->subscribe($this->buyable(), 'not-an-email'));
        $this->assertNull(StockAlertService::create()->subscribe($this->buyable(), ''));
    }

    public function testDedupeReusesActiveAlert(): void
    {
        $service = StockAlertService::create();
        $buyable = $this->buyable();

        $first = $service->subscribe($buyable, 'dup@example.com');
        $second = $service->subscribe($buyable, 'dup@example.com');

        $this->assertSame((int) $first->ID, (int) $second->ID, 'The same active alert is reused, not duplicated.');
        $this->assertSame(1, StockAlert::get()->filter('Email', 'dup@example.com')->count());
    }

    public function testConfirmAndUnsubscribeByToken(): void
    {
        $service = StockAlertService::create();
        $alert = $service->subscribe($this->buyable(), 'token@example.com');

        $confirmed = $service->confirmByToken($alert->Token);
        $this->assertSame(StockAlert::STATUS_VERIFIED, $confirmed->Status);

        $unsub = $service->unsubscribeByToken($alert->Token);
        $this->assertSame(StockAlert::STATUS_UNSUBSCRIBED, $unsub->Status);

        $this->assertNull($service->confirmByToken('nonexistent-token'));
    }

    public function testNotifyBackInStockIsOneShotAndSkipsPending(): void
    {
        $buyable = $this->buyable();
        $service = StockAlertService::create();

        // One verified (armed) and one pending (unconfirmed) waiter.
        $verified = $service->subscribe($buyable, 'armed@example.com');
        $verified->confirm();
        $verified->write();
        $service->subscribe($buyable, 'pending@example.com'); // stays Pending

        $sent = $service->notifyBackInStock($buyable);
        $this->assertSame(1, $sent, 'Only the verified waiter is notified.');

        $verified = StockAlert::get()->byID($verified->ID);
        $this->assertSame(StockAlert::STATUS_NOTIFIED, $verified->Status);
        $this->assertNotEmpty($verified->NotifiedAt);

        // One-shot: a second run sends nothing (already Notified).
        $this->assertSame(0, $service->notifyBackInStock($buyable));
    }

    public function testMatchingAlertsFindsProductWaiters(): void
    {
        $buyable = $this->buyable();
        $service = StockAlertService::create();
        $alert = $service->subscribe($buyable, 'match@example.com');
        $alert->confirm();
        $alert->write();

        $matches = $service->matchingAlerts($buyable, [StockAlert::STATUS_VERIFIED]);
        $this->assertSame(1, $matches->count());
        $this->assertSame('match@example.com', $matches->first()->Email);
    }

    public function testWaitingCount(): void
    {
        $buyable = $this->buyable();
        $service = StockAlertService::create();
        $service->subscribe($buyable, 'one@example.com');
        $verified = $service->subscribe($buyable, 'two@example.com');
        $verified->confirm();
        $verified->write();

        $this->assertSame(2, $service->waitingCount($buyable), 'Pending + Verified both count.');
    }

    public function testPruneExpired(): void
    {
        Config::modify()->set(StockAlertService::class, 'verify_ttl_days', 7);
        $buyable = $this->buyable();
        $service = StockAlertService::create();

        // Create the pending alert stamped well in the past (beyond the TTL).
        $alert = DBDatetime::withFixedNow('2020-01-01 00:00:00', fn () => $service->subscribe($buyable, 'stale@example.com'));

        $expired = $service->pruneExpired();
        $this->assertSame(1, $expired);
        $this->assertSame(StockAlert::STATUS_EXPIRED, StockAlert::get()->byID($alert->ID)->Status);
    }

    private function buyable(string $title = 'Test buyable'): StockAlertTestBuyable
    {
        $buyable = StockAlertTestBuyable::create();
        $buyable->Title = $title;
        $buyable->write();

        return $buyable;
    }
}

/**
 * A minimal Buyable stand-in (plain DataObject, not a SiteTree Product) for the service tests. Co-located per the
 * reviews TestOnly pattern.
 *
 * @property string $Title
 */
class StockAlertTestBuyable extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_SvcTestBuyable';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    public function createItem(int $quantity = 1, array $filter = []): OrderItem
    {
        return OrderItem::create();
    }

    public function canPurchase(?Member $member = null, int $quantity = 1): bool
    {
        return false;
    }

    public function sellingPrice(): float
    {
        return 12.50;
    }
}
