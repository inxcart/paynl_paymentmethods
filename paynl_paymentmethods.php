<?php

if (!defined('_TB_VERSION_')) {
    exit;
}

require_once __DIR__.'/includes/classes/Autoload.php';

class paynl_paymentmethods extends PaymentModule
{
    public $postErrors = [];
    public $html = '';

    public function __construct()
    {
        $this->name = 'paynl_paymentmethods';
        $this->tab = 'payments_gateways';
        $this->author = 'thirty bees';
        $this->version = '3.4.4';
        $this->module_key = '6c2f48f238008e8f68271f5e4763d308';

        $this->currencies = true;
        $this->currencies_mode = 'radio';

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Pay.nl Payment methods');
        $this->description = $this->l('Accept payments by Pay.nl');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

        if (Tools::getValue('id_order')) {
            $idOrder = (int) Tools::getValue('id_order');
            $order = new Order($idOrder);
            $this->displayName = $order->payment;
        }
    }

    public function validateOrderPay($idCart, $idOrderState, $amountPaid, $extraCosts, $paymentMethod = 'Unknown', $message = null, $extraVars = [], $currencySpecial = null, $dontTouchAmount = false, $secureKey = false, Shop $shop = null)
    {
        $statusPending = Configuration::get('PAYNL_WAIT');
        $statusPaid = Configuration::get('PAYNL_SUCCESS');
        $result = false;

        // Als er nog geen order van dit cartid is, de order valideren.
        $orderId = Order::getOrderByCartId($idCart);
        if ($orderId == false) {
            if ($idOrderState == $statusPaid) {
                if ($extraCosts != 0) {
                    $idOrderStateTmp = $statusPending;
                } else {
                    $idOrderStateTmp = $statusPaid;
                }
            } else {
                $idOrderStateTmp = $idOrderState;
            }
            $result = parent::validateOrder($idCart, $idOrderStateTmp, $amountPaid, $paymentMethod, $message, $extraVars, $currencySpecial, $dontTouchAmount, $secureKey, $shop);
            $orderId = $this->currentOrder;

            if ($extraCosts == 0 && $idOrderStateTmp == $statusPaid) {
                //Als er geen extra kosten zijn, en de order staat op betaald zijn we klaar
                return $result;
            }
        }

        if ($orderId && $idOrderState == $statusPaid) {
            $order = new Order($orderId);
            $shippingCost = $order->total_shipping;

            $newShippingCosts = $shippingCost + $extraCosts;
            $extraCostsExcl = round($extraCosts / (1 + (21 / 100)), 2);

            if ($extraCosts != 0) {
                //als de order extra kosten heeft, moeten deze worden toegevoegd.
                $order->total_shipping = $newShippingCosts;
                $order->total_shipping_tax_excl = $order->total_shipping_tax_excl + $extraCostsExcl;
                $order->total_shipping_tax_incl = $newShippingCosts;

                $order->total_paid_tax_excl = $order->total_paid_tax_excl + $extraCostsExcl;

                $order->total_paid_tax_incl = $order->total_paid_real = $order->total_paid = $order->total_paid + $extraCosts;
            }
            $result = $order->addOrderPayment($amountPaid, $paymentMethod, $extraVars['transaction_id']);

            if (number_format($order->total_paid_tax_incl, 2) !== number_format($amountPaid, 2)) {
                $idOrderState = Configuration::get('PS_OS_ERROR');
            }
            //paymentid ophalen
            $orderPayment = OrderPayment::getByOrderId($order->id);

            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState((int) $idOrderState, $order, $orderPayment);
            $sql = new DbQuery();
            $sql->select('`invoice_number`, `invoice_date`, `delivery_number`, `delivery_date`');
            $sql->from(bqSQL(Order::$definition['table']));
            $sql->from('`id_order` = '.(int) $order->id);
            $res = Db::getInstance()->getRow($sql);
            $order->invoice_date = $res['invoice_date'];
            $order->invoice_number = $res['invoice_number'];
            $order->delivery_date = $res['delivery_date'];
            $order->delivery_number = $res['delivery_number'];

            $order->update();

            $history->addWithemail();
        }

        return $result;
    }

    public function install()
    {
        if (!parent::install() || !$this->createTransactionTable() || !Configuration::updateValue('PAYNL_TOKEN', '') || !Configuration::updateValue('PAYNL_SERVICE_ID', '') || !Configuration::updateValue('PAYNL_ORDER_DESC', '') || !Configuration::updateValue('PAYNL_WAIT', '10') || !Configuration::updateValue('PAYNL_SUCCESS', '2') || !Configuration::updateValue('PAYNL_AMOUNTNOTVALID', '0') || !Configuration::updateValue('PAYNL_CANCEL', '6') || !Configuration::updateValue('PAYNL_COUNTRY_EXCEPTIONS', '') || !Configuration::updateValue('PAYNL_PAYMENT_METHOD_ORDER', '') || !$this->registerHook('paymentReturn') || !$this->registerHook('payment')) {
            return false;
        }

        return true;
    }

    private function createTransactionTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."pay_transactions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` varchar(50) NOT NULL,
        `option_id` int(11) NOT NULL,
        `amount` int(11) NOT NULL,
        `currency` char(3) NOT NULL,
        `order_id` int(11) NOT NULL,
        `status` varchar(10) NOT NULL DEFAULT 'PENDING',
        `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_update` datetime DEFAULT NULL,
        `start_data` text NOT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=myisam AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;";

        DB::getInstance()->execute($sql);

        return true;
    }

    public function validateOnStart($paymentMethodId)
    {
        $arrValidateOnStart = Configuration::get('PAYNL_VALIDATE_ON_START');
        if (!empty($arrValidateOnStart)) {
            $arrValidateOnStart = unserialize($arrValidateOnStart);
            if (isset($arrValidateOnStart[$paymentMethodId]) && $arrValidateOnStart[$paymentMethodId] == 1) {
                return true;
            }
        }

        return false;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('PAYNL_TOKEN')
            || !Configuration::deleteByName('PAYNL_SERVICE_ID')
            || !Configuration::deleteByName('PAYNL_ORDER_DESC')
            || !Configuration::deleteByName('PAYNL_WAIT')
            || !Configuration::deleteByName('PAYNL_SUCCESS')
            || !Configuration::deleteByName('PAYNL_AMOUNTNOTVALID')
            || !Configuration::deleteByName('PAYNL_CANCEL')
            || !Configuration::deleteByName('PAYNL_COUNTRY_EXCEPTIONS')
            || !Configuration::deleteByName('PAYNL_PAYMENT_METHOD_ORDER')
            || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * @param $params
     *
     * @return string
     */
    public function hookPayment($params)
    {
        $objCurrency = $this->getCurrency();
        /** @var Cart $cart */
        $cart = $params['cart'];
        $intOrderAmount = round(number_format(Tools::convertPrice($cart->getOrderTotal(), $objCurrency), 2, '.', '') * 100);
        $token = Configuration::get('PAYNL_TOKEN');
        $serviceId = Configuration::get('PAYNL_SERVICE_ID');

        if (!$token || $serviceId) {
            return '';
        }

        if ($this->validateOrderData($intOrderAmount)) {
            $methodOrder = Configuration::get('PAYNL_PAYMENT_METHOD_ORDER');
            $methodOrder = @unserialize($methodOrder);
            if ($methodOrder == false) {
                $methodOrder = [];
            }

            $minAmount = Configuration::get('PAYNL_PAYMENT_MIN');
            $minAmount = @unserialize($minAmount);

            $maxAmount = Configuration::get('PAYNL_PAYMENT_MAX');
            $maxAmount = @unserialize($maxAmount);

            $countryExceptions = Configuration::get('PAYNL_COUNTRY_EXCEPTIONS');
            $countryExceptions = @unserialize($countryExceptions);
            if ($countryExceptions == false) {
                $countryExceptions = [];
            }

            $apiGetservice = new Pay_Api_Getservice();
            $apiGetservice->setApiToken($token);
            $apiGetservice->setServiceId($serviceId);

            $activeProfiles = $apiGetservice->doRequest();
            $activeProfiles = $activeProfiles['paymentOptions'];

            $paymentaddress = new Address($params['cart']->id_address_invoice);
            $countryid = $paymentaddress->id_country;

            // Only the profiles of the target country should remain in this array :). (Only when the count is > 0, otherwise it might indicate problems)
            if (count($countryExceptions) > 0) {
                if (isset($countryExceptions[$countryid])) {
                    foreach ($activeProfiles as $id => $profile) {
                        if (!isset($countryExceptions[$countryid][$profile['id']])) {
                            unset($activeProfiles[$id]);
                        }
                    }
                }
            }

            // Order remaining profiles based by order...
            asort($methodOrder);

            $activeProfilesTemp = $activeProfiles;
            $activeProfiles = [];

            foreach (array_keys($methodOrder) as $iProfileId) {
                foreach ($activeProfilesTemp as $iKey => $arrActiveProfile) {
                    if ($arrActiveProfile['id'] == $iProfileId) {
                        $minAmountForPP = @$minAmount[$iProfileId];
                        $maxAmountForPP = @$maxAmount[$iProfileId];

                        if (!empty($minAmountForPP) && ($minAmountForPP * 100) > $intOrderAmount) {
                            continue;
                        }
                        if (!empty($maxAmountForPP) && ($maxAmountForPP * 100) < $intOrderAmount) {
                            continue;
                        }

                        $arrActiveProfile['name'] = $this->getPaymentMethodName($iProfileId);
                        $arrActiveProfile['extraCosts'] = number_format($this->getExtraCosts($arrActiveProfile['id'], $intOrderAmount / 100), 2);
                        array_push($activeProfiles, $arrActiveProfile);
                        unset($activeProfilesTemp[$iKey]);
                    }
                }
            }

            $this->context->smarty->assign(
                [
                    'this_path'     => $this->_path,
                    'profiles'      => $activeProfiles,
                    //'banks' => $paynl->getIdealBanks(),
                    'this_path_ssl' => (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/',
                ]
            );

            return $this->display(_PS_MODULE_DIR_.'/'.$this->name.'/'.$this->name.'.php', 'payment.tpl');
        } else {
            return '';
        }
    }

    protected function validateOrderData($intOrderAmount)
    {
        return true;
    }

    public function getPaymentMethodName($paymentMethodId)
    {
        $token = Configuration::get('PAYNL_TOKEN');
        $serviceId = Configuration::get('PAYNL_SERVICE_ID');

        $names = Configuration::get('PAYNL_PAYMENT_METHOD_NAME');
        $names = @unserialize($names);
        if (is_array($names) && !empty($names[$paymentMethodId])) {
            return $names[$paymentMethodId];
        }

        $apiService = new Pay_Api_Getservice();
        $apiService->setApiToken($token);
        $apiService->setServiceId($serviceId);

        $result = $apiService->doRequest();

        if (isset($result['paymentOptions'][$paymentMethodId])) {
            return $result['paymentOptions'][$paymentMethodId]['name'];
        } else {
            return false;
        }
    }

    public function getExtraCosts($paymentMethodId, $totalAmount)
    {
        $arrExtraCosts = Configuration::get('PAYNL_PAYMENT_EXTRA_COSTS');
        $arrExtraCosts = unserialize($arrExtraCosts);

        $arrExtraCosts = $arrExtraCosts[$paymentMethodId];
        if (empty($arrExtraCosts)) {
            return 0;
        }

        $fixed = !empty($arrExtraCosts['fixed']) ? $arrExtraCosts['fixed'] : 0;
        $percentage = !empty($arrExtraCosts['percentage']) ? $arrExtraCosts['percentage'] : 0;
        $max = !empty($arrExtraCosts['max']) ? $arrExtraCosts['max'] : 0;

        $extraCosts = $fixed;
        $extraCosts += ($totalAmount * ($percentage / 100));
        if ($extraCosts > $max && $max != 0) {
            $extraCosts = $max;
        }

        return round($extraCosts, 2);
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $this->smarty->assign(
            [
                'status'   => 'ok',
                'id_order' => $params['objOrder']->id,
            ]
        );

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    public function getContent()
    {
        $this->html = '<h2>'.$this->displayName.'</h2>';

        if (isset($_POST['submitPaynl'])) {
            if (!isset($_POST['api'])) {
                $_POST['api'] = 1;
            }

            if (!sizeof($this->postErrors)) {
                Configuration::updateValue('PAYNL_TOKEN', $_POST['paynltoken']);
                Configuration::updateValue('PAYNL_SERVICE_ID', $_POST['service_id']);
                Configuration::updateValue('PAYNL_DESCRIPTION_PREFIX', $_POST['description_prefix']);
                Configuration::updateValue('PAYNL_WAIT', $_POST['wait']);
                Configuration::updateValue('PAYNL_SUCCESS', $_POST['success']);
                Configuration::updateValue('PAYNL_CANCEL', $_POST['cancel']);
                if (isset($_POST['enaC'])) {
                    Configuration::updateValue('PAYNL_COUNTRY_EXCEPTIONS', serialize($_POST['enaC']));
                }
                if (isset($_POST['enaO'])) {
                    Configuration::updateValue('PAYNL_PAYMENT_METHOD_ORDER', serialize($_POST['enaO']));
                }
                if (isset($_POST['payExtraCosts'])) {
                    //kommas voor punten vervangen, en zorgen dat het allemaal getallen zijn
                    $arrExtraCosts = [];

                    foreach ($_POST['payExtraCosts'] as $paymentMethodId => $paymentMethod) {
                        foreach ($paymentMethod as $type => $value) {
                            $value = str_replace(',', '.', $value);
                            $value = $value * 1;
                            if ($value == 0) {
                                $value = '';
                            }
                            $arrExtraCosts[$paymentMethodId][$type] = $value;
                        }
                    }
                    Configuration::updateValue('PAYNL_PAYMENT_EXTRA_COSTS', serialize($arrExtraCosts));
                }
                if (isset($_POST['profileName'])) {
                    Configuration::updateValue('PAYNL_PAYMENT_METHOD_NAME', serialize($_POST['profileName']));
                }
                if (isset($_POST['minAmount'])) {
                    Configuration::updateValue('PAYNL_PAYMENT_MIN', serialize($_POST['minAmount']));
                }
                if (isset($_POST['maxAmount'])) {
                    Configuration::updateValue('PAYNL_PAYMENT_MAX', serialize($_POST['maxAmount']));
                }

                if (isset($_POST['validateOnStart'])) {
                    Configuration::updateValue('PAYNL_VALIDATE_ON_START', serialize($_POST['validateOnStart']));
                }

                $this->displayConf();
            } else {
                $this->displayErrors();
            }
        }

        $this->displayPaynl();
        $this->displayFormSettings();

        return $this->html;
    }

    public function displayConf()
    {
        $this->html .= '
    <div class="conf confirm">
      <img src="../img/admin/ok.gif" alt="'.$this->l('Confirmation').'" />
      '.$this->l('Settings updated').'
    </div>';
    }

    public function displayErrors()
    {
        $nbErrors = sizeof($this->postErrors);
        $this->html .= '
    <div class="alert error">
      <h3>'.($nbErrors > 1 ? $this->l('There are') : $this->l('There is')).' '.$nbErrors.' '.($nbErrors > 1 ? $this->l('errors') : $this->l('error')).'</h3>
      <ol>';
        foreach ($this->postErrors AS $error) {
            $this->html .= '<li>'.$error.'</li>';
        }
        $this->html .= '
      </ol>
    </div>';
    }

    public function displayPaynl()
    {
        $this->html .= '
    <img src="../modules/paynl_paymentmethods/pay.nl.logo.gif" height="20%" width="20%" style="float:left; margin-right:15px;" />
    <b>'.$this->l('This module allows you to accept payments by Pay.nl.').'</b>
    <br /><br /><br />';
    }

    public function displayFormSettings()
    {

        $arrConfig = [];
        $arrConfig[] = 'PAYNL_TOKEN';
        $arrConfig[] = 'PAYNL_DESCRIPTION_PREFIX';
        $arrConfig[] = 'PAYNL_SERVICE_ID';
        $arrConfig[] = 'PAYNL_WAIT';
        $arrConfig[] = 'PAYNL_SUCCESS';
        $arrConfig[] = 'PAYNL_AMOUNTNOTVALID';
        $arrConfig[] = 'PAYNL_CANCEL';
        $arrConfig[] = 'PAYNL_COUNTRY_EXCEPTIONS';
        $arrConfig[] = 'PAYNL_PAYMENT_METHOD_ORDER';
        $arrConfig[] = 'PAYNL_PAYMENT_EXTRA_COSTS';
        $arrConfig[] = 'PAYNL_VALIDATE_ON_START';
        $arrConfig[] = 'PAYNL_PAYMENT_METHOD_NAME';
        $arrConfig[] = 'PAYNL_PAYMENT_MIN';
        $arrConfig[] = 'PAYNL_PAYMENT_MAX';

        $conf = Configuration::getMultiple($arrConfig);

        $paynltoken = array_key_exists('paynltoken', $_POST) ? $_POST['paynltoken'] : (array_key_exists('PAYNL_TOKEN', $conf) ? $conf['PAYNL_TOKEN'] : '');
        $serviceId = array_key_exists('service_id', $_POST) ? $_POST['service_id'] : (array_key_exists('PAYNL_SERVICE_ID', $conf) ? $conf['PAYNL_SERVICE_ID'] : '');
        $descriptionPrefix = array_key_exists('description_prefix', $_POST) ? $_POST['description_prefix'] : (array_key_exists('PAYNL_DESCRIPTION_PREFIX', $conf) ? $conf['PAYNL_DESCRIPTION_PREFIX'] : '');

        $wait = array_key_exists('wait', $_POST) ? $_POST['wait'] : (array_key_exists('PAYNL_WAIT', $conf) ? $conf['PAYNL_WAIT'] : '11');
        $success = array_key_exists('success', $_POST) ? $_POST['success'] : (array_key_exists('PAYNL_SUCCESS', $conf) ? $conf['PAYNL_SUCCESS'] : '2');
        $cancel = array_key_exists('cancel', $_POST) ? $_POST['cancel'] : (array_key_exists('PAYNL_CANCEL', $conf) ? $conf['PAYNL_CANCEL'] : '6');

        $minAmount = (array_key_exists('PAYNL_PAYMENT_MIN', $conf) ? $conf['PAYNL_PAYMENT_MIN'] : '');
        $maxAmount = (array_key_exists('PAYNL_PAYMENT_MAX', $conf) ? $conf['PAYNL_PAYMENT_MAX'] : '');
        if ($minAmount) {
            $minAmount = unserialize($minAmount);
        } else {
            $minAmount = [];
        }
        if ($maxAmount) {
            $maxAmount = unserialize($maxAmount);
        } else {
            $maxAmount = [];
        }

        // Get states
        $states = OrderState::getOrderStates($this->context->language->id);

        $osWait = '<select name="wait">';
        foreach ($states as $state) {
            if ($state['logable'] == 0) {
                $selected = ($state['id_order_state'] == $wait) ? ' selected' : '';
                $osWait .= '<option value="'.$state['id_order_state'].'"'.$selected.'>'.$state['name'].'</option>';
            }
        }
        $osWait .= '</select>';

        $osSuccess = '<select name="success">';
        foreach ($states as $state) {
            if ($state['logable'] == 1) {
                $selected = ($state['id_order_state'] == $success) ? ' selected' : '';
                $osSuccess .= '<option value="'.$state['id_order_state'].'"'.$selected.'>'.$state['name'].'</option>';
            }
        }
        $osSuccess .= '</select>';

        $osCancel = '<select name="cancel">';
        foreach ($states as $state) {
            if ($state['logable'] == 0) {
                $selected = ($state['id_order_state'] == $cancel) ? ' selected' : '';
                $osCancel .= '<option value="'.$state['id_order_state'].'"'.$selected.'>'.$state['name'].'</option>';
            }
        }
        $osCancel .= '</select>';

        $countries = DB::getInstance()->ExecuteS('SELECT id_country FROM '._DB_PREFIX_.'module_country WHERE id_module = '.(int) ($this->id));
        foreach ($countries as $country) {
            $this->country[$country['id_country']] = $country['id_country'];
        }

        $exceptions = '';
        try {
            $token = Configuration::get('PAYNL_TOKEN');
            $serviceId = Configuration::get('PAYNL_SERVICE_ID');

            $serviceApi = new Pay_Api_Getservice();
            $serviceApi->setApiToken($token);
            $serviceApi->setServiceId($serviceId);

            $profiles = $serviceApi->doRequest();
            $profiles = $profiles['paymentOptions'];

            $countries = Country::getCountries($this->context->language->id);

            $forceProfilesEnable = false;
            $profilesEnable = (array_key_exists('PAYNL_COUNTRY_EXCEPTIONS', $conf) ? $conf['PAYNL_COUNTRY_EXCEPTIONS'] : '');
            if (strlen($profilesEnable) == 0) {
                $profilesEnable = [];
                $forceProfilesEnable = true;
            } else {
                $profilesEnable = @unserialize($profilesEnable);
                if ($profilesEnable == false) {
                    $forceProfilesEnable = true;
                    $profilesEnable = [];
                }
            }

            $profilesOrder = (array_key_exists('PAYNL_PAYMENT_METHOD_ORDER', $conf) ? $conf['PAYNL_PAYMENT_METHOD_ORDER'] : '');
            $extraCosts = (array_key_exists('PAYNL_PAYMENT_EXTRA_COSTS', $conf) ? $conf['PAYNL_PAYMENT_EXTRA_COSTS'] : '');
            $validateOnStart = (array_key_exists('PAYNL_VALIDATE_ON_START', $conf) ? $conf['PAYNL_VALIDATE_ON_START'] : '');

            if (strlen($profilesOrder) == 0) {
                $profilesOrder = [];
            } else {
                $profilesOrder = @unserialize($profilesOrder);
                if ($profilesOrder == false) {
                    $profilesOrder = [];
                }
            }

            if (strlen($extraCosts) == 0) {
                $extraCosts = [];
            } else {
                $extraCosts = @unserialize($extraCosts);
                if ($extraCosts == false) {
                    $extraCosts = [];
                }
            }
            if (strlen($validateOnStart) == 0) {
                $validateOnStart = [];
            } else {
                $validateOnStart = @unserialize($validateOnStart);
                if ($validateOnStart == false) {
                    $validateOnStart = [];
                }
            }

            $exceptions = '<br /><h2 class="space">'.$this->l('Payment restrictions').'</h2>';
            $exceptions .= '<table border="1"><tr><th>'.$this->l('Country').'</th><th colspan="'.count($profiles).'">'.$this->l('Payment methods').'</th></tr>';
            $exceptions .= '<tr><td>&nbsp;</td>';
            foreach ($profiles as $profile) {
                $exceptions .= '<td>'.$profile['name'].'</td>';
            }
            $exceptions .= '</tr>';

            foreach ($countries as $countryid => $country) {
                if (!isset($this->country[$countryid])) {
                    continue;
                }

                $exceptions .= "<tr><td>".$country['name']."</td>";

                foreach ($profiles as $profile) {
                    $exceptions .= "<td>";

                    if (!$forceProfilesEnable) {
                        $exceptions .= '<input type="checkbox" name="enaC['.$countryid.']['.$profile['id'].']" value="'.$profile['name'].'"'.(isset($profilesEnable[$countryid][$profile['id']]) ? ' checked="checked"' : '').' />';
                    } else {
                        $exceptions .= '<input type="checkbox" name="enaC['.$countryid.']['.$profile['id'].']" value="'.$profile['name'].'" checked="checked" />';
                    }

                    $exceptions .= '</td>';
                }
                $exceptions .= "</tr>";
            }
            $exceptions .= "</table>";

            $exceptions .= '<br /><h2 class="space">'.$this->l('Payment priority').'</h2>';
            $exceptions .= '<p>'.$this->l('Lower priority is more important').'</p>';
            $exceptions .= '<table border="1"><tr><th>'.$this->l('Payment method').'</th><th>'.$this->l('Order').'</th>';
            $exceptions .= '<th>'.$this->l('Min. amount').'</th>';
            $exceptions .= '<th>'.$this->l('Max. amount').'</th>';
            $exceptions .= '<th>'.$this->l('Extra costs fixed').'</th>';
            $exceptions .= '<th>'.$this->l('Extra costs percentage').'</th>';
            $exceptions .= '<th>'.$this->l('Extra costs max').'</th>';
            $exceptions .= '<th>'.$this->l('Validate on transaction start').'</th>';
            $exceptions .= '</tr>';

            $names = (array_key_exists('PAYNL_PAYMENT_METHOD_NAME', $conf) ? $conf['PAYNL_PAYMENT_METHOD_NAME'] : '');
            $names = unserialize($names);

            foreach ($profiles as $profile) {
                $name = $profile['name'];
                if (is_array($names) && !empty($names[$profile['id']])) {
                    $name = $names[$profile['id']];
                }

                $exceptions .= '<tr><td><input type="text" name="profileName['.$profile['id'].']" value="'.$name.'" /></td><td>';

                $exceptions .= '<select name="enaO['.$profile['id'].']">';
                $value = '';
                if (isset($profilesOrder[$profile['id']])) {
                    $value = $profilesOrder[$profile['id']];
                }

                $valueAmount = count($profiles);
                for ($i = 0; $i < $valueAmount; $i++) {
                    $selected = '';
                    if ($value == $i) {
                        $selected = 'selected="selected"';
                    }

                    $exceptions .= '<option value="'.$i.'" '.$selected.'>'.$this->l('Priority').' '.($i + 1).'</option>';
                }
                $exceptions .= '</select>';
                $exceptions .= '</td>';

                $fixed = @$extraCosts[$profile['id']]['fixed'];
                $percentage = @$extraCosts[$profile['id']]['percentage'];
                $max = @$extraCosts[$profile['id']]['max'];

                $exceptions .= '<td><input name="minAmount['.$profile['id'].']" type="text" value="'.(isset($minAmount[$profile['id']]) ? $minAmount[$profile['id']] : '').'" /></td>';
                $exceptions .= '<td><input name="maxAmount['.$profile['id'].']" type="text" value="'.(isset($maxAmount[$profile['id']]) ? $maxAmount[$profile['id']] : '').'" /></td>';
                $exceptions .= '<td><input name="payExtraCosts['.$profile['id'].'][fixed]" type="text" value="'.$fixed.'" /></td>';
                $exceptions .= '<td><input name="payExtraCosts['.$profile['id'].'][percentage]"  type="text" value="'.$percentage.'" /></td>';
                $exceptions .= '<td><input name="payExtraCosts['.$profile['id'].'][max]"  type="text" value="'.$max.'" /></td>';

                $validateOnStartChecked = '';
                if (isset($validateOnStart[$profile['id']]) && $validateOnStart[$profile['id']] == 1) {
                    $validateOnStartChecked = "checked='checked'";
                }

                $exceptions .= '<td><input type="hidden" name="validateOnStart['.$profile['id'].']" value="0" /><input '.$validateOnStartChecked.' name="validateOnStart['.$profile['id'].']"  type="checkbox" value="1" /></td>';

                $exceptions .= '</tr>';
            }
            $exceptions .= '</table>';
        } catch (Exception $ex) {
            $exceptions = '<br /><h2 class="space">'.$this->l('Payment restrictions').'</h2>'.
                '<br />'.$this->l('Payment restrictions available after connecting to Pay.nl');
        }

        $this->html .= '
    <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
    <fieldset>
      <legend><img src="../img/admin/contact.gif" />'.$this->l('Settings').'</legend>

      <label>'.$this->l('Token').'</label>
      <div class="margin-form"><input type="text" size="33" name="paynltoken" value="'.htmlentities($paynltoken, ENT_COMPAT, 'UTF-8').'" /></div>
      <label>'.$this->l('Service ID').'</label>
      <div class="margin-form"><input type="text" size="33" name="service_id" value="'.htmlentities($serviceId, ENT_COMPAT, 'UTF-8').'" /></div>
      <label>'.$this->l('Order description prefix').'</label>
      <div class="margin-form"><input type="text" size="33" name="description_prefix" value="'.htmlentities($descriptionPrefix, ENT_COMPAT, 'UTF-8').'" /></div>
      <br>
      <hr>
      <br>
      <label>'.$this->l('Pending').'</label>
      <div class="margin-form">'.$osWait.' Alleen van toepassing op betalingen waarbij extra kosten worden gerekend, de status gaat daarna meteen naar success</div>
      <label>'.$this->l('Success').'</label>
      <div class="margin-form">'.$osSuccess.'</div>
      <label>'.$this->l('Cancel').'</label>
      <div class="margin-form">'.$osCancel.'</div>
      <br />'
            .$exceptions.
            '<br /><center><input type="submit" name="submitPaynl" value="'.$this->l('Update settings').'" class="button" /></center>
    </fieldset>
    </form><br /><br />';
    }

}
