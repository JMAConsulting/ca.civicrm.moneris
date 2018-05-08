<?php

class CRM_Moneris_Utils_HookInvoker {

  private static $singleton;

  static $_nullObject = NULL;

  private function __construct() {
  }

  /**
   * @return \CRM_Moneris_Utils_HookInvoker
   */
  public static function singleton() {
    if (!self::$singleton) {
      self::$singleton = new CRM_Moneris_Utils_HookInvoker();
    }
    return self::$singleton;
  }

  /**
   * hook to allow an update of the contribution that is about to be processed
   *
   * @return array
   */
  public function monerisRecurringPre(&$params, &$contribution) {
    $hook =  CRM_Utils_Hook::singleton();
    return $hook->invoke(array('params', 'contribution'), $params, $contribution,
      self::$_nullObject, self::$_nullObject, self::$_nullObject, self::$_nullObject, 'civicrm_monerisRecurringPre');
  }

}
