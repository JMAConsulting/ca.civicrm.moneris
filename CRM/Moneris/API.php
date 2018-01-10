<?php

require_once 'vendor/autoload.php';

/**
 * Class to send Moneris API request
 */
class CRM_Moneris_API {

  /**
   * Instance of this object.
   *
   * @var CRM_Moneris_API
   */
  public static $_singleton = NULL;

  /**
   * Search parameters later formated into API url arguments
   *
   * @var array
   */
  protected $_storeID;

  /**
   * Search parameters later formated into API url arguments
   *
   * @var array
   */
  protected $_apiToken;

  /**
   * Search parameters later formated into API url arguments
   *
   * @var array
   */
  protected $_apiToken;

  /**
   * Search parameters later formated into API url arguments
   *
   * @var array
   */
  protected $_server;

  protected $_custInfo;

  protected $_procCountryCode;

  /**
   * The constructor
   */
  public function __construct($storeID, $apiToken) {
    $this->_storeID = $storeID;
    $this->_apiToken = $apiToken;
    $this->_custInfo = new mpgCustInfo();
  }

  /**
   * Singleton function used to manage this object.
   *
   * @param int $storeID
   * @param string $apiToken
   * @param bool $reset
   *
   * @return CRM_Moneris_API
   */
  public static function &singleton($storeID, $apiToken, $reset = FALSE) {
    if (self::$_singleton === NULL || $reset) {
      self::$_singleton = new CRM_Moneris_API($storeID, $apiToken);
    }
    return self::$_singleton;
  }

  /**
   * Singleton function used to manage this object.
   *
   * @param array $settingParams
   *   Moodle parameters
   *
   * @return CRM_Moneris_API
   */
  public function isTest($mode = TRUE) {
    $this->_isTest = $mode;
    return $this;
  }

  /**
   * Function to call core_user_get_users webservice to fetch moodle user
   */
  public function setCustInfo($params) {
    if (!empty($params['email'])) {
      $this->_custInfo->setEmail($params['email']);
    }

    $billing = array(
      'first_name' => CRM_Utils_Array::value('billing_first_name', $params),
      'last_name' => CRM_Utils_Array::value('billing_last_name', $params),
      'address' => CRM_Utils_Array::value('street_address', $params),
      'city' => CRM_Utils_Array::value('city', $params),
      'province' => CRM_Utils_Array::value('state_province', $params),
      'postal_code' => CRM_Utils_Array::value('postal_code', $params),
      'country' => CRM_Utils_Array::value('country', $params),
    );
    $this->_custInfo->setBilling($billing);
  }

  public function sendTransaction($params) {
    $mpgTxn = new mpgTransaction($params);
    $mpgTxn->setCustInfo($this->_custInfo);

    return $this->sendRequest($mpgTxn);
  }

  public function setProcCountryCode($currencyID) {
    $this->_procCountryCode = ($currencyID == 'USD') ? 'US' : 'CA';
  }

  public function sendRecurTransaction($trxnParams, $recurParams) {
    $mpgRecur = new mpgRecur($recurParams);

    $mpgTxn = new mpgTransaction($trxnParams);
    $mpgTxn->setRecur($mpgRecur);
    $mpgTxn->setCustInfo($this->_custInfo);

    return $this->sendRequest($mpgTxn);
  }

  /**
   * Function used to make Moodle API request
   *
   * @param string $apiFunc
   *   Donor Search API function name
   *
   * @return array
   */
  public function sendRequest($apiObj) {
    $mpgRequest = new mpgRequest($apiObj);
    $mpgRequest->setProcCountryCode($this->_procCountryCode);
    if ($this->_isTest) {
      $this->setTestMode(TRUE);
    }
    $response = new mpgHttpsPost($this->_storeID, $this->_apiToken, $mpgRequest);

    return array(
      self::isError($response),
      $response,
    );
  }

  /**
   * Record error response if there's anything wrong in $response
   *
   * @param string $response
   *   fetched data from Moodle API
   *
   * @return bool
   *   Found error ? TRUE or FALSE
   */
  public static function isError($response) {
    $responseCode = $response->getResponseCode();
    $isError = TRUE;
    if (CRM_Utils_System::isNull($responseCode)) {
      $isError = TRUE;
    }
    elseif (($responseCode >= 0) && ($responseCode < 50)) {
      $isError = FALSE;
    }

    return $isError;
  }


}
