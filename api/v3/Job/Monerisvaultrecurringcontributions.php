<?php
use CRM_Moneris_ExtensionUtil as E;

/**
 * Job.Monerisvaultrecurringcontributions API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_job_Monerisvaultrecurringcontributions_spec(&$spec) {

}

/**
 * Job.Monerisvaultrecurringcontributions API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_Monerisvaultrecurringcontributions($params) {

  // Running this job in parallel could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.monerisvaultrecurringcontributions');
  if (!$lock->acquire()) {
    return civicrm_api3_create_success(ts('Failed to acquire lock. No contribution records were processed.'));
  }
  $sqlparams = [];

  // contribution_status_id: 2=Pending, 5=InProgress
  $sql = "
SELECT
  cr.id as recur_id, cr.contact_id, cr.payment_token_id,
  c.id as original_contribution_id, c.contribution_status_id,
  c.total_amount, c.currency, c.invoice_id,
  pt.token
FROM
  civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON (c.contribution_recur_id = cr.id)
  INNER JOIN civicrm_payment_token pt ON cr.payment_token_id = pt.id
  INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
WHERE
  pp.name = 'Moneris' AND cr.payment_token_id IS NOT NULL
  AND cr.contribution_status_id IN (2,5)";
  // in case the job was called to execute a specific recurring contribution id -- not yet implemented!
  if (!empty($params['recur_id'])) {
    $sql .= ' AND cr.id = %1';
    $sqlparams[1] = [$params['recur_id'], 'Positive'];
  }
  else {
    // normally, process all recurring contributions due today or earlier.
    // FIXME: normally we should use '=', unless catching up.
    // If catching up, we need to manually update the next_sched_contribution_date
    // because CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment() only updates
    // if the receive_date = next_sched_contribution_date.
    $sql .= ' AND (cr.next_sched_contribution_date <= CURDATE()
                OR (cr.next_sched_contribution_date IS NULL AND cr.start_date <= CURDATE()))';
  }

  //$crypt = new CRM_Cardvault_Encrypt();
  $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($params['payment_processor_id'], $params['payment_processor_mode']);
  $counter = 0;
  $error_count = 0;
  $output = [];
  $dao = CRM_Core_DAO::executeQuery($sql, $sqlparams);
  while ($dao->fetch()) {
    // If the initial contribution is pending (2), then we use that
    // invoice_id for the payment processor. Otherwise we generate a new one.
    $invoice_id = NULL;
    if ($dao->contribution_status_id == 2) {
      $invoice_id = $dao->invoice_id;
    }
    else {
      // ex: civicrm_api3_contribution_transact() does somethign similar.
      $invoice_id = sha1(uniqid(rand(), TRUE));
    }
    // Investigate whether we can use the Contribution.transact API call?
    // it seemed a bit trickier to use, because of pricesets/amounts, more lifting
    // than just calling 'repeattransaction'.
    $payment_params = [
      'contactID' => $dao->contact_id,
      'billing_first_name' => '',
      'billing_last_name' => '',
      'amount' => $dao->total_amount,
      'currencyID' => $dao->currency,
      'invoiceID' => $invoice_id,
      'payment_token_id' => $dao->payment_token_id,
      'street_address' => '',
      'city' => '',
      'state_province' => '',
      'country' => '',
    ];

    // create or retrieve the contribution
    $contribution_id = NULL;
    if ($dao->contribution_status_id == 1) {

      // ensure the money is clean before processing
      $payment_params['amount'] = CRM_Utils_Rule::cleanMoney($payment_params['amount']);
      $repeat_params = [
        'contribution_recur_id' => $dao->recur_id,
        'original_contribution_id' => $dao->original_contribution_id,
        'invoice_id' => $invoice_id,
        'contribution_status_id' => 2,  // Pending
        'receive_date' => date('YmdHis'),
      ];

      if (!isset($params['update_amounts_and_taxes']) || !$params['update_amounts_and_taxes']) {
        // don't update so do the standard repeat tranapaction
        $result = civicrm_api3('Contribution', 'repeattransaction', $repeat_params);
        // Presumably there is a good reason why CiviCRM is not storing
        // our new invoice_id. Anyone know?
        $contribution_id = $result['id'];
        civicrm_api3('Contribution', 'create', [
          'id' => $contribution_id,
          'invoice_id' => $invoice_id,
        ]);
      }
      else {
        // update amount and taxes by going through all line items
        // TODO: we must have taxes enabled and extension cdntaxcalculator for taxes updates
        // FIXME: check for extension instead
        if (!class_exists('CRM_Cdntaxcalculator_BAO_CDNTaxes')) {
          throw new CRM_Core_Exception(ts('cdntaxcalculator extension must be enabled to run with params update_amounts_and_taxes=1'));
        }

        // duplicate contribution and update amounts
        $result = _repeat_transaction_with_updates($repeat_params);
        $contribution_id = $result['id'];
        $payment_params['amount'] = CRM_Utils_Rule::cleanMoney($result['total_amount']);

      }
    }
    elseif ($dao->contribution_status_id == 2) {
      // previously created but not processed
      $contribution_id = $dao->original_contribution_id;
    }
    else {
      Civi::log()->warning("Moneris: contribution ID {$dao->original_contribution_id} has an unexpected status: {$dao->contribution_status_id} -- skipping renewal.");
    }

    if ($contribution_id) {
      // update the current contribution
      $payment_params['id'] = $contribution_id;

      // processing the payment
      try {
        CRM_Moneris_Utils::processTokenPayment($paymentProcessor, $dao->token, $invoice_id, $payment_params['amount']);
      }
      catch (PaymentProcessorException $e) {
        Civi::log()->error('Moneris: failed payment: ' . $e->getMessage());
        $payment_params['payment_status_id'] = 4;  // Failed
      }

      //
      civicrm_api3('Contribution', 'create', [
        'id' => $contribution_id,
        'contribution_status_id' => $payment_params['payment_status_id'],
        'trxn_id' => $payment_params['payment_status_id'],
      ]);

      // TODO: update recurring payment status to In Progress ?
    }

  }

}

function _repeat_transaction_with_updates($params) {
  if (empty($params['original_contribution_id'])) {
    throw new CRM_Core_Exception(ts('No original contribution to duplicate'));
  }

  // Inspiration from civicrm_api3_contribution_repeattransaction()
  $contribution = new CRM_Contribute_BAO_Contribution();
  $contribution->id = $params['original_contribution_id'];
  if (!$contribution->find(TRUE)) {
    throw new API_Exception('A valid original contribution ID is required', 'invalid_data');
  }

  $original_contribution = clone $contribution;
  $ids = $input = [];

  $contribution->loadRelatedObjects($input, $ids, TRUE);

  unset($contribution->id, $contribution->receive_date, $contribution->invoice_id, $contribution->trxn_id);

  // Set the contribution status to Pending, since we are not charging yet
  // and receive_date (for now) set to 'today', even if we haven't charged it yet,
  // but we will update this in the API call that processes this.
  $contribution->contribution_status_id = 2; // Pending
  $contribution->receive_date = date('YmdHis');
  foreach ($param as $key => $value) {
    $contribution->$key = $value;
  }
  $contribution->save();

  // now that we have a contribution, let's update it
  $lineitem_result = civicrm_api3('LineItem', 'get', [
    'sequential' => 1,
    // 'entity_table' => 'civicrm_contribution',
    'contribution_id' => $original_contribution->id,
  ]);

  $new_total_amount = 0;
  $new_tax_amount = 0;
  $tax_line_item = NULL;
  foreach ($lineitem_result['values'] as $original_line_item) {
    // fixing line item
    $p = [
      'entity_table' => $original_line_item['entity_table'],
      // FIXME: could we have something different that contribution / membership ? might be a problem
      'entity_id' => ($original_line_item['entity_table'] == 'civicrm_contribution') ? $contribution->id : $original_line_item['entity_id'],
      'contribution_id' => $contribution->id,
      'price_field_id' => $original_line_item['price_field_id'],
      'label' => $original_line_item['label'],
      'qty' => $original_line_item['qty'],
      'unit_price' => $original_line_item['unit_price'],
      'line_total' => $original_line_item['line_total'],
      'participant_count' => $original_line_item['participant_count'],
      'price_field_value_id' => $original_line_item['price_field_value_id'],
      'financial_type_id' => $original_line_item['financial_type_id'],
      'deductible_amount' => $original_line_item['deductible_amount'],
    ];
    // Fetch the current amount of the line item (handle price increases).
    if (!empty($original_line_item['price_field_value_id'])) {
      $pfv = civicrm_api3('PriceFieldValue', 'getsingle', [
        'id' => $original_line_item['price_field_value_id'],
      ]);
      $p['unit_price'] = $pfv['amount'];
      $p['line_total'] = $pfv['amount'] * $p['qty'];
      $taxes = CRM_Cdntaxcalculator_BAO_CDNTaxes::getTaxesForContact($dao->contact_id);
      $p['tax_amount'] = round($p['line_total'] * ($taxes['HST_GST']/100), 2);
      $p['line_total'] += $p['tax_amount'];
    }
    elseif (!$original_line_item['line_total']) {
      // Probably a 0$ item, so it's OK to not have a price_field_value_id
      // and we can just leave the line_total and unit_price empty.
    }
    if (empty($p['line_total'])) {
      $p['line_total'] = '0';
      $p['tax_amount'] = '0';
    }
    $t = civicrm_api3('LineItem', 'create', $p);
    $new_total_amount += $p['line_total'];
    $new_tax_amount += $p['tax_amount'];
  }

  // Update the total amount and taxes on the contribution
  $contribution->total_amount = $new_total_amount + $new_tax_amount;
  $contribution->net_amount = $contribution->total_amount;
  $contribution->non_deductible_amount = $contribution->total_amount;
  $contribution->tax_amount = $new_tax_amount;
  $contribution->save();

  // Fetch all memberships for this contribution and associate the (future) contribution
  // FIXME: not sure about this one
  /*$sql2 = 'SELECT m.contact_id, m.id as membership_id
      FROM civicrm_membership m
      LEFT JOIN civicrm_membership_payment mp ON (mp.membership_id = m.id)
      LEFT JOIN civicrm_contribution contrib ON (contrib.id = mp.contribution_id)
      WHERE m.contact_id = %1
        AND m.status_id = 3'; // FIXME hardcoded status (renewal ready)
  $dao2 = CRM_Core_DAO::executeQuery($sql2, [
    1 => [$dao->contact_id, 'Positive'],
  ]);
  while ($dao2->fetch()) {
    civicrm_api3('MembershipPayment', 'create', [
      'contribution_id' => $contribution->id,
      'membership_id' => $dao2->membership_id,
    ]);
  }*/

  return array(
    'id' => $contribution->id,
    'total_amount' => $new_total_amount,
  );

}
