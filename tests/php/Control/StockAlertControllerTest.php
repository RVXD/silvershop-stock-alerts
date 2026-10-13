<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Tests\Control;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverShop\StockAlerts\Control\StockAlertController;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;

/**
 * The HTTP flow for the subscribe endpoint and the tokenised confirm/unsubscribe landings. Product resolution
 * through resolveBuyable() + the subscribe policy are covered at the service level; here we exercise the controller
 * glue (method guard, honeypot, unknown-subject, JSON shape, token landings) without writing a SiteTree Product.
 */
class StockAlertControllerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        StockAlertTestBuyable::class,
    ];

    protected bool $tokenWasEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::disable();
    }

    protected function tearDown(): void
    {
        if ($this->tokenWasEnabled) {
            SecurityToken::enable();
        }
        parent::tearDown();
    }

    public function testSubscribeRejectsGet(): void
    {
        $controller = StockAlertController::create();
        $request = new HTTPRequest('GET', 'stock-alert/subscribe');
        $request->setSession(new Session([]));
        $controller->setRequest($request);

        try {
            $controller->subscribe($request);
            $this->fail('Expected a 405 for a GET request.');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(405, $e->getResponse()->getStatusCode());
        }
    }

    public function testHoneypotPretendsSuccessWithoutSubscribing(): void
    {
        $response = $this->postSubscribe([
            'ProductID' => 1,
            'email' => 'bot@example.com',
            'website' => 'i-am-a-bot', // honeypot tripped
        ]);
        $json = json_decode((string) $response->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertSame('pending', $json['state']);
        $this->assertSame(0, StockAlert::get()->count(), 'A tripped honeypot creates no alert.');
    }

    public function testConfirmAndUnsubscribeByToken(): void
    {
        $buyable = StockAlertTestBuyable::create(['Title' => 'Controller token']);
        $buyable->write();

        $alert = StockAlert::create();
        $alert->Email = 'tok@example.com';
        $alert->BuyableClass = StockAlertTestBuyable::class;
        $alert->BuyableID = $buyable->ID;
        $alert->write();

        $this->callWithToken('confirm', $alert->Token);
        $this->assertSame(StockAlert::STATUS_VERIFIED, StockAlert::get()->byID($alert->ID)->Status);

        $this->callWithToken('unsubscribe', $alert->Token);
        $this->assertSame(StockAlert::STATUS_UNSUBSCRIBED, StockAlert::get()->byID($alert->ID)->Status);
    }

    private function postSubscribe(array $postVars): HTTPResponse
    {
        $controller = StockAlertController::create();
        $request = new HTTPRequest('POST', 'stock-alert/subscribe', [], $postVars);
        $request->addHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setSession(new Session([]));
        $controller->setRequest($request);

        return $controller->subscribe($request);
    }

    private function callWithToken(string $action, string $token): void
    {
        $controller = StockAlertController::create();
        $request = new HTTPRequest('GET', 'stock-alert/' . $action . '/' . $token);
        $request->setRouteParams(['Token' => $token]);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $controller->$action($request);
    }
}

/**
 * A minimal Buyable stand-in (plain DataObject, not a SiteTree Product) for the controller token test. Co-located
 * per the reviews TestOnly pattern.
 *
 * @property string $Title
 */
class StockAlertTestBuyable extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'StockAlerts_CtrlTestBuyable';

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
