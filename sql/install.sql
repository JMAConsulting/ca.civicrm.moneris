INSERT INTO civicrm_managed (module, name, entity_type, entity_id) VALUES ('ca.civicrm.moneris','Moneris','PaymentProcessorType',(SELECT id FROM civicrm_payment_processor_type where name = 'Moneris'));
