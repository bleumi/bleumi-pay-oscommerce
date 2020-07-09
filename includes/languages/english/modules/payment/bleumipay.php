<?php

/**
 * Bleumi Pay Language Tokens
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

require_once __DIR__ . '/../../../../configure.php';

if (ENABLE_SSL == true) {
    $link = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG;
} else {
    $link = HTTP_SERVER . DIR_WS_HTTP_CATALOG;
}

define('MODULE_PAYMENT_BLEUMIPAY_TEXT_TITLE', 'Bleumi Pay');
define(
    'MODULE_PAYMENT_BLEUMIPAY_TEXT_DESCRIPTION', 'Accept digital currency payments (like Tether USD, USD Coin, Stasis EURO, CryptoFranc).<br/><br/>To use this extension, you need to sign up for a Bleumi Pay account and create an API Key from <a href="https://pay.bleumi.com/app/" target="_blank" title="Bleumi Pay Dashboard">https://pay.bleumi.com/app/</a>'
);
define('MODULE_PAYMENT_BLEUMIPAY_TEXT_CATALOG_TITLE', 'Bleumi Pay - Pay with Digital Currencies');
