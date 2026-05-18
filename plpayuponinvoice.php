<?php
/**
 * Pay upon Invoice payment module for PrestaShop 8.2
 * Module: plpayuponinvoice
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Plpayuponinvoice extends PaymentModule
{
    const CONFIG_ADMIN_EMAIL        = 'PLPAYUPONINVOICE_ADMIN_EMAIL';
    const CONFIG_ORDER_STATE        = 'PLPAYUPONINVOICE_ORDER_STATE_ID';
    const CONFIG_QUOTATION_CARRIER  = 'PLPAYUPONINVOICE_QUOTATION_CARRIER';

    public function __construct()
    {
        $this->name = 'plpayuponinvoice';
        $this->tab = 'payments_gateways';
        $this->version = '1.2.0';
        $this->author = 'Planivista';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Pay upon Invoice');
        $this->description = $this->l('Allows customers to place an order and receive an invoice for payment afterwards.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Pay upon Invoice?');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionPresentPaymentOptions')
            && $this->registerHook('actionPresentCart')
            && $this->registerHook('actionPresentOrder')
            && $this->registerHook('displayBeforeCarrier')
            && $this->createOrderState()
            && Configuration::updateValue(self::CONFIG_ADMIN_EMAIL, Configuration::get('PS_SHOP_EMAIL'))
            && Configuration::updateValue(self::CONFIG_QUOTATION_CARRIER, 0);
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && Configuration::deleteByName(self::CONFIG_ADMIN_EMAIL)
            && Configuration::deleteByName(self::CONFIG_QUOTATION_CARRIER);
        // CONFIG_ORDER_STATE is kept intentionally so existing orders retain their status.
    }

    /**
     * Creates a custom "Awaiting Invoice" order state on first install.
     */
    private function createOrderState(): bool
    {
        $existingId = (int) Configuration::get(self::CONFIG_ORDER_STATE);
        if ($existingId && Validate::isLoadedObject(new OrderState($existingId))) {
            return true;
        }

        $orderState = new OrderState();
        $orderState->color = '#4169E1';
        $orderState->send_email = false;
        $orderState->module_name = $this->name;
        $orderState->invoice = false;
        $orderState->logable = true;
        $orderState->paid = false;
        $orderState->shipped = false;
        $orderState->delivery = false;
        $orderState->hidden = false;
        $orderState->unremovable = false;

        foreach (Language::getLanguages(false) as $language) {
            switch (strtolower($language['iso_code'])) {
                case 'nl':
                    $orderState->name[$language['id_lang']] = 'Wacht op factuur';
                    break;
                default:
                    $orderState->name[$language['id_lang']] = 'Awaiting Invoice';
            }
        }

        if (!$orderState->add()) {
            return false;
        }

        @copy(
            _PS_IMG_DIR_ . 'os/9.gif',
            _PS_IMG_DIR_ . 'os/' . (int) $orderState->id . '.gif'
        );

        return Configuration::updateValue(self::CONFIG_ORDER_STATE, (int) $orderState->id);
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Replaces the shipping cost display in the order summary with "Quotation"
     * when the quotation carrier is selected, instead of showing "Free".
     */
    /**
     * Returns the configured quotation carrier IDs as an array of ints.
     * Supports both legacy single-value ("3") and multi-value ("3,7") storage.
     */
    private function getQuotationCarrierIds(): array
    {
        $value = Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        if (!$value) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /**
     * Injects JS that replaces "Free" with "Quotation" for quotation carriers
     * in the carrier selection list during checkout.
     */
    public function hookDisplayBeforeCarrier(array $params): string
    {
        $carrierIds = $this->getQuotationCarrierIds();
        if (empty($carrierIds)) {
            return '';
        }

        $idsJson      = json_encode(array_values($carrierIds));
        $labelJson    = json_encode($this->l('Quotation'));

        return '<script>
(function () {
    var ids   = ' . $idsJson . ';
    var label = ' . $labelJson . ';

    function applyLabels() {
        ids.forEach(function (id) {
            var el = document.querySelector(\'label[for="delivery_option_\' + id + \'"] .carrier-price\');
            if (el && el.textContent !== label) { el.textContent = label; }
        });
    }

    function init() {
        applyLabels();

        var container = document.querySelector(\'.delivery-options-list, #js-delivery\');
        if (container) {
            new MutationObserver(applyLabels).observe(container, { childList: true, subtree: true });
        }

        if (window.prestashop) {
            prestashop.on(\'updatedDeliveryForm\', applyLabels);
        } else {
            document.addEventListener(\'DOMContentLoaded\', function () {
                if (window.prestashop) { prestashop.on(\'updatedDeliveryForm\', applyLabels); }
            });
        }
    }

    if (document.readyState === \'loading\') {
        document.addEventListener(\'DOMContentLoaded\', init);
    } else {
        init();
    }
}());
</script>';
    }

    public function hookActionPresentCart(array $params): void
    {
        $carrierIds = $this->getQuotationCarrierIds();
        if (empty($carrierIds)) {
            return;
        }

        $cart = $this->context->cart;
        if (!$cart || !in_array((int) $cart->id_carrier, $carrierIds)) {
            return;
        }

        $presentedCart = $params['presentedCart'];
        $subtotals = $presentedCart['subtotals'];

        if (isset($subtotals['shipping'])) {
            $subtotals['shipping']['value'] = $this->l('Quotation');
            $presentedCart['subtotals'] = $subtotals;
        }
    }

    /**
     * Replaces the shipping cost display on the order-confirmation page with
     * "Quotation" when the order used the quotation carrier.
     *
     * OrderSubtotalLazyArray::getShipping() is not @isRewritable, so the normal
     * array assignment would throw a RuntimeException. We call offsetSet() with
     * $force = true to bypass that restriction.
     */
    public function hookActionPresentOrder(array $params): void
    {
        $carrierIds = $this->getQuotationCarrierIds();
        if (empty($carrierIds)) {
            return;
        }

        // Load the order directly from the request — more reliable than
        // parsing the carrier out of the presented lazy array.
        $orderId = (int) Tools::getValue('id_order');
        if (!$orderId) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || !in_array((int) $order->id_carrier, $carrierIds)) {
            return;
        }

        /** @var \PrestaShop\PrestaShop\Adapter\Presenter\Order\OrderLazyArray $presentedOrder */
        $presentedOrder = $params['presentedOrder'];

        // $subtotals is an OrderSubtotalLazyArray object — must use offsetSet directly.
        $subtotals   = $presentedOrder['subtotals'];
        $shippingData = $subtotals['shipping'];
        if (is_array($shippingData)) {
            $shippingData['value'] = $this->l('Quotation');
            $subtotals->offsetSet('shipping', $shippingData, true);
        }
    }

    /**
     * When the quotation carrier is selected, filters out all other payment
     * methods so "Pay upon Invoice" is the only option available.
     */
    public function hookActionPresentPaymentOptions(array $params): void
    {
        $carrierIds = $this->getQuotationCarrierIds();
        if (empty($carrierIds)) {
            return;
        }

        if (!in_array((int) $this->context->cart->id_carrier, $carrierIds)) {
            return;
        }

        foreach (array_keys($params['paymentOptions']) as $moduleName) {
            if ($moduleName !== $this->name) {
                unset($params['paymentOptions'][$moduleName]);
            }
        }
    }

    /**
     * Returns the payment option shown in checkout.
     * Adjusts the description text when the quotation carrier is selected.
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active) {
            return [];
        }

        $carrierIds = $this->getQuotationCarrierIds();
        $isQuotation = (!empty($carrierIds) && in_array((int) $this->context->cart->id_carrier, $carrierIds));

        $this->context->smarty->assign([
            'module_dir'          => $this->_path,
            'is_quotation_carrier' => $isQuotation,
        ]);

        $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $paymentOption
            ->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay upon Invoice'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:plpayuponinvoice/views/templates/hook/payment_option.tpl'
                )
            );

        return [$paymentOption];
    }

    /**
     * Renders the confirmation block shown on the order-confirmation page.
     */
    public function hookPaymentReturn(array $params): string
    {
        if (!$this->active) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        $carrierIds = $this->getQuotationCarrierIds();
        $isQuotation = (!empty($carrierIds) && in_array((int) $order->id_carrier, $carrierIds));

        $this->context->smarty->assign([
            'order_reference'      => $order->reference,
            'shop_name'            => Configuration::get('PS_SHOP_NAME'),
            'shop_email'           => Configuration::get('PS_SHOP_EMAIL'),
            'is_quotation_carrier' => $isQuotation,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_confirmation.tpl');
    }

    /**
     * Suppresses emails that are replaced by our own notifications:
     * - order_conf  : default customer confirmation (we send plpayuponinvoice_customer)
     * - new_order   : ps_emailalerts admin notification (we send plpayuponinvoice_admin)
     */
    public function hookActionEmailSendBefore(array $params)
    {
        $template = $params['template'];

        if ($template === 'order_conf') {
            $orderId = (int) ($params['templateVars']['{id_order}'] ?? 0);
            if (!$orderId) {
                return null;
            }
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
                return null;
            }
            return false;
        }

        if ($template === 'new_order') {
            $reference = $params['templateVars']['{order_name}'] ?? '';
            if (!$reference) {
                return null;
            }
            $orderId = (int) Db::getInstance()->getValue(
                'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`
                 WHERE `reference` = \'' . pSQL($reference) . '\''
            );
            if (!$orderId) {
                return null;
            }
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
                return null;
            }
            return false;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Back-office configuration page
    // -------------------------------------------------------------------------

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitPlpayuponinvoice')) {
            $adminEmail = trim(Tools::getValue(self::CONFIG_ADMIN_EMAIL));
            $postedIds  = Tools::getValue(self::CONFIG_QUOTATION_CARRIER);
            if (!is_array($postedIds)) {
                $postedIds = $postedIds ? [$postedIds] : [];
            }
            $carrierIds = implode(',', array_filter(array_map('intval', $postedIds)));

            if (!Validate::isEmail($adminEmail)) {
                $output .= $this->displayError($this->l('Please enter a valid email address.'));
            } else {
                Configuration::updateValue(self::CONFIG_ADMIN_EMAIL, $adminEmail);
                Configuration::updateValue(self::CONFIG_QUOTATION_CARRIER, $carrierIds);
                $output .= $this->displayConfirmation($this->l('Settings saved successfully.'));
            }
        }

        return $output . $this->renderConfigForm();
    }

    private function renderConfigForm(): string
    {
        $carriers = Carrier::getCarriers(
            (int) $this->context->language->id,
            true,
            false,
            false,
            null,
            Carrier::ALL_CARRIERS
        );

        $selectedIds = $this->getQuotationCarrierIds();

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->submit_action = 'submitPlpayuponinvoice';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                self::CONFIG_ADMIN_EMAIL => Configuration::get(self::CONFIG_ADMIN_EMAIL),
            ],
            'languages'   => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Pay upon Invoice — Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Invoice notification email'),
                        'name'     => self::CONFIG_ADMIN_EMAIL,
                        'size'     => 50,
                        'required' => true,
                        'desc'     => $this->l('Receives a notification whenever a new order needs an invoice.'),
                    ],
                    [
                        'type'         => 'html',
                        'label'        => $this->l('Shipping on quotation carriers'),
                        'name'         => 'quotation_carriers_block',
                        'html_content' => $this->renderCarrierMultiSelect($carriers, $selectedIds),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ]]);
    }

    private function renderCarrierMultiSelect(array $carriers, array $selectedIds): string
    {
        $fieldName = self::CONFIG_QUOTATION_CARRIER . '[]';
        $desc = $this->l(
            'When any of these carriers is selected at checkout, all other payment methods are hidden '
            . 'and the confirmation messages mention that a shipping quotation will follow. '
            . 'Hold Ctrl (or Cmd on Mac) to select multiple carriers.'
        );

        $html = '<select name="' . htmlspecialchars($fieldName) . '"'
            . ' id="' . self::CONFIG_QUOTATION_CARRIER . '"'
            . ' multiple="multiple"'
            . ' class="form-control" style="height:auto;min-height:80px;">';

        foreach ($carriers as $carrier) {
            $id       = (int) $carrier['id_carrier'];
            $name     = htmlspecialchars($carrier['name']);
            $selected = in_array($id, $selectedIds) ? ' selected="selected"' : '';
            $html    .= '<option value="' . $id . '"' . $selected . '>' . $name . '</option>';
        }

        $html .= '</select>';
        $html .= '<p class="help-block">' . htmlspecialchars($desc) . '</p>';

        return $html;
    }
}
