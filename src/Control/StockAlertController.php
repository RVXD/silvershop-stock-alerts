<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Control;

use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\StockAlerts\Model\StockAlert;
use SilverShop\StockAlerts\Service\StockAlertService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
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
     * Honeypot field name — a hidden input real users never fill. A non-empty value marks a bot submission.
     */
    private static string $honeypot_field = 'website';

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), 'stock-alert', $action);
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return $this->renderState('invalid');
    }

    /**
     * Subscribe to a back-in-stock alert for a product (optionally a specific variation).
     */
    public function subscribe(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->httpError(405, 'Method not allowed');
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.CsrfError',
                'Your session has expired. Please reload the page and try again.'
            ), null);
        }

        // Honeypot tripped → pretend success without doing anything.
        $honeypot = (string) static::config()->get('honeypot_field');
        if (trim((string) $request->postVar($honeypot)) !== '') {
            return $this->respond($request, true, 'pending', $this->pendingMessage(), null);
        }

        $email = trim((string) $request->postVar('email'));
        $buyable = $this->resolveBuyable($request);
        if (!$buyable) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.NoProduct',
                'Sorry, we could not find that product.'
            ), null);
        }

        $member = Security::getCurrentUser();
        $alert = StockAlertService::create()->subscribe($buyable, $email, [
            'Source' => (string) ($request->postVar('Source') ?: 'pdp'),
            'Member' => $member,
            'Type' => StockAlert::TYPE_BACK_IN_STOCK,
        ]);

        if (!$alert) {
            return $this->respond($request, false, 'invalid', _t(
                self::class . '.InvalidEmail',
                'Please enter a valid email address.'
            ), null);
        }

        $pending = $alert->Status === StockAlert::STATUS_PENDING;
        $message = $pending ? $this->pendingMessage() : $this->confirmedMessage();

        return $this->respond($request, true, $pending ? 'pending' : 'confirmed', $message, $alert);
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
        ?StockAlert $alert
    ): HTTPResponse {
        $email = $alert ? (string) $alert->Email : trim((string) $request->postVar('email'));

        if ($this->wantsJson($request)) {
            $response = HTTPResponse::create((string) json_encode([
                'ok' => $ok,
                'state' => $state,
                'title' => $ok ? $this->doneTitle() : '',
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
            ]);
        }

        return $this->redirectBackToProduct($request);
    }

    protected function redirectBackToProduct(HTTPRequest $request): HTTPResponse
    {
        $back = (string) ($request->getHeader('Referer') ?: '');
        if ($back === '') {
            $productId = (int) $request->postVar('ProductID');
            $product = $productId > 0 ? Product::get()->byID($productId) : null;
            $back = $product ? (string) $product->Link() : Director::baseURL();
        }
        // Anchor to the notify form so the success state is in view.
        if (!str_contains($back, '#')) {
            $back .= '#notify-form';
        }

        return $this->redirect($back);
    }

    protected function wantsJson(HTTPRequest $request): bool
    {
        return $request->isAjax()
            || str_contains((string) $request->getHeader('Accept'), 'application/json');
    }

    protected function pendingMessage(): string
    {
        return _t(
            self::class . '.CheckInbox',
            'Almost there! Check your inbox to confirm, and we\'ll email you the moment it\'s back.'
        );
    }

    protected function confirmedMessage(): string
    {
        return _t(self::class . '.Confirmed', 'You\'re on the list — we\'ll email you the moment it\'s back in stock.');
    }

    protected function doneTitle(): string
    {
        return _t(self::class . '.DoneTitle', 'We\'ll let you know');
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
            'Alert' => $alert,
            'ProductTitle' => $alert ? $alert->getBuyableTitle() : '',
            'ProductLink' => $alert ? $alert->BuyableLink() : '',
        ])->renderWith('SilverShop\\StockAlerts\\StockAlertPage');

        return HTTPResponse::create((string) $html);
    }
}
