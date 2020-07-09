<?php

/**
 * Bleumipay Cron
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

require_once 'includes/modules/payment/bleumipay/init.php';
require_once 'includes/application_top.php';

/**
 * Bleumipay Cron
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

class BleumiPay_CronHandler
{
    /**
     * Execute
     * 
     * @return void
     */
    public function execute()
    {
        $job = null;
        $id = $_GET["id"];
        switch ($id) {
        case "payment":
            $job = new PaymentCron();
            break;
        case "order":
            $job = new OrderCron();
            break;
        case "retry":
            $job = new RetryCron();
            break;
        default:
            echo "cron id not supplied. valid values = ['payment', 'order', 'retry']";
            return;
                break;
        }
        $job->execute();
    }
}

$cron_handler = new BleumiPay_CronHandler();
$cron_handler->execute();
