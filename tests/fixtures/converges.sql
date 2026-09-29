-- Synthetic schema for the tests: written the way dbDelta() expects, so a second call changes nothing.
CREATE TABLE {prefix}acme_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL DEFAULT '0',
  name varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  amount decimal(10,2) NOT NULL DEFAULT '0.00',
  payload longtext,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY site_status (site_id,status),
  KEY name (name(50))
) {charset_collate};
