<?php

/**
 * Bleumipay
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */

require_once __DIR__ . '/bleumipay/init.php';

/**
 * Bleumipay functions
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */
class Bleumipay
{
    public $code;
    public $title;
    public $description;
    public $enabled;
    public $order_status;
    private $_check;
    public $api;
    public $utils;
    public $dbHandler;

    /**
     * Constructor
     *
     * @return void
     */    
    function __construct()
    {

        $this->code = 'bleumipay';
        $this->title = MODULE_PAYMENT_BLEUMIPAY_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_BLEUMIPAY_TEXT_DESCRIPTION;
        $this->sort_order = MODULE_PAYMENT_BLEUMIPAY_SORT_ORDER;
        $this->enabled = ((MODULE_PAYMENT_BLEUMIPAY_STATUS == 'True') ? true : false);
        $this->api = new \Bleumi\BleumiPay\APIHandler();
        $this->utils = new \Bleumi\BleumiPay\Utils();
        $this->dbHandler = new \Bleumi\BleumiPay\DBHandler();
        if ((int) MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID;
        }
    }

    /**
     * Javascript validation
     * 
     * @return boolean
     */    
    function javascript_validation()
    {
        return false;
    }

    /**
     * Selection
     * 
     * @return array
     */    
    function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->title
        );
    }

    /**
     * Pre confirmation check
     * 
     * @return boolean
     */
    function pre_confirmation_check()
    {
        return false;
    }

    /**
     * Confirmation button
     * 
     * @return boolean
     */
    function confirmation()
    {
        return false;
    }

    /**
     * Process button
     * 
     * @return void
     */
    function process_button()
    {
        return false;
    }

    /**
     * Check to see whether module get error
     *
     * @return boolean
     */
    function get_error()
    {
        return array(
            'title' => 'Bleumi Pay API call failed',
            'error' => stripslashes(urldecode($_GET['bleumi_pay_error']))
        );
    }

    /**
     * Check to see whether module is installed
     *
     * @return boolean
     */
    function check()
    {
        if (!isset($this->_check)) {
            $check_query = tep_db_query("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_BLEUMIPAY_STATUS'");
            $this->_check = tep_db_num_rows($check_query) > 0;
        }
        return $this->_check;
    }

    /**
     * Store transaction info to the order and process any results that come back from the payment gateway
     * 
     * @return boolean
     */
    function before_process()
    {
        global $messageStack;
        $start_at = date("Y-m-d H:i:s");
        $next_token = '';
        $result =  $this->api->getPayments($start_at, $next_token);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $payment_error_return = 'payment_error='. $result[0]['code'] .'&bleumi_pay_error=Apologies. Checkout with Bleumi Pay does not appear to be working at the moment.';
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_CONFIRMATION, $payment_error_return, 'SSL', true, false));
        }
        return false;
    }

    /**
     * Post-processing activities
     * When the order returns from the processor, this stores the results in order-status-history and logs data for subsequent reference
     *
     * @return boolean
     */
    public function after_process()
    {
        global $insert_id, $order;
        $this->dbHandler->createOrderMetaData($insert_id);
        $sql_data_array = array(
            'orders_id' => $insert_id,
            'orders_status_id' => $this->dbHandler->getStatuscodeID("Pending"),
            'date_added' => 'now()',
            'customer_notified' => 0
        );
        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $result = $this->api->create($order);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = "Order creation failed for # " . $insert_id. ' error_message=' . $result[0]['message'];
            $this->utils->log($msg);
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, $msg, 'SSL', true));
        }
        $_SESSION['cart']->reset(true);
        tep_redirect($result[1]['url']);
        return false;
    }

    /**
     * Used to display error message details
     *
     * @return boolean
     */
    function output_error()
    {
        return false;
    }

    /**
     * Install payment module
     * 
     * @return void
     */    
    function install()
    {
        $query = tep_db_query("SHOW TABLES LIKE 'bleumi_pay_cron'");

        if (tep_db_num_rows($query) === 0) {
            $sql = "CREATE TABLE bleumi_pay_cron (
                   `id` BIGINT(20) UNSIGNED NOT NULL,
                    `payment_updated_at` TIMESTAMP,
                    `order_updated_at`TIMESTAMP,
                    PRIMARY KEY (id))";
            tep_db_query($sql);
        }

        $query = tep_db_query("SHOW TABLES LIKE 'bleumi_pay_orders'");
        if (tep_db_num_rows($query) === 0) {
            $sql = "CREATE TABLE bleumi_pay_orders (
                `orders_id` BIGINT(11) UNSIGNED NOT NULL,
                `bleumipay_addresses` TEXT(0),
                `bleumipay_payment_status` VARCHAR(30),
                `bleumipay_txid` VARCHAR(30),
                `bleumipay_data_source` VARCHAR(30),
                `bleumipay_transient_error` VARCHAR(30),
                `bleumipay_transient_error_code` VARCHAR(30),
                `bleumipay_transient_error_msg` VARCHAR(500),
                `bleumipay_retry_action` VARCHAR(100),
                `bleumipay_transient_error_count` VARCHAR(30),
                `bleumipay_hard_error` VARCHAR(30),
                `bleumipay_hard_error_code` VARCHAR(30),
                `bleumipay_hard_error_msg` VARCHAR(500),
                `bleumipay_processing_completed` VARCHAR(30),
                PRIMARY KEY (orders_id))";
            tep_db_query($sql);
        }

        $statusArray = array("Awaiting For Payment", "Multi Token Payment", "Failed", "Canceled");

        foreach ($statusArray as $status) {
            $this->dbHandler->installStatus($status);
        }
        
        $query = tep_db_query("SELECT * FROM `bleumi_pay_cron` WHERE `id` = 1 ");
        if (tep_db_num_rows($query) == 0) {
            $currentTime = date('Y-m-d H:i:s', strtotime(date("Y-m-d H:i:s")) - 24*3600); //24 hours earlier time
            tep_db_query("INSERT INTO `bleumi_pay_cron` (`id`, `payment_updated_at`, `order_updated_at`) VALUES (1, '" . $currentTime . "', '" . $currentTime . "');");
        }

        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_STATUS')) {
            tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Bleumi Pay Module', 'MODULE_PAYMENT_BLEUMIPAY_STATUS', 'True', 'Do you want to accept Bleumi Pay payments?', '6', '1', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
        }
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_LOGGING')) {
            tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable log to files', 'MODULE_PAYMENT_BLEUMIPAY_LOGGING', 'True', 'Do you want to enable logging to files? The /bleumipay_log directory must have full writing privileges', '6', '20', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
        }
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_API_KEY')) {
            tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('API Key', 'MODULE_PAYMENT_BLEUMIPAY_API_KEY','', 'Get API Key from Bleumi Pay Dashboard <a href=\"https://pay.bleumi.com/dashboard/settings\" target=\"_blank\">Settings &gt; API keys &gt; Create an API key</a>', '6', '2', now())");
        }
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_SORT_ORDER')) {
            tep_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort order of display.', 'MODULE_PAYMENT_BLEUMIPAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '5', now())");
        }

        // Pending
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Pending)', 'MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Pending") . "', 'Set the status of prepared orders', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        }    
        // Awaiting Payment
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Awaiting For Payment)', 'MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Awaiting For Payment") . "', 'Set the status of successful orders', '6', '7', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 
        // MultiToken Payment
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Multi Token Payment)', 'MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Multi Token Payment") . "', 'Set the status of Multi Token Payment orders', '6', '8', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 
        //Processing
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_PROCESSING_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Processing)', 'MODULE_PAYMENT_BLEUMIPAY_PROCESSING_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Processing") . "', 'Set the status of Processing orders', '6', '9', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 
        // Canceled
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_CANCELED_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Order Canceled)', 'MODULE_PAYMENT_BLEUMIPAY_CANCELED_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Canceled") . "', 'Set the status of canceled orders', '6', '10', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 
        // Delivered
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_DELIVERED_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Order Delivered)', 'MODULE_PAYMENT_BLEUMIPAY_DELIVERED_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Delivered") . "', 'Set the status of Delivered orders', '6', '3', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 
        //Failed
        if (!$this->dbHandler->configParamExists('MODULE_PAYMENT_BLEUMIPAY_FAILED_STATUS_ID')) {
            $sql = " INSERT INTO  " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status (Order Failed)', 'MODULE_PAYMENT_BLEUMIPAY_FAILED_STATUS_ID', '" .
            $this->dbHandler->getStatuscodeID("Failed") . "', 'Set the status of failed orders', '6', '12', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())";
            tep_db_query($sql);
        } 

    }

    /**
     * Remove the module and all its settings
     * 
     * @return void
     */  
    function remove()
    {
        $query = tep_db_query("SHOW TABLES LIKE 'bleumi_pay_cron'");	
        if (tep_db_num_rows($query) > 0) {	
            tep_db_query("DROP TABLE `" . "bleumi_pay_cron`");	
        }
        $query = tep_db_query("SHOW TABLES LIKE 'bleumi_pay_orders'");	
        if (tep_db_num_rows($query) > 0) {	
            tep_db_query("DROP TABLE `" . "bleumi_pay_orders`");	
        }
        tep_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
    }


    /**
     * Internal list of configuration keys used for configuration of the module
     *
     * @return array
     */
    function keys()
    {
        return array(
            'MODULE_PAYMENT_BLEUMIPAY_STATUS',
            'MODULE_PAYMENT_BLEUMIPAY_API_KEY',
            'MODULE_PAYMENT_BLEUMIPAY_SORT_ORDER',
            'MODULE_PAYMENT_BLEUMIPAY_LOGGING',
            'MODULE_PAYMENT_BLEUMIPAY_PENDING_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_AWAITING_PAYMENT_CONFIRMATION_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_MULTI_TOKEN_PAYMENT_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_PROCESSING_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_CANCELED_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_DELIVERED_STATUS_ID',
            'MODULE_PAYMENT_BLEUMIPAY_FAILED_STATUS_ID',
        );
    }
}
