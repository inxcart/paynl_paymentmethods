<?php
/*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2014 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
class paynl_paymentmethodsExchangeModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $transactionId = Tools::getValue('order_id');
        $action = Tools::getValue('action');

        try {
            if (strpos($action, 'refund') !== false) {
                throw new Pay_Exception('Ignoring refund');
            }
            if (strpos($action, 'pending') !== false) {
                throw new Pay_Exception('Ignoring pending');
            }
            $result = Pay_Helper_Transaction::processTransaction($transactionId);
        } catch (Exception $ex) {
            echo "TRUE| ";
            echo $ex->getMessage();
            die();
        }
        echo 'TRUE| Status updated to '.$result['state'].' for cartId: '.$result['orderId'].' orderId: '.@$result['real_order_id'];
        die();
    }
}
