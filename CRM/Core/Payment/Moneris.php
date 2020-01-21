<?php

/**
 * @author Alan Dixon
 *
 * A custom extension to replace the core code Moneris payment processor.
 * Todo: provide option to use the US mpg version
 *
 */
class CRM_Core_Payment_Moneris extends CRM_Core_Payment {

  CONST PAYMENT_STATUS_MONERIS_AUTHORISED = 'moneris_authorised';
  CONST PAYMENT_STATUS_MONERIS_REVERSED = 'moneris_reversed';

  CONST MONERIS_API_TRANSACTION_TYPE_PURCHASE = '00';
  CONST MONERIS_API_TRANSACTION_TYPE_AUTH = '01';
  CONST MONERIS_API_TRANSACTION_TYPE_CAPTURE = '02';
  CONST MONERIS_API_TRANSACTION_TYPE_REFUND = '04';
  CONST MONERIS_DO_RECURRING = 1;

  CONST MONERIS_RECURRING_PROCESS_NOW = 1;

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Moneris');

    // get merchant data from config
    $config = CRM_Core_Config::singleton();
    // live or test
    $this->_profile['server'] = (('live' == $mode) ? 'prod' : 'test');
    $this->_profile['storeid'] = $this->_paymentProcessor['user_name'];
    $this->_profile['apitoken'] = $this->_paymentProcessor['password'];
    $currencyID = $config->defaultCurrency;
    if ('CAD' != $currencyID) {
      return CRM_Moneris_Utils::error('Invalid configuration:' . $currencyID . ', you must use currency $CAD with Moneris');
      // Configuration error: default currency must be CAD
    }
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Moneris($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Should the first payment date be configurable when setting up back office recurring payments.
   * In the case of Authorize.net this is an option
   * @return bool
   */
  protected function supportsFutureRecurStartDate() {
    return TRUE;
  }

  function doDirectPayment(&$params) {
//print_r($params); die();
    // watchdog('moneris_civicrm_ca', 'Params: <pre>!params</pre>', array('!params' => print_r($params, TRUE)), WATCHDOG_NOTICE);
    //make sure i've been called correctly ...
    if (!$this->_profile) {
      return CRM_Moneris_Utils::error('Unexpected error, missing profile');
    }
    if ($params['currencyID'] != 'CAD') {
      return CRM_Moneris_Utils::error('Invalid currency selection, must be $CAD');
    }
    if (CRM_Utils_Array::value('is_recur', $params) && empty($params['contributionRecurID']) && !empty($params['contributionID'])) {
      $params['contributionRecurID'] = CRM_Core_DAO::singleValueQuery("SELECT contribution_recur_id FROM civicrm_contribution WHERE id = " . $params['contributionID']);
    }
    $isRecur =  CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'];
    // require moneris supplied api library
    require_once 'CRM/Moneris/mpgClasses.php';

    /* unused params: cvv not yet implemented, payment action ingored (should test for 'Sale' value?)
        [cvv2] => 000
        [ip_address] => 192.168.0.103
        [payment_action] => Sale
        [contact_type] => Individual
        [geo_coord_id] => 1 */


    // get main email
    if (empty($params['email'])) {
      if (!empty($params['contactID'])) {
        $api_request = array('version' => 3, 'sequential' => 1, 'is_billing' => 1, 'contact_id' => $params['contactID']);
        $result = civicrm_api('Email', 'getsingle', $api_request);
        if (empty($result['is_error'])) {
          $email = $result['email'];
        }
        else {
          unset($api_request['is_billing']);
          $result = civicrm_api('Email', 'get', $api_request);
          if (!empty($result)) {
            $email = $result['values'][0]['email'];
          }
        }
      }
    }
    else {
      $email = $params['email'];
    }

    // format credit card expiry date
    $expiry_string = sprintf('%04d%02d', $params['year'], $params['month']);

    // FIXME : is there already a token for this contact
    // is it better to disable previous token and create new one, update credit card on the existing token or just create a new one (option ?)
    // for now, create a new token each time
    $token = FALSE;
    $token_id = CRM_Utils_Array::value('payment_token_id', $params, NULL);
    /*if (!empty($params['contactID'])) {
      $result = civicrm_api3('PaymentToken', 'get', array(
        'sequential' => 1,
        'contact_id' => 9,
      ));
      //...
    }*/
    // get token_id based on token
    if (!empty($token_id)) {
      $token = civicrm_api3('PaymentToken', 'getvalue', array('id' => $token_id, 'return' => 'token'));
    }

    if (!$token) {

      // create a new vault credit card and get a corresponding token
      $txnArray=array(
        'type'=>'res_add_cc',
        //'cust_id'=>'CiviCRM-'.$params['contactID'],
        //'phone'=>$phone,
        //'email'=>$email,
        //'note'=>$note,
        'pan'=>$params['credit_card_number'],
        'expdate'=>substr($expiry_string, 2, 4),
        'crypt_type'=>7,
      );

      $mpgTxn = new mpgTransaction($txnArray);
      $mpgResponse = CRM_Moneris_Utils::mpgHttpsRequestPost($this->_profile['storeid'], $this->_profile['apitoken'], $mpgTxn, $this->_profile['server']);
      if (is_a($mpgResponse, 'CRM_Core_Error')) {
        return $mpgResponse;
      }

      /* Success */
      $token = $mpgResponse->getDataKey();

      // FIXME: shouldn't we have contactID in every case ??
      if (!empty($params['contactID'])) {
        $number = $params['credit_card_number'];
        $result = civicrm_api3('PaymentToken', 'create', array(
          'contact_id' => $params['contactID'],
          'email' => $email,
          'token' => $token,
          'payment_processor_id' => $this->_paymentProcessor['id'],
          'expiry_date' => $params['year'] . '-' . $params['month'],
          'billing_first_name' => $params['billing_first_name'],
          'billing_last_name' => $params['billing_last_name'],
          'masked_account_number' => str_repeat("*", strlen($number) - 4) . substr($number, strlen($number) - 4),
          'ip_address' => $_SERVER['REMOTE_ADDR'],
        ));
        $token_id = $result['id'];
      }
    }

    //create an mpgCustInfo object
    $mpgCustInfo = new mpgCustInfo();
    //call set methods of the mpgCustinfo object
    if (!empty($email)) {
      $mpgCustInfo->setEmail($email);
    }
    //get text representations of province/country to send to moneris for billing info

    $billing = array(
      'first_name' => $params['billing_first_name'],
      'last_name' => $params['billing_last_name'],
      'address' => $params['street_address'],
      'city' => $params['city'],
      'province' => $params['state_province'],
      'postal_code' => $params['postal_code'],
      'country' => $params['country'],
    );
    $mpgCustInfo->setBilling($billing);
    // set orderid as invoiceID to help match things up with Moneris later
    $orderid = $params['invoiceID'];
    $amount = CRM_Utils_Rule::cleanMoney($params['amount']);


    // now that we have a token and customer info
    // ensure that the credit card is good or process the payment


    if ($isRecur && !MONERIS_RECURRING_PROCESS_NOW) {

      // only check the credit card
      // payment will be done later by a cron task (could be done in a future day)
      // we don't use the orderid because we want to reserve it for the real transaction
      $result = CRM_Moneris_Utils::cardVerification($this, $token, $orderid . '-check');

    } else {

      $amount = sprintf('%01.2f', $amount);
      $extraParams = array(
        'cust_info' =>  $mpgCustInfo
      );
      $result = CRM_Moneris_Utils::processTokenPayment($this, $token, $orderid, $amount, $extraParams);

    }

    if (is_a($result, 'CRM_Core_Error')) {
      return $result;
    }

    // SUCCESS

    // FIXME: doesn't work for localized installation
    //$statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id');
    $completed = 1;
    $pending = 2;
    $ongoing = 5;

    $mpgResponse = $result;
    //Civi::log()->debug('mpgResponse -- ' . print_r($mpgResponse,1));
    $params['trxn_result_code'] = (integer) $mpgResponse->getResponseCode();
    $params['trxn_id'] = $mpgResponse->getTxnNumber();
    $params['gross_amount'] = $mpgResponse->getTransAmount();
    $params['payment_status_id'] = $completed; //array_search('Completed', $statuses);

    // add a recurring payment schedule if requested
    // NOTE: recurring payments will be scheduled for the 15th, TODO: make configurable
    if ($isRecur) {

      $recurring_status = $ongoing;

      if (MONERIS_RECURRING_PROCESS_NOW) {
        $next = strtotime('+'.$params['frequency_interval'].' '.$params['frequency_unit']);
        $next_sched_contribution_date = date('YmdHis', $next);
      }
      else {
        // status pending because the payment will be done later
        $params['payment_status_id'] = $pending; //array_search('Pending', $statuses);
        $recurring_status = $pending;
        $next_sched_contribution_date = date('YmdHis');
      }

      // fix next date to take allow days into account
      // days at which we want to make the recurring payment
      // FIXME: should be a setting
      /* $allow_days = array(15);
      if (!empty($next_sched_contribution_date)) {
        if (max($allow_days) > 0) {
          $init_time = strtotime($next_sched_contribution_date);
          $from_time = _moneris_contributionrecur_next($init_time,$allow_days);
          $next_sched_contribution_date = date('Ymd', $from_time) . '030000';
        }
      } */

      // FIXME: it is not saved anywhere...
      $params['payment_token_id'] = $token_id;

      // associate token_id to recurring contribution
      if (!empty($token_id) && !empty($params['contributionRecurID'])) {
        $result = civicrm_api3('ContributionRecur', 'create', array(
          'id' => $params['contributionRecurID'],
          'payment_token_id' => $token_id,
          'contribution_status_id' =>  $recurring_status,
          'next_sched_contribution_date' => $next_sched_contribution_date,
        ));
      }
    }

    return $params;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool|object
   */
  public function cancelSubscription(&$message = '', $params = array()) {
    // We use vault, so there is no recurring in Moneris - we do this by a schedule job
    // Let's return TRUE so that CiviCRM cancel the recurring contribution
    return TRUE;
  }


  // might become a supported core function but for now just create our own function name
  /**
   * refundPayment --
   *
   * @param  array  $params [description]
   * @return [type]         [description]
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function refundPayment($params = array()) {
    // find the token for this contribution
    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', array('id' => $params['contribution_id']));
    }
    catch (CiviCRM_API3_Exception $e) {
      // FIXME: display an error message or something ?
      throw new \Civi\Payment\Exception\PaymentProcessorException($e->getMessage());
    }

    if ($contribution['contribution_status_id'] == 9) {
      $participantId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $params['contribution_id'], 'participant_id', 'contribution_id');
      $entityType = $participantId ? 'participant' : 'contribution';
      if ($participantId) {
        $paymentInfo = CRM_Core_BAO_FinancialTrxn::getPartialPaymentWithType($participantId, 'participant');
      }
      else {
        $paymentInfo = CRM_Core_BAO_FinancialTrxn::getPartialPaymentWithType($params['contribution_id'], 'contribution');
      }
      if (!empty($paymentInfo['refund_due'])) {
        $contribution['total_amount'] = CRM_Utils_Money::format(abs($paymentInfo['refund_due']), NULL, '%a');
        $trxnsData = $params;
        $trxnsData['participant_id'] = $participantId;
        $trxnsData['contribution_id'] = $params['contribution_id'];
        $trxnsData['is_send_contribution_notification'] = FALSE;
        $trxnsData['total_amount'] = (float) $paymentInfo['refund_due'];
        Civi::log()->debug('trxnsData -- ' . print_r($trxnsData, 1));
        civicrm_api3('Payment', 'create', $trxnsData);
      }
    }
    // only completed payment can be refund
    elseif ($contribution['contribution_status_id'] != 1) {
      // display an error ?
      throw new \Civi\Payment\Exception\PaymentProcessorException('Only completed payment can be refunded');
    }

    // FIXME: we might want to support void from payment that are not yet on the payer billing (won't even appear)
    // either have a clear error message or make it work

    // TODO : have a reliable way to determine whether it's a void or a refund
    // for now, always do a refund
    $type = 'refund';

    require_once 'CRM/Moneris/mpgClasses.php';
    // try to do a refund on the token and transaction
    $txnArray=array(
      'type' => $type,
      'txn_number'=> $contribution['trxn_id'],
      'order_id'=> $contribution['invoice_id'],
      // FIXME: amount should be remaining amount to make it work for partial payment / partial refund ??
      // any way we can get it from $params ?
      'amount' => $contribution['total_amount'],
      'crypt_type'=> '7',
      //'cust_id' => 'Customer ID',
      'dynamic_descriptor' => 'Refund from CiviCRM'
    );
    $mpgTxn = new mpgTransaction($txnArray);


    Civi::log()->debug('refund -- ' . print_r($mpgTxn,1));
    $result = CRM_Moneris_Utils::mpgHttpsRequestPost($this->_profile['storeid'], $this->_profile['apitoken'], $mpgTxn, $this->_profile['server']);
    if (is_a($result, 'CRM_Core_Error')) {
      throw new \Civi\Payment\Exception\PaymentProcessorException(CRM_Core_Error::getMessages($result));
    }

    // success, get data

    $mpgResponse = $result;
    $params['trxn_result_code'] = (integer) $mpgResponse->getResponseCode();
    $params['trxn_id'] = $mpgResponse->getTxnNumber();
    $params['gross_amount'] = $mpgResponse->getTransAmount();
    // Now create Payments for refund process.
    $trxnsData = $params;
    $trxnsData['participant_id'] = $participantId;
    $trxnsData['contribution_id'] = $params['contribution_id'];
    $trxnsData['is_send_contribution_notification'] = FALSE;
    $trxnsData['total_amount'] = -$contribution['total_amount'];
    $trxnsData['trxn_result_code'] = $params['trxn_result_code'];
    $trxnsData['trxn_date'] = $result->TransDate . ' ' . $result->TransTime;
    civicrm_api3('Payment', 'create', $trxnsData);

    $currentContribution = civicrm_api3('Contribution', 'getsingle', ['id' => $params['contribution_id']]);
    // If the Contribution total is now 0 set the status to be refunded.
    if ($currentContribution['total_amount'] == 0) {
      civicrm_api3('Contribution', 'create', array(
        'contact_id' => $this->_contactID,
        'contribution_id' => $this->_id,
        'contribution_status_id' => 7, // refund
        'cancel_date' => date('Y-m-d H:i:s'),
      ));
    }
    return $params;
  }


  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Store ID is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Password is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

}
