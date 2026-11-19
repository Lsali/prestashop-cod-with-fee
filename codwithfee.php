<?php
/**
 * Cash on Delivery with Fee
 *
 * Payment method "Cash on Delivery" with a configurable fee. The fee is added to the cart as a hidden
 * virtual product "COD Fee" when the customer confirms the order, so it appears as a separate line
 * on the order, invoice and emails.
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CodWithFee extends PaymentModule
{
    const CONF_FEE = 'CODWF_FEE';
    const CONF_TAX_GROUP = 'CODWF_TAX_RULES_GROUP';
    const CONF_FREE_ABOVE = 'CODWF_FREE_ABOVE';
    const CONF_MIN_TOTAL = 'CODWF_MIN_TOTAL';
    const CONF_MAX_TOTAL = 'CODWF_MAX_TOTAL';
    const CONF_ORDER_STATE = 'CODWF_ORDER_STATE';
    const CONF_TITLE = 'CODWF_TITLE';
    const CONF_DESCRIPTION = 'CODWF_DESCRIPTION';
    const CONF_PRODUCT = 'CODWF_FEE_PRODUCT';
    /** Comma-separated carrier IDs for which COD is disabled */
    const CONF_DISABLED_CARRIERS = 'CODWF_DISABLED_CARRIERS';

    const FEE_REFERENCE = 'COD-FEE';

    public function __construct()
    {
        $this->name = 'codwithfee';
        $this->tab = 'payments_gateways';
        $this->version = '1.3.0';
        $this->author = 'levent sali';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = ['validation', 'ajax'];
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = 'Cash on Delivery with Fee';
        $this->description = 'Cash on Delivery with configurable fee, order total limits and order status.';
    }

    /** @return string[] Configuration keys shown in the settings form */
    private function getFormKeys()
    {
        return [
            self::CONF_FEE,
            self::CONF_TAX_GROUP,
            self::CONF_FREE_ABOVE,
            self::CONF_MIN_TOTAL,
            self::CONF_MAX_TOTAL,
            self::CONF_ORDER_STATE,
            self::CONF_TITLE,
            self::CONF_DESCRIPTION,
            self::CONF_DISABLED_CARRIERS,
        ];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionValidateOrder')
            && $this->installDefaults()
            && $this->syncFeeProduct();
    }

    public function uninstall()
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if ($idProduct) {
            $product = new Product($idProduct);
            if (Validate::isLoadedObject($product)) {
                $product->delete();
            }
        }

        foreach ($this->getFormKeys() as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName(self::CONF_PRODUCT);

        return parent::uninstall();
    }

    private function installDefaults()
    {
        $defaults = [
            self::CONF_FEE => '3.00',
            self::CONF_TAX_GROUP => 0,
            self::CONF_FREE_ABOVE => 0,
            self::CONF_MIN_TOTAL => 0,
            self::CONF_MAX_TOTAL => 0,
            self::CONF_ORDER_STATE => (int) Configuration::get('PS_OS_PREPARATION'),
            self::CONF_TITLE => 'Cash on Delivery',
            self::CONF_DESCRIPTION => 'Pay in cash upon delivery of your order.',
            self::CONF_DISABLED_CARRIERS => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------
     *  Availability and fee calculation
     * ------------------------------------------------------------------ */

    /**
     * COD is available if the cart is not virtual and the total
     * (excluding the COD fee) is within the configured limits.
     * Countries, currencies, customer groups and carriers can also be restricted
     * from Payment → Preferences.
     */
    public function isAvailableForCart(Cart $cart)
    {
        if ($cart->isVirtualCart()) {
            return false;
        }

        // Disable for specific shipping methods (carriers)
        $idCarrier = (int) $cart->id_carrier;
        if ($idCarrier > 0) {
            $disabledRaw = (string) Configuration::get(self::CONF_DISABLED_CARRIERS);
            if ($disabledRaw !== '') {
                $disabled = array_filter(array_map('intval', explode(',', $disabledRaw)));
                if (in_array($idCarrier, $disabled, true)) {
                    return false;
                }
            }
        }

        // Total without the COD fee (if already present in the cart)
        $total = $this->getCartTotalWithoutFee($cart, true, Cart::BOTH);
        $min = (float) Configuration::get(self::CONF_MIN_TOTAL);
        $max = (float) Configuration::get(self::CONF_MAX_TOTAL);

        if ($min > 0 && $total < $min) {
            return false;
        }
        if ($max > 0 && $total > $max) {
            return false;
        }

        return true;
    }

    /**
     * The fee (tax included) the customer will pay for this cart. 0 if no fee applies.
     */
    public function getFeeForCart(Cart $cart)
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct) {
            return 0.0;
        }

        $fee = (float) Product::getPriceStatic($idProduct, true);
        if ($fee <= 0) {
            return 0.0;
        }

        $freeAbove = (float) Configuration::get(self::CONF_FREE_ABOVE);
        if ($freeAbove > 0) {
            $productsTotal = $this->getCartTotalWithoutFee($cart, true, Cart::BOTH_WITHOUT_SHIPPING);
            if ($productsTotal >= $freeAbove) {
                return 0.0;
            }
        }

        return $fee;
    }

    /**
     * Cart total without the COD fee product.
     */
    public function getCartTotalWithoutFee(Cart $cart, $withTaxes = true, $type = Cart::BOTH)
    {
        $total = (float) $cart->getOrderTotal($withTaxes, $type);
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct || !$this->cartHasFeeProduct($cart)) {
            return $total;
        }

        $fee = (float) Product::getPriceStatic($idProduct, $withTaxes);
        // For BOTH / BOTH_WITHOUT_SHIPPING we only subtract the fee product value
        if ($type === Cart::BOTH || $type === Cart::BOTH_WITHOUT_SHIPPING || $type === Cart::ONLY_PRODUCTS) {
            $total = max(0, $total - $fee);
        }

        return $total;
    }

    public function cartHasFeeProduct(Cart $cart)
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct || !$cart->id) {
            return false;
        }
        $products = $cart->getProducts(true);
        foreach ($products as $product) {
            if ((int) $product['id_product'] === $idProduct) {
                return true;
            }
        }

        return false;
    }

    public function addFeeToCart(Cart $cart)
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct || !$cart->id) {
            return false;
        }

        // Ensure the fee product is active and available
        $this->ensureFeeProductReady($idProduct);

        // Always exactly 1 quantity
        $this->removeFeeFromCart($cart);

        $result = $cart->updateQty(1, $idProduct, null, false, 'up', 0, null, true, true);
        if ($result < 0) {
            // Negative value = error in updateQty
            $result = false;
        }

        if (!$result || !$this->cartHasFeeProduct($cart)) {
            // Fallback: direct insert into cart_product table (PS 9 compatibility)
            $idShop = (int) $this->context->shop->id;
            $idAddress = (int) $cart->id_address_delivery;
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'cart_product`
                WHERE `id_cart` = ' . (int) $cart->id . '
                  AND `id_product` = ' . (int) $idProduct
            );
            $ok = Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'cart_product`
                (`id_cart`, `id_product`, `id_address_delivery`, `id_shop`, `id_product_attribute`, `quantity`, `date_add`)
                VALUES (
                    ' . (int) $cart->id . ',
                    ' . (int) $idProduct . ',
                    ' . (int) $idAddress . ',
                    ' . (int) $idShop . ',
                    0,
                    1,
                    NOW()
                )'
            );
            if (method_exists('Cart', 'resetStaticCache')) {
                Cart::resetStaticCache();
            }
            $cart = new Cart((int) $cart->id);
            if ($this->context && isset($this->context->cart) && (int) $this->context->cart->id === (int) $cart->id) {
                $this->context->cart = $cart;
            }

            return $ok && $this->cartHasFeeProduct($cart);
        }

        if (method_exists('Cart', 'resetStaticCache')) {
            Cart::resetStaticCache();
        }

        return true;
    }

    public function removeFeeFromCart(Cart $cart)
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct || !$cart->id) {
            return;
        }

        if ($this->cartHasFeeProduct($cart)) {
            $cart->deleteProduct($idProduct, 0, 0, 0, false);
        }

        // Extra cleanup in case deleteProduct missed the row
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'cart_product`
            WHERE `id_cart` = ' . (int) $cart->id . '
              AND `id_product` = ' . (int) $idProduct
        );

        if (method_exists('Cart', 'resetStaticCache')) {
            Cart::resetStaticCache();
        }
    }

    /**
     * Activates / fixes the fee product so it can be added to an order.
     */
    public function ensureFeeProductReady($idProduct)
    {
        $product = new Product((int) $idProduct);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        $changed = false;
        if (!(int) $product->active) {
            $product->active = 1;
            $changed = true;
        }
        if (!(int) $product->available_for_order) {
            $product->available_for_order = 1;
            $changed = true;
        }
        if (!(int) $product->is_virtual) {
            $product->is_virtual = 1;
            $changed = true;
        }
        if (property_exists($product, 'product_type') && $product->product_type !== 'virtual') {
            $product->product_type = 'virtual';
            $changed = true;
        }
        if ($changed) {
            $product->update();
        }

        StockAvailable::setProductOutOfStock((int) $product->id, 1);
        StockAvailable::setQuantity((int) $product->id, 0, 9999);

        return true;
    }

    /* ------------------------------------------------------------------
     *  Checkout
     * ------------------------------------------------------------------ */

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart']) || !($params['cart'] instanceof Cart)) {
            return [];
        }

        /** @var Cart $cart */
        $cart = $params['cart'];

        // Do not remove the fee here: if the customer already selected COD,
        // it must stay in the total. Removal is handled by JS when payment method changes.
        if (!(int) Configuration::get(self::CONF_PRODUCT) || !$this->isAvailableForCart($cart)) {
            return [];
        }

        $fee = $this->getFeeForCart($cart);
        $title = (string) Configuration::get(self::CONF_TITLE);
        $info = (string) Configuration::get(self::CONF_DESCRIPTION);

        if ($fee > 0) {
            $feeText = $this->formatPrice($fee);
            $title .= ' (+' . $feeText . ')';
            $info = trim($info . ' COD fee: ' . $feeText . '.');
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true));

        if ($info !== '') {
            $option->setAdditionalInformation('<p>' . htmlspecialchars($info, ENT_QUOTES, 'UTF-8') . '</p>');
        }

        return [$option];
    }

    /**
     * Checkout JS: add/remove fee when payment method changes.
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!$this->active) {
            return;
        }

        $controller = $this->context->controller;
        if (!is_object($controller)) {
            return;
        }

        $phpSelf = isset($controller->php_self) ? $controller->php_self : '';
        $pageName = method_exists($controller, 'getPageName') ? $controller->getPageName() : '';
        $isCheckout = in_array($phpSelf, ['order', 'order-opc', 'checkout'], true)
            || in_array($pageName, ['checkout', 'order', 'order-opc'], true)
            || (isset($controller->page_name) && in_array($controller->page_name, ['checkout', 'order'], true));

        if (!$isCheckout) {
            return;
        }

        $ajaxUrl = $this->context->link->getModuleLink($this->name, 'ajax', [], true);
        Media::addJsDef([
            'codwithfeeConfig' => [
                'ajaxUrl' => $ajaxUrl,
                'moduleName' => $this->name,
                'feeProductId' => (int) Configuration::get(self::CONF_PRODUCT),
            ],
        ]);

        $this->context->controller->registerJavascript(
            'module-codwithfee-checkout',
            'modules/' . $this->name . '/views/js/checkout.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    private function formatPrice($amount)
    {
        $currency = $this->context->currency;
        $locale = method_exists($this->context, 'getCurrentLocale') ? $this->context->getCurrentLocale() : null;

        if ($locale) {
            return $locale->formatPrice($amount, $currency->iso_code);
        }

        return Tools::displayPrice($amount, $currency);
    }

    /* ------------------------------------------------------------------
     *  Fee product
     * ------------------------------------------------------------------ */

    /**
     * Creates the fee product if it does not exist, otherwise syncs it with settings
     * (price and tax rule).
     */
    public function syncFeeProduct()
    {
        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        $product = $idProduct ? new Product($idProduct) : null;

        if ($product && Validate::isLoadedObject($product)) {
            $this->applyFeeToProduct($product);
            if (property_exists($product, 'product_type')) {
                $product->product_type = 'virtual';
            }
            $product->is_virtual = 1;
            $product->active = 1;
            $product->available_for_order = 1;
            $ok = (bool) $product->update();
            $this->ensureFeeProductReady((int) $product->id);

            return $ok;
        }

        return $this->createFeeProduct();
    }

    private function applyFeeToProduct(Product $product)
    {
        $amount = (float) Configuration::get(self::CONF_FEE);
        $idTaxGroup = (int) Configuration::get(self::CONF_TAX_GROUP);
        $rate = $this->getTaxRate($idTaxGroup);

        // Setting is the final price (tax included). Product stores tax-excluded price.
        $product->id_tax_rules_group = $idTaxGroup;
        $product->price = $rate > 0 ? round($amount / (1 + $rate / 100), 6) : $amount;
        $product->active = 1;
    }

    private function getTaxRate($idTaxRulesGroup)
    {
        if (!$idTaxRulesGroup) {
            return 0.0;
        }

        $address = new Address();
        $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->id_state = 0;
        $address->postcode = 0;

        $taxManager = TaxManagerFactory::getManager($address, (int) $idTaxRulesGroup);

        return (float) $taxManager->getTaxCalculator()->getTotalRate();
    }

    private function createFeeProduct()
    {
        $idCategory = (int) Configuration::get('PS_HOME_CATEGORY');

        $product = new Product();
        $product->name = [];
        $product->link_rewrite = [];
        foreach (Language::getLanguages(false) as $language) {
            $product->name[(int) $language['id_lang']] = 'COD Fee';
            $product->link_rewrite[(int) $language['id_lang']] = 'cod-fee';
        }

        $product->reference = self::FEE_REFERENCE;
        $product->is_virtual = 1;               // does not affect shipping and weight
        $product->weight = 0;
        $product->visibility = 'none';          // not visible in catalog or search
        $product->available_for_order = 1;
        $product->show_price = 1;
        $product->minimal_quantity = 1;
        $product->id_category_default = $idCategory;
        // PrestaShop 8/9 product type
        if (property_exists($product, 'product_type')) {
            $product->product_type = 'virtual';
        }
        $this->applyFeeToProduct($product);

        if (!$product->add()) {
            return false;
        }

        $product->addToCategories([$idCategory]);
        StockAvailable::setProductOutOfStock((int) $product->id, 1);
        StockAvailable::setQuantity((int) $product->id, 0, 9999);

        Configuration::updateValue(self::CONF_PRODUCT, (int) $product->id);

        return true;
    }

    /**
     * After order creation: if the fee is missing from order products, add it
     * and fix totals (workaround for PS 9 where visibility=none may be ignored).
     */
    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            return;
        }
        if (empty($params['orderStatus']) || empty($params['cart'])) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'];
        /** @var Cart $cart */
        $cart = $params['cart'];

        // Only for orders of this module
        if ($order->module !== $this->name) {
            return;
        }

        $idProduct = (int) Configuration::get(self::CONF_PRODUCT);
        if (!$idProduct) {
            return;
        }

        // If the fee already exists on the order, do nothing
        foreach ($order->getProducts() as $p) {
            if ((int) $p['product_id'] === $idProduct) {
                return;
            }
        }

        $feeTaxIncl = (float) Product::getPriceStatic($idProduct, true);
        $feeTaxExcl = (float) Product::getPriceStatic($idProduct, false);
        if ($feeTaxIncl <= 0) {
            return;
        }

        $this->ensureFeeProductReady($idProduct);
        $product = new Product($idProduct, false, (int) $order->id_lang);

        // Create OrderDetail for the fee
        $orderDetail = new OrderDetail();
        $orderDetail->id_order = (int) $order->id;
        $orderDetail->id_order_invoice = 0;
        $orderDetail->product_id = $idProduct;
        $orderDetail->product_attribute_id = 0;
        $orderDetail->id_customization = 0;
        $orderDetail->product_name = is_array($product->name)
            ? (string) reset($product->name)
            : (string) $product->name;
        if ($orderDetail->product_name === '') {
            $orderDetail->product_name = 'COD Fee';
        }
        $orderDetail->product_quantity = 1;
        $orderDetail->product_quantity_in_stock = 1;
        $orderDetail->product_price = $feeTaxExcl;
        $orderDetail->unit_price_tax_incl = $feeTaxIncl;
        $orderDetail->unit_price_tax_excl = $feeTaxExcl;
        $orderDetail->total_price_tax_incl = $feeTaxIncl;
        $orderDetail->total_price_tax_excl = $feeTaxExcl;
        $orderDetail->product_reference = self::FEE_REFERENCE;
        $orderDetail->product_ean13 = '';
        $orderDetail->product_upc = '';
        $orderDetail->product_weight = 0;
        $orderDetail->tax_name = '';
        $orderDetail->tax_rate = $feeTaxExcl > 0
            ? round((($feeTaxIncl / $feeTaxExcl) - 1) * 100, 3)
            : 0;
        $orderDetail->id_tax_rules_group = (int) $product->id_tax_rules_group;
        $orderDetail->id_warehouse = 0;
        $orderDetail->id_shop = (int) $order->id_shop;

        if (!$orderDetail->add()) {
            PrestaShopLogger::addLog(
                'codwithfee: failed to add COD fee OrderDetail to order ' . (int) $order->id,
                3,
                null,
                'Order',
                (int) $order->id,
                true
            );

            return;
        }

        // Update order totals
        $order->total_products = (float) $order->total_products + $feeTaxExcl;
        $order->total_products_wt = (float) $order->total_products_wt + $feeTaxIncl;
        $order->total_paid_tax_excl = (float) $order->total_paid_tax_excl + $feeTaxExcl;
        $order->total_paid_tax_incl = (float) $order->total_paid_tax_incl + $feeTaxIncl;
        $order->total_paid = (float) $order->total_paid + $feeTaxIncl;
        $order->update();

        PrestaShopLogger::addLog(
            'codwithfee: added fee ' . $feeTaxIncl . ' to order ' . (int) $order->id,
            1,
            null,
            'Order',
            (int) $order->id,
            true
        );
    }

    /* ------------------------------------------------------------------
     *  Settings page
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitCodWithFee')) {
            $output .= $this->processForm();
        }

        $output .= '<div class="alert alert-info">'
            . '<p>The fee is added to the order as a hidden product «COD Fee» (reference '
            . self::FEE_REFERENCE . ', ID ' . (int) Configuration::get(self::CONF_PRODUCT) . '). '
            . 'Change the fee amount and tax rule here, not from the Catalog, because changes there '
            . 'will be overwritten on the next save.</p>'
            . '<p>Countries, currencies and customer groups for which COD is available are configured under '
            . '<strong>Payment → Preferences</strong>. In addition, you can disable COD for specific '
            . 'shipping methods (carriers) in the settings below (e.g. BOX NOW).</p>'
            . '</div>';

        return $output . $this->renderForm();
    }

    private function processForm()
    {
        $errors = [];
        $values = [];

        $numbers = [
            self::CONF_FEE => 'COD fee',
            self::CONF_FREE_ABOVE => 'Free fee above amount',
            self::CONF_MIN_TOTAL => 'Minimum order total',
            self::CONF_MAX_TOTAL => 'Maximum order total',
        ];
        foreach ($numbers as $key => $label) {
            $raw = str_replace(',', '.', trim((string) Tools::getValue($key)));
            if ($raw === '') {
                $raw = '0';
            }
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000000) {
                $errors[] = $label . ': please enter a valid number (0 or greater).';
                continue;
            }
            $values[$key] = number_format((float) $raw, 2, '.', '');
        }

        if (isset($values[self::CONF_MIN_TOTAL], $values[self::CONF_MAX_TOTAL])
            && (float) $values[self::CONF_MIN_TOTAL] > 0
            && (float) $values[self::CONF_MAX_TOTAL] > 0
            && (float) $values[self::CONF_MIN_TOTAL] > (float) $values[self::CONF_MAX_TOTAL]
        ) {
            $errors[] = 'Minimum order total cannot be greater than maximum order total.';
        }

        $idTaxGroup = (int) Tools::getValue(self::CONF_TAX_GROUP);
        if ($idTaxGroup > 0 && !Validate::isLoadedObject(new TaxRulesGroup($idTaxGroup))) {
            $errors[] = 'The selected tax rule does not exist.';
        }
        $values[self::CONF_TAX_GROUP] = $idTaxGroup;

        $idState = (int) Tools::getValue(self::CONF_ORDER_STATE);
        if (!Validate::isLoadedObject(new OrderState($idState))) {
            $errors[] = 'The selected order status does not exist.';
        }
        $values[self::CONF_ORDER_STATE] = $idState;

        $title = trim(strip_tags((string) Tools::getValue(self::CONF_TITLE)));
        if ($title === '') {
            $errors[] = 'Checkout title cannot be empty.';
        }
        $values[self::CONF_TITLE] = $title;
        $values[self::CONF_DESCRIPTION] = trim(strip_tags((string) Tools::getValue(self::CONF_DESCRIPTION)));

        // Shipping methods for which COD is disabled (multi-select)
        // Form name is CONF_DISABLED_CARRIERS[] – Tools::getValue may return with or without []
        $disabledCarriers = Tools::getValue(self::CONF_DISABLED_CARRIERS);
        if ($disabledCarriers === false || $disabledCarriers === null || $disabledCarriers === '') {
            $disabledCarriers = Tools::getValue(self::CONF_DISABLED_CARRIERS . '[]', []);
        }
        if (!is_array($disabledCarriers)) {
            $disabledCarriers = $disabledCarriers !== false && $disabledCarriers !== null && $disabledCarriers !== ''
                ? [(int) $disabledCarriers]
                : [];
        }
        $disabledCarriers = array_values(array_unique(array_filter(array_map('intval', $disabledCarriers))));
        $values[self::CONF_DISABLED_CARRIERS] = implode(',', $disabledCarriers);

        if ($errors) {
            return $this->displayError(implode('<br>', $errors));
        }

        foreach ($values as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        if (!$this->syncFeeProduct()) {
            return $this->displayError('Settings were saved, but the fee product could not be updated.');
        }

        return $this->displayConfirmation('Settings saved successfully.');
    }

    private function renderForm()
    {
        $currency = Currency::getDefaultCurrency();
        $sign = Validate::isLoadedObject($currency) ? $currency->sign : '€';

        $taxOptions = [['id' => 0, 'name' => 'No tax']];
        foreach (TaxRulesGroup::getTaxRulesGroups(true) as $group) {
            $taxOptions[] = ['id' => (int) $group['id_tax_rules_group'], 'name' => $group['name']];
        }

        $stateOptions = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $stateOptions[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }

        $carrierOptions = [];
        $idLang = (int) $this->context->language->id;
        $carriers = Carrier::getCarriers($idLang, true, false, false, null, Carrier::ALL_CARRIERS);
        foreach ($carriers as $carrier) {
            $carrierOptions[] = [
                'id' => (int) $carrier['id_carrier'],
                'name' => $carrier['name'] . (empty($carrier['is_module']) ? '' : ' (module)'),
            ];
        }

        $form = [
            'form' => [
                'legend' => ['title' => 'Cash on Delivery settings', 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => 'COD fee (tax included)',
                        'name' => self::CONF_FEE,
                        'class' => 'fixed-width-md',
                        'suffix' => $sign,
                        'desc' => 'Final amount the customer pays. Set to 0 for no fee.',
                    ],
                    [
                        'type' => 'select',
                        'label' => 'Tax rule for the fee',
                        'name' => self::CONF_TAX_GROUP,
                        'options' => ['query' => $taxOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => 'If you select a tax rule, the tax-excluded price is calculated automatically so the final amount stays as defined above.',
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Free fee for orders above',
                        'name' => self::CONF_FREE_ABOVE,
                        'class' => 'fixed-width-md',
                        'suffix' => $sign,
                        'desc' => 'If the products total (excluding shipping) reaches this amount, no COD fee is charged. 0 = fee always applies.',
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Minimum order total',
                        'name' => self::CONF_MIN_TOTAL,
                        'class' => 'fixed-width-md',
                        'suffix' => $sign,
                        'desc' => 'Below this total (including shipping, excluding COD fee) COD is not shown. 0 = no limit.',
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Maximum order total',
                        'name' => self::CONF_MAX_TOTAL,
                        'class' => 'fixed-width-md',
                        'suffix' => $sign,
                        'desc' => 'Above this total COD is not shown. 0 = no limit.',
                    ],
                    [
                        'type' => 'select',
                        'label' => 'Order status',
                        'name' => self::CONF_ORDER_STATE,
                        'options' => ['query' => $stateOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => 'Status assigned to the order when the customer places it.',
                    ],
                    [
                        'type' => 'select',
                        'label' => 'Disable for shipping methods',
                        'name' => self::CONF_DISABLED_CARRIERS . '[]',
                        'multiple' => true,
                        'class' => 'chosen',
                        'options' => ['query' => $carrierOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => 'Select the shipping methods (carriers) for which COD will not be available. Leave empty to allow COD for all carriers. Example: if you select BOX NOW, COD will be hidden when the customer chooses that shipping method.',
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Checkout title',
                        'name' => self::CONF_TITLE,
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => 'Checkout description',
                        'name' => self::CONF_DESCRIPTION,
                        'rows' => 3,
                        'cols' => 60,
                        'desc' => 'Shown under the title when the customer selects COD. The fee is added automatically.',
                    ],
                ],
                'submit' => ['title' => 'Save'],
            ],
        ];

        $fieldsValue = [];
        foreach ($this->getFormKeys() as $key) {
            if ($key === self::CONF_DISABLED_CARRIERS) {
                continue;
            }
            $fieldsValue[$key] = Tools::getValue($key, Configuration::get($key));
        }

        // Multi-select: name ends with [], value must be array of selected IDs
        $disabledRaw = Tools::getValue(self::CONF_DISABLED_CARRIERS);
        if ($disabledRaw === false || $disabledRaw === null || $disabledRaw === '') {
            $disabledRaw = Tools::getValue(self::CONF_DISABLED_CARRIERS . '[]', Configuration::get(self::CONF_DISABLED_CARRIERS));
        }
        if (is_array($disabledRaw)) {
            $fieldsValue[self::CONF_DISABLED_CARRIERS . '[]'] = array_map('intval', $disabledRaw);
        } else {
            $fieldsValue[self::CONF_DISABLED_CARRIERS . '[]'] = $disabledRaw !== '' && $disabledRaw !== false && $disabledRaw !== null
                ? array_filter(array_map('intval', explode(',', (string) $disabledRaw)))
                : [];
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        // PrestaShop 9 official way for form action URL
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false, [], ['configure' => $this->name]);
        $helper->submit_action = 'submitCodWithFee';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->fields_value = $fieldsValue;

        return $helper->generateForm([$form]);
    }
}
