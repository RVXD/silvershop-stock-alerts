<?php

declare(strict_types=1);

namespace SilverShop\StockAlerts\Extension;

use SilverShop\Page\Product;
use SilverShop\StockAlerts\Control\StockAlertController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Exposes the back-in-stock subscribe endpoint + form helpers on the product page, for the storefront buy-box /
 * sticky-bar notify form (the design-fixed `.silvershop-stock-alert #notify-form`). Mirrors
 * silvershop/wishlist's WishlistProductControllerExtension.
 *
 * @extends Extension<\SilverShop\Page\ProductController>
 */
class StockAlertProductControllerExtension extends Extension
{
    /**
     * Memoised no-JS success flash for this request (so the template can read it several times).
     *
     * @var ArrayData|false|null
     */
    protected $stockAlertFlash = false;

    /**
     * Root-relative subscribe endpoint (e.g. "/stock-alert/subscribe"), safe as a form action on the product page
     * where a relative link would resolve against the product URL.
     */
    public function StockAlertSubscribeLink(): string
    {
        return Controller::join_links('/', StockAlertController::singleton()->Link('subscribe'));
    }

    /**
     * Hidden CSRF field for the no-JS subscribe POST.
     */
    public function StockAlertSecurityField(): DBHTMLText
    {
        $token = SecurityToken::inst();
        $field = DBHTMLText::create();
        $field->setValue(sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            Convert::raw2att($token->getName()),
            Convert::raw2att($token->getValue())
        ));

        return $field;
    }

    /**
     * The current product's ID, for the form's hidden ProductID field.
     */
    public function StockAlertProductID(): int
    {
        $product = $this->getOwner()->data();

        return $product instanceof Product ? (int) $product->ID : 0;
    }

    /**
     * Prefill the email input for a logged-in member.
     */
    public function StockAlertEmailPrefill(): string
    {
        $member = Security::getCurrentUser();

        return ($member && $member->exists()) ? (string) $member->Email : '';
    }

    /**
     * The no-JS success state for this product, or null. Set by {@link StockAlertController::subscribe()} after a
     * plain POST (the JS path reveals the done panel client-side instead). Reading it clears it, so it shows once.
     */
    public function StockAlertFlash(): ?ArrayData
    {
        if ($this->stockAlertFlash !== false) {
            return $this->stockAlertFlash ?: null;
        }
        $this->stockAlertFlash = null;

        $product = $this->getOwner()->data();
        if (!$product instanceof Product) {
            return null;
        }

        try {
            $session = $this->getOwner()->getRequest()->getSession();
            $flash = $session->get('StockAlertFlash');
            if (is_array($flash) && (int) ($flash['ProductID'] ?? 0) === (int) $product->ID) {
                $session->clear('StockAlertFlash');
                $this->stockAlertFlash = ArrayData::create([
                    'Done' => true,
                    'Email' => (string) ($flash['Email'] ?? ''),
                    'Pending' => (bool) ($flash['Pending'] ?? false),
                ]);
            }
        } catch (\Throwable) {
            return null;
        }

        return $this->stockAlertFlash ?: null;
    }
}
