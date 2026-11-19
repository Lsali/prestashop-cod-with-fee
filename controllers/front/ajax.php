<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CodWithFeeAjaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$this->module->active) {
            $this->ajaxDie(json_encode(['success' => false, 'error' => 'invalid_cart']));
        }

        $action = Tools::getValue('action');

        if ($action === 'add') {
            $this->module->removeFeeFromCart($cart);
            // Reload cart after remove so totals are clean
            $cart = new Cart((int) $cart->id);
            $this->context->cart = $cart;

            if (!$this->module->isAvailableForCart($cart)) {
                $this->ajaxDie(json_encode(['success' => false, 'error' => 'not_available', 'fee' => 0]));
            }

            $fee = (float) $this->module->getFeeForCart($cart);
            if ($fee > 0) {
                if (!$this->module->addFeeToCart($cart)) {
                    $this->ajaxDie(json_encode(['success' => false, 'error' => 'add_failed', 'fee' => 0]));
                }
                $cart = new Cart((int) $cart->id);
                $this->context->cart = $cart;
            }

            $this->ajaxDie(json_encode([
                'success' => true,
                'fee' => $fee,
                'has_fee' => $fee > 0 && $this->module->cartHasFeeProduct($cart),
                'total' => (float) $cart->getOrderTotal(true, Cart::BOTH),
            ]));
        }

        if ($action === 'remove') {
            $this->module->removeFeeFromCart($cart);
            $cart = new Cart((int) $cart->id);
            $this->context->cart = $cart;

            $this->ajaxDie(json_encode([
                'success' => true,
                'fee' => 0,
                'has_fee' => false,
                'total' => (float) $cart->getOrderTotal(true, Cart::BOTH),
            ]));
        }

        $this->ajaxDie(json_encode(['success' => false, 'error' => 'unknown_action']));
    }

    /**
     * Compatible ajax response helper (ajaxDie may not exist on all PS versions).
     */
    protected function ajaxDie($value = null, $controller = null, $method = null)
    {
        if (method_exists('Controller', 'ajaxDie') || method_exists(get_parent_class($this), 'ajaxDie')) {
            parent::ajaxDie($value, $controller, $method);
        }
        echo $value;
        exit;
    }
}
