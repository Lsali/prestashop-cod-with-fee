<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CodWithFeeValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        // Cart has already been turned into an order (e.g. double click)
        if ($cart->OrderExists()) {
            Tools::redirect($this->context->link->getPageLink('history', true));
        }

        // Payment method must still be available (country, currency, group, carrier)
        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if ($paymentModule['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->abort($cart, 'Cash on Delivery is not available for this order.');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        // Clear any previous fee and check order total limits
        $this->module->removeFeeFromCart($cart);
        // Reload after removal
        $cart = new Cart((int) $cart->id);
        $this->context->cart = $cart;

        if (!$this->module->isAvailableForCart($cart)) {
            $this->abort($cart, 'Cash on Delivery is not available for this order total.');
        }

        $fee = (float) $this->module->getFeeForCart($cart);

        // Add the fee (if any) to the cart
        if ($fee > 0) {
            if (!$this->module->addFeeToCart($cart)) {
                PrestaShopLogger::addLog(
                    'codwithfee: addFeeToCart failed for cart ' . (int) $cart->id,
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
                $this->abort($cart, 'Could not add the COD fee. Please try again.');
            }
            // Reload cart
            $cart = new Cart((int) $cart->id);
            $this->context->cart = $cart;

            if (!$this->module->cartHasFeeProduct($cart)) {
                PrestaShopLogger::addLog(
                    'codwithfee: fee product not in cart after add for cart ' . (int) $cart->id,
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
                $this->abort($cart, 'Could not add the COD fee. Please try again.');
            }
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // If a fee exists but the total does not include it, add it manually to the amount
        if ($fee > 0 && !$this->module->cartHasFeeProduct($cart)) {
            $total += $fee;
        }

        $orderState = (int) Configuration::get(CodWithFee::CONF_ORDER_STATE);
        if (!$orderState) {
            $orderState = (int) Configuration::get('PS_OS_PREPARATION');
        }

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                $orderState,
                $total,
                (string) Configuration::get(CodWithFee::CONF_TITLE),
                null,
                [],
                (int) $this->context->currency->id,
                false,
                $customer->secure_key
            );
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'codwithfee: validateOrder failed: ' . $e->getMessage(),
                3,
                null,
                'Cart',
                (int) $cart->id,
                true
            );
            $this->abort($cart, 'The order could not be completed. Please try again.');
        }

        if (!$this->module->currentOrder) {
            $this->abort($cart, 'The order could not be completed. Please try again.');
        }

        Tools::redirect(
            'index.php?controller=order-confirmation'
            . '&id_cart=' . (int) $cart->id
            . '&id_module=' . (int) $this->module->id
            . '&id_order=' . (int) $this->module->currentOrder
            . '&key=' . $customer->secure_key
        );
    }

    /**
     * Removes the fee from the cart (if order was not created) and redirects back to checkout.
     */
    private function abort(Cart $cart, $message)
    {
        if (!$cart->OrderExists()) {
            $this->module->removeFeeFromCart($cart);
        }

        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
