-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/AspaklaryaLockDown/dbPatches/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/aspaklarya_lockdown_pages (
  al_page_id INT UNSIGNED NOT NULL,
  al_read_allowed TINYINT(1) UNSIGNED DEFAULT 1 NOT NULL,
  UNIQUE INDEX al_page_id (al_page_id),
  PRIMARY KEY(al_page_id)
) /*$wgDBTableOptions*/;
