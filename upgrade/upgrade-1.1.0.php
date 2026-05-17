<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.1.0: register the actionEmailSendBefore hook.
 */
function upgrade_module_1_1_0(Plpayuponinvoice $module): bool
{
    return $module->registerHook('actionEmailSendBefore');
}
