<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0(Plpayuponinvoice $module): bool
{
    return $module->registerHook('actionPresentPaymentOptions')
        && $module->registerHook('actionPresentCart')
        && $module->registerHook('actionPresentOrder')
        && Configuration::updateValue(Plpayuponinvoice::CONFIG_QUOTATION_CARRIER, 0);
}
