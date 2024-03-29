-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: AspaklaryaLockDown/dbPatches/ptches/revisions_add_id.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
DROP  INDEX al_rev_id;
DROP  INDEX al_page_id;
DROP  INDEX "primary";
ALTER TABLE  aspaklarya_lockdown_revisions
ADD  alr_id SERIAL NOT NULL;
ALTER TABLE  aspaklarya_lockdown_revisions
ADD  alr_rev_id INT NOT NULL;
ALTER TABLE  aspaklarya_lockdown_revisions
ADD  alr_page_id INT NOT NULL;
ALTER TABLE  aspaklarya_lockdown_revisions
DROP  al_rev_id;
ALTER TABLE  aspaklarya_lockdown_revisions
DROP  al_page_id;
CREATE UNIQUE INDEX alr_id ON aspaklarya_lockdown_revisions (alr_id);
CREATE UNIQUE INDEX alr_rev_id ON aspaklarya_lockdown_revisions (alr_rev_id);
CREATE UNIQUE INDEX alr_page_id ON aspaklarya_lockdown_revisions (alr_rev_id, alr_page_id);
ALTER TABLE  aspaklarya_lockdown_revisions
ADD  PRIMARY KEY (alr_id, alr_rev_id);