<?php

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

}

?>
