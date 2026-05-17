<?php
/**
 * Front-office validation controller for plpayuponinvoice.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class PlpayuponinvoiceValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        $cart = $this->context->cart;

        if (
            !$cart->id_customer
            || !$cart->id_address_delivery
            || !$cart->id_address_invoice
            || !$this->module->active
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorised = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === 'plpayuponinvoice') {
                $authorised = true;
                break;
            }
        }

        if (!$authorised) {
            $this->errors[] = $this->module->l('This payment method is not available.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency     = $this->context->currency;
        $total        = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $orderStateId = (int) Configuration::get(Plpayuponinvoice::CONFIG_ORDER_STATE);

        $this->module->validateOrder(
            (int)  $cart->id,
            $orderStateId,
            $total,
            $this->module->displayName,
            null,
            [],
            (int)  $currency->id,
            false,
            $customer->secure_key
        );

        $order = new Order((int) $this->module->currentOrder);

        // Check carrier on the created order — more reliable than $cart->id_carrier
        // which can be 0 in certain PS8 checkout flows at this point in the request.
        $carrierId   = (int) Configuration::get(Plpayuponinvoice::CONFIG_QUOTATION_CARRIER);
        $isQuotation = ($carrierId && (int) $order->id_carrier === $carrierId);

        $this->sendAdminNotification($order, $customer, $currency, $isQuotation);
        $this->sendCustomerNotification($order, $customer, $currency, $isQuotation);

        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            null,
            null,
            [
                'id_cart'   => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order'  => (int) $this->module->currentOrder,
                'key'       => $customer->secure_key,
            ]
        ));
    }

    private function sendAdminNotification(Order $order, Customer $customer, Currency $currency, bool $isQuotation): void
    {
        $adminEmail = Configuration::get(Plpayuponinvoice::CONFIG_ADMIN_EMAIL);
        if (!$adminEmail) {
            return;
        }

        if ($isQuotation) {
            $quotationNoteHtml = '<div style="margin:16px 0;padding:12px 16px;background:#fff3cd;border-left:4px solid #ffc107;">'
                . '<strong>&#9888; Shipping on quotation</strong> — This order requires a shipping quote. '
                . 'Include the shipping cost in the invoice or send a separate quotation to the customer.'
                . '</div>';
            $quotationNoteTxt = "\n*** SHIPPING ON QUOTATION ***\nThis order requires a shipping quote.\n"
                . "Include the shipping cost in the invoice or send a separate quotation.\n";
        } else {
            $quotationNoteHtml = '';
            $quotationNoteTxt  = '';
        }

        $shippingDisplay = $isQuotation
            ? $this->module->l('Quotation', 'validation')
            : Tools::displayPrice($order->total_shipping, $currency);

        $templateVars = [
            '{order_reference}'    => $order->reference,
            '{order_id}'           => (string) $order->id,
            '{customer_name}'      => $customer->firstname . ' ' . $customer->lastname,
            '{customer_email}'     => $customer->email,
            '{shipping_display}'   => $shippingDisplay,
            '{order_total}'        => Tools::displayPrice($order->total_paid, $currency),
            '{shop_name}'          => Configuration::get('PS_SHOP_NAME'),
            '{order_date}'         => date('d-m-Y H:i'),
            '{admin_order_url}'    => $this->context->link->getAdminLink('AdminOrders')
                . '&id_order=' . (int) $order->id . '&vieworder',
            '{quotation_note}'     => $quotationNoteHtml,
            '{quotation_note_txt}' => $quotationNoteTxt,
        ];

        Mail::Send(
            (int) $this->context->language->id,
            'plpayuponinvoice_admin',
            sprintf(
                Mail::l('New order awaiting invoice: %s', $this->context->language->id),
                $order->reference
            ),
            $templateVars,
            $adminEmail,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->module->name . '/mails/',
            false,
            (int) $this->context->shop->id
        );
    }

    private function sendCustomerNotification(Order $order, Customer $customer, Currency $currency, bool $isQuotation): void
    {
        if ($isQuotation) {
            $quotationNoteHtml = '<div style="margin:16px 0;padding:12px 16px;background:#e8f4fd;border-left:4px solid #4169E1;">'
                . '<strong>Shipping quotation:</strong> You have selected <em>shipping on quotation</em>. '
                . 'A shipping quote will be prepared and included with your invoice. '
                . 'The shipping cost will be added to the invoice amount.'
                . '</div>';
            $quotationNoteTxt = "\nSHIPPING QUOTATION\nYou have selected shipping on quotation. A shipping quote\n"
                . "will be included with your invoice. The shipping cost will be\n"
                . "added to the invoice amount.\n";
        } else {
            $quotationNoteHtml = '';
            $quotationNoteTxt  = '';
        }

        $templateVars = [
            '{order_reference}'    => $order->reference,
            '{customer_name}'      => $customer->firstname . ' ' . $customer->lastname,
            '{order_total}'        => Tools::displayPrice($order->total_paid, $currency),
            '{shop_name}'          => Configuration::get('PS_SHOP_NAME'),
            '{shop_email}'         => Configuration::get('PS_SHOP_EMAIL'),
            '{order_date}'         => date('d-m-Y H:i'),
            '{quotation_note}'     => $quotationNoteHtml,
            '{quotation_note_txt}' => $quotationNoteTxt,
        ];

        Mail::Send(
            (int) $this->context->language->id,
            'plpayuponinvoice_customer',
            sprintf(
                Mail::l('Your order %s has been received — invoice to follow', $this->context->language->id),
                $order->reference
            ),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->module->name . '/mails/',
            false,
            (int) $this->context->shop->id
        );
    }
}
