<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Spam;

use SilverShop\StockAlerts\Spam\HoneypotSpamProtector;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The built-in (default) spam protector: an empty honeypot is a human, a filled one is a bot.
 */
class HoneypotSpamProtectorTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testEmptyHoneypotIsNotSpam(): void
    {
        $request = new HTTPRequest('POST', 'stock-alert/subscribe', [], ['email' => 'a@b.com', 'website' => '']);

        $this->assertFalse(HoneypotSpamProtector::create()->isSpam($request));
    }

    public function testFilledHoneypotIsSpam(): void
    {
        $request = new HTTPRequest('POST', 'stock-alert/subscribe', [], ['email' => 'a@b.com', 'website' => 'http://spam']);

        $this->assertTrue(HoneypotSpamProtector::create()->isSpam($request));
    }

    public function testHoneypotFieldNameIsConfigurable(): void
    {
        Config::modify()->set(HoneypotSpamProtector::class, 'honeypot_field', 'leave_blank');

        $tripped = new HTTPRequest('POST', 'stock-alert/subscribe', [], ['leave_blank' => 'x']);
        $clean = new HTTPRequest('POST', 'stock-alert/subscribe', [], ['website' => 'x']); // old name no longer watched

        $this->assertTrue(HoneypotSpamProtector::create()->isSpam($tripped));
        $this->assertFalse(HoneypotSpamProtector::create()->isSpam($clean));
    }
}
