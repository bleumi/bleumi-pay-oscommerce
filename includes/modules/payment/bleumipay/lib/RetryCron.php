<?php

/**
 * Retry Cron
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
require_once 'includes/application_top.php';

/**
 * Retry Cron
 *
 * ("Retry failed transient actions") functions
 * Finds all the orders that failed during data synchronization
 * and re-performs them
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

class RetryCron
{
    public $dbHandler;
    public $api;
    public $utils;
    public $exception;
    public $orderCron;
    public $paymentCron;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->exception = new ExceptionHandler();
        $this->dbHandler = new DBHandler();
        $this->orderCron = new OrderCron();
        $this->paymentCron = new PaymentCron();
        $this->api = new APIHandler();
        $this->utils = new Utils();
    }
    
    /**
     * Retry cron main function.
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'retry-cron';
        $this->utils->log($data_source . ' : looking for orders with transient errors');
        $retry_orders = $this->dbHandler->getTransientErrorOrders();
        while ($order = tep_db_fetch_array($retry_orders)) {
            $orders_id = $order['orders_id'];
            $action = $this->dbHandler->getMeta($orders_id, 'bleumipay_retry_action');
            $msg  = $data_source . ': order_id :' . $orders_id . ' staring retry action :';
            $this->exception->checkRetryCount($orders_id);
            switch ($action) {
            case "syncOrder":
                $this->utils->log($msg . $action);
                $this->orderCron->syncOrder($order, $data_source);
                break;
            case "syncPayment":
                $this->utils->log($msg . $action);
                $this->paymentCron->syncPayment(null, $orders_id, $data_source);
                break;
            case "settle":
                $this->utils->log($msg . $action);
                $result = $this->api->getPaymentTokenBalance($order);
                if (is_null($result[0]['code'])) {
                    $this->orderCron->settleOrder($order, $result[1], $data_source);
                }
                break;
            case "refund":
                $this->utils->log($msg . $action);
                $result = $this->api->getPaymentTokenBalance($order);
                if (is_null($result[0]['code'])) {
                    $this->orderCron->refundOrder($order, $result[1], $data_source);
                }
                break;
            default:
                break;
            }
        }
    }
}
