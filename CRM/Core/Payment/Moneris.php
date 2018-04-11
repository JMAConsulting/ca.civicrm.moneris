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
      return self::error('Invalid configuration:' . $currencyID . ', you must use currency $CAD with Moneris');
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

  function doDirectPayment(&$params) {
//print_r($params); die();
    // watchdog('moneris_civicrm_ca', 'Params: <pre>!params</pre>', array('!params' => print_r($params, TRUE)), WATCHDOG_NOTICE);
    //make sure i've been called correctly ...
    if (!$this->_profile) {
      return self::error('Unexpected error, missing profile');
    }
    if ($params['currencyID'] != 'CAD') {
      return self::error('Invalid currency selection, must be $CAD');
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

    // TODO : is there already a token for this contact ?
    $token = False;
    /*if (!empty($params['contactID'])) {
      $result = civicrm_api3('PaymentToken', 'get', array(
        'sequential' => 1,
        'contact_id' => 9,
      ));
      //...
    }*/

    if ($token) {
    }
    else {
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
      $mpgRequest = $this->newMpgRequest($mpgTxn);
      $mpgHttpPost = new mpgHttpsPost($this->_profile['storeid'], $this->_profile['apitoken'], $mpgRequest);
      $mpgResponse = $mpgHttpPost->getMpgResponse();


      // FIXME: add a helper function for this instead of duplicating
      $responseCode = $mpgResponse->getResponseCode();
      if (self::isError($mpgResponse)) {
        if ($responseCode) {
          return self::error($mpgResponse);
        }
        else {
          return self::error('No reply from server - check your settings &/or try again');
        }
      }
      /* Check for application errors */
      $result = self::checkResult($mpgResponse);
      if (is_a($result, 'CRM_Core_Error')) {
        return $result;
      }

      /* Success */
      $dataKey = $mpgResponse->getDataKey();
      $token_id = NULL;

      // FIXME: shouldn't we have contactID in every case ??
      if (!empty($params['contactID'])) {
        $number = $params['credit_card_number'];
        $result = civicrm_api3('PaymentToken', 'create', array(
          'contact_id' => $params['contactID'],
          'email' => $email,
          'token' => $dataKey,
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

    //this code based on Moneris example code #

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
    $my_orderid = $params['invoiceID'];
    $amount = CRM_Utils_Rule::cleanMoney($params['amount']);
    $txnArray = array(
      'type' => 'res_purchase_cc',
      'data_key' => $dataKey,
      'order_id' => $my_orderid,
      'amount' => sprintf('%01.2f', $amount),
      //'pan' => $params['credit_card_number'],
      //'expdate' => substr($expiry_string, 2, 4),
      'crypt_type' => '7',
      // 'cust_id' => $params['contactID'],
    );
    // deal with recurring contributions
    // the contribution will be only a card verification
    if ($isRecur) {
      $txnArray['type'] = 'res_card_verification_cc';
      unset($txnArray['amount']);
    }
    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $txnArray);

    //create a transaction object passing the hash created above
    $mpgTxn = new mpgTransaction($txnArray);
    $mpgRequest = $this->newMpgRequest($mpgTxn);
    $mpgHttpPost = new mpgHttpsPost($this->_profile['storeid'], $this->_profile['apitoken'], $mpgRequest);
    // get an mpgResponse object
    $mpgResponse = $mpgHttpPost->getMpgResponse();
    $responseCode = $mpgResponse->getResponseCode();
    if (self::isError($mpgResponse)) {
      if ($responseCode) {
        return self::error($mpgResponse);
      }
      else {
        return self::error('No reply from server - check your settings &/or try again');
      }
    }
    /* Check for application errors */

    $result = self::checkResult($mpgResponse);
    if (is_a($result, 'CRM_Core_Error')) {
      return $result;
    }

    /* Success */

    $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id');

    $params['trxn_result_code'] = (integer) $mpgResponse->getResponseCode();
    $params['trxn_id'] = $mpgResponse->getTxnNumber();
    $params['gross_amount'] = $mpgResponse->getTransAmount();
    $params['payment_status_id'] = array_search('Completed', $statuses);

    // todo: above assignment seems to be ignored, not getting stored in the civicrm_financial_trxn table
    // check if this still true

    // add a recurring payment schedule if requested
    // NOTE: recurring payments will be scheduled for the 20th, TODO: make configurable
    if ($isRecur && MONERIS_DO_RECURRING) {

      // FIXME: it is not saved anywhere...
      $params['payment_token_id'] = $token_id;

      // status pending because the payment will be done later
      $params['payment_status_id'] = array_search('Pending', $statuses);
    }

    return $params;
  }

  function isError(&$response) {
    $responseCode = $response->getResponseCode();
    if (is_null($responseCode)) {
      return TRUE;
    }
    if ('null' == $responseCode) {
      return TRUE;
    }
    if (($responseCode >= 0) && ($responseCode < 50)) {
      return FALSE;
    }
    return TRUE;
  }

  // ignore for now, more elaborate error handling later.
  function &checkResult(&$response) {
    return $response;

    $errors = $response->getErrors();
    if (empty($errors)) {
      return $result;
    }

    $e = CRM_Core_Error::singleton();
    if (is_a($errors, 'ErrorType')) {
      $e->push($errors->getErrorCode(),
        0, NULL,
        $errors->getShortMessage() . ' ' . $errors->getLongMessage()
      );
    }
    else {
      foreach ($errors as $error) {
        $e->push($error->getErrorCode(),
          0, NULL,
          $error->getShortMessage() . ' ' . $error->getLongMessage()
        );
      }
    }
    return $e;
  }

  function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(),
        0, NULL,
        $error->getMessage()
      );
    }
    elseif (is_string($error)) {
      $e->push(9002,
        0, NULL,
        $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
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

  function newMpgRequest($mpgTxn) {
    $mpgRequest = new mpgRequest($mpgTxn);
    $mpgRequest->setProcCountryCode("CA"); //"US" for sending transaction to US environment, we might want to support it one day
    if ($this->_profile['server'] == 'test') {
      $mpgRequest->setTestMode(true); //false or comment out this line for production transactions
    }
    return $mpgRequest;
  }

}

