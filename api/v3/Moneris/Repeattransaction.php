<?php

require_once('api/v3/Contribution.php');

use CRM_Moneris_ExtensionUtil as E;

/**
 * Moneris.Repeattransaction API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_moneris_Repeattransaction_spec(&$spec) {
}

/**
 * Moneris.Repeattransaction API
 * Warning: we really should have this in core otherwise, we need to update this
 * copy of Contribution.RepeatTransaction api with a call to hook to allow amount updates
 * could be added to to Core with a proper supportsToken() and only if a token is defined ?
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_moneris_Repeattransaction($params) {
  $input = $ids = array();
  civicrm_api3_verify_one_mandatory($params, NULL, array('contribution_recur_id', 'original_contribution_id'));
  if (empty($params['original_contribution_id'])) {
    //  CRM-19873 call with test mode.
    $params['original_contribution_id'] = civicrm_api3('contribution', 'getvalue', array(
      'return' => 'id',
      'contribution_status_id' => array('IN' => array('Completed')),
      'contribution_recur_id' => $params['contribution_recur_id'],
      'contribution_test' => CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionRecur', $params['contribution_recur_id'], 'is_test'),
      'options' => array('limit' => 1, 'sort' => 'id DESC'),
    ));
  }
  $contribution = new CRM_Contribute_BAO_Contribution();
  $contribution->id = $params['original_contribution_id'];
  if (!$contribution->find(TRUE)) {
    throw new API_Exception(
      'A valid original contribution ID is required', 'invalid_data');
  }
  $original_contribution = clone $contribution;
  $input['payment_processor_id'] = civicrm_api3('contributionRecur', 'getvalue', array(
    'return' => 'payment_processor_id',
    'id' => $contribution->contribution_recur_id,
  ));
  try {
    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new API_Exception('failed to load related objects');
    }

    unset($contribution->id, $contribution->receive_date, $contribution->invoice_id);
    $contribution->receive_date = $params['receive_date'];

    // Specific for Moneris Vault
    // TODO: add condition
    CRM_Moneris_Utils_HookInvoker::singleton()->monerisRecurringPre($params, $contribution);
    // End Specifics

    $passThroughParams = array(
      'trxn_id',
      'total_amount',
      'campaign_id',
      'fee_amount',
      'financial_type_id',
      'contribution_status_id',
    );
    $input = array_intersect_key($params, array_fill_keys($passThroughParams, NULL));

    //return _ipn_process_transaction($params, $contribution, $input, $ids, $original_contribution);
  }
  catch(Exception $e) {
    throw new API_Exception('failed to load related objects' . $e->getMessage() . "\n" . $e->getTraceAsString());
  }
}
