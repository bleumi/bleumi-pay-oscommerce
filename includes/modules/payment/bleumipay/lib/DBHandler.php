<?php

/**
 * DBHandler
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
 * DBHandler 
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

class DBHandler
{

    const CRON_COLLISION_SAFE_MINUTES = 10;
    const AWAIT_PAYMENT_MINUTES = 24 * 60;
    public $utils;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->utils = new Utils;
    }

    /**
     * Retrieves the last execution time of the cron job
     *
     * @param $name Column to fetch value for
     *
     * @return string
     */
    public function getCronTime($name)
    {
        $cron_time = date("Y-m-d H:i:s", strtotime("-1 day"));
        try {
            $sql = "SELECT * 
                    FROM `bleumi_pay_cron`
                    WHERE `id` = 1 ";
            $result = tep_db_fetch_array(tep_db_query($sql));;
            if (!empty($result)) {
                $cron_time = $result[$name];
            }
        } catch (\Throwable $th) {
            $this->utils->log('bleumi_pay: getCronTime: Exception : returning default value');
        }
        return $cron_time;
    }

    /**
     * Sets the last execution time of the cron job
     *
     * @param $name Column to update in bleumi_pay_cron table
     * @param $time UNIX date/time value
     *
     * @return void
     */
    public function updateRuntime($name, $time)
    {
        $sql = "UPDATE `bleumi_pay_cron` SET `" . $name . "` = '" . $time . "' WHERE id = 1";
        tep_db_query($sql);
    }

    /**
     * Verifies whether the configuration parameter exists
     *
     * @param $value Congifuration Key
     *
     * @return boolean
     */
    public function configParamExists($value)
    {
        $query = tep_db_query("SELECT * FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . $value . "'");
        if (tep_db_num_rows($query) > 0) {
            return true;
        }
        return false;
    }

    /**
     * Get Status ID for name 
     *
     * @param $status_name Congifuration Key
     *
     * @return boolean
     */
    public function getStatuscodeID($status_name)
    {
        $query = tep_db_query("SELECT orders_status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = '$status_name' limit 1");
        $fetch = tep_db_fetch_array($query);
        return $fetch["orders_status_id"];
    }

    /**
     * Create Bleumi Pay Custom Statuses 
     *
     * @param $status_name Congifuration Key
     *
     * @return boolean
     */
    public function installStatus($status_name)
    {
        $check_query = tep_db_query("SELECT orders_status_id FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = '$status_name' limit 1");
        if (tep_db_num_rows($check_query) < 1) {
            $status_query = tep_db_query("SELECT max(orders_status_id) AS status_id FROM " . TABLE_ORDERS_STATUS);
            $status = tep_db_fetch_array($status_query);
            $status_id = $status['status_id'] + 1;
            $languages = tep_get_languages();
            for ($i = 0, $n = sizeof($languages); $i < $n; $i++) {
                tep_db_query(" INSERT INTO  " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) VALUES ('" . $status_id . "', '" . $languages[$i]['id'] . "', '$status_name')");
            }
        } else {
            $check = tep_db_fetch_array($check_query);
            $status_id = $check['orders_status_id'];
        }
    }

    /**
     * Create order in bleumi pay orders table
     *
     * @param $orders_id ID of the order to add the details
     *
     * @return void
     */
    public function createOrderMetaData($orders_id)
    {
        tep_db_query("INSERT INTO bleumi_pay_orders (orders_id) VALUES ($orders_id);");
    }

    /**
     * Get the (Pending/Awaiting confirmation/Multi Token Payment)
     * order for the orders_id.
     *
     * @param $orders_id ID of the order to get the details
     *
     * @return object
     */
    public function getPendingOrder($orders_id)
    {
        $pending = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID');
        $awaiting = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID');
        $multitoken = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID');
        $sql = "SELECT * 
                FROM " . TABLE_ORDERS . " 
                WHERE 
                    `orders_id` = " . (int) $orders_id . " AND 
                    `payment_method` = 'Bleumi Pay' AND
                    `orders_status` IN ($pending, $awaiting, $multitoken)";
        $result = tep_db_fetch_array(tep_db_query($sql));
        return $result;
    }

    /**
     * Get all orders that are modified after $updatedTime
     * Usage: The list of orders processed by Orders cron
     *
     * @param $updatedTime Filter criteria - orders that are modified after this value will be returned
     *
     * @return object
     */
    public function getUpdatedOrders($updatedTime)
    {
        $complete_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_DELIVERED_STATUS_ID');
        $canceled_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_CANCELED_STATUS_ID');
        $sql = "SELECT t1.*
                FROM " . TABLE_ORDERS . " t1, 
                    bleumi_pay_orders t2
                WHERE 
                    t1.orders_id = t2.orders_id AND
                    `orders_status` IN ('" . $complete_status . "', '" . $canceled_status . "') AND 
                    `last_modified` BETWEEN '" . $updatedTime . "' AND now() AND
                    (
                        (`bleumipay_processing_completed` = 'no') OR 
                        (`bleumipay_processing_completed` IS NULL)
                    ) 
                ORDER BY 
                    `orders_id` ASC";
        $results = tep_db_query($sql);
        return $results;
    }

    /**
     * Update Pending Order
     *
     * @param $orders_id ID of the order to update
     * @param $status    New status
     *
     * @return void
     */
    public function updatePendingOrder($orders_id, $status)
    {
        if (!empty($orders_id) && !empty($status)) {
            $order = $this->getOrder($orders_id);
            if ($order) {
                $comments = "";
                $this->updateOrderStatus($orders_id, $status, $comments); // Processing
            }
            return array("success" => "");
        } else {
            return array("error" => "invalid order id");
        }
    }

    /**
     * Get Order Meta Data
     *
     * @param $orders_id   ID of the order to get meta data
     * @param $column_name Column Name
     *
     * @return void
     */
    public function getMeta($orders_id, $column_name)
    {
        try {
            $sql = "SELECT " . $column_name . "
                    FROM bleumi_pay_orders
                    WHERE `orders_id` = '" . (int) $orders_id . "'";
            $metadata = tep_db_fetch_array(tep_db_query($sql));
        } catch (\Throwable $th) {
            $this->utils->log('bleumi_pay: getMeta: Exception : orders_id : ' . $orders_id . " for " . $column_name);
            return null;
        }
        return $metadata[$column_name];
    }

    /**
     * Delete Order Meta Data
     *
     * @param $orders_id   ID of the order to delete
     * @param $column_name Column Name
     *
     * @return void
     */
    public function deleteMetaData($orders_id, $column_name)
    {
        return $this->updateStringData($orders_id, $column_name);
    }

    /**
     * Get Order
     *
     * @param $orders_id ID of the order to get details 
     *
     * @return void
     */
    public function getOrder($orders_id)
    {
        $orderId = (int) $orders_id;
        $sql = "SELECT * 
                FROM " . TABLE_ORDERS . " 
                WHERE `orders_id` =  '" . $orderId . "'";
        $result = tep_db_fetch_array(tep_db_query($sql));
        return $result;
    }

    /**
     * Get Order Total
     *
     * @param $orders_id ID of the order to get total 
     *
     * @return void
     */
    public function getOrderTotal($orders_id)
    {
        $orderId = (int) $orders_id;
        $sql = "SELECT `value`
                FROM " . TABLE_ORDERS_TOTAL . " 
                WHERE `orders_id` = '" . $orderId . "' AND `title` = 'Total:'";
        $query = tep_db_fetch_array(tep_db_query($sql));
        return $query['value'];
    }

    /**
     * Changes the order status to 'multi_token_payment'
     *
     * @param $orders_id    ID of the order to change status to 'multi_token_payment'
     * @param $order_status List of valid order statuses
     *
     * @return bool
     */
    public function markAsMultiTokenPayment($orders_id, $order_status)
    {
        $multi_token_status =  $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID');
        $pending_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID');
        $awaiting_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID');
        $valid_statuses = array($pending_status, $awaiting_status);
        $comment = "Multiple Token Payment, Bleumi Pay Dashboard can be used to refund any token balance";
        if (in_array($order_status, $valid_statuses)) {
            $this->updateOrderStatus($orders_id, $multi_token_status, $comment); //Multi Token Status ID
            return true;
        }
        return false;
    }

    /**
     * Update Meta Data
     * 
     * @param $orders_id    Order ID
     * @param $column_name  Column Name
     * @param $column_value Column Value
     * 
     * @return array
     */
    public function updateMetaData($orders_id, $column_name, $column_value)
    {
        return $this->updateStringData($orders_id, $column_name, $column_value);
    }

    /**
     * Update String Data
     * 
     * @param $orders_id    Order ID
     * @param $column_name  Column Name
     * @param $column_value Column Value
     * 
     * @return array
     */
    public function updateStringData($orders_id, $column_name, $column_value = null)
    {
        $orderId = (int) $orders_id;

        if (!empty($orderId)) {
            $set_clause = "";
            if (!empty($column_value)) {
                $set_clause = " SET `" . $column_name . "` = '" . $column_value . "'";
            } else {
                $set_clause = " SET `" . $column_name . "` = null";
            }
            $sql = "UPDATE bleumi_pay_orders "
                . $set_clause .
                " WHERE  `orders_id` =  '" . $orderId . "'";
            tep_db_query($sql);
        }
    }

    /**
     * Get all orders with status = $orderStatus
     * Usage: Orders cron to get all orders that are in
     * 'awaiting_confirmation' status to check if
     * they are still awaiting even after 24 hours.
     *
     * @param $status Filter criteria - status value
     * @param $field  The field to filter on. ('bleumipay_payment_status', 'orders_status')
     *
     * @return object
     */
    public function getOrdersForStatus($status, $field)
    {
        $results = null;
        $where_clause_1 = null;
        if ($field === "orders_status") {
            $where_clause_1 =  "`orders_status` = '" . $status . " '";
        } else if ($field === "bleumipay_payment_status") {
            $where_clause_1 = "`bleumipay_payment_status` = '" . $status . "' ";
        }
        $sql = "SELECT t1.* 
                FROM " . TABLE_ORDERS . " t1,
                    bleumi_pay_orders t2
                WHERE 
                    t1.orders_id = t2.orders_id AND 
                    " . $where_clause_1 . "
                    AND ((`bleumipay_processing_completed` = 'no') OR (`bleumipay_processing_completed` IS NULL))   
                ORDER BY 
                    last_modified ASC";
        $results = tep_db_query($sql);
        return $results;
    }

    /**
     * Get all orders with transient errors.
     * Used by: Retry cron to reprocess such orders
     *
     * @return array
     */
    public function getTransientErrorOrders()
    {
        $sql = "SELECT t1.*
                FROM " . TABLE_ORDERS . " t1,
                bleumi_pay_orders t2 
                WHERE t1.orders_id = t2.orders_id AND 
                      `bleumipay_transient_error` = 'yes' AND 
                      (
                        (`bleumipay_hard_error` = 'no') OR 
                        (`bleumipay_hard_error` IS NULL) OR 
                        (`bleumipay_hard_error` = '')
                      )  AND 
                      (
                        (`bleumipay_processing_completed` = 'no') OR 
                        (`bleumipay_processing_completed` IS NULL) OR 
                        (`bleumipay_processing_completed` = '')
                      )  
                ORDER BY `last_modified`;";
        $results = tep_db_query($sql);;
        return $results;
    }

    /**
     * Update order status 
     *
     * @param $orderId        Order id
     * @param $newOrderStatus Bleumi pay order status
     * @param $comments       Comments for the order
     *
     * @return void
     */
    public function updateOrderStatus($orderId, $newOrderStatus, $comments)
    {
        $sql_data_array = array(
            'orders_id' => $orderId,
            'orders_status_id' => $newOrderStatus,
            'date_added' => 'now()',
            'comments' => $comments,
            'customer_notified' => 0
        );

        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $sql = "UPDATE " . TABLE_ORDERS . "
                SET `orders_status` = '" . (int) $newOrderStatus . "', last_modified = now()
                WHERE `orders_id` = '" . (int) $orderId . "'";
        tep_db_query($sql);
    }

    /**
     * Changes the order status to 'processing'
     *
     * @param $orders_id    Order ID
     * @param $order_status List of valid order statuses
     *
     * @return bool
     */
    public function markOrderAsProcessing($orders_id, $order_status)
    {
        $multi_token_status =  $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID');
        $pending_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID');
        $awaiting_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID');

        $valid_statuses = array($pending_status, $awaiting_status, $multi_token_status);
        if (in_array($order_status, $valid_statuses)) {
            $this->updateOrderStatus(
                $orders_id,
                $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PROCESSING_STATUS_ID'),
                sprintf('Payment received for the order')
            );
            return true;
        }
        return false;
    }

    /**
     * Changes the order status to 'awaiting_payment'
     *
     * @param $orders_id Order ID
     *
     * @return bool
     */
    public function markOrderAsAwaitingPaymentConfirmation($orders_id)
    {
        $order = $this->getOrder($orders_id);
        $pending_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID');
        $order_status = $order['orders_status'];
        $valid_statuses = array($pending_status);
        if (in_array($order_status, $valid_statuses)) {
            $this->updateOrderStatus(
                $orders_id,
                $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID'),
                sprintf('Order status set to Awaiting payment for order-id: %s', $orders_id)
            );
            return true;
        }
        return false;
    }

    /**
     * Changes the order status to 'payment_failed'
     *
     * @param $orders_id    Order ID
     * @param $order_status List of valid order statuses
     *
     * @return bool
     */
    public function failThisOrder($orders_id, $order_status)
    {
        $comment = "";
        $failed =  $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_FAILED_STATUS_ID');
        $awaiting_status = $this->utils->getModuleParam('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID');
        if ($order_status == $awaiting_status) {
            $this->updateOrderStatus($orders_id, $failed, $comment);
            return true;
        }
        return false;
    }
}
