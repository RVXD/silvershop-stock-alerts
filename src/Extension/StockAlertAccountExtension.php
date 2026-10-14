<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Security;

/**
 * Adds a "My alerts" section to the shop account area — an `account/alerts` page listing the member's active
 * back-in-stock and price-drop alerts with a one-click unsubscribe. Mirrors silvershop/wishlist's
 * WishlistAccountExtension (action + link accessor; the nav link is rendered by the theme's AccountNavigation
 * override, guarded on the accessor's presence) and silvershop/newsletter's account section.
 *
 * Only attached when silvershop/core is installed (the extension targets AccountPageController, registered in
 * the module's _config).
 *
 * @extends Extension<\SilverShop\Page\AccountPageController>
 */
class StockAlertAccountExtension extends Extension
{
    /**
     * @var array<string>
     */
    private static array $allowed_actions = [
        'alerts',
    ];

    /**
     * Renders AccountPage_alerts.ss (the template reads $MemberStockAlerts).
     *
     * @return array<string, mixed>
     */
    public function alerts(): array
    {
        return [];
    }

    /**
     * Account-area link to the "My alerts" section — its presence drives the nav entry in the theme.
     */
    public function StockAlertsAccountLink(): string
    {
        return $this->getOwner()->Link('alerts');
    }

    /**
     * The current member's active alerts (awaiting confirmation, armed/watching, or already notified), newest
     * first — matched by member link or by email so a guest alert made before login still shows. Expired and
     * unsubscribed rows are left out. Null when nobody is logged in.
     *
     * @return DataList|null
     */
    public function MemberStockAlerts(): ?DataList
    {
        $member = Security::getCurrentUser();
        if (!$member || !$member->exists()) {
            return null;
        }

        $email = trim(mb_strtolower((string) $member->Email));

        return StockAlert::get()
            ->filter('Status', [
                StockAlert::STATUS_PENDING,
                StockAlert::STATUS_VERIFIED,
                StockAlert::STATUS_NOTIFIED,
            ])
            ->filterAny([
                'MemberID' => (int) $member->ID,
                'Email' => $email,
            ])
            ->sort('Created', 'DESC');
    }

    /**
     * Whether the member has any alerts to show — lets the nav/section hide cleanly when empty.
     */
    public function HasStockAlerts(): bool
    {
        $alerts = $this->MemberStockAlerts();

        return $alerts !== null && $alerts->exists();
    }
}
