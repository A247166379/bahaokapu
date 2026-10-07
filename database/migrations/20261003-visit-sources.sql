-- Additive attribution only. No raw referer, IP, URL path, query, account or order fields.
CREATE TABLE IF NOT EXISTS `{prefix}visit_sources_minute` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_date` DATE NOT NULL,
  `stat_hour` TINYINT UNSIGNED NOT NULL,
  `stat_minute` TINYINT UNSIGNED NOT NULL,
  `visitor_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_key` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_domain` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `visit_source_minute` (`stat_date`,`stat_hour`,`stat_minute`,`visitor_key`,`source_key`,`source_domain`),
  KEY `visit_source_date_visitor` (`stat_date`,`visitor_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
