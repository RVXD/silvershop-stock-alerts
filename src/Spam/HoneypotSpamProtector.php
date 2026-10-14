<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Spam;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * The built-in, zero-dependency default spam protector: a honeypot field real users never see or fill (paired with
 * the controller's always-on CSRF token). A non-empty honeypot value marks a scripted submission.
 *
 * This is deliberately NOT a hard-wire to `silverstripe/spamprotection`'s global SpamProtector — a shop that wants
 * a captcha or its own module points the controller's `spam_protector` config at its own
 * {@link StockAlertSpamProtector} instead.
 */
class HoneypotSpamProtector implements StockAlertSpamProtector
{
    use Configurable;
    use Injectable;

    /**
     * The hidden form field real users never fill (matches the form's honeypot input name).
     */
    private static string $honeypot_field = 'website';

    public function isSpam(HTTPRequest $request): bool
    {
        $field = (string) static::config()->get('honeypot_field');
        if ($field === '') {
            return false;
        }

        return trim((string) $request->postVar($field)) !== '';
    }
}
