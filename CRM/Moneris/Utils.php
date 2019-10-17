<?php

//use CRM_ProvInvoice_ExtensionUtil as E;
use CRM_Moneris_ExtensionUtil as E;
class CRM_Moneris_Utils {

  /**
   * Verify the credit card associated with the $token
   * Useful when we just want to ensure the card is good but don't want to process the payment right away
   *
   * @param  string $token   data_key required by Moneris
   * @param  string $orderid internal order id for future reference
   * @return mpgResponse          If any error, return a CRM_Core_Error
   */
  public function cardVerification($processor, $token, $orderid) {
    require_once 'CRM/Moneris/mpgClasses.php';

    $txnArray = array(
      'type' => 'res_card_verification_cc',
      'data_key' => $token,
      'order_id' => $orderid,
      'crypt_type' => '7',
    );

    //create a transaction object passing the hash created above
    $mpgTxn = new mpgTransaction($txnArray);
    return self::mpgHttpsRequestPost($processor->_profile['storeid'], $processor->_profile['apitoken'], $mpgTxn, $processor->_profile['server']);

  }

  /**
   * Do a one time Moneris vault payment using the token given
   *
   * @param  string $token   data_key required by Moneris
   * @param  string $orderid internal order id for future reference
   * @param  float $amount  amount to be charged
   * @param  array $params  extra details, e.g. cust_info (mpgCustInfo object)
   * @return mpgResponse          If any error, return a CRM_Core_Error
   */
  public function processTokenPayment($processor, $token, $orderid, $amount, $params = array()) {
    require_once 'CRM/Moneris/mpgClasses.php';

    $txnArray = array(
      'type' => 'res_purchase_cc',
      'data_key' => $token,
      'order_id' => $orderid,
      'amount' => $amount,
      'crypt_type' => '7',
      // 'cust_id' => $params['contactID'],
    );

    //create a transaction object passing the hash created above
    $mpgTxn = new mpgTransaction($txnArray);
    // add customer information if any
    if (!empty($params['cust_info'])) {
      $mpgTxn->setCustInfo($params['cust_info']);
    }

    return self::mpgHttpsRequestPost($processor->_profile['storeid'], $processor->_profile['apitoken'], $mpgTxn, $processor->_profile['server']);

  }

  /**
   * Internal helper that creates a mpgRequest based on the given mpgTransaction in test/prod mode
   *
   * @param  mpgTransaction $mpgTxn transaction that will be used for the request
   * @param  string $mode   'test' (default) or 'prod'
   * @return mpgRequest         return the initialized mpgRequest
   */
  static function mpgRequest($mpgTxn, $mode) {
    $mpgRequest = new mpgRequest($mpgTxn);
    $mpgRequest->setProcCountryCode("CA"); //"US" for sending transaction to US environment, we might want to support it one day
    if ($mode == 'test') {
      $mpgRequest->setTestMode(true); //false or comment out this line for production transactions
    }
    return $mpgRequest;
  }

  static function mpgHttpsRequestPost($storeid, $apitoken, $mpgTxn, $mode) {
    require_once 'CRM/Moneris/mpgClasses.php';

    $mpgRequest = self::mpgRequest($mpgTxn, $mode);
    $mpgHttpPost = new mpgHttpsPost($storeid, $apitoken, $mpgRequest);

    // get an mpgResponse object
    $mpgResponse = $mpgHttpPost->getMpgResponse();
    $responseCode = $mpgResponse->getResponseCode();

    if (self::isError($mpgResponse)) {
      if ($responseCode) {
        static::log($mpgResponse);
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
    static::log($mpgResponse);
    return $mpgResponse;
  }


  static function isError(&$response) {
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

  static function &error($error = NULL) {
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

  static function &checkResult(&$response) {
    if (!$response->getComplete()) {
      $e = CRM_Core_Error::singleton();
      $e->push(9999, 0, NULL, $response->getMessage());
      return $e;
    }

    return $response;
  }


  /**
   * Generates a human-readable receipt using the purchase response from Netbanx.
   *
   * @param $params Array of form data.
   * @param $response Array of the Netbanx response.
   */
  static function generateReceipt($params, $mpgResponse) {
    $receipt = '';
    $receipt .= static::getNameAndAddress() . "\n\n";
    $receipt .= E::ts('CREDIT CARD TRANSACTION RECORD') . "\n\n";
    $trxnDate = $mpgResponse->getTransDate();
    if (isset($trxnDate)) {
      $receipt .= E::ts('Date: %1 %2', array(1 => $trxnDate, 2 => $mpgResponse->getTransTime())) . "\n";
    }
    else {
      $receipt .= E::ts('Date: %1', array(1 => date('Y-m-d H:i:s'))) . "\n";
    }

    $receipt .= E::ts('Receipt Id: %1', array(1 => $mpgResponse->getReceiptId())) . "\n";
    $receipt .= E::ts('Reference Number: %1', array(1 => $mpgResponse->getReferenceNum())) . "\n";
    $receipt .= E::ts('Type: %1', array(1 => static::getTransactionType($mpgResponse->getTransType()))) . "\n";
    $receipt .= E::ts('Authorization: %1', array(1 => $mpgResponse->getAuthCode())) . "\n";
    $receipt .= E::ts('Credit card type: %1', array(1 => static::getCardType($mpgResponse->getCardType()))) . "\n";
    //$receipt .= E::ts('Credit card holder name: %1', array(1 => $params['first_name'] . ' ' . $params['last_name'])) . "\n";
    $receipt .= E::ts('Credit card number: %1', array(1 => $mpgResponse->getResDataMaskedPan())) . "\n\n";
    $receipt .= E::ts('Transaction amount: %1', array(1 => CRM_Utils_Money::format($mpgResponse->getTransAmount()))) . "\n\n";

    if (static::isError($mpgResponse)) {
      $receipt .= E::ts('TRANSACTION FAILED') . "\n\n";
      // We are not supposed to display the message given in $mpgResponse->getMessage()
    }
    else {
      $receipt .= E::ts('TRANSACTION APPROVED - THANK YOU') . "\n\n";
    }

    $receipt .= "\n";
    $receipt .= E::ts('Prices are in canadian dollars ($ CAD).') . "\n";
    $receipt .= E::ts('Please find the details of your transaction in the invoice');


    // save in db
    $token_id = isset($params['payment_token_id']) ? $params['payment_token_id'] : '';
    try {
      CRM_Core_DAO::executeQuery('
INSERT INTO civicrm_moneris_receipt (trxn_id, trxn_type, reference, receipt_msg, card_type, card_number, timestamp, token_id)
VALUES (%1, %2, %3, %4, %5, %6, %7, %8)',
      array(
        1 => array($mpgResponse->getReceiptId(), 'String'),
        2 => array($mpgResponse->getTransType(), 'String'),
        3 => array($mpgResponse->getReferenceNum(), 'String'),
        4 => array($receipt, 'String'),
        5 => array($mpgResponse->getCardType(), 'String'),
        // FIXME: we have 4 first and 4 last but we should keep only 4 last
        6 => array($mpgResponse->getResDataMaskedPan(), 'String'),
        7 => array(time(), 'Integer'),
        8 => array($token_id, 'String'),
      ));
    }
    catch (Exception $e) {
      // failsafe - at least get the receipt saved
      Civi::log()->debug('Moneris:: Fail to save receipt -- ' . $receipt);
      CRM_Core_DAO::executeQuery('INSERT INTO civicrm_moneris_receipt (trxn_id, reference, receipt_msg) VALUES (%1, %2, %3)', array(
        1 => array($mpgResponse->getReceiptId(), 'String'),
        2 => array($mpgResponse->getReferenceNum(), 'String'),
        3 => array($receipt, 'String'),
      ));

    }

    return $receipt;
  }


  static function log($mpgResponse, $params = array()) {
    $token_id = isset($params['payment_token_id']) ? $params['payment_token_id'] : '';
    try {
      CRM_Core_DAO::executeQuery('
INSERT INTO civicrm_moneris_log (trxn_id, trxn_type, reference, card_type, card_number, timestamp, token_id, response_code, message)
VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)',
      array(
        1 => array($mpgResponse->getReceiptId(), 'String'),
        2 => array($mpgResponse->getTransType(), 'String'),
        3 => array($mpgResponse->getReferenceNum(), 'String'),
        4 => array($mpgResponse->getCardType(), 'String'),
        // FIXME: we have 4 first and 4 last but we should keep only 4 last
        5 => array($mpgResponse->getResDataMaskedPan(), 'String'),
        6 => array(time(), 'Integer'),
        7 => array($token_id, 'String'),
        8 => array($mpgResponse->getResponseCode(), 'String'),
        9 => array($mpgResponse->getMessage(), 'String'),
      ));
    }
    catch (Exception $e) {
      Civi::log()->debug('Moneris:: Fail to log -- ' . print_r($mpgResponse,1));
    }
  }


  // for possible values, see
  // https://developer.moneris.com/Documentation/NA/E-Commerce%20Solutions/API/Response%20Fields

  static function getTransactionType($type) {
    switch ($type) {
      case '00': return E::ts('Purchase'); break;
      case '01': return E::ts('Pre-Authorization'); break;
      case '02': return E::ts('Pre-Authorization completion'); break;
      case '04': return E::ts('Refund'); break;
      case '11': return E::ts('Purchase correction'); break;
    }
    return '';
  }

  static function getCardType($type) {
    switch ($type) {
      case 'M': return E::ts('Mastercard'); break;
      case 'V': return E::ts('Visa'); break;
      case 'AM': return E::ts('American Express'); break;
      case 'D': return E::ts('Debit'); break;
    }
    return '';
  }

  /**
   * Returns the org's name and address
   */
  function getNameAndAddress() {
    $receipt = '';
    // Fetch the domain name
    $domain = civicrm_api('Domain', 'get', array('version' => 3));
    $org_name = $domain['values'][1]['name'];

    // get province abbrev
    $province = CRM_Core_DAO::singleValueQuery('SELECT abbreviation FROM civicrm_state_province WHERE id = %1', array(1 => array($domain['values'][1]['domain_address']['state_province_id'], 'Integer')));
    // $country = db_query('SELECT name FROM {civicrm_country} WHERE id = :id', array(':id' => $domain['values'][1]['domain_address']['country_id']))->fetchField();
    $receipt .= $org_name . "\n";
    $receipt .= $domain['values'][1]['domain_address']['street_address'] . "\n";
    $receipt .= $domain['values'][1]['domain_address']['city'] . ', ' . $province;
    return $receipt;
  }

}

?>
