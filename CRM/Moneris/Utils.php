<?php

class CRM_Moneris_Utils {

  static function mpgRequest($mpgTxn, $mode = 'test') {
    $mpgRequest = new mpgRequest($mpgTxn);
    $mpgRequest->setProcCountryCode("CA"); //"US" for sending transaction to US environment, we might want to support it one day
    if ($mode == 'test') {
      $mpgRequest->setTestMode(true); //false or comment out this line for production transactions
    }
    return $mpgRequest;
  }

  static function mpgHttpsPost($storeid, $apitoken, $mpgTxn) {
    require_once 'CRM/Moneris/mpgClasses.php';

    //create a transaction object passing the hash created above
    $mpgTxn = new mpgTransaction($txnArray);

    $mpgRequest = self::mpgRequest($mpgTxn);
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
    return TRUE;
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

  // ignore for now, more elaborate error handling later.
  static function &checkResult(&$response) {
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

}

?>
