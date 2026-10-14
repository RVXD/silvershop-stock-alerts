<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Control;

use SilverShop\StockAlerts\Control\StockAlertController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;

/**
 * The per-IP + per-email subscribe throttle (abuse prevention — bounds how many confirmation emails the public
 * endpoint can be scripted to send). Exercised through a probe subclass that exposes the protected check, with a
 * unique identifier per case so the cache-backed counters start fresh regardless of the shared cache backend.
 */
class StockAlertRateLimitTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testPerEmailThrottle(): void
    {
        Config::modify()->set(StockAlertController::class, 'max_subscribes_per_window', 2);
        Config::modify()->set(StockAlertController::class, 'subscribe_window_mins', 60);

        $controller = RateLimitProbeController::create();
        $email = 'rl-' . uniqid() . '@example.com';
        $request = $this->request();

        $this->assertFalse($controller->probe($request, $email), '1st subscribe is allowed.');
        $this->assertFalse($controller->probe($request, $email), '2nd subscribe is allowed.');
        $this->assertTrue($controller->probe($request, $email), '3rd subscribe (over the cap) is throttled.');
    }

    public function testPerIpThrottleAcrossDifferentEmails(): void
    {
        Config::modify()->set(StockAlertController::class, 'max_subscribes_per_window', 2);
        Config::modify()->set(StockAlertController::class, 'subscribe_window_mins', 60);

        $controller = RateLimitProbeController::create();
        $request = $this->request($this->uniqueIp());

        $this->assertFalse($controller->probe($request, 'a-' . uniqid() . '@example.com'));
        $this->assertFalse($controller->probe($request, 'b-' . uniqid() . '@example.com'));
        // Same IP, a third DISTINCT (fresh) email — still blocked by the per-IP limiter.
        $this->assertTrue($controller->probe($request, 'c-' . uniqid() . '@example.com'));
    }

    public function testThrottleDisabledWhenMaxIsZero(): void
    {
        Config::modify()->set(StockAlertController::class, 'max_subscribes_per_window', 0);
        Config::modify()->set(StockAlertController::class, 'subscribe_window_mins', 60);

        $controller = RateLimitProbeController::create();
        $request = $this->request($this->uniqueIp());
        $email = 'off-' . uniqid() . '@example.com';

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($controller->probe($request, $email), 'No throttle when the cap is disabled.');
        }
    }

    private function request(?string $ip = null): HTTPRequest
    {
        $request = new HTTPRequest('POST', 'stock-alert/subscribe');
        $request->setSession(new Session([]));
        if ($ip !== null) {
            $request->setIP($ip);
        }

        return $request;
    }

    /**
     * A valid, effectively-unique IPv6 so the per-IP limiter's cache counter starts fresh every run.
     */
    private function uniqueIp(): string
    {
        return (string) inet_ntop(random_bytes(16));
    }
}

/**
 * Exposes the controller's protected rate-limit check for testing.
 */
class RateLimitProbeController extends StockAlertController implements TestOnly
{
    public function probe(HTTPRequest $request, string $email): bool
    {
        return $this->isRateLimited($request, $email);
    }
}
