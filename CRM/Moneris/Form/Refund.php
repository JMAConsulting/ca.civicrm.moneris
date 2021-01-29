<?php

use CRM_Moneris_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Moneris_Form_Refund extends CRM_Core_Form {

  /**
   * contact ID
   * @var object
   */
  protected $_contactID;

  /**
   * Payment Processor ID
   * @var object
   */
  protected $_paymentProcessorID;

  /**
   * Set variables up before form is built.
   */
  public function preProcess() {
    // Check permission for action.
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
      // @todo replace with throw new CRM_Core_Exception().
      CRM_Core_Error::fatal(ts('You do not have permission to access this page.'));
    }

    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this, TRUE);
    $this->_contactID = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);

    $this->_paymentProcessorID = civicrm_api3('EntityFinancialTrxn', 'get', [
      'sequential' => 1,
      'return' => ["financial_trxn_id.payment_processor_id"],
      'entity_table' => "civicrm_contribution",
      'entity_id' => $this->_id,
      'options' => ['limit' => 1, 'sort' => "id DESC"],
    ])['values'][0]['financial_trxn_id.payment_processor_id'];

    if (empty($this->_paymentProcessorID)) {
      CRM_Core_Error::statusBounce(ts('No payment processor found for this contribution'));
    }

    parent::preProcess();
  }

  public function buildQuickForm() {
    $this->addButtons(
      array(
        array(
          'type' => 'next',
          'name' => ts('Refund'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE,
        ),
        array(
          'type' => 'cancel',
          'name' => ts('Cancel'),
        ),
      )
    );
    return;
  }

  public function postProcess() {
    // FIXME: doesn't work for multiple Moneris Processors
    $payment = Civi\Payment\System::singleton()->getById($this->_paymentProcessorID);

    $urlParams = "reset=1&cid={$this->_contactID}&selectedChild=contribute";
    $url = CRM_Utils_System::url('civicrm/contact/view', $urlParams);

    // process the refund
    try {
      $result = $payment->refundPayment(array('contribution_id' => $this->_id));
    }
    catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
      CRM_Core_Error::statusBounce($e->getMessage(), $url, ts('Payment Processor Error'));
    }

    // Success

    // do the refund in CiviCRM
    civicrm_api3('Contribution', 'create', array(
      'contact_id' => $this->_contactID,
      'contribution_id' => $this->_id,
      'contribution_status_id' => 7, // refund
      'trxn_id' => $result['trxn_id'],
      'trxn_result_code' => $result['trxn_result_code'],
      'cancel_date' => date('YmdHis'),
    ));

    // redirect to contact contribution tab
    CRM_Core_Session::singleton()->replaceUserContext($url);
    return;
  }

}
