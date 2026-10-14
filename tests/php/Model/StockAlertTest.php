<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Model;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

class StockAlertTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        StockAlertTestBuyable::class,
    ];

    public function testTokenIsGeneratedAndEmailNormalised(): void
    {
        $alert = StockAlert::create();
        $alert->Email = '  Someone@Example.COM ';
        $alert->write();

        $this->assertNotEmpty($alert->Token, 'A token is auto-generated on write.');
        $this->assertSame(32, strlen($alert->Token), 'The token is a 128-bit hex string.');
        $this->assertSame('someone@example.com', $alert->Email, 'The email is trimmed + lowercased.');
    }

    public function testBuyableResolvesFromPolymorph(): void
    {
        $buyable = $this->makeBuyable('Resolvable');

        $alert = StockAlert::create();
        $alert->Email = 'a@b.com';
        $alert->BuyableClass = StockAlertTestBuyable::class;
        $alert->BuyableID = $buyable->ID;
        $alert->write();

        $this->assertNotNull($alert->getBuyable());
        $this->assertSame((int) $buyable->ID, (int) $alert->getBuyable()->ID);
        $this->assertSame((int) $buyable->ID, (int) $alert->getProduct()->ID);
        $this->assertSame('Resolvable', $alert->getBuyableTitle());
    }

    public function testBuyableIsNullWhenSubjectMissing(): void
    {
        $alert = StockAlert::create();
        $alert->BuyableClass = StockAlertTestBuyable::class;
        $alert->BuyableID = 999999;
        $alert->write();

        $this->assertNull($alert->getBuyable());
        $this->assertNull($alert->getProduct());
    }

    public function testLifecycleTransitions(): void
    {
        $alert = StockAlert::create();
        $alert->Email = 'c@d.com';
        $alert->write();
        $this->assertSame(StockAlert::STATUS_PENDING, $alert->Status);
        $this->assertTrue($alert->isActive());

        $alert->confirm();
        $this->assertSame(StockAlert::STATUS_VERIFIED, $alert->Status);
        $this->assertTrue((bool) $alert->Consent);
        $this->assertNotEmpty($alert->ConsentAt);
        $this->assertNotEmpty($alert->ConfirmedAt);
        $this->assertTrue($alert->isActive());

        $alert->markNotified();
        $this->assertSame(StockAlert::STATUS_NOTIFIED, $alert->Status);
        $this->assertNotEmpty($alert->NotifiedAt);
        $this->assertFalse($alert->isActive());

        $fresh = StockAlert::create();
        $fresh->markUnsubscribed();
        $this->assertSame(StockAlert::STATUS_UNSUBSCRIBED, $fresh->Status);

        $fresh->markExpired();
        $this->assertSame(StockAlert::STATUS_EXPIRED, $fresh->Status);
    }

    public function testTokenLinks(): void
    {
        $alert = StockAlert::create();
        $alert->write();

        $this->assertStringContainsString('stock-alert/confirm/' . $alert->Token, $alert->ConfirmLink());
        $this->assertStringContainsString('stock-alert/unsubscribe/' . $alert->Token, $alert->UnsubscribeLink());
    }

    public function testMaskEmailHelper(): void
    {
        $this->assertSame('m•••@example.com', StockAlert::maskEmail('mila.devries@example.com'));
        $this->assertSame('b•••@shop.test', StockAlert::maskEmail('bob@shop.test'));
        $this->assertSame('', StockAlert::maskEmail(''));
        $this->assertSame('•••', StockAlert::maskEmail('@no-local'), 'A missing local part is fully masked.');
    }

    public function testDisplayEmailRespectsMaskConfig(): void
    {
        $alert = StockAlert::create();
        $alert->Email = 'Mila.Devries@Example.com';
        $alert->write(); // onBeforeWrite lowercases → mila.devries@example.com

        Config::modify()->set(StockAlert::class, 'mask_displayed_email', true);
        $this->assertSame('m•••@example.com', $alert->getMaskedEmail());
        $this->assertSame('m•••@example.com', $alert->getDisplayEmail(), 'Masked on the persistent panel by default.');

        Config::modify()->set(StockAlert::class, 'mask_displayed_email', false);
        $this->assertSame('mila.devries@example.com', $alert->getDisplayEmail(), 'Full address when masking is off.');
        $this->assertSame('m•••@example.com', $alert->getMaskedEmail(), 'getMaskedEmail is always masked.');
    }

    private function makeBuyable(string $title): StockAlertTestBuyable
    {
        $buyable = StockAlertTestBuyable::create();
        $buyable->Title = $title;
        $buyable->write();

        return $buyable;
    }
}

/**
 * A minimal Buyable stand-in — a plain DataObject (not a SiteTree Product), so it writes without the CMS/assets
 * machinery; the StockAlert polymorph resolves any DataObject by class + ID. Co-located like reviews' TestOnly stand-ins.
 *
 * @property string $Title
 */
class StockAlertTestBuyable extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_ModelTestBuyable';

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
