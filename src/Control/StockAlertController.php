<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Control;

use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverShop\StockAlerts\Spam\HoneypotSpamProtector;
use SilverShop\StockAlerts\Spam\StockAlertSpamProtector;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Cache\RateLimiter;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Public endpoint for stock alerts: the buy-box subscribe POST, and the tokenised one-click double-opt-in confirm
 * and unsubscribe landings. Routed at `stock-alert` (see _config/routes.yml), mirroring silvershop/newsletter's
 * NewsletterController and silvershop/reviews' ReviewInvitationController.
 *
 * subscribe() answers JSON to the theme's notify.js (progressive enhancement) and, for a no-JS POST, stashes a
 * one-shot success flash and redirects back to the product (the buy box then renders the "check your email" state).
 */
class StockAlertController extends Controller
{
    private static string $url_segment = 'stock-alert';

    private static array $allowed_actions = [
        'subscribe',
        'confirm',
        'unsubscribe',
        'index',
    ];

    private static array $url_handlers = [
        'confirm/$Token!' => 'confirm',
        'unsubscribe/$Token!' => 'unsubscribe',
        'subscribe' => 'subscribe',
        '' => 'index',
    ];

    /**
     * Abuse prevention on the public subscribe endpoint: at most this many subscribes per rolling window, counted
     * per IP AND per email (cache-backed). 0 for either disables the throttle. Set via config.
     */
    private static int $max_subscribes_per_window = 5;

    private static int $subscribe_window_mins = 60;

    /**
     * The spam protector for the subscribe forms — a class implementing {@link StockAlertSpamProtector}, resolved
     * via Injector. Defaults to the built-in honeypot; a shop swaps in its own without touching the site-wide
     * SpamProtector.
     */
    private static string $spam_protector = HoneypotSpamProtector::class;

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), 'stock-alert', $action);
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return $this->renderState('invalid');
    }

    /**
     * Subscribe to a stock alert for a product (optionally a specific variation) — back-in-stock, or a price-drop
     * watch when Type=PriceDrop. Hardened: CSRF, a pluggable spam protector (honeypot by default), and a per-IP +
     * per-email rate limit bounding how many confirmation emails the endpoint can be scripted to send.
     */
    public function subscribe(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->httpError(405, 'Method not allowed');
        }

        $type = $this->resolveType($request);

        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.CsrfError',
                'Your session has expired. Please reload the page and try again.'
            ), null, $type);
        }

        // Spam protection (built-in honeypot by default, pluggable) → pretend success without doing anything.
        if ($this->spamProtector()->isSpam($request)) {
            return $this->respond($request, true, 'pending', $this->pendingMessage($type), null, $type);
        }

        $member = Security::getCurrentUser();
        $email = trim((string) $request->postVar('email'));
        // A logged-in member's watch/notify form posts no email field — it watches against their account
        // address. Fall back to it so a member submit never fails validation with a "no field to fill" error.
        if ($email === '' && $member && $member->exists()) {
            $email = trim((string) $member->Email);
        }
        $buyable = $this->resolveBuyable($request);
        if (!$buyable) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.NoProduct',
                'Sorry, we could not find that product.'
            ), null, $type);
        }

        // Rate limit (per IP AND per email) before we create a row or send a confirmation email.
        if ($this->isRateLimited($request, $email)) {
            return $this->respond($request, false, 'throttled', $this->throttleMessage(), null, $type);
        }

        $alert = StockAlertService::create()->subscribe($buyable, $email, [
            'Source' => (string) ($request->postVar('Source') ?: 'pdp'),
            'Member' => $member,
            'Type' => $type,
        ]);

        if (!$alert) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.InvalidEmail',
                'Please enter a valid email address.'
            ), null, $type);
        }

        $pending = $alert->Status === StockAlert::STATUS_PENDING;
        $message = $pending ? $this->pendingMessage($type) : $this->confirmedMessage($type);

        return $this->respond($request, true, $pending ? 'pending' : 'confirmed', $message, $alert, $type);
    }

    public function confirm(HTTPRequest $request): HTTPResponse
    {
        $alert = StockAlertService::create()->confirmByToken((string) $request->param('Token'));

        return $this->renderState($alert ? 'confirmed' : 'invalid', $alert);
    }

    public function unsubscribe(HTTPRequest $request): HTTPResponse
    {
        $alert = StockAlertService::create()->unsubscribeByToken((string) $request->param('Token'));

        return $this->renderState($alert ? 'unsubscribed' : 'invalid', $alert);
    }

    /**
     * The alert type requested in the POST, constrained to the known types (defaults to back-in-stock). The PDP
     * "watch price" control posts Type=PriceDrop; the back-in-stock notify form posts no Type (or BackInStock).
     */
    protected function resolveType(HTTPRequest $request): string
    {
        $type = (string) $request->postVar('Type');

        return $type === StockAlert::TYPE_PRICE_DROP
            ? StockAlert::TYPE_PRICE_DROP
            : StockAlert::TYPE_BACK_IN_STOCK;
    }

    /**
     * Resolve the subject buyable from the POST: a Variation when a valid VariationID is given, else the Product.
     *
     * @return DataObject|null
     */
    protected function resolveBuyable(HTTPRequest $request)
    {
        $variationId = (int) $request->postVar('VariationID');
        if ($variationId > 0 && class_exists(Variation::class)) {
            $variation = Variation::get()->byID($variationId);
            if ($variation && $variation->exists()) {
                return $variation;
            }
        }

        $productId = (int) $request->postVar('ProductID');
        if ($productId > 0) {
            $product = Product::get()->byID($productId);
            if ($product && $product->exists()) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Answer a subscribe request: JSON for the enhanced (JS) path, a redirect-back with a one-shot flash for no-JS.
     */
    protected function respond(
        HTTPRequest $request,
        bool $ok,
        string $state,
        string $message,
        ?StockAlert $alert,
        string $type = StockAlert::TYPE_BACK_IN_STOCK
    ): HTTPResponse {
        $email = $alert ? (string) $alert->Email : trim((string) $request->postVar('email'));

        if ($this->wantsJson($request)) {
            $response = HTTPResponse::create((string) json_encode([
                'ok' => $ok,
                'state' => $state,
                'type' => $type,
                'title' => $ok ? $this->doneTitle($type) : '',
                'message' => $message,
                'email' => $email,
            ]));
            $response->addHeader('Content-Type', 'application/json');

            return $response;
        }

        // No-JS: stash a one-shot success flash keyed to the product and redirect back to the PDP.
        if ($ok) {
            $productId = (int) $request->postVar('ProductID');
            if ($alert) {
                $product = $alert->getProduct();
                if ($product) {
                    $productId = (int) $product->ID;
                }
            }
            $request->getSession()->set('StockAlertFlash', [
                'ProductID' => $productId,
                'Email' => $email,
                'Pending' => $state === 'pending',
                'Type' => $type,
            ]);
        }

        return $this->redirectBackToProduct($request, $type);
    }

    protected function redirectBackToProduct(
        HTTPRequest $request,
        string $type = StockAlert::TYPE_BACK_IN_STOCK
    ): HTTPResponse {
        $back = (string) ($request->getHeader('Referer') ?: '');
        if ($back === '') {
            $productId = (int) $request->postVar('ProductID');
            $product = $productId > 0 ? Product::get()->byID($productId) : null;
            $back = $product ? (string) $product->Link() : Director::baseURL();
        }
        // Anchor to the matching form so the success state is in view (price-drop watch vs back-in-stock notify).
        if (!str_contains($back, '#')) {
            $back .= $type === StockAlert::TYPE_PRICE_DROP ? '#watch-price-form' : '#notify-form';
        }

        return $this->redirect($back);
    }

    protected function wantsJson(HTTPRequest $request): bool
    {
        return $request->isAjax()
            || str_contains((string) $request->getHeader('Accept'), 'application/json');
    }

    protected function pendingMessage(string $type = StockAlert::TYPE_BACK_IN_STOCK): string
    {
        if ($type === StockAlert::TYPE_PRICE_DROP) {
            return _t(
                self::class . '.CheckInboxPriceDrop',
                'Almost there! Check your inbox to confirm, and we\'ll email you if the price drops.'
            );
        }

        return _t(
            self::class . '.CheckInbox',
            'Almost there! Check your inbox to confirm, and we\'ll email you the moment it\'s back.'
        );
    }

    protected function confirmedMessage(string $type = StockAlert::TYPE_BACK_IN_STOCK): string
    {
        if ($type === StockAlert::TYPE_PRICE_DROP) {
            return _t(
                self::class . '.ConfirmedPriceDrop',
                'You\'re watching this price — we\'ll email you if it drops.'
            );
        }

        return _t(self::class . '.Confirmed', 'You\'re on the list — we\'ll email you the moment it\'s back in stock.');
    }

    protected function doneTitle(string $type = StockAlert::TYPE_BACK_IN_STOCK): string
    {
        if ($type === StockAlert::TYPE_PRICE_DROP) {
            return _t(self::class . '.DoneTitlePriceDrop', 'You\'re watching this price');
        }

        return _t(self::class . '.DoneTitle', 'We\'ll let you know');
    }

    protected function throttleMessage(): string
    {
        return _t(
            self::class . '.Throttled',
            'You\'ve made a lot of requests — please wait a little while before trying again.'
        );
    }

    /**
     * The configured spam protector (built-in honeypot by default), resolved via Injector so a shop can swap in its
     * own {@link StockAlertSpamProtector} without touching the site-wide SpamProtector.
     */
    protected function spamProtector(): StockAlertSpamProtector
    {
        $class = (string) static::config()->get('spam_protector');
        if ($class !== '' && class_exists($class)) {
            $protector = Injector::inst()->get($class);
            if ($protector instanceof StockAlertSpamProtector) {
                return $protector;
            }
        }

        return HoneypotSpamProtector::create();
    }

    /**
     * Whether this subscribe attempt is over the throttle — counted per IP AND per email (cache-backed), so a
     * script cannot be pointed at one victim's inbox or spray many addresses. A hit is recorded on each limiter
     * only while still under the cap.
     */
    protected function isRateLimited(HTTPRequest $request, string $email): bool
    {
        $max = (int) static::config()->get('max_subscribes_per_window');
        $window = (int) static::config()->get('subscribe_window_mins');
        if ($max <= 0 || $window <= 0) {
            return false;
        }

        $limiters = [];
        $ip = (string) $request->getIP();
        if ($ip !== '') {
            $limiters[] = RateLimiter::create('stockalerts-subscribe-ip-' . md5($ip), $max, $window);
        }
        $email = trim(mb_strtolower($email));
        if ($email !== '') {
            $limiters[] = RateLimiter::create('stockalerts-subscribe-email-' . md5($email), $max, $window);
        }

        foreach ($limiters as $limiter) {
            if (!$limiter->canAccess()) {
                return true;
            }
        }
        foreach ($limiters as $limiter) {
            $limiter->hit();
        }

        return false;
    }

    protected function renderState(string $state, ?StockAlert $alert = null): HTTPResponse
    {
        $html = $this->customise([
            'Title' => _t(self::class . '.Title', 'Stock alerts'),
            'ShopName' => StockAlertService::create()->senderName(),
            'State' => $state,
            'IsConfirmed' => $state === 'confirmed',
            'IsUnsubscribed' => $state === 'unsubscribed',
            'IsInvalid' => $state === 'invalid',
            'IsPriceDrop' => $alert instanceof StockAlert && $alert->isPriceDrop(),
            'Alert' => $alert,
            'ProductTitle' => $alert ? $alert->getBuyableTitle() : '',
            'ProductLink' => $alert ? $alert->BuyableLink() : '',
        ])->renderWith('SilverShop\\StockAlerts\\StockAlertPage');

        return HTTPResponse::create((string) $html);
    }
}
