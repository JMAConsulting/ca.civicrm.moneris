<?php

require_once 'CRM/Core/Page.php';

class CRM_Moneris_Page_MonerisStatus extends CRM_Core_Page {
  function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('Moneris Status'));

    $this->assign('currentVersion', CRM_Utils_System::version());
    // Example: Assign a variable for use in a template
    $this->assign('currentTime', date('Y-m-d H:i:s'));

    parent::run();
  }
}
