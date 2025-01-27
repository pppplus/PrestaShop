<?php
/**
 * 2007-2019 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;

class CartControllerCore extends FrontController
{
    public $php_self = 'cart';

    protected $id_product;
    protected $id_product_attribute;
    protected $id_address_delivery;
    protected $customization_id;
    protected $qty;
    /**
     * To specify if you are in the preview mode or not.
     *
     * @var bool
     */
    protected $preview;
    public $ssl = true;
    /**
     * An array of errors, in case the update action of product is wrong.
     *
     * @var string[]
     */
    protected $updateOperationError = array();

    /**
     * This is not a public page, so the canonical redirection is disabled.
     *
     * @param string $canonicalURL
     */
    public function canonicalRedirection($canonicalURL = '')
    {
    }

    /**
     * Initialize cart controller.
     *
     * @see FrontController::init()
     */
    public function init()
    {
        parent::init();

        // Send noindex to avoid ghost carts by bots
        header('X-Robots-Tag: noindex, nofollow', true);

        // Get page main parameters
        $this->id_product = (int) Tools::getValue('id_product', null);
        $this->id_product_attribute = (int) Tools::getValue('id_product_attribute', Tools::getValue('ipa'));
        $this->customization_id = (int) Tools::getValue('id_customization');
        $this->qty = abs(Tools::getValue('qty', 1));
        $this->id_address_delivery = (int) Tools::getValue('id_address_delivery');
        $this->preview = ('1' === Tools::getValue('preview'));

        /* Check if the products in the cart are available */
        if ('show' === Tools::getValue('action')) {
            $isAvailable = $this->areProductsAvailable();
            if (Tools::getIsset('checkout')) {
                return Tools::redirect($this->context->link->getPageLink('order'));
            }
            if (true !== $isAvailable) {
                $this->errors[] = $isAvailable;
            }
        }
    }

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        if (Configuration::isCatalogMode() && Tools::getValue('action') === 'show') {
            Tools::redirect('index.php');
        }

        $presenter = new CartPresenter();
        $presented_cart = $presenter->present($this->context->cart, $shouldSeparateGifts = true);

        $this->context->smarty->assign([
            'cart' => $presented_cart,
            'static_token' => Tools::getToken(false),
        ]);

        if (count($presented_cart['products']) > 0) {
            $this->setTemplate('checkout/cart');
        } else {
            $this->context->smarty->assign([
                'allProductsLink' => $this->context->link->getCategoryLink(Configuration::get('PS_HOME_CATEGORY')),
            ]);
            $this->setTemplate('checkout/cart-empty');
        }
        parent::initContent();
    }

    public function displayAjaxUpdate()
    {
        if (Configuration::isCatalogMode()) {
            return;
        }

        $productsInCart = $this->context->cart->getProducts();
        $updatedProducts = array_filter($productsInCart, array($this, 'productInCartMatchesCriteria'));
        $updatedProduct = reset($updatedProducts);
        $productQuantity = $updatedProduct['quantity'];

        if (!$this->errors) {
            $cartPresenter = new CartPresenter();
            $presentedCart = $cartPresenter->present($this->context->cart);

            // filter product output
            $presentedCart['products'] = $this->get('prestashop.core.filter.front_end_object.product_collection')
                ->filter($presentedCart['products']);

            $this->ajaxRender(Tools::jsonEncode([
                'success' => true,
                'id_product' => $this->id_product,
                'id_product_attribute' => $this->id_product_attribute,
                'id_customization' => $this->customization_id,
                'quantity' => $productQuantity,
                'cart' => $presentedCart,
                'errors' => empty($this->updateOperationError) ? '' : reset($this->updateOperationError),
            ]));

            return;
        } else {
            $this->ajaxRender(Tools::jsonEncode([
                'hasError' => true,
                'errors' => $this->errors,
                'quantity' => $productQuantity,
            ]));

            return;
        }
    }

    public function displayAjaxRefresh()
    {
        if (Configuration::isCatalogMode()) {
            return;
        }

        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxRender(Tools::jsonEncode([
            'cart_detailed' => $this->render('checkout/_partials/cart-detailed'),
            'cart_detailed_totals' => $this->render('checkout/_partials/cart-detailed-totals'),
            'cart_summary_items_subtotal' => $this->render('checkout/_partials/cart-summary-items-subtotal'),
            'cart_summary_totals' => $this->render('checkout/_partials/cart-summary-totals'),
            'cart_detailed_actions' => $this->render('checkout/_partials/cart-detailed-actions'),
            'cart_voucher' => $this->render('checkout/_partials/cart-voucher'),
        ]));
    }

    /**
     * @deprecated 1.7.3.1 the product link is now accessible
     *                     in #quantity_wanted[data-url-update]
     */
    public function displayAjaxProductRefresh()
    {
        if ($this->id_product) {
            $idProductAttribute = 0;
            $groups = Tools::getValue('group');

            if (!empty($groups)) {
                $idProductAttribute = (int) Product::getIdProductAttributeByIdAttributes(
                    $this->id_product,
                    $groups,
                    true
                );
            }
            $url = $this->context->link->getProductLink(
                $this->id_product,
                null,
                null,
                null,
                $this->context->language->id,
                null,
                $idProductAttribute,
                false,
                false,
                true,
                [
                    'quantity_wanted' => (int) $this->qty,
                    'preview' => $this->preview,
                ]
            );
        } else {
            $url = false;
        }
        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxRender(Tools::jsonEncode([
            'success' => true,
            'productUrl' => $url,
        ]));
    }

    public function postProcess()
    {
        $this->updateCart();
    }

    protected function updateCart()
    {
        // Update the cart ONLY if $this->cookies are available, in order to avoid ghost carts created by bots
        if ($this->context->cookie->exists()
            && !$this->errors
            && !($this->context->customer->isLogged() && !$this->isTokenValid())
        ) {
            if (Tools::getIsset('add') || Tools::getIsset('update')) {
                $this->processChangeProductInCart();
            } elseif (Tools::getIsset('delete')) {
                $this->processDeleteProductInCart();
            } elseif (CartRule::isFeatureActive()) {
                if (Tools::getIsset('addDiscount')) {
                    if (!($code = trim(Tools::getValue('discount_name')))) {
                        $this->errors[] = $this->trans(
                            'You must enter a voucher code.',
                            array(),
                            'Shop.Notifications.Error'
                        );
                    } elseif (!Validate::isCleanHtml($code)) {
                        $this->errors[] = $this->trans(
                            'The voucher code is invalid.',
                            array(),
                            'Shop.Notifications.Error'
                        );
                    } else {
                        if (($cartRule = new CartRule(CartRule::getIdByCode($code)))
                            && Validate::isLoadedObject($cartRule)
                        ) {
                            if ($error = $cartRule->checkValidity($this->context, false, true)) {
                                $this->errors[] = $error;
                            } else {
                                $this->context->cart->addCartRule($cartRule->id);
                            }
                        } else {
                            $this->errors[] = $this->trans(
                                'This voucher does not exist.',
                                array(),
                                'Shop.Notifications.Error'
                            );
                        }
                    }
                } elseif (($id_cart_rule = (int) Tools::getValue('deleteDiscount'))
                    && Validate::isUnsignedId($id_cart_rule)
                ) {
                    $this->context->cart->removeCartRule($id_cart_rule);
                    CartRule::autoAddToCart($this->context);
                }
            }
        } elseif (!$this->isTokenValid() && Tools::getValue('action') !== 'show' && !Tools::getValue('ajax')) {
            Tools::redirect('index.php');
        }
    }

    /**
     * This process delete a product from the cart.
     */
    protected function processDeleteProductInCart()
    {
        $customization_product = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'customization`'
            . ' WHERE `id_cart` = ' . (int) $this->context->cart->id
            . ' AND `id_product` = ' . (int) $this->id_product
            . ' AND `id_customization` != ' . (int) $this->customization_id
        );

        if (count($customization_product)) {
            $product = new Product((int) $this->id_product);
            if ($this->id_product_attribute > 0) {
                $minimal_quantity = (int) Attribute::getAttributeMinimalQty($this->id_product_attribute);
            } else {
                $minimal_quantity = (int) $product->minimal_quantity;
            }

            $total_quantity = 0;
            foreach ($customization_product as $custom) {
                $total_quantity += $custom['quantity'];
            }

            if ($total_quantity < $minimal_quantity) {
                $this->errors[] = $this->trans(
                    'You must add %quantity% minimum quantity',
                    array('%quantity%' => $minimal_quantity),
                    'Shop.Notifications.Error'
                );

                return false;
            }
        }

        $data = array(
            'id_cart' => (int) $this->context->cart->id,
            'id_product' => (int) $this->id_product,
            'id_product_attribute' => (int) $this->id_product_attribute,
            'customization_id' => (int) $this->customization_id,
            'id_address_delivery' => (int) $this->id_address_delivery,
        );

        Hook::exec('actionObjectProductInCartDeleteBefore', $data, null, true);

        if ($this->context->cart->deleteProduct(
            $this->id_product,
            $this->id_product_attribute,
            $this->customization_id,
            $this->id_address_delivery
        )) {
            Hook::exec('actionObjectProductInCartDeleteAfter', $data);

            if (!Cart::getNbProducts((int) $this->context->cart->id)) {
                $this->context->cart->setDeliveryOption(null);
                $this->context->cart->gift = 0;
                $this->context->cart->gift_message = '';
                $this->context->cart->update();
            }

            $isAvailable = $this->areProductsAvailable();
            if (true !== $isAvailable) {
                $this->updateOperationError[] = $isAvailable;
            }
        }

        CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();
    }

    /**
     * This process add or update a product in the cart.
     */
    protected function processChangeProductInCart()
    {
        $mode = (Tools::getIsset('update') && $this->id_product) ? 'update' : 'add';
        $ErrorKey = ('update' === $mode) ? 'updateOperationError' : 'errors';

        if (Tools::getIsset('group')) {
            $this->id_product_attribute = (int) Product::getIdProductAttributeByIdAttributes(
                $this->id_product,
                Tools::getValue('group')
            );
        }

        if ($this->qty == 0) {
            $this->{$ErrorKey}[] = $this->trans(
                'Null quantity.',
                array(),
                'Shop.Notifications.Error'
            );
        } elseif (!$this->id_product) {
            $this->{$ErrorKey}[] = $this->trans(
                'Product not found',
                array(),
                'Shop.Notifications.Error'
            );
        }

        $product = new Product($this->id_product, true, $this->context->language->id);
        if (!$product->id || !$product->active || !$product->checkAccess($this->context->cart->id_customer)) {
            $this->{$ErrorKey}[] = $this->trans(
                'This product (%product%) is no longer available.',
                array('%product%' => $product->name),
                'Shop.Notifications.Error'
            );

            return;
        }

        if (!$this->id_product_attribute && $product->hasAttributes()) {
            $minimum_quantity = ($product->out_of_stock == 2)
                ? !Configuration::get('PS_ORDER_OUT_OF_STOCK')
                : !$product->out_of_stock;
            $this->id_product_attribute = Product::getDefaultAttribute($product->id, $minimum_quantity);
            // @todo do something better than a redirect admin !!
            if (!$this->id_product_attribute) {
                Tools::redirectAdmin($this->context->link->getProductLink($product));
            }
        }

        $qty_to_check = $this->qty;
        $cart_products = $this->context->cart->getProducts();

        if (is_array($cart_products)) {
            foreach ($cart_products as $cart_product) {
                if ($this->productInCartMatchesCriteria($cart_product)) {
                    $qty_to_check = $cart_product['cart_quantity'];

                    if (Tools::getValue('op', 'up') == 'down') {
                        $qty_to_check -= $this->qty;
                    } else {
                        $qty_to_check += $this->qty;
                    }

                    break;
                }
            }
        }

        // Check product quantity availability
        if ('update' !== $mode && $this->shouldAvailabilityErrorBeRaised($product, $qty_to_check)) {
            $this->{$ErrorKey}[] = $this->trans(
                'The product is no longer available in this quantity.',
                array('%product%' => $product->name),
                'Shop.Notifications.Error'
            );
        }

        // Check minimal_quantity
        if (!$this->id_product_attribute) {
            if ($qty_to_check < $product->minimal_quantity) {
                $this->errors[] = $this->trans(
                    'The minimum purchase order quantity for the product %product% is %quantity%.',
                    array('%product%' => $product->name, '%quantity%' => $product->minimal_quantity),
                    'Shop.Notifications.Error'
                );

                return;
            }
        } else {
            $combination = new Combination($this->id_product_attribute);
            if ($qty_to_check < $combination->minimal_quantity) {
                $this->errors[] = $this->trans(
                    'The minimum purchase order quantity for the product %product% is %quantity%.',
                    array('%product%' => $product->name, '%quantity%' => $combination->minimal_quantity),
                    'Shop.Notifications.Error'
                );

                return;
            }
        }

        // If no errors, process product addition
        if (!$this->errors) {
            // Add cart if no cart found
            if (!$this->context->cart->id) {
                if (Context::getContext()->cookie->id_guest) {
                    $guest = new Guest(Context::getContext()->cookie->id_guest);
                    $this->context->cart->mobile_theme = $guest->mobile_theme;
                }
                $this->context->cart->add();
                if ($this->context->cart->id) {
                    $this->context->cookie->id_cart = (int) $this->context->cart->id;
                }
            }

            // Check customizable fields

            if (!$product->hasAllRequiredCustomizableFields() && !$this->customization_id) {
                $this->{$ErrorKey}[] = $this->trans(
                    'Please fill in all of the required fields, and then save your customizations.',
                    array(),
                    'Shop.Notifications.Error'
                );
            }

            if (!$this->errors) {
                $cart_rules = $this->context->cart->getCartRules();
                $available_cart_rules = CartRule::getCustomerCartRules(
                    $this->context->language->id,
                    (isset($this->context->customer->id) ? $this->context->customer->id : 0),
                    true,
                    true,
                    true,
                    $this->context->cart,
                    false,
                    true
                );
                $update_quantity = $this->context->cart->updateQty(
                    $this->qty,
                    $this->id_product,
                    $this->id_product_attribute,
                    $this->customization_id,
                    Tools::getValue('op', 'up'),
                    $this->id_address_delivery,
                    null,
                    true,
                    true
                );
                if ($update_quantity < 0) {
                    // If product has attribute, minimal quantity is set with minimal quantity of attribute
                    $minimal_quantity = ($this->id_product_attribute)
                        ? Attribute::getAttributeMinimalQty($this->id_product_attribute)
                        : $product->minimal_quantity;
                    $this->{$ErrorKey}[] = $this->trans(
                        'You must add %quantity% minimum quantity',
                        array('%quantity%' => $minimal_quantity),
                        'Shop.Notifications.Error'
                    );
                } elseif (!$update_quantity) {
                    $this->errors[] = $this->trans(
                        'You already have the maximum quantity available for this product.',
                        array(),
                        'Shop.Notifications.Error'
                    );
                } elseif ($this->shouldAvailabilityErrorBeRaised($product, $qty_to_check)) {
                    // check quantity after cart quantity update
                    $this->{$ErrorKey}[] = $this->trans(
                        'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                        array('%product%' => $product->name),
                        'Shop.Notifications.Error'
                    );
                }
            }
        }

        $removed = CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();
    }

    /**
     * @param $productInCart
     *
     * @return bool
     */
    public function productInCartMatchesCriteria($productInCart)
    {
        return (
            !isset($this->id_product_attribute) ||
            (
                $productInCart['id_product_attribute'] == $this->id_product_attribute &&
                $productInCart['id_customization'] == $this->customization_id
            )
        ) && isset($this->id_product) && $productInCart['id_product'] == $this->id_product;
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $presenter = new CartPresenter();
        $presented_cart = $presenter->present($this->context->cart);

        if (count($presented_cart['products']) == 0) {
            $page['body_classes']['cart-empty'] = true;
        }

        return $page;
    }

    /**
     * Check product quantity availability.
     *
     * @param Product $product
     * @param int $qtyToCheck
     *
     * @return bool
     */
    protected function shouldAvailabilityErrorBeRaised($product, $qtyToCheck)
    {
        if (($this->id_product_attribute)) {
            return !Product::isAvailableWhenOutOfStock($product->out_of_stock)
                && !Attribute::checkAttributeQty($this->id_product_attribute, $qtyToCheck);
        } elseif (Product::isAvailableWhenOutOfStock($product->out_of_stock)) {
            return false;
        }

        // product quantity is the available quantity after decreasing products in cart
        $productQuantity = Product::getQuantity(
            $this->id_product,
            $this->id_product_attribute,
            null,
            $this->context->cart,
            $this->customization_id
        );

        return $productQuantity < 0;
    }

    /**
     * Check if the products in the cart are available.
     *
     * @return bool|string
     */
    protected function areProductsAvailable()
    {
        $product = $this->context->cart->checkQuantities(true);

        if (true === $product || !is_array($product)) {
            return true;
        }
        if ($product['active']) {
            return $this->trans(
                'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                array('%product%' => $product['name']),
                'Shop.Notifications.Error'
            );
        }

        return $this->trans(
            'This product (%product%) is no longer available.',
            array('%product%' => $product['name']),
            'Shop.Notifications.Error'
        );
    }
}
