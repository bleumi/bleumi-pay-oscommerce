<?php
/**
 * Payment File Doc Comment
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
 * Payment Class Doc Comment
 *
 * ("Payments Processor") functions
 * Check payment received in Bleumi Pay and update Orders.
 * All payments received after the last time of this job run are applied to the orders
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

class PaymentCron
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
     * Payments cron main function
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'payments-cron';

        $start_at =  $this->dbHandler->getCronTime('payment_updated_at');
        $this->utils->log('[Info] Bleumi Pay' . $data_source . ' : looking for payment modified after : ' . $start_at);
        $next_token = '';
        $updated_at = 0;

        do {
            $result =  $this->api->getPayments($start_at, $next_token);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->utils->log('[Info] Bleumi Pay' . $data_source . ' : getPayments api request failed. ' . $result[0]['message'] . ' exiting payments-cron.');
                return $result[0];
            }
            $payments = $result[1]['results'];
            if (is_null($payments)) {
                $this->utils->log('[Info] Bleumi Pay' . $data_source . ' : unable to fetch payments to process');
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'no payments data found.', 'bleumipay',
                );
                return $errorStatus;
            }
            try {
                $next_token = $result[1]['next_token'];
            } catch (\Exception $e) {
            }
            if (is_null($next_token)) {
                $next_token = '';
            }

            foreach ($payments as $payment) {
                $updated_at = $payment['updated_at'];
                $this->utils->log('[Info] Bleumi Pay' . $data_source . ' : processing payment : ' . $payment['id'] . ' ' . date('Y-m-d H:i:s', $updated_at));
                $this->syncPayment($payment, $payment['id'], $data_source);
            }
        } while ($next_token !== '');

        if ($updated_at > 0) {
            $updated_at = $updated_at + 1;
            $this->utils->log("updated_at2 " . $updated_at . " " . date('Y-m-d H:i:s', $updated_at));

            $this->dbHandler->updateRuntime("payment_updated_at",   date('Y-m-d H:i:s', $updated_at));

            $this->utils->log($data_source . ' : setting payment_updated_at: ' . date('Y-m-d H:i:s', $updated_at));
        }
    }

    /**
     * Sync Payment
     *
     * @param $payment     Payment to process.
     * @param $payment_id  Payment ID.
     * @param $data_source Cron job identifier.
     *
     * @return void
     */
    public function syncPayment($payment, $payment_id, $data_source)
    {
        $order = $this->dbHandler->getPendingOrder($payment_id);
        $orders_id = null;
        try {
            if (!is_null($order)) {
                $orders_id = $order['orders_id'];
            }
        } catch (\Exception $e) {
        }
        if (!empty($orders_id)) {
            $bp_hard_error = $this->dbHandler->getMeta($orders_id, 'bleumipay_hard_error');
            // // If there is a hard error (or) transient error action does not match, return
            $bp_transient_error = $this->dbHandler->getMeta($orders_id, 'bleumipay_transient_error');
            $bp_retry_action = $this->dbHandler->getMeta($orders_id, 'bleumipay_retry_action');
            if (($bp_hard_error == 'yes') || (($bp_transient_error == 'yes') && ($bp_retry_action != 'syncPayment'))) {
                $msg = 'syncPayment: ' . $data_source . ' ' . $orders_id . ' : Skipping, hard error found (or) retry_action mismatch, order retry_action is : ' . $bp_retry_action;
                $this->utils->log($msg);
                return;
            }

            // // If already processing completed, no need to sync
            $bp_processing_completed = $this->dbHandler->getMeta($orders_id, 'bleumipay_processing_completed');
            if ($bp_processing_completed == 'yes') {
                $msg = 'Processing already completed for this order. No further changes possible.';
                $this->utils->log('syncPayment: ' . $data_source . ' : ' . $orders_id . ' ' . $msg);
                return;
            }

            // // Exit payments_cron update if bp_payment_status indicated operations are in progress or completed
            $orders_status = $order['orders_status'];
            $bp_payment_status = $this->dbHandler->getMeta($orders_id, 'bleumipay_payment_status');
            $invalid_bp_statuses = array('settle_in_progress', 'settled', 'settle_failed', 'refund_in_progress', 'refunded', 'refund_failed');
            if (in_array($bp_payment_status, $invalid_bp_statuses)) {
                $msg = 'syncPayment: ' . $data_source . ' : ' . $orders_id . ' exiting .. bp_status:' . $bp_payment_status . ' order_status:' . $orders_status;
                $this->utils->log($msg);
                return;
            }

            // skip payments_cron update if order was sync-ed by orders_cron in recently.
            $bp_data_source = $this->dbHandler->getMeta($orders_id, 'bleumipay_data_source');
            $currentTime = strtotime(date("Y-m-d H:i:s")); //server unix time
            
            $date_modified = strtotime($order['last_modified']);
            if ($date_modified == 0) {
                $date_modified = strtotime($order['date_purchased']);
            } 

            $minutes = $this->utils->getMinutesDiff($currentTime, $date_modified);
            if ($minutes < $this->dbHandler::CRON_COLLISION_SAFE_MINUTES) {
                if (($data_source === 'payments-cron') && ($bp_data_source === 'orders-cron')) {
                    $msg = 'syncPayment:' . $orders_id . ' skipping payment processing at this time as Orders_CRON processed this order recently, will be processing again later' . 'bleumipay';
                    $this->exception->logTransientException($orders_id, 'syncPayment', 'E102', $msg);
                    return;
                }
            }

            $addresses = json_encode($payment["addresses"]);
            $this->utils->log('bleumi_pay: $addresses ' . $addresses);
            $this->dbHandler->updateMetaData($orders_id, 'bleumipay_addresses', $addresses);
            //Get token balance
            $result = $this->api->getPaymentTokenBalance($orders_id, $payment);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                if ($result[0]['code'] == -2) {
                    $success = $this->dbHandler->markAsMultiTokenPayment($orders_id, $orders_status);
                    if ($success) {
                        $msg = $result[0]['message'];
                        $this->utils->log("bleumi_pay: '. $data_source .' : syncPayment : order-id: " . $orders_id . " " . $msg . "', order status changed to 'multi_token_payment");
                    }
                } else {
                    $this->utils->log("bleumi_pay: " . $data_source . " : syncPayment : order-id: " . $orders_id . 'get token balance error');
                }
                return;
            }
            $payment_info = $result[1];
            $amount = 0;
            try {
                $amount = (float) $payment_info['token_balances'][0]['balance'];
            } catch (\Exception $e) {
            }
            $order_value = (float) $this->dbHandler->getOrderTotal($orders_id);
            if ($amount >= $order_value) {
                $this->dbHandler->updateOrderStatus(
                    $orders_id,
                    $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PROCESSING_STATUS_ID'),
                    sprintf('Payment Received in temporary Wallet. Change the Status to Completed for Settlement')
                );

                $success = $this->dbHandler->markOrderAsProcessing($orders_id, $orders_status);
                if ($success) {
                    $this->dbHandler->updateMetaData($orders_id, 'bleumipay_processing_completed', "no");
                    $this->dbHandler->updateMetaData($orders_id, 'bleumipay_payment_status', "payment-received");
                    $this->dbHandler->updateMetaData($orders_id, 'bleumipay_data_source', $data_source);
                    $this->utils->log("syncPayment: " . $data_source . " : order-id: " . $orders_id . " set to Payment Received'");
                }
            }
        }
    }
}
