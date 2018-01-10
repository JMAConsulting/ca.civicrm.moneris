<?php

/**
 * @author Alan Dixon
 *
 * A custom extension to replace the core code Moneris payment processor.
 * Todo: provide option to use the US mpg version
 *
 */
require_once 'vendor/autoload.php';

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

  protected $_monerisAPI;

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

    $currencyID = CRM_Core_Config::singleton()->defaultCurrency;
    if (!in_array($currencyID, array('USD', 'CAD'))) {
      return self::error('Invalid configuration:' . $currencyID . ', you must use currency $CAD with Moneris');
    }

    // live or test
    $isTest = ('live' !== $mode);
    $this->_monerisAPI = CRM_Civimoodle_API::singleton(
      $this->_paymentProcessor['signature'],
      $this->_paymentProcessor['password'],
      TRUE)
      ->isTest($isTest)
      ->setProcCountryCode($currencyID);
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
    if (!$this->_monerisAPI) {
      return self::error('Unexpected error, missing Moneris setting');
    }
    if (!in_array($params['currencyID'], array('CAD', 'USD'))) {
      return self::error('Invalid currency selection, must be $CAD or USD');
    }
    $isRecur =  CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'];

    /* unused params: cvv not yet implemented, payment action ingored (should test for 'Sale' value?)
        [cvv2] => 000
        [ip_address] => 192.168.0.103
        [payment_action] => Sale
        [contact_type] => Individual
        [geo_coord_id] => 1 */

    //this code based on Moneris example code #
    //create an mpgCustInfo object
    $mpgCustInfo = new mpgCustInfo();
    //call set methods of the mpgCustinfo object
    if (empty($params['email'])) {
      if (!empty($params['contactID'])) {
        $result = civicrm_api3('Email', 'get', array(
          'sequential' => 1,
          'is_billing' => 1,
          'contact_id' => $params['contactID'],
        ));
        if ($result['count'] == 0) {
          $result = civicrm_api3('Email', 'get', array(
            'sequential' => 1,
            'contact_id' => $params['contactID'],
          ));
        }
        $params['email'] = CRM_Utils_Array::value(0, $result['values']);
      }
    }
    $this->_monerisAPI->setCustInfo($params);

    // set orderid as invoiceID to help match things up with Moneris later
    $my_orderid = $params['invoiceID'];
    $expiry_string = sprintf('%04d%02d', $params['year'], $params['month']);
    $amount = CRM_Utils_Rule::cleanMoney($params['amount']);
    $txnArray = array(
      'type' => 'purchase',
      'order_id' => $my_orderid,
      'amount' => sprintf('%01.2f', $amount),
      'pan' => $params['credit_card_number'],
      'expdate' => substr($expiry_string, 2, 4),
      'crypt_type' => '7',
      // 'cust_id' => $params['contactID'],
    );
    // deal with recurring contributions
    // my first contibution will be only a card verification
    if ($isRecur) {
      $txnArray['type'] = 'card_verification';
      unset($txnArray['amount']);
    }
    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $txnArray);

    list($isError, $mpgResponse) = $this->_monerisAPI->sendTransaction($txnArray);
    $params['trxn_result_code'] = $mpgResponse->getResponseCode();
    if ($isError) {
      if ($params['trxn_result_code']) {
        return self::error($mpgResponse);
      }
      else {
        return self::error('No reply from server - check your settings &/or try again');
      }
    }

    $result = self::checkResult($mpgResponse);
    if (is_a($result, 'CRM_Core_Error')) {
      return $result;
    }

    /* Success */
    $params['trxn_result_code'] = (integer) $mpgResponse->getResponseCode();
    // todo: above assignment seems to be ignored, not getting stored in the civicrm_financial_trxn table
    $params['trxn_id'] = $mpgResponse->getTxnNumber();
    $params['gross_amount'] = $mpgResponse->getTransAmount();
    // add a recurring payment schedule if requested
    // NOTE: recurring payments will be scheduled for the 20th, TODO: make configurable
    if ($isRecur && MONERIS_DO_RECURRING) {
      //Recur Variables
      $recurUnit     = $params['frequency_unit'];
      $recurInterval = $params['frequency_interval'];
      $day           = 60 * 60 * 24;
      $next          = time();
      // earliest start date is tomorrow
      do {
        $next = $next + $day;
        $date = getdate($next);
      } while ($date['mday'] != 20);
      // next payment in moneris required format
      $startDate = date("Y/m/d", $next);
      $numRecurs = !empty($params['installments']) ? $params['installments'] : 99;
      //$startNow = 'true'; -- setting start now to false will mean the main transaction doesn't happen!
      $recurAmount = sprintf('%01.2f', $amount);
      //Create an array with the recur variables
      $recurArray = array(
        'recur_unit' => $recurUnit,
        'start_date' => $startDate,
        'num_recurs' => $numRecurs,
        'start_now' => 'false',
        'period' => $recurInterval,
        'recur_amount' => $recurAmount,
        'amount' => $recurAmount,
      );
      $txnArray['type'] = 'purchase';
      list($isError, $mpgResponse) = $this->_monerisAPI->sendRecurTransaction($txnArray, $recurArray);

      $params['trxn_result_code'] = $mpgResponse->getResponseCode();
      if ($isError) {
        if ($params['trxn_result_code']) {
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
      $params['trxn_result_code'] = (integer) $mpgResponse->getResponseCode();
      $params['trxn_id'] = $mpgResponse->getTxnNumber();
      $params['gross_amount'] = $mpgResponse->getTransAmount();
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

    if (empty($this->_paymentProcessor['signature'])) {
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
