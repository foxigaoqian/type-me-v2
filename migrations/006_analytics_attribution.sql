-- P0 analytics attribution: root seed tracking across visits, shares and orders.
-- Compatible with MySQL 5.7+ and MariaDB 10.5+. Safe to run repeatedly.

DROP PROCEDURE IF EXISTS type_me_add_column;
DELIMITER $$
CREATE PROCEDURE type_me_add_column(IN table_name_value VARCHAR(64), IN column_name_value VARCHAR(64), IN column_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = table_name_value AND COLUMN_NAME = column_name_value
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', table_name_value, '` ADD COLUMN ', column_sql);
    PREPARE statement_to_run FROM @ddl;
    EXECUTE statement_to_run;
    DEALLOCATE PREPARE statement_to_run;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS type_me_add_index;
DELIMITER $$
CREATE PROCEDURE type_me_add_index(IN table_name_value VARCHAR(64), IN index_name_value VARCHAR(64), IN index_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = table_name_value AND INDEX_NAME = index_name_value
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', table_name_value, '` ADD INDEX `', index_name_value, '` ', index_sql);
    PREPARE statement_to_run FROM @ddl;
    EXECUTE statement_to_run;
    DEALLOCATE PREPARE statement_to_run;
  END IF;
END$$
DELIMITER ;

CALL type_me_add_column('test_attempts','seed_id','`seed_id` VARCHAR(64) NOT NULL DEFAULT "" AFTER `campaign`');
CALL type_me_add_index('test_attempts','idx_attempt_seed','(`seed_id`,`started_at`)');

CALL type_me_add_column('events','seed_id','`seed_id` VARCHAR(64) NOT NULL DEFAULT "" AFTER `campaign`');
CALL type_me_add_index('events','idx_event_seed_time','(`seed_id`,`occurred_at`)');

CALL type_me_add_column('shares','campaign','`campaign` VARCHAR(128) NOT NULL DEFAULT "" AFTER `source`');
CALL type_me_add_column('shares','seed_id','`seed_id` VARCHAR(64) NOT NULL DEFAULT "" AFTER `campaign`');
CALL type_me_add_index('shares','idx_share_seed','(`seed_id`,`created_at`)');

CALL type_me_add_column('orders','source','`source` VARCHAR(64) NOT NULL DEFAULT "direct" AFTER `primary_personality`');
CALL type_me_add_column('orders','campaign','`campaign` VARCHAR(128) NOT NULL DEFAULT "" AFTER `source`');
CALL type_me_add_column('orders','seed_id','`seed_id` VARCHAR(64) NOT NULL DEFAULT "" AFTER `campaign`');
CALL type_me_add_index('orders','idx_order_seed','(`seed_id`,`created_at`)');

DROP PROCEDURE IF EXISTS type_me_add_column;
DROP PROCEDURE IF EXISTS type_me_add_index;
