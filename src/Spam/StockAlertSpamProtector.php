<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Spam;

use SilverStripe\Control\HTTPRequest;

/**
 * The seam that decides whether a subscribe POST to {@link \SilverShop\StockAlerts\Control\StockAlertController}
 * looks like spam. It is resolved via Injector from the controller's `spam_protector` config, so a shop can swap in
 * its own protection for the stock-alert forms WITHOUT touching the site-wide `silverstripe/spamprotection`
 * SpamProtector:
 *
 *  - the zero-dep default is {@link HoneypotSpamProtector} (honeypot field + the always-on CSRF token);
 *  - a shop can point `spam_protector` at its own class (e.g. one wrapping `silverstripe/spamprotection` or
 *    `xddesigners/silverstripe-honeypotfield`);
 *  - and extra hidden fields a protector needs can be injected into the hand-rolled storefront form via the
 *    `updateStockAlertFormFields` extension hook on the product controller.
 *
 * Kept to a single verdict method so it stays trivial to implement and unit-test against a raw request (the
 * storefront form is progressively-enhanced markup, not a SilverStripe Form instance).
 */
interface StockAlertSpamProtector
{
    /**
     * Whether this subscribe request should be treated as spam (silently dropped — the controller still answers a
     * fake "pending" so a bot learns nothing).
     */
    public function isSpam(HTTPRequest $request): bool;
}
