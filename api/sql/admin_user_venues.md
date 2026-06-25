# admin_user_venues migration

```sql
CREATE TABLE IF NOT EXISTS `admin_user_venues` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` INT NOT NULL,
  `venue_id` INT NOT NULL,
  `relation_type` VARCHAR(32) NOT NULL DEFAULT 'franchise',
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_user_venue` (`admin_user_id`, `venue_id`),
  KEY `idx_venue_id` (`venue_id`),
  KEY `idx_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台账号可管理场地关系表';
```

