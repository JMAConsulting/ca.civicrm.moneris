<?php

// FIXME: replace opy/paste most function from CiviUnitTestCase with a extends ?
// doesn't seems to work, why ? == '_populateDB requires CIVICRM_UF=UnitTests' even with :
// env CIVICRM_UF=UnitTests phpunit4 ./tests/phpunit/api/v3/Moneris/RepeattransactionTest.phpICRM_UF=UnitTests'


use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Payment\System;

/**
 * Moneris.Repeattransaction API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Moneris_BaseTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3DocTrait;

  /**
   * Assume empty database with just civicrm_data.
   */
  protected $_individualId;
  protected $_financialTypeId = 1;
  protected $_apiversion = 3;
  protected $_pageParams = array();

  /**
   * Track tables we have modified during a test.
   */
  protected $_tablesToTruncate = array();

  private $tx = NULL;

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();

    $session = CRM_Core_Session::singleton();
    $session->set('userID', NULL);

    $this->_individualId = $this->individualCreate();
    $this->paymentProcessorID = $this->paymentProcessorCreate();
    $this->_pageParams = array(
      'title' => 'Test Contribution Page',
      'financial_type_id' => 1,
      'currency' => 'CAD',
      'financial_account_id' => 1,
      'payment_processor' => $this->paymentProcessorID,
      'is_active' => 1,
      'is_allow_other_amount' => 1,
      'min_amount' => 10,
      'max_amount' => 1000,
    );
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   */
  public function testApiExample() {
    //$result = civicrm_api3('Moneris', 'Repeattransaction', array('magicword' => 'sesame'));
    //$this->assertEquals('Twelve', $result['values'][12]['name']);
  }

  /**
   * Create an instance of the paypal processor.
   * @todo this isn't a great place to put it - but really it belongs on a class that extends
   * this parent class & we don't have a structure for that yet
   * There is another function to this effect on the PaypalPro test but it appears to be silently failing
   * & the best protection against that is the functions this class affords
   * @param array $params
   * @return int $result['id'] payment processor id
   */
  public function paymentProcessorCreate($params = array()) {
    $params = array_merge(array(
        'name' => 'demo',
        'domain_id' => CRM_Core_Config::domainID(),
        'payment_processor_type_id' => 'Moneris',
        'is_active' => 1,
        'is_default' => 0,
        'is_test' => 1,
        'user_name' => 'store5',
        'password' => 'yesguy',
        'signature' => NULL,
        'url_site' => 'https://esqa.moneris.com/',
        'url_api' => NULL,
        'url_button' => NULL,
        'class_name' => 'Payment_Moneris',
        'billing_mode' => 3,
        'financial_type_id' => 1,
        'financial_account_id' => 12,
        'payment_instrument_id' => 'Credit Card',
      ),
      $params);
    if (!is_numeric($params['payment_processor_type_id'])) {
      // really the api should handle this through getoptions but it's not exactly api call so lets just sort it
      //here
      $params['payment_processor_type_id'] = $this->callAPISuccess('payment_processor_type', 'getvalue', array(
        'name' => $params['payment_processor_type_id'],
        'return' => 'id',
      ), 'integer');
    }
    $result = $this->callAPISuccess('payment_processor', 'create', $params);
    return $result['id'];
  }

  /**
   * Generic function to create Individual, to be used in test cases
   *
   * @param array $params
   *   parameters for civicrm_contact_add api function call
   * @param int $seq
   *   sequence number if creating multiple individuals
   * @param bool $random
   *
   * @return int
   *   id of Individual created
   */
  public function individualCreate($params = array(), $seq = 0, $random = FALSE) {
    $params = array_merge($this->sampleContact('Individual', $seq, $random), $params);
    return $this->_contactCreate($params);
  }

  /**
   * Helper function for getting sample contact properties.
   *
   * @param string $contact_type
   *   enum contact type: Individual, Organization
   * @param int $seq
   *   sequence number for the values of this type
   *
   * @return array
   *   properties of sample contact (ie. $params for API call)
   */
  public function sampleContact($contact_type, $seq = 0, $random = FALSE) {
    $samples = array(
      'Individual' => array(
        // The number of values in each list need to be coprime numbers to not have duplicates
        'first_name' => array('Anthony', 'Joe', 'Terrence', 'Lucie', 'Albert', 'Bill', 'Kim'),
        'middle_name' => array('J.', 'M.', 'P', 'L.', 'K.', 'A.', 'B.', 'C.', 'D', 'E.', 'Z.'),
        'last_name' => array('Anderson', 'Miller', 'Smith', 'Collins', 'Peterson'),
      ),
      'Organization' => array(
        'organization_name' => array(
          'Unit Test Organization',
          'Acme',
          'Roberts and Sons',
          'Cryo Space Labs',
          'Sharper Pens',
        ),
      ),
      'Household' => array(
        'household_name' => array('Unit Test household'),
      ),
    );
    $params = array('contact_type' => $contact_type);
    foreach ($samples[$contact_type] as $key => $values) {
      $params[$key] = $values[$seq % count($values)];
      if ($random) {
        $params[$key] .= substr(sha1(rand()), 0, 5);
      }
    }
    if ($contact_type == 'Individual') {
      $params['email'] = strtolower(
        $params['first_name'] . '_' . $params['last_name'] . '@civicrm.org'
      );
      $params['prefix_id'] = 3;
      $params['suffix_id'] = 3;
    }
    return $params;
  }

  /**
   * Private helper function for calling civicrm_contact_add.
   *
   * @param array $params
   *   For civicrm_contact_add api function call.
   *
   * @throws Exception
   *
   * @return int
   *   id of Household created
   */
  private function _contactCreate($params) {
    $result = $this->callAPISuccess('contact', 'create', $params);
    if (!empty($result['is_error']) || empty($result['id'])) {
      throw new Exception('Could not create test contact, with message: ' . CRM_Utils_Array::value('error_message', $result) . "\nBacktrace:" . CRM_Utils_Array::value('trace', $result));
    }
    return $result['id'];
  }

  /**
   * Emulate a logged in user since certain functions use that.
   * value to store a record in the DB (like activity)
   * CRM-8180
   *
   * @return int
   *   Contact ID of the created user.
   */
  public function createLoggedInUser() {
    $params = array(
      'first_name' => 'Logged In',
      'last_name' => 'User ' . rand(),
      'contact_type' => 'Individual',
    );
    $contactID = $this->individualCreate($params);
    $this->callAPISuccess('UFMatch', 'create', array(
      'contact_id' => $contactID,
      'uf_name' => 'superman',
      'uf_id' => 6,
    ));

    $session = CRM_Core_Session::singleton();
    $session->set('userID', $contactID);
    return $contactID;
  }

  /**
   * @param array $params
   * @param $context
   */
  public function _checkFinancialRecords($params, $context) {
    $entityParams = array(
      'entity_id' => $params['id'],
      'entity_table' => 'civicrm_contribution',
    );
    $contribution = $this->callAPISuccess('contribution', 'getsingle', array('id' => $params['id']));
    $this->assertEquals($contribution['total_amount'] - $contribution['fee_amount'], $contribution['net_amount']);
    if ($context == 'pending') {
      $trxn = CRM_Financial_BAO_FinancialItem::retrieveEntityFinancialTrxn($entityParams);
      $this->assertNull($trxn, 'No Trxn to be created until IPN callback');
      return;
    }
    $trxn = current(CRM_Financial_BAO_FinancialItem::retrieveEntityFinancialTrxn($entityParams));
    $trxnParams = array(
      'id' => $trxn['financial_trxn_id'],
    );
    if ($context != 'online' && $context != 'payLater') {
      $compareParams = array(
        'to_financial_account_id' => 6,
        'total_amount' => CRM_Utils_Array::value('total_amount', $params, 100),
        'status_id' => 1,
      );
    }
    if ($context == 'feeAmount') {
      $compareParams['fee_amount'] = 50;
    }
    elseif ($context == 'online') {
      $compareParams = array(
        'to_financial_account_id' => 12,
        'total_amount' => CRM_Utils_Array::value('total_amount', $params, 100),
        'status_id' => 1,
        'payment_instrument_id' => CRM_Utils_Array::value('payment_instrument_id', $params, 1),
      );
    }
    elseif ($context == 'payLater') {
      $compareParams = array(
        'to_financial_account_id' => 7,
        'total_amount' => CRM_Utils_Array::value('total_amount', $params, 100),
        'status_id' => 2,
      );
    }
    $this->assertDBCompareValues('CRM_Financial_DAO_FinancialTrxn', $trxnParams, $compareParams);
    $entityParams = array(
      'financial_trxn_id' => $trxn['financial_trxn_id'],
      'entity_table' => 'civicrm_financial_item',
    );
    $entityTrxn = current(CRM_Financial_BAO_FinancialItem::retrieveEntityFinancialTrxn($entityParams));
    $fitemParams = array(
      'id' => $entityTrxn['entity_id'],
    );
    $compareParams = array(
      'amount' => CRM_Utils_Array::value('total_amount', $params, 100),
      'status_id' => 1,
      'financial_account_id' => CRM_Utils_Array::value('financial_account_id', $params, 1),
    );
    if ($context == 'payLater') {
      $compareParams = array(
        'amount' => CRM_Utils_Array::value('total_amount', $params, 100),
        'status_id' => 3,
        'financial_account_id' => CRM_Utils_Array::value('financial_account_id', $params, 1),
      );
    }
    $this->assertDBCompareValues('CRM_Financial_DAO_FinancialItem', $fitemParams, $compareParams);
    if ($context == 'feeAmount') {
      $maxParams = array(
        'entity_id' => $params['id'],
        'entity_table' => 'civicrm_contribution',
      );
      $maxTrxn = current(CRM_Financial_BAO_FinancialItem::retrieveEntityFinancialTrxn($maxParams, TRUE));
      $trxnParams = array(
        'id' => $maxTrxn['financial_trxn_id'],
      );
      $compareParams = array(
        'to_financial_account_id' => 5,
        'from_financial_account_id' => 6,
        'total_amount' => 50,
        'status_id' => 1,
      );
      $trxnId = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($params['id'], 'DESC');
      $this->assertDBCompareValues('CRM_Financial_DAO_FinancialTrxn', $trxnParams, $compareParams);
      $fitemParams = array(
        'entity_id' => $trxnId['financialTrxnId'],
        'entity_table' => 'civicrm_financial_trxn',
      );
      $compareParams = array(
        'amount' => 50,
        'status_id' => 1,
        'financial_account_id' => 5,
      );
      $this->assertDBCompareValues('CRM_Financial_DAO_FinancialItem', $fitemParams, $compareParams);
    }
    // This checks that empty Sales tax rows are not being created. If for any reason it needs to be removed the
    // line should be copied into all the functions that call this function & evaluated there
    // Be really careful not to remove or bypass this without ensuring stray rows do not re-appear
    // when calling completeTransaction or repeatTransaction.
    $this->callAPISuccessGetCount('FinancialItem', array('description' => 'Sales Tax', 'amount' => 0), 0);
  }

  /**
   * Compare all values in a single retrieved DB record to an array of expected values.
   * @param string $daoName
   * @param array $searchParams
   * @param $expectedValues
   */
  public function assertDBCompareValues($daoName, $searchParams, $expectedValues) {
    //get the values from db
    $dbValues = array();
    CRM_Core_DAO::commonRetrieve($daoName, $searchParams, $dbValues);

    // compare db values with expected values
    self::assertAttributesEquals($expectedValues, $dbValues);
  }

  /**
   * Assert attributes are equal.
   *
   * @param $expectedValues
   * @param $actualValues
   * @param string $message
   *
   * @throws PHPUnit_Framework_AssertionFailedError
   */
  public function assertAttributesEquals($expectedValues, $actualValues, $message = NULL) {
    foreach ($expectedValues as $paramName => $paramValue) {
      if (isset($actualValues[$paramName])) {
        $this->assertEquals($paramValue, $actualValues[$paramName], "Value Mismatch On $paramName - value 1 is " . print_r($paramValue, TRUE) . "  value 2 is " . print_r($actualValues[$paramName], TRUE));
      }
      else {
        $this->assertNull($expectedValues[$paramName], "Attribute '$paramName' not present in actual array and we expected it to be " . $expectedValues[$paramName]);
      }
    }
  }

  /**
   * Clean up financial entities after financial tests (so we remember to get all the tables :-))
   */
  public function quickCleanUpFinancialEntities() {
    $tablesToTruncate = array(
      'civicrm_activity',
      'civicrm_activity_contact',
      'civicrm_contribution',
      'civicrm_contribution_soft',
      'civicrm_contribution_product',
      'civicrm_financial_trxn',
      'civicrm_financial_item',
      'civicrm_contribution_recur',
      'civicrm_line_item',
      'civicrm_contribution_page',
      'civicrm_payment_processor',
      'civicrm_entity_financial_trxn',
      'civicrm_membership',
      'civicrm_membership_type',
      'civicrm_membership_payment',
      'civicrm_membership_log',
      'civicrm_membership_block',
      'civicrm_event',
      'civicrm_participant',
      'civicrm_participant_payment',
      'civicrm_pledge',
      'civicrm_pledge_payment',
      'civicrm_price_set_entity',
      'civicrm_price_field_value',
      'civicrm_price_field',
    );
    $this->quickCleanup($tablesToTruncate);
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_membership_status WHERE name NOT IN('New', 'Current', 'Grace', 'Expired', 'Pending', 'Cancelled', 'Deceased')");
    $this->restoreDefaultPriceSetConfig();
    $var = TRUE;
    CRM_Member_BAO_Membership::createRelatedMemberships($var, $var, TRUE);
    $this->disableTaxAndInvoicing();
    $this->setCurrencySeparators(',');
    CRM_Core_PseudoConstant::flush('taxRates');
    System::singleton()->flushProcessors();
  }

  /**
   * Quick clean by emptying tables created for the test.
   *
   * @param array $tablesToTruncate
   * @param bool $dropCustomValueTables
   * @throws \Exception
   */
  public function quickCleanup($tablesToTruncate, $dropCustomValueTables = FALSE) {
    if ($this->tx) {
      throw new Exception("CiviUnitTestCase: quickCleanup() is not compatible with useTransaction()");
    }
    if ($dropCustomValueTables) {
      $optionGroupResult = CRM_Core_DAO::executeQuery('SELECT option_group_id FROM civicrm_custom_field');
      while ($optionGroupResult->fetch()) {
        if (!empty($optionGroupResult->option_group_id)) {
          CRM_Core_DAO::executeQuery('DELETE FROM civicrm_option_group WHERE id = ' . $optionGroupResult->option_group_id);
        }
      }
      $tablesToTruncate[] = 'civicrm_custom_group';
      $tablesToTruncate[] = 'civicrm_custom_field';
    }

    $tablesToTruncate = array_unique(array_merge($this->_tablesToTruncate, $tablesToTruncate));

    CRM_Core_DAO::executeQuery("SET FOREIGN_KEY_CHECKS = 0;");
    foreach ($tablesToTruncate as $table) {
      $sql = "TRUNCATE TABLE $table";
      CRM_Core_DAO::executeQuery($sql);
    }
    CRM_Core_DAO::executeQuery("SET FOREIGN_KEY_CHECKS = 1;");

    if ($dropCustomValueTables) {
      $dbName = self::getDBName();
      $query = "
SELECT TABLE_NAME as tableName
FROM   INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = '{$dbName}'
AND    ( TABLE_NAME LIKE 'civicrm_value_%' )
";

      $tableDAO = CRM_Core_DAO::executeQuery($query);
      while ($tableDAO->fetch()) {
        $sql = "DROP TABLE {$tableDAO->tableName}";
        CRM_Core_DAO::executeQuery($sql);
      }
    }
  }

  /**
   * @return string
   */
  public static function getDBName() {
    static $dbName = NULL;
    if ($dbName === NULL) {
      require_once "DB.php";
      $dsninfo = DB::parseDSN(CIVICRM_DSN);
      $dbName = $dsninfo['database'];
    }
    return $dbName;
  }

  public function restoreDefaultPriceSetConfig() {
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_price_set WHERE name NOT IN('default_contribution_amount', 'default_membership_type_amount')");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_price_set SET id = 1 WHERE name ='default_contribution_amount'");
    CRM_Core_DAO::executeQuery("INSERT INTO `civicrm_price_field` (`id`, `price_set_id`, `name`, `label`, `html_type`, `is_enter_qty`, `help_pre`, `help_post`, `weight`, `is_display_amounts`, `options_per_line`, `is_active`, `is_required`, `active_on`, `expire_on`, `javascript`, `visibility_id`) VALUES (1, 1, 'contribution_amount', 'Contribution Amount', 'Text', 0, NULL, NULL, 1, 1, 1, 1, 1, NULL, NULL, NULL, 1)");
    CRM_Core_DAO::executeQuery("INSERT INTO `civicrm_price_field_value` (`id`, `price_field_id`, `name`, `label`, `description`, `amount`, `count`, `max_value`, `weight`, `membership_type_id`, `membership_num_terms`, `is_default`, `is_active`, `financial_type_id`, `non_deductible_amount`) VALUES (1, 1, 'contribution_amount', 'Contribution Amount', NULL, '1', NULL, NULL, 1, NULL, NULL, 0, 1, 1, 0.00)");
  }

  /**
   * Enable Tax and Invoicing
   */
  protected function enableTaxAndInvoicing($params = array()) {
    // Enable component contribute setting
    $contributeSetting = array_merge($params,
      array(
        'invoicing' => 1,
        'invoice_prefix' => 'INV_',
        'credit_notes_prefix' => 'CN_',
        'due_date' => 10,
        'due_date_period' => 'days',
        'notes' => '',
        'is_email_pdf' => 1,
        'tax_term' => 'Sales Tax',
        'tax_display_settings' => 'Inclusive',
      )
    );
    return Civi::settings()->set('contribution_invoice_settings', $contributeSetting);
  }

  /**
   * Disable Tax and Invoicing
   */
  protected function disableTaxAndInvoicing($params = array()) {
    if (!empty(\Civi::$statics['CRM_Core_PseudoConstant']) && isset(\Civi::$statics['CRM_Core_PseudoConstant']['taxRates'])) {
      unset(\Civi::$statics['CRM_Core_PseudoConstant']['taxRates']);
    }
    // Enable component contribute setting
    $contributeSetting = array_merge($params,
      array(
        'invoicing' => 0,
      )
    );
    return Civi::settings()->set('contribution_invoice_settings', $contributeSetting);
  }

  /**
   * Get possible thousand separators.
   *
   * @return array
   */
  public function getThousandSeparators() {
    return array(array('.'), array(','));
  }

  /**
   * Set the separators for thousands and decimal points.
   *
   * @param string $thousandSeparator
   */
  protected function setCurrencySeparators($thousandSeparator) {
    Civi::settings()->set('monetaryThousandSeparator', $thousandSeparator);
    Civi::settings()
      ->set('monetaryDecimalPoint', ($thousandSeparator === ',' ? '.' : ','));
  }

  /**
   * Format money as it would be input.
   *
   * @param string $amount
   *
   * @return string
   */
  protected function formatMoneyInput($amount) {
    return CRM_Utils_Money::format($amount, NULL, '%a');
  }

}
