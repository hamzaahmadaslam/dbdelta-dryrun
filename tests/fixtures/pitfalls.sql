-- Synthetic schema for the tests and the README: each table shows one way a CREATE TABLE statement keeps
-- dbDelta() altering the table on every call. Not taken from any real plugin.

CREATE TABLE {prefix}acme_width (
  id int unsigned NOT NULL AUTO_INCREMENT,
  total int NOT NULL DEFAULT '0',
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_enum (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  state enum('Open','Closed') NOT NULL DEFAULT 'Open',
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_spaces (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  price decimal(10, 2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_default (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rate decimal(5,2) NOT NULL DEFAULT '0',
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_alias (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enabled boolean NOT NULL DEFAULT '0',
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_oneline (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lat float, lng float,
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE {prefix}acme_index (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY email (email) USING BTREE
) {charset_collate};

CREATE TABLE {prefix}acme_json (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  settings json,
  PRIMARY KEY  (id)
) {charset_collate};

CREATE TABLE IF NOT EXISTS {prefix}acme_exists (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY  (id)
) {charset_collate};
