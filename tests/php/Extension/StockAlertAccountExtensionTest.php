<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Extension;

use SilverShop\StockAlerts\Extension\StockAlertAccountExtension;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * The account "My alerts" section data: the member sees their active back-in-stock + price-drop alerts (matched
 * by member link or email), and not the expired/unsubscribed ones or other people's.
 */
class StockAlertAccountExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testMemberStockAlertsAreScopedAndFiltered(): void
    {
        $member = Member::create();
        $member->Email = 'me@example.com';
        $member->write();
        $this->logInAs($member);

        // Linked by member.
        $this->makeAlert('me@example.com', StockAlert::STATUS_VERIFIED, (int) $member->ID);
        // Linked only by email (guest sign-up before login).
        $this->makeAlert('me@example.com', StockAlert::STATUS_NOTIFIED, 0);
        // Terminal states: excluded.
        $this->makeAlert('me@example.com', StockAlert::STATUS_EXPIRED, (int) $member->ID);
        $this->makeAlert('me@example.com', StockAlert::STATUS_UNSUBSCRIBED, (int) $member->ID);
        // Someone else: excluded.
        $this->makeAlert('other@example.com', StockAlert::STATUS_VERIFIED, 0);

        $extension = new StockAlertAccountExtension();
        $alerts = $extension->MemberStockAlerts();

        $this->assertNotNull($alerts);
        $this->assertSame(2, $alerts->count(), 'Only the two active alerts for this member (by id or email) show.');
        $this->assertTrue($extension->HasStockAlerts());
        foreach ($alerts as $alert) {
            $this->assertSame('me@example.com', $alert->Email);
            $this->assertContains($alert->Status, [StockAlert::STATUS_VERIFIED, StockAlert::STATUS_NOTIFIED]);
        }
    }

    public function testNullWhenLoggedOut(): void
    {
        $this->logOut();
        $extension = new StockAlertAccountExtension();

        $this->assertNull($extension->MemberStockAlerts());
        $this->assertFalse($extension->HasStockAlerts());
    }

    private function makeAlert(string $email, string $status, int $memberId): StockAlert
    {
        $alert = StockAlert::create();
        $alert->Email = $email;
        $alert->Status = $status;
        $alert->MemberID = $memberId;
        $alert->write();

        return $alert;
    }
}
