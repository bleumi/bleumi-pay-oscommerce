<?php

/**
 * Bleumi Pay Payment Success Page
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

namespace Bleumi\BleumiPay;

require 'includes/application_top.php';
require_once __DIR__ . '/includes/modules/payment/bleumipay/init.php';


// if the customer is not logged on, redirect them to the shopping cart page
if (!tep_session_is_registered('customer_id')) {
    tep_redirect(tep_href_link(FILENAME_SHOPPING_CART));
}

$page_content = $oscTemplate->getContent('checkout_success');

if (isset($HTTP_GET_VARS['action']) && ($HTTP_GET_VARS['action'] == 'update')) {
    tep_redirect(tep_href_link(FILENAME_DEFAULT));
}

$params = array(
    "id" => $_GET["id"],
    "hmac_alg" => $_GET["hmac_alg"],
    "hmac_input" => $_GET["hmac_input"],
    "hmac_keyId" => $_GET["hmac_keyId"],
    "hmac_value" => $_GET["hmac_value"],
);

$orders_id = $_GET["id"];
$api = new \Bleumi\BleumiPay\APIHandler();
$dbHandler = new \Bleumi\BleumiPay\DBHandler();
$utils = new \Bleumi\BleumiPay\Utils();
$result = $api->validateUrl($params);

if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
    $utils->log('verify_payment: ' . $orders_id . ': api request failed: ' . $result[0]['message']);
    return;
}

$validation_result = $result[1];
$utils->log('verify_payment: ' . $orders_id . ': api result: ' . json_encode($validation_result));
if ($validation_result["valid"] == 1) {
    $utils->log($validation_result["valid"] . $orders_id);
    $order = $dbHandler->getOrder($orders_id);
    $input_arr = explode("|", $decoded_input);
    $paid_amount = (float) $input_arr[3];
    $amount = $orders->info['total'];

    $utils->log('verify_payment: paid_amount:' . $paid_amount . ' order amount: ' . $amount);
    if ($paid_amount >= $amount) {
        $dbHandler->markOrderAsAwaitingPaymentConfirmation($orders_id);
        $utils->log('verify_payment: Awaiting Payment Confirmation status change complete');
    }
}
tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS));
