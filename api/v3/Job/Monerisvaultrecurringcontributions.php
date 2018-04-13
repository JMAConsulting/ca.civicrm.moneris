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

  // Running this job in parallell could generate bad duplicate contributions.
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
  pp.token
FROM
  civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON (c.contribution_recur_id = cr.id)
  INNER JOIN civicrm_payment_token pt ON cr.payment_token_id = pt.id
  INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
WHERE
  pp.name = 'Moneris' AND pp.payment_token_id IS NOT NULL
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
      // The original contribution was already completed - do a duplicate
      // TODO: before saving the duplicate, add a call to a hook to allow for price adjustment (e.g. taxes)
      // Invoke XXX

      // ensure the money is clean before processing
      $payment_params['amount'] = CRM_Utils_Rule::cleanMoney($payment_params['amount']);

      $result = civicrm_api3('Contribution', 'repeattransaction', [
        'contribution_recur_id' => $dao->recur_id,
        'original_contribution_id' => $dao->original_contribution_id,
        'contribution_status_id' => $payment_status_id,
        'invoice_id' => $invoice_id,
        'contribution_status_id' => 2,  // Pending
        'trxn_result_code' => $payment_params['trxn_result_code'],
        'trxn_id' => $payment_params['trxn_id'],
      ]);
      // Presumably there is a good reason why CiviCRM is not storing
      // our new invoice_id. Anyone know?
      $contribution_id = $result['id'];
      civicrm_api3('Contribution', 'create', [
        'id' => $contribution_id,
        'invoice_id' => $invoice_id,
      ]);
    }
    elseif ($dao->contribution_status_id == 2) {
      $contibution_id = $dao->original_contribution_id;
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
