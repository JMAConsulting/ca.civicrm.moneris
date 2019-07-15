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

  // option to update amount and taxes by going through all line items
  // require taxes enabled and extension cdntaxcalculator for taxes updates
  if ($params['update_amounts_and_taxes'] && !class_exists('CRM_Cdntaxcalculator_BAO_CDNTaxes')) {
    throw new CRM_Core_Exception(ts('cdntaxcalculator extension must be enabled to run with params update_amounts_and_taxes=1'));
  }

  // FIXME: CiviCRM send receipt before the payment and the amount seems wrong
  // until we fix this, just throw an exception
  /* if (!isset($params['update_amounts_and_taxes']) || !$params['update_amounts_and_taxes']) {
    throw new CRM_Core_Exception(ts('Only support update_amounts_and_taxes=1 mode'));
  } */

  // Running this job in parallel could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.monerisvaultrecurringcontributions');
  if (!$lock->acquire()) {
    return civicrm_api3_create_success(ts('Failed to acquire lock. No contribution records were processed.'));
  }

  // for logging
  $payments = [];

  // not used, but we could add some params to limit which recurring payment to do
  $sqlparams = [];

  // TODO: strategy for failure ? right now, we stop any recurring after the first failure
  // we might want to have different settings (retry x times every x days?)


  // pre-process: in case the next_schedule_contribution_date is not properly updated
  // we don't want to make several payment in a row for the same recurring
  // if there is a contribution with a receive date greater than the next scheduled date, we can assume the status or date should be updated
  $sql = "
SELECT
  cr.id as recur_id,
  cr.next_sched_contribution_date,
  cr.frequency_interval, cr.frequency_unit,
  c.receive_date, c.contribution_status_id
FROM
  civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON (c.contribution_recur_id = cr.id)
  INNER JOIN civicrm_payment_token pt ON cr.payment_token_id = pt.id
  INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
  LEFT JOIN civicrm_contribution c2 ON (c.contribution_recur_id = c2.contribution_recur_id AND c.id < c2.id)
WHERE
  pp.name = 'Moneris' AND cr.payment_token_id IS NOT NULL
  AND cr.contribution_status_id IN (2,5)
  AND c2.id IS NULL
  AND (cr.next_sched_contribution_date IS NOT NULL AND c.receive_date >= cr.next_sched_contribution_date)";
  $dao = CRM_Core_DAO::executeQuery($sql);

  // TODO: add some log
  while ($dao->fetch()) {
    // pending
    if ($dao->contribution_status_id == 1) {
      // if the next contribution date is in the past, let's remove the next contribution date
      // so that we can process the payment right away
      civicrm_api3('ContributionRecur', 'create', array('id' => $dao->recur_id, 'next_sched_contribution_date' => NULL));
    }
    // completed
    elseif ($dao->contribution_status_id == 2) {
      // we should update the next contribution date
      $next = strtotime($dao->receive_date.'+'.$dao->frequency_interval.' '.$dao->frequency_unit);
      $next_sched_contribution_date = date('YmdHis', $next);
      $next_sched_contribution_date = moneris_fixNextScheduleDate($next_sched_contribution_date);
      civicrm_api3('ContributionRecur', 'create', array('id' => $dao->recur_id, 'next_sched_contribution_date' => $next_sched_contribution_date));
    }
    else {
      // let's update the recurring status so that it won't be processed
      $status = 4; // failed
      civicrm_api3('ContributionRecur', 'create', array('id' => $dao->recur_id, 'contribution_status_id' => $status));
    }
  }


  // contribution_status_id: 2=Pending, 5=InProgress
  // we take only the latest valid one for each contribution_recur_id (see https://stackoverflow.com/a/123481/2708428)
  // FIXME: a recurring payment with only one failed payment won't work
  // (can only happen if the card verification has worked but the payment a few days later didn't)
  $sql = "
SELECT
  cr.id as recur_id, cr.contact_id, cr.payment_token_id,
  c.id as original_contribution_id, c.contribution_status_id,
  c.total_amount, c.currency, c.invoice_id,
  pt.token,
  cr.frequency_interval, cr.frequency_unit,
  contact.preferred_language
FROM
  civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON (c.contribution_recur_id = cr.id)
  INNER JOIN civicrm_payment_token pt ON cr.payment_token_id = pt.id
  INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
  LEFT JOIN civicrm_contribution c2 ON (c.contribution_recur_id = c2.contribution_recur_id AND c.id < c2.id)
  INNER JOIN civicrm_contact contact ON contact.id = c.contact_id
WHERE
  pp.name = 'Moneris' AND cr.payment_token_id IS NOT NULL
  AND cr.contribution_status_id IN (2,5)
  AND c.contribution_status_id IN (1,2)
  AND c2.id IS NULL";

  // normally, process all recurring contributions due today or earlier.
  // FIXME: normally we should use '=', unless catching up.
  // If catching up, we need to manually update the next_sched_contribution_date
  // because CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment() only updates
  // if the receive_date = next_sched_contribution_date.
  $sql .= ' AND (DATE(cr.next_sched_contribution_date) <= CURDATE()
                 OR (cr.next_sched_contribution_date IS NULL AND DATE(cr.start_date) <= CURDATE()))';
  // testing before mass processing
  $sql .= " LIMIT 1";

  $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($params['payment_processor_id'], $params['payment_processor_mode']);
  $counter = 0;
  $error_count = 0;
  $output = [];
  $dao = CRM_Core_DAO::executeQuery($sql, $sqlparams);
  while ($dao->fetch()) {

    // FIXME: HACK for receipt in multilingual installation
    // but should be handled by CiviCRM core by default
    CRM_Core_BAO_ActionSchedule::setCommunicationLanguage(CRM_Core_I18n::AUTO, $dao->preferred_language);

    // logging
    $currentlog = array(
      'contribution_recur_id' => $dao->recur_id,
      'success' => 0,
    );
    // Investigate whether we can use the Contribution.transact API call?
    // it seemed a bit trickier to use, because of pricesets/amounts, more lifting
    // than just calling 'repeattransaction'.
    $payment_params = [
      'contactID' => $dao->contact_id,
      'billing_first_name' => '',
      'billing_last_name' => '',
      'amount' => $dao->total_amount,
      'currencyID' => $dao->currency,
      //'invoiceID' => $invoice_id,
      'payment_token_id' => $dao->payment_token_id,
      'token' => $dao->token,
      'street_address' => '',
      'city' => '',
      'state_province' => '',
      'country' => '',
    ];

    // create or retrieve the contribution
    $contribution_id = NULL;

    // Pending
    if ($dao->contribution_status_id == 2) {
      // previously created but not processed (first one)
      $contribution_id = $dao->original_contribution_id;
    }
    // Completed, create a new contribution
    elseif ($dao->contribution_status_id == 1) {

      // ensure the money is clean before processing
      $payment_params['amount'] = CRM_Utils_Rule::cleanMoney($payment_params['amount']);
      $repeat_params = [
        'contribution_recur_id' => $dao->recur_id,
        'contact_id' => $dao->contact_id,
        'original_contribution_id' => $dao->original_contribution_id,
        //'invoice_id' => $invoice_id,
        'contribution_status_id' => 2,  // Pending
        'receive_date' => date('YmdHis'),
      ];

      if (!isset($params['update_amounts_and_taxes']) || !$params['update_amounts_and_taxes']) {
        // keep the same amount so use the standard repeat tranapaction
        // FIXME 1: if we go this way amount seems not right ? needs testing
        // FIXME 2: seem to send a receipt even if we don't have the payment done see if is_email_receipt = FALSE solves this
        $repeat_params['is_email_receipt'] = FALSE;
        $result = civicrm_api3('Contribution', 'repeattransaction', $repeat_params);
        // Presumably there is a good reason why CiviCRM is not storing
        // our new invoice_id. Anyone know?
        $contribution_id = $result['id'];
      }
      else {

        // FIXME: it should be generic and not specificaly related to Moneris... but how ?
        // duplicate contribution and update amounts (taxes and selected price items)
        $result = _repeat_transaction_with_updates($repeat_params, $params['payment_processor_id']);
        $contribution_id = $result['id'];
        $payment_params['amount'] = CRM_Utils_Rule::cleanMoney($result['amount']);

      }
    }
    // for now, any other statuses are skipped
    else {
      Civi::log()->warning("Moneris: contribution ID {$dao->original_contribution_id} has an unexpected status: {$dao->contribution_status_id} -- skipping renewal.");
    }

    if ($contribution_id) {

      $paymentProcessorObj = Civi\Payment\System::singleton()->getByProcessor($paymentProcessor);

      // If the initial contribution is pending (2), then we use that
      // invoice_id for the payment processor. Otherwise we generate a new one.
      $invoice_id = NULL;
      if ($dao->contribution_status_id == 2) {
        $invoice_id = $dao->invoice_id;
      }
      // create an invoice ID based on the contribution ID for easier searches in Moneris
      else {
        // combine the contribution_id with a small hash to have something searchable but unique
        $invoice_id = $contribution_id .  '-' . substr(sha1(uniqid(rand(), TRUE)),0,6);
        // in case something goes wrong, we'd better update the invoice id before the processing
        civicrm_api3('Contribution', 'create', [
          'id' => $contribution_id,
          'contact_id' => $payment_params['contactID'],
          'invoice_id' => $invoice_id,
        ]);
      }
      $payment_params['invoiceID'] = $invoice_id;

      // processing the payment
      $success = TRUE;
      $error_msg = '';
      try {
        $result = CRM_Moneris_Utils::processTokenPayment($paymentProcessorObj, $payment_params['token'], $payment_params['invoiceID'], $payment_params['amount']);
      }
      catch (PaymentProcessorException $e) {
        $error_msg = 'Moneris: failed payment: ' . $e->getMessage();
        $success = FALSE;
      }

      if (is_a($result, 'CRM_Core_Error')) {
        $error_msg = 'Moneris: failed payment: ' . CRM_Core_Error::getMessages($result);
        $success = FALSE;
      }

      // now, update the current contribution, at least the status
      $update_params = array(
        'id' => $contribution_id,
        'contact_id' => $payment_params['contactID'],
        // ensure the payment is considered done and have a receipt sent
        'is_pay_later' => 0,
      );

      // whatever is wrong, we must update the status to failed
      $recur_upd_params = array(
        'id' => $dao->recur_id,
        'contribution_status_id' => 5,
      );
      if (!$success) {
        $update_params['contribution_status_id'] = 4;  // Failed
        $recur_upd_params['contribution_status_id'] = 4;

        // update contribuion and financials
        civicrm_api3('Contribution', 'create', $update_params);
      }
      else {
        $mpgResponse = $result;

        // for log and user receipt
        $receipt = CRM_Moneris_Utils::generateReceipt($payment_params, $mpgResponse);

        $update_params['invoice_id'] = $payment_params['invoiceID'];
        $update_params['trxn_result_code'] = (integer) $result->getResponseCode();
        $update_params['trxn_id'] = $result->getTxnNumber();
        $update_params['gross_amount'] = $result->getTransAmount();
        $update_params['contribution_status_id'] = 1;  // Completed

        // TODO: add info about transaction (from token ?) for the receipt
        //$input['card_type_id'] = $mpgResponse->getCardType();
        $update_params['pan_truncation'] = $mpgResponse->getResDataMaskedPan();

        // Update the next_sched_contribution_date
        $next = strtotime('+'.$dao->frequency_interval.' '.$dao->frequency_unit);
        $next_sched_contribution_date = date('YmdHis', $next);
        $next_sched_contribution_date = moneris_fixNextScheduleDate($next_sched_contribution_date);
        $recur_upd_params['next_sched_contribution_date'] = $next_sched_contribution_date;

        $result = civicrm_api3('Contribution', 'completetransaction', $update_params);
      }

      // update recurring payment status to In Progress or Failed
      // + next_sched_contribution_date
      civicrm_api3('ContributionRecur', 'create', $recur_upd_params);


      // logging
      $currentlog['success'] = $success;
      $currentlog['error'] = $error_msg;
      $currentlog['contribution_id'] = $contribution_id;
      $currentlog['original_amount'] = $dao->total_amount;
      $currentlog['new_amount'] = $payment_params['amount'];

    }

    $payments[] = $currentlog;

  }

  return civicrm_api3_create_success($payments, $params, 'Job', 'monerisvaultrecurringcontributions');

}

function _repeat_transaction_with_updates($params, $payment_processor_id) {
  if (empty($params['original_contribution_id'])) {
    throw new CRM_Core_Exception(ts('No original contribution to duplicate'));
  }

  // original inspiration from civicrm_api3_contribution_repeattransaction() but changed a lot

  // get original contribution
  $original_contribution = new CRM_Contribute_BAO_Contribution();
  $original_contribution->id = $params['original_contribution_id'];
  if (!$original_contribution->find(TRUE)) {
    throw new API_Exception('A valid original contribution ID is required', 'invalid_data');
  }

  // from CRM_Cdntaxcalculator_BAO_CDNTaxes::checkTaxAmount (could we use a function from there instead)
  $taxes = CRM_Cdntaxcalculator_BAO_CDNTaxes::getTaxRatesForContact($original_contribution->contact_id);
  $taxRates = CRM_Core_PseudoConstant::getTaxRates();
  foreach ($taxRates as $ft => &$values) {
    $taxRates[$ft] = $taxes['TAX_TOTAL'];
  }
  $contrib_tax_rate = 0;
  if (array_key_exists($original_contribution->financial_type_id, $taxRates)) {
    $contrib_tax_rate = $taxRates[$original_contribution->financial_type_id] / 100;
  }

  // now that we have a contribution, let's update it
  $lineitem_result = civicrm_api3('LineItem', 'get', [
    'sequential' => 1,
    // 'entity_table' => 'civicrm_contribution',
    'contribution_id' => $original_contribution->id,
  ]);

  $new_total_amount = 0;
  $new_tax_amount = 0;
  $tax_line_item = NULL;
  $line_items = [];
  foreach ($lineitem_result['values'] as $original_line_item) {

    // fixing line item
    $p = [
      'entity_table' => CRM_Utils_Array::value('entity_table', $original_line_item),
      'entity_id' => CRM_Utils_Array::value('entity_id', $original_line_item),
      'price_field_id' => CRM_Utils_Array::value('price_field_id', $original_line_item),
      'label' => CRM_Utils_Array::value('label', $original_line_item),
      'qty' => CRM_Utils_Array::value('qty', $original_line_item),
      'unit_price' => CRM_Utils_Array::value('unit_price', $original_line_item),
      'line_total' => CRM_Utils_Array::value('line_total', $original_line_item),
      'participant_count' => CRM_Utils_Array::value('participant_count', $original_line_item),
      'price_field_value_id' => CRM_Utils_Array::value('price_field_value_id', $original_line_item),
      'financial_type_id' => CRM_Utils_Array::value('financial_type_id', $original_line_item),
      'deductible_amount' => CRM_Utils_Array::value('deductible_amount', $original_line_item),
    ];

    // get line_item tax rate or contribution tax rate if no financial_type
    $tax_rate = $contrib_tax_rate;
    if (array_key_exists($p['financial_type_id'], $taxRates)) {
      $tax_rate = $taxRates[$p['financial_type_id']] / 100;
    }

    // Fetch the current amount of the line item (handle price increases).
    if (!empty($original_line_item['price_field_value_id'])) {
      $pfv = civicrm_api3('PriceFieldValue', 'getsingle', [
        'id' => $original_line_item['price_field_value_id'],
      ]);
      $p['unit_price'] = $pfv['amount'];
      $p['line_total'] = $pfv['amount'] * $p['qty'];
      $p['tax_amount'] = round($p['line_total'] * $tax_rate, 2);
      // taxes are included in contribution total amount but not in line_total
      //$p['line_total'] += $p['tax_amount'];
    }
    elseif (!$original_line_item['line_total']) {
      // Probably a 0$ item, so it's OK to not have a price_field_value_id
      // and we can just leave the line_total and unit_price empty.
    }
    if (empty($p['line_total'])) {
      $p['line_total'] = '0';
      $p['tax_amount'] = '0';
    }

    // we don't use api because of tax calculation problems
    // it will not use the tax amount we have computed
    //$li = CRM_Price_BAO_LineItem::create($p);
    $line_items[] = $p;
    //$t = civicrm_api3('LineItem', 'create', $p);

    $new_total_amount += $p['line_total'];
    $new_tax_amount += $p['tax_amount'];
  }

  $p = [
    'contact_id' => $original_contribution->contact_id,
    'financial_type_id' => $original_contribution->financial_type_id,
    'is_test' => $original_contribution->is_test,
    'payment_instrument_id' => $original_contribution->payment_instrument_id,

    'contribution_recur_id' => $params['contribution_recur_id'],
    'contribution_status_id' => 2,
    'receive_date' => date('YmdHis'),
    'total_amount' => $new_total_amount + $new_tax_amount,
    'tax_amount' => $new_tax_amount,
    'payment_processor_id' => $payment_processor_id,
    'skipLineItem' => 1,
    'source' => 'Job.Monerisvaultrecurringcontributions',

    // hack - this line is required if we wants to have financial items created
    'is_pay_later' => 1,
  ];
  //$contribution = CRM_Contribute_BAO_Contribution::add($p);
  $res = civicrm_api3('Contribution', 'create', $p);
  $contribution_id = $res['id'];
  CRM_Contribute_BAO_ContributionRecur::copyCustomValues($p['contribution_recur_id'], $contribution_id);

  // add line items
  $eft = new CRM_Financial_DAO_EntityFinancialTrxn();
  $eft->entity_id = $contribution_id;
  $eft->entity_table = 'civicrm_contribution';
  if (!$eft->find(TRUE)) {
    throw new API_Exception('The contribution was not properly created', 'invalid_data');
  }
  $trxnID = $eft->financial_trxn_id;
  foreach ($line_items as $p) {
    // FIXME: could we have something different that contribution / membership ? might be a problem
    $p['entity_id'] = ($p['entity_table'] == 'civicrm_contribution') ? $contribution_id : $p['entity_id'];
    $p['contribution_id'] = $contribution_id;
    // we don't use api because of tax calculation problems
    // it will replace the tax amount we have computed
    //$res = civicrm_api3('LineItem', 'create', $p);
    $li = CRM_Price_BAO_LineItem::create($p);
    $id = $li->id;
    $lineItem = civicrm_api3('LineItem', 'getsingle', array('id' => $id));
    CRM_Lineitemedit_Util::insertFinancialItemOnAdd($lineItem, $trxnID);
  }

  // remove is_pay_later
  civicrm_api3('Contribution', 'create', ['id' => $contribution_id, 'is_pay_later' => 0]);

  // TODO: Fetch membership for this contribution and associate the (future) contribution ?
  // Should not be necessary if the contribution_recur is correctly defined

  return array(
    'id' => $contribution_id,
    'amount' => number_format($new_total_amount + $new_tax_amount, 2),
  );

}

