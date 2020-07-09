<?php

/**
 * Utils
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

namespace Bleumi\BleumiPay;

/**
 * Utility functions
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

class Utils
{
    /**
     * Get Minutes Difference - Returns the difference in minutes between 2 datetimes
     *
     * @param $dateTime1 start datetime
     * @param $dateTime2 end datetime
     *
     * @return bool
     */
    public function getMinutesDiff($dateTime1, $dateTime2)
    {
        $minutes = abs($dateTime1 - $dateTime2) / 60;
        return $minutes;
    }

    /**
     * Returns the transaction link for the txhash in the given chain
     *
     * @param string $txHash Transaction hash
     * @param string $chain  Network
     *
     * @return string
     */
    public function getTransactionLink($txHash, $chain = null)
    {
        switch ($chain) {
            case 'alg_mainnet':
                return 'https://algoexplorer.io/tx/' . $txHash;
            case 'alg_testnet':
                return 'https://testnet.algoexplorer.io/tx/' . $txHash;
            case 'rsk':
                return 'https://explorer.rsk.co/tx/' . $txHash;
            case 'rsk_testnet':
                return 'https://explorer.testnet.rsk.co/tx/' . $txHash;
            case 'mainnet':
            case 'xdai':
                return 'https://etherscan.io/tx/' . $txHash;
            case 'goerli':
            case 'xdai_testnet':
                return 'https://goerli.etherscan.io/tx/' . $txHash;
            default:
                return '';
        }
    }


    /**
     * Logger function for debugging
     *
     * @param $message Log message
     *
     * @return bool
     */
    public function log($message)
    {
        if (MODULE_PAYMENT_BlEUMIPAY_LOGGING == 'False') return false;

        $filename = sprintf("%s/#%s.log", DIR_WS_MODULES . 'payment/bleumipay/bleumipay_log', date("Ymd", time()));
        $fp = @fopen($filename, "a");
        $msg = sprintf("%s - %s\r\n", date("H:i", time()), $message);
        @fwrite($fp, $msg);
        @fclose($fp);
        return true;
    }

    /**
     * Get Configuration Module Parameter
     *
     * @param $key Key of the config parameter
     *
     * @return string
     */
    public function getModuleParam($key)
    {
        $result = null;
        $query = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . "
        where configuration_key = '" . $key . "'");
        if (tep_db_num_rows($query) === 0) {
            return $result;
        }
        $query_result_array = tep_db_fetch_array($query);
        $result = $query_result_array['configuration_value'];
        return $result;
    }
}
