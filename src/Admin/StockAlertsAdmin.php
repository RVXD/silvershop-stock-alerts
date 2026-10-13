<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Admin;

use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS area listing stock alerts — filter by Type / Status / email, see who is waiting on what and which alerts
 * have been notified. Mirrors silvershop/reviews' ReviewsAdmin.
 */
class StockAlertsAdmin extends ModelAdmin
{
    private static array $managed_models = [
        StockAlert::class,
    ];

    private static string $url_segment = 'stock-alerts';

    private static string $menu_title = 'Stock alerts';

    private static string $menu_icon_class = 'font-icon-bell';
}
