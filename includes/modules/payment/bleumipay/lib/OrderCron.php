<?php

/**
 * Order Cron
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

/**
 * Order Cron
 * 
 * ("Orders Updater") functions
 * Actions Order Statuses changes to Bleumi Pay
 * Any status updates in orders is posted to Bleumi Pay by these function
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

class OrderCron
{
    public $dbHandler;
    public $api;
    public $exception;
    public $utils;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->exception = new ExceptionHandler;
        $this->dbHandler = new DBHandler;
        $this->api = new APIHandler;
        $this->utils = new Utils;
    }

    /**
     * Orders cron main function
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'orders-cron';
        $start_at = $this->dbHandler->getCronTime("order_updated_at");
        $this->utils->log($data_source . " : looking for orders modified after : " . $start_at);
        $orders = $this->dbHandler->getUpdatedOrders($start_at);
        $updated_at = 0;
        while ($order = tep_db_fetch_array($orders)) {
            $updated_at = strtotime($order['last_modified']);
            $this->utils->log('[Info]: ' . $data_source . ' : processing payment :' .  $order['orders_id']);
            $this->syncOrder($order, $data_source);
        }
        if ($updated_at > 0) {
            $updated_at = $updated_at + 1;
            $this->dbHandler->updateRuntime('order_updated_at', date('Y-m-d H:i:s', $updated_at));
            $this->utils->log('[Info]: ' . $data_source . ' : setting order_updated_at to : ' . date('Y-m-d H:i:s', $updated_at));
        }
        //To verify the status of settle_in_progress orders
        $this->verifySettleOperationStatuses($data_source);
        //Fail order that are awaiting payment confirmation after cut-off (24 Hours) time.
        $this->failUnconfirmedPaymentOrders($data_source);
        //To verify the status of refund_in_progress orders
        $this->verifyRefundOperationStatuses($data_source);
        //To ensure balance in all tokens are refunded
        $this->verifyCompleteRefund($data_source);
    }

    /**
     * Sync Order
     *
     * @param object $order       Order to process
     * @param string $data_source Cron job identifier
     *
     * @return void
     */
    public function syncOrder($order, $data_source)
    {
        $orders_id = null;
        try {
            $orders_id = $order['orders_id'];
            $order_modified_date = strtotime($order['last_modified']);
            if ($order_modified_date == 0) {
                $order_modified_date = strtotime($order['date_purchased']);
            } 
            $order_status = $order['orders_status'];
        } catch (\Exception $e) {
        }
        if (empty($orders_id)) {
            return;
        }

        $bp_hard_error = $this->dbHandler->getMeta($orders_id, 'bleumipay_hard_error');
        $bp_transient_error = $this->dbHandler->getMeta($orders_id, 'bleumipay_transient_error');
        $bp_retry_action = $this->dbHandler->getMeta($orders_id, 'bleumipay_retry_action');
        $order_modified_date = strtotime($order['last_modified']); // coverts formated date to unix time

        // If there is a hard error, return
        if (($bp_hard_error == 'yes')) {
            $msg = 'syncOrder: ' . $data_source . ' :' . $orders_id . ' Skipping, hard error found. ';
            $this->utils->log($msg);
            return;
        }

        // If there is a transient error & retry_action does not match, return
        if ((($bp_transient_error == 'yes') && ($bp_retry_action != 'syncOrder'))) {
            $msg = 'syncOrder:  ' . $data_source . ' : ' . $orders_id . ' Skipping, transient error found and retry_action does not match, order retry_action is : ' . $bp_retry_action;
            $this->utils->log($msg);
            return;
        }

        //If Bleumi Pay processing completed, return
        $bp_processing_completed = $this->dbHandler->getMeta($orders_id, 'bleumipay_processing_completed');
        if ($bp_processing_completed == 'yes') {
            $msg = 'Processing already completed for this order. No further changes possible.';
            $this->utils->log('syncOrder: ' . $data_source . ' :' . $orders_id . ' ' . $msg);
            return;
        }

        //If order is in settle_in_progress or refund_in_progress, return
        $bp_payment_status = $this->dbHandler->getMeta($orders_id, 'bleumipay_payment_status');

        if (($bp_payment_status == 'refund_in_progress') || ($bp_payment_status == 'settle_in_progress')) {
            return;
        }

        $prev_data_source = $this->dbHandler->getMeta($orders_id, 'bleumipay_data_source');
        $currentTime = strtotime(date("Y-m-d H:i:s")); //Server Unix time
        $minutes = $this->utils->getMinutesDiff($currentTime, $order_modified_date);

        $this->utils->log('syncOrder:  ' . $data_source . ' : currentTime' . $currentTime . ' mins:' . $minutes);

        if ($minutes < $this->dbHandler::CRON_COLLISION_SAFE_MINUTES) {
            // Skip orders-cron update if order was updated by payments-cron recently.
            if (($data_source === 'orders-cron') && ($prev_data_source === 'payments-cron')) {
                $msg = 'Skipping syncOrder at this time as payments-cron updated this order recently, will be re-tried again';
                $this->utils->log('syncOrder: ' . $data_source . ' :' . $orders_id . ' ' . $msg);
                $this->exception->logTransientException($orders_id, 'syncOrder', 'E200', $msg);
                return;
            }
        }

        $order_status = $order["orders_status"];
        $result = $this->api->getPaymentTokenBalance($orders_id);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            //If balance of more than 1 token is found, log transient error & return
            if ($result[0]['code'] == -2) {
                $success = $this->dbHandler->markAsMultiTokenPayment($orders_id, $order_status);
                if ($success) {
                    $msg = $result[0]['message'];
                    $this->utils->log($data_source . ' : syncOrder : order-id: ' . $orders_id . ' ' . $msg . "', order status changed to 'multi_token_payment' ");
                }
            } else {
                $this->utils->log($data_source . ' : syncOrder: order-id: ' . $orders_id . ' token balance error : ' . $result[0]['message']);
            }
            return;
        }
        $payment_info = $result[1];

        //If no payment amount is found, return

        $amount = 0;
        try {
            $amount = (float) $payment_info['token_balances'][0]['balance'];
        } catch (\Exception $e) {
        }

        if ($amount == 0) {
            $msg = 'order-id' . $orders_id . ' payment is blank.';
            $this->utils->log('bleumi_pay:  ' . $data_source . ' : syncOrder: ' . $msg);
            return;
        }

        $completed = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_DELIVERED_STATUS_ID');
        $canceled = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_CANCELED_STATUS_ID');

        switch ($order_status) {
        case $completed: //complete
            $msg = ' settling payment.';
            $this->settleOrder($order, $payment_info, $data_source);
            break;
        case $canceled: //canceled
            $msg = ' refunding payment.';
            $this->refundOrder($order, $payment_info, $data_source);
            break;
        default:
            $msg = ' switch case : unhandled order status: ' . $order_status;
            break;
        }
        $this->utils->log($data_source . ' : syncOrder : order-id: ' . $orders_id . ' :' . $msg);
    }

    /**
     * Settle orders and set to settle_in_progress Bleumi Pay status
     *
     * @param $order        Order to settle payment
     * @param $payment_info Payment Information
     * @param $data_source  Cron job identifier
     *
     * @return void
     */
    public function settleOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        $orders_id = $order['orders_id'];
        usleep(300000); // rate limit delay.
        $result = $this->api->settlePayment($payment_info, $order);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->exception->logTransientException($orders_id, 'syncOrder', 'E103', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                //$order->reduce_order_stock(); // Reduce stock levels
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_txid', $operation['txid']);
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_payment_status', 'settle_in_progress');
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_processing_completed', 'no');
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_data_source', $data_source);
                $this->exception->clearTransientError($orders_id);
            }
            $msg = 'settlePayment invoked, tx-id is: ' . $operation['txid'];
        }
        $this->utils->log($data_source . ' : settleOrder : order-id :' . $orders_id . ' ' . $msg);
    }

    /**
     * Refund Orders and set to refund_in_progress Bleumi Pay status
     *
     * @param $order        Order to refund payment
     * @param $payment_info Payment Information
     * @param $data_source  Cron job identifier
     *
     * @return void
     */
    public function refundOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        usleep(300000); // rate limit delay.
        $orders_id = $order['orders_id'];
        $result = $this->api->refundPayment($payment_info, $orders_id);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->exception->logTransientException($orders_id, 'syncOrder', 'E205', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_txid', $operation['txid']);
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_payment_status', 'refund_in_progress');
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_processing_completed', 'no');
                $this->exception->clearTransientError($orders_id);
                $msg = ' refundPayment invoked, tx-id is: ' . $operation['txid'];
            }
        }
        $this->utils->log($data_source . ' : refundOrder : ' . $orders_id . ' ' . $msg);
        $this->dbHandler->updateMetaData($orders_id, 'bleumipay_data_source', $data_source);
    }

    /**
     * Find Orders which are in refund_in_progress
     * Bleumi Pay status
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifyRefundOperationStatuses($data_source)
    {
        $orders = $this->dbHandler->getOrdersForStatus('refund_in_progress', 'bleumipay_payment_status');
        if (empty($orders)) {
            return;
        }
        $operation = "refund";
        $this->api->verifyOperationCompletion($orders, $operation, $data_source);
    }

    /**
     * Fail the orders that are not confirmed even after cut-off time. (24 hour)
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function failUnconfirmedPaymentOrders($data_source)
    {
        $awaitingStatus =  $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID');
        $orders = $this->dbHandler->getOrdersForStatus($awaitingStatus, 'orders_status');
        while ($order = tep_db_fetch_array($orders)) {
            if (!empty($order)) {
                $order_id = $order['orders_id'];
                $order_status = $order['orders_status'];
                $currentTime = strtotime(date("Y-m-d H:i:s")); //Server UNIX time
                $order_updated_date = strtotime($order['last_modified']);
                if ($order_updated_date == 0) {
                    $order_updated_date = strtotime($order['date_purchased']);
                }    
                if ($order_updated_date > 0 ) {
                    $minutes = $this->utils->getMinutesDiff($currentTime, $order_updated_date);
                    if ($minutes > $this->dbHandler::AWAIT_PAYMENT_MINUTES) {
                        $msg = 'Payment confirmation not received before cut-off time, elapsed minutes: ' . round($minutes, 2);
                        $this->utils->log('failUnconfirmedPaymentOrders: order-id: ' . $order_id . ' ' . $msg);
                        $this->dbHandler->failThisOrder($order_id, $order_status);
                    }
                }
            }
        }
    }

    /**
     * Verify that the refund is complete
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifyCompleteRefund($data_source)
    {
        $orders = $this->dbHandler->getOrdersForStatus('refunded', 'bleumipay_payment_status');
        while ($order = tep_db_fetch_array($orders)) {
            $orders_id = $order['orders_id'];
            $result = $this->api->getPaymentTokenBalance($orders_id);
            $payment_info = $result[1];
            $token_balances = array();
            try {
                $token_balances = $payment_info['token_balances'];
            } catch (\Exception $e) {
            }

            $token_balances_modified = array();
            //All tokens are refunded, can mark the order as processing completed
            if (count($token_balances) == 0) {
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_processing_completed', 'yes');
                $this->utils->log('verifyCompleteRefund: ' . $orders_id . ' processing completed. token_balance count =' . count($token_balances));
                return;
            }
            $next_token = '';
            do {
                $ops_result = $this->api->listPaymentOperations($orders_id);
                $operations = $ops_result[1]['results'];
                $next_token = null;
                try {
                    $next_token = $operations['next_token'];
                } catch (\Exception $e) {
                }

                if (is_null($next_token)) {
                    $next_token = '';
                }

                $valid_operations = array('createAndRefundWallet', 'refundWallet');

                foreach ($token_balances as $token_balance) {
                    $token_balance['refunded'] = 'no';
                    foreach ($operations as $operation) {
                        if (isset($operation['hash']) && (!is_null($operation['hash']))) {
                            if (($operation['inputs']['token'] === $token_balance['addr']) && ($operation['status'] == 'yes') && ($operation['chain'] == $token_balance['chain']) && (in_array($operation['func_name'], $valid_operations))) {
                                $token_balance['refunded'] = 'yes';
                                break;
                            }
                        }
                    }
                    array_push($token_balances_modified, $token_balance);
                }
            } while ($next_token !== '');

            $all_refunded = 'yes';
            foreach ($token_balances_modified as $token_balance) {
                if ($token_balance['refunded'] === 'no') {
                    $amount = $token_balance['balance'];
                    if (!is_null($amount)) {
                        $payment_info['id'] = $orders_id;
                        $item = array(
                            'chain' => $token_balance['chain'],
                            'addr' => $token_balance['addr'],
                            'balance' => $token_balance['balance'],
                        );
                        $payment_info['token_balances'] = array($item);
                        $this->refundOrder($order, $payment_info, $data_source);
                        $all_refunded = 'no';
                        break;
                    }
                }
            }
            if ($all_refunded == 'yes') {
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_processing_completed', 'yes');
                $this->utils->log('verifyCompleteRefund: ' . $orders_id . ' processing completed.');
            }
        }
    }

    /**
     * Find Orders which are in bp_payment_status = settle_in_progress
     * and check transaction status
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifySettleOperationStatuses($data_source)
    {
        $orders = $this->dbHandler->getOrdersForStatus('settle_in_progress', 'bleumipay_payment_status');
        $operation = "settle";
        $this->api->verifyOperationCompletion($orders, $operation, $data_source);
    }
}
