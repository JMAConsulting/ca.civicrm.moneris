CREATE TABLE `civicrm_moneris_receipt` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Receipt ID',
  `trxn_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'CiviCRM transaction ID',
  `trxn_type` varchar(10) NOT NULL DEFAULT '00' COMMENT 'Transaction code type',
  `reference` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Moneris reference number',
  `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'A Unix timestamp indicating when this receipt was created',
  `receipt_msg` text CHARACTER SET utf8 COMMENT 'Full store receipt, including credit card transaction',
  `first_name` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Billing first name',
  `last_name` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Billing last name',
  `card_type` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Credit card type',
  `card_number` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Partial credit card number',
  `token_id` int(11) NULL COMMENT 'FK to civicrm_token',
  `ip` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT 'IP address of the donor',
  PRIMARY KEY (`id`),
  UNIQUE KEY (`trxn_id`, `trxn_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Logs all netbanx credit card receipts sent to users.';

CREATE TABLE `civicrm_moneris_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Log ID',
  `trxn_id` varchar(255) NOT NULL COMMENT 'CiviCRM transaction ID',
  `trxn_type` varchar(32) DEFAULT NULL COMMENT 'Transaction code type',
  `reference` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Moneris reference number',
  `card_type` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Credit card type',
  `card_number` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Partial credit card number',
  `token_id` int(11) NULL COMMENT 'FK to civicrm_token',
  `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'A Unix timestamp indicating when this message was sent or received.',
  `response_code` varchar(10) DEFAULT NULL COMMENT 'Transaction response code',
  `message` text DEFAULT NULL COMMENT 'Transaction response message',
  /*`fail` tinyint(4) DEFAULT '0' COMMENT 'Set to 1 if the message was an error.',*/
  `ip` varchar(255) NOT NULL COMMENT 'IP of the visitor',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=96918 DEFAULT CHARSET=utf8 COMMENT='Logs all communications with the payment gateway.';
