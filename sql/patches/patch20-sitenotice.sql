-- -----------------------------------------------------------------------------
-- Hey!
-- 
-- This is a new patch-creation script which SHOULD stop double-patching and
-- running patches out-of-order.
--
-- If you're running patches, please close this file, and run this from the 
-- command line:
--   $ mysql -u USERNAME -p SCHEMA < patchXX-this-file.sql
-- where:
--      USERNAME = a user with CREATE/ALTER access to the schema
--      SCHEMA = the schema to run the changes against
--      patch-XX-this-file.sql = this file
--
-- If you are writing patches, you need to copy this template to a numbered 
-- patch file, update the patchversion variable, and add the SQL code to upgrade
-- the database where indicated below.

DROP PROCEDURE IF EXISTS SCHEMA_UPGRADE_SCRIPT;
DELIMITER ';;'
CREATE PROCEDURE SCHEMA_UPGRADE_SCRIPT() BEGIN
  -- -------------------------------------------------------------------------
  -- Developers - set the number of the schema patch here!
  -- -------------------------------------------------------------------------
  DECLARE patchversion INT DEFAULT 20;
  -- -------------------------------------------------------------------------
  -- working variables
  DECLARE currentschemaversion INT DEFAULT 0;
  DECLARE lastversion INT;

  -- check the schema has a version table
  IF NOT EXISTS(SELECT *
                FROM information_schema.tables
                WHERE table_name = 'schemaversion' AND table_schema = DATABASE())
  THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Please ensure patches are run in order! This database does not have a schemaversion table.';
  END IF;

  -- get the current version
  SELECT version
  INTO currentschemaversion
  FROM schemaversion;

  -- check schema is not ahead of this patch
  IF currentschemaversion >= patchversion
  THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'This patch has already been applied!';
  END IF;

  -- check schema is up-to-date
  SET lastversion = patchversion - 1;
  IF currentschemaversion != lastversion
  THEN
    SET @message_text = CONCAT('Please ensure patches are run in order! This patch upgrades to version ', patchversion,
                               ', but the database is not version ', lastversion);
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = @message_text;
  END IF;

  -- -------------------------------------------------------------------------
  -- Developers - put your upgrade statements here!
  -- -------------------------------------------------------------------------

  -- ----------------------------------
  -- data migration

  SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
  START TRANSACTION;

  DELETE FROM log
  WHERE objecttype = 'InterfaceMessage' AND objectid <> 31;
  UPDATE log
  SET objecttype = 'SiteNotice'
  WHERE objectid = 31 AND objecttype = 'InterfaceMessage';
  DELETE FROM interfacemessage
  WHERE id <> 31;
  UPDATE interfacemessage
  SET id = 1
  WHERE id = 31;

  COMMIT;
  -- Finished data migration, continue with schema changes
  -- ---------------------------------------

  ALTER TABLE interfacemessage DROP type, DROP description, RENAME TO sitenotice;

  -- drop some old unused views
  DROP VIEW IF EXISTS acc_emails;
  DROP VIEW IF EXISTS acc_trustedips;

  -- -------------------------------------------------------------------------
  -- finally, update the schema version to indicate success
  UPDATE schemaversion
  SET version = patchversion;
END;;

DELIMITER ';'
CALL SCHEMA_UPGRADE_SCRIPT();
DROP PROCEDURE IF EXISTS SCHEMA_UPGRADE_SCRIPT;