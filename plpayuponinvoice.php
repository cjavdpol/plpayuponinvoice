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
    public function hookActionPresentCart(array $params): void
    {
        $carrierId = (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        if (!$carrierId) {
            return;
        }

        $cart = $this->context->cart;
        if (!$cart || (int) $cart->id_carrier !== $carrierId) {
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
        $carrierId = (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        if (!$carrierId) {
            return;
        }

        // Load the order directly from the request — more reliable than
        // parsing the carrier out of the presented lazy array.
        $orderId = (int) Tools::getValue('id_order');
        if (!$orderId) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || (int) $order->id_carrier !== $carrierId) {
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
        $carrierId = (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        if (!$carrierId) {
            return;
        }

        if ((int) $this->context->cart->id_carrier !== $carrierId) {
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

        $carrierId = (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        $isQuotation = ($carrierId && (int) $this->context->cart->id_carrier === $carrierId);

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

        $carrierId = (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER);
        $isQuotation = ($carrierId && (int) $order->id_carrier === $carrierId);

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
            $carrierId  = (int) Tools::getValue(self::CONFIG_QUOTATION_CARRIER);

            if (!Validate::isEmail($adminEmail)) {
                $output .= $this->displayError($this->l('Please enter a valid email address.'));
            } else {
                Configuration::updateValue(self::CONFIG_ADMIN_EMAIL, $adminEmail);
                Configuration::updateValue(self::CONFIG_QUOTATION_CARRIER, $carrierId);
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

        $carrierOptions = [['id_carrier' => 0, 'name' => '— ' . $this->l('None (disabled)') . ' —']];
        foreach ($carriers as $carrier) {
            $carrierOptions[] = [
                'id_carrier' => (int) $carrier['id_carrier'],
                'name'       => $carrier['name'],
            ];
        }

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
                self::CONFIG_ADMIN_EMAIL       => Configuration::get(self::CONFIG_ADMIN_EMAIL),
                self::CONFIG_QUOTATION_CARRIER => (int) Configuration::get(self::CONFIG_QUOTATION_CARRIER),
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
                        'type'    => 'select',
                        'label'   => $this->l('Shipping on quotation carrier'),
                        'name'    => self::CONFIG_QUOTATION_CARRIER,
                        'desc'    => $this->l(
                            'When this carrier is selected at checkout, all other payment methods are hidden '
                            . 'and the confirmation messages mention that a shipping quotation will follow.'
                        ),
                        'options' => [
                            'query' => $carrierOptions,
                            'id'    => 'id_carrier',
                            'name'  => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ]]);
    }
}
