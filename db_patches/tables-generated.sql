-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/TitleKey/db_patches//tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/titlekey (
  tk_page INT UNSIGNED NOT NULL,
  tk_namespace INT NOT NULL,
  tk_key VARBINARY(255) NOT NULL,
  INDEX name_key (tk_namespace, tk_key),
  PRIMARY KEY(tk_page)
) /*$wgDBTableOptions*/;
