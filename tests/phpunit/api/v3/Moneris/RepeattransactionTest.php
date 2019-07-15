<?php

/**
 * Moneris.Repeattransaction API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Moneris_RepeattransactionTest extends api_v3_Moneris_BaseTest {

  //use \Civi\Test\Api3DocTrait;

  protected $_params;

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   */
  public function setUpHeadless() {
    parent::setUpHeadless();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();

    $this->_params = array(
      'contact_id' => $this->_individualId,
      'receive_date' => '20120511',
      'total_amount' => 10.00,
      'currency' => 'CAD',
      'financial_type_id' => $this->_financialTypeId,
      'non_deductible_amount' => 10.00,
      'fee_amount' => 0.00,
      'net_amount' => 10.00,
      'source' => 'SSF',
      'contribution_status_id' => 1,
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
   * Test repeat contribution successfully creates line item.
   */
  public function testRepeatTransaction() {
    $originalContribution = $this->setUpRepeatTransaction($recurParams = array(), 'single');
    print_r($originalContribution);
    $res = $this->callAPISuccess('contribution', 'repeattransaction', array(
      'original_contribution_id' => $originalContribution['id'],
      'contribution_status_id' => 'Completed',
      'trxn_id' => uniqid(),
    ));
    $lineItemParams = array(
      'entity_id' => $originalContribution['id'],
      'sequential' => 1,
      'return' => array(
        'entity_table',
        'qty',
        'unit_price',
        'line_total',
        'label',
        'financial_type_id',
        'deductible_amount',
        'price_field_value_id',
        'price_field_id',
      ),
    );
    print_r(civicrm_api3('line_item', 'get', array_merge($lineItemParams, array(
      'entity_id' => $originalContribution['id'] + 1,
    ))));
    $lineItem1 = $this->callAPISuccess('line_item', 'get', array_merge($lineItemParams, array(
      'entity_id' => $originalContribution['id'],
    )));
    $lineItem2 = $this->callAPISuccess('line_item', 'get', array_merge($lineItemParams, array(
      'entity_id' => $originalContribution['id'] + 1,
    )));
    print_r($lineItem1);
    print_r($lineItem2);

    unset($lineItem1['values'][0]['id'], $lineItem1['values'][0]['entity_id']);
    unset($lineItem2['values'][0]['id'], $lineItem2['values'][0]['entity_id']);
    $this->assertEquals($lineItem1['values'][0], $lineItem2['values'][0]);
    /*$this->_checkFinancialRecords(array(
      'id' => $originalContribution['id'] + 1,
      'payment_instrument_id' => $this->callAPISuccessGetValue('PaymentProcessor', array(
        'id' => $originalContribution['payment_processor_id'],
        'return' => 'payment_instrument_id',
      )),
    ), 'online');*/
    $this->quickCleanUpFinancialEntities();
  }

  /**
   * Set up a repeat transaction.
   *
   * @param array $recurParams
   *
   * @return array
   */
  protected function setUpRepeatTransaction($recurParams = array(), $flag, $contributionParams = array()) {
    $params = array_merge($this->_params, array(
      'installments' => '12',
      'frequency_interval' => '1',
      'amount' => $this->_params['total_amount'],
      'frequency_unit' => 'day',
      'payment_processor_id' => $this->paymentProcessorID,
    ));
    $contributionRecur = $this->callAPISuccess('contribution_recur', 'create', array_merge(
      $params, $recurParams));
    print_r(array_merge($params, $recurParams));

    $originalContribution = '';
    if ($flag == 'multiple') {
      // CRM-19309 create a contribution + also add in line_items (plural):
      $params = array_merge($this->_params, $contributionParams);
      $originalContribution = $this->callSuccess('contribution', 'create', array_merge(
          $params,
          array(
            'contribution_recur_id' => $contributionRecur['id'],
            'skipLineItem' => 1,
            'api.line_item.create' => array(
              array(
                'price_field_id' => 1,
                'qty' => 2,
                'line_total' => '7',
                'unit_price' => '3.50',
                'financial_type_id' => 1,
              ),
              array(
                'price_field_id' => 1,
                'qty' => 1,
                'line_total' => '3',
                'unit_price' => '3',
                'financial_type_id' => 2,
              ),
            ),
          )
        )
      );
    }
    elseif ($flag == 'single') {
      $params = array_merge($this->_params, array('contribution_recur_id' => $contributionRecur['id']));
      $params = array_merge($params, $contributionParams);
      //$originalContribution = $this->callAPISuccess('contribution', 'create', $params);
      print_r($params);
      $originalContribution = civicrm_api3('contribution', 'create', $params);
    }
    $originalContribution['payment_processor_id'] = $this->paymentProcessorID;
    return $originalContribution;
  }

}
