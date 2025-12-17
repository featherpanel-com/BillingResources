CREATE TABLE
	IF NOT EXISTS `featherpanel_billingresources_user_resources` (
		`id` INT (11) NOT NULL AUTO_INCREMENT,
		`memory_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Memory limit in MB',
		`cpu_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'CPU limit in percentage',
		`disk_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Disk limit in MB',
		`server_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Server limit',
		`database_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Database limit',
		`backup_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Backup limit',
		`allocation_limit` INT (11) NOT NULL DEFAULT 0 COMMENT 'Allocation limit',
		`user_id` INT (11) NOT NULL,
		`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`id`),
		KEY `idx_user_id` (`user_id`),
		CONSTRAINT `billingresources_user_resources_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `featherpanel_users` (`id`) ON DELETE CASCADE
	) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;