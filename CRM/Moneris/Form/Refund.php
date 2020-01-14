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
   * Test or live mode
   * @var object
   */
  protected $_isTest;


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
    parent::preProcess();

    $this->_isTest = 0;
    if ($this->_action & CRM_Core_Action::PREVIEW) {
      $this->_isTest = 1;
    }
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
    $payment = Civi\Payment\System::singleton()->getByName('Moneris', $this->_isTest);

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

    // redirect to contact contribution tab
    CRM_Core_Session::singleton()->replaceUserContext($url);
    return;
  }

}
