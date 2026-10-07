-- Additive only. Anonymous per-minute counters; no IP/UA/URL/account/order fields.
CREATE TABLE IF NOT EXISTS `{prefix}visit_statistics_minute` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_date` DATE NOT NULL,
  `stat_hour` TINYINT UNSIGNED NOT NULL,
  `stat_minute` TINYINT UNSIGNED NOT NULL,
  `visitor_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `device` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `country` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `visit_minute_visitor` (`stat_date`,`stat_hour`,`stat_minute`,`visitor_key`,`device`,`country`),
  KEY `visit_date_visitor` (`stat_date`,`visitor_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}visit_statistics_meta` (
  `meta_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meta_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
