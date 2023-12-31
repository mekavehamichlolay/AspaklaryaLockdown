-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/AspaklaryaLockDown/dbPatches/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE aspaklarya_lockdown_pages (
  al_page_id INT NOT NULL,
  al_read_allowed SMALLINT DEFAULT 1 NOT NULL,
  PRIMARY KEY(al_page_id)
);

CREATE UNIQUE INDEX al_page_id ON aspaklarya_lockdown_pages (al_page_id);
