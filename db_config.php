<?php
/**
 * PDO database helpers for CostSavings (user identity and role persistence).
 * Aligns with Scorecard: roles live in users.user_role.
 */

if (!defined('DB_HOST')) {
    die('Database configuration constants not defined. Please include config.php first.');
}

/**
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
            $tempPdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            initializeDatabase($tempPdo);

            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            migrateSchema($pdo);
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please check your database configuration.');
        }
    }

    return $pdo;
}

/**
 * @param PDO $pdo
 */
function initializeDatabase($pdo) {
    try {
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
            if ($stmt->rowCount() == 0) {
                error_log("Database '" . DB_NAME . "' does not exist and could not be created.");
                throw new Exception("Database '" . DB_NAME . "' does not exist. Please create it manually.");
            }
        }
        $pdo->exec('USE `' . DB_NAME . '`');

        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `email` VARCHAR(255) PRIMARY KEY,
            `first_name` VARCHAR(255) NULL,
            `last_name` VARCHAR(255) NULL,
            `user_role` VARCHAR(255) NULL,
            `role_set_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $columns = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'first_name'")->fetchAll();
            if (empty($columns)) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(255) NULL AFTER `email`');
            }
        } catch (PDOException $e) {
            error_log('Error adding first_name column: ' . $e->getMessage());
        }
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_name'")->fetchAll();
            if (empty($columns)) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(255) NULL AFTER `first_name`');
            }
        } catch (PDOException $e) {
            error_log('Error adding last_name column: ' . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log('Database initialization error: ' . $e->getMessage());
        throw new Exception('Failed to initialize database: ' . $e->getMessage());
    }
}

/**
 * AI monthly usage counter table. Invoked from migrateSchema (after users exist) and from AiService::ask
 * so usage checks never hit a missing table if a later migration step failed.
 *
 * @param PDO $pdo
 */
function ensureAiUsageTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_usage` (
            `user_id` INT UNSIGNED NOT NULL,
            `year_month` CHAR(7) NOT NULL,
            `usage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`, `year_month`),
            CONSTRAINT `fk_ai_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('ensureAiUsageTable (with FK): ' . $e->getMessage());
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_usage` (
                `user_id` INT UNSIGNED NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                `usage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`, `year_month`),
                KEY `idx_ai_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e2) {
            error_log('ensureAiUsageTable (no FK): ' . $e2->getMessage());
        }
    }

    // Legacy installs used column `count` (reserved in SQL); PDO native prepares can fail on it.
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'ai_usage'")->fetch(PDO::FETCH_NUM);
        if (!$exists) {
            return;
        }
        $hasOld = $pdo->query("SHOW COLUMNS FROM `ai_usage` LIKE 'count'")->fetch();
        $hasNew = $pdo->query("SHOW COLUMNS FROM `ai_usage` LIKE 'usage_count'")->fetch();
        if ($hasOld && !$hasNew) {
            $pdo->exec('ALTER TABLE `ai_usage` CHANGE `count` `usage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        } elseif (!$hasNew) {
            $pdo->exec('ALTER TABLE `ai_usage` ADD COLUMN `usage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (PDOException $e) {
        error_log('ensureAiUsageTable column repair: ' . $e->getMessage());
    }
}

/**
 * Extended schema: organizations, auth, invitations, vendor columns, AI/reminder tables.
 *
 * @param PDO $pdo
 */
function migrateSchema(PDO $pdo) {
    static $migrated = false;
    if ($migrated) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `organizations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL DEFAULT 'Organization',
            `max_users` TINYINT UNSIGNED NOT NULL DEFAULT 10,
            `deadline_reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `notification_webhook_url` VARCHAR(1024) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO `organizations` (`id`, `name`, `max_users`) VALUES (1, 'Default Organization', 10)
            ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)");

        $orgWebhookCol = $pdo->query("SHOW COLUMNS FROM `organizations` LIKE 'notification_webhook_url'")->fetch();
        if (!$orgWebhookCol) {
            $pdo->exec('ALTER TABLE `organizations` ADD COLUMN `notification_webhook_url` VARCHAR(1024) NULL AFTER `deadline_reminders_enabled`');
        }

        $hasUserId = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'id'")->fetch();
        if (!$hasUserId) {
            $pdo->exec("CREATE TABLE `users_new` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `org_id` INT UNSIGNED NULL,
                `username` VARCHAR(64) NULL,
                `email` VARCHAR(255) NOT NULL,
                `password_hash` VARCHAR(255) NULL,
                `role` ENUM('admin','member') NOT NULL DEFAULT 'member',
                `display_name` VARCHAR(255) NULL,
                `first_name` VARCHAR(255) NULL,
                `last_name` VARCHAR(255) NULL,
                `user_role` VARCHAR(255) NULL,
                `role_set_at` DATETIME NULL,
                `deadline_reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_users_email` (`email`),
                UNIQUE KEY `uk_users_username` (`username`),
                KEY `idx_users_org` (`org_id`),
                CONSTRAINT `fk_users_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("INSERT INTO `users_new` (`email`, `first_name`, `last_name`, `user_role`, `role_set_at`, `created_at`, `updated_at`)
                SELECT `email`, `first_name`, `last_name`, `user_role`, `role_set_at`, `created_at`, `updated_at` FROM `users`");
            $pdo->exec("DROP TABLE `users`");
            $pdo->exec("RENAME TABLE `users_new` TO `users`");

            $minId = $pdo->query('SELECT MIN(id) AS m FROM users')->fetch();
            $firstId = (int) ($minId['m'] ?? 1);
            $stmt = $pdo->prepare('UPDATE users SET org_id = 1, role = :r WHERE id = :id');
            $stmt->execute([':r' => 'admin', ':id' => $firstId]);
            $stmt = $pdo->prepare('UPDATE users SET org_id = 1, role = :r WHERE id <> :id');
            $stmt->execute([':r' => 'member', ':id' => $firstId]);
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'username'")->fetch();
            if (!$cols) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `username` VARCHAR(64) NULL AFTER `org_id`');
                $pdo->exec('ALTER TABLE `users` ADD UNIQUE KEY `uk_users_username` (`username`)');
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'password_hash'")->fetch();
            if (!$cols) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `email`');
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('admin','member') NOT NULL DEFAULT 'member' AFTER `password_hash`");
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'org_id'")->fetch();
            if (!$cols) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `org_id` INT UNSIGNED NULL AFTER `id`');
                $pdo->exec('UPDATE `users` SET `org_id` = 1 WHERE `org_id` IS NULL');
                $pdo->exec('ALTER TABLE `users` ADD CONSTRAINT `fk_users_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`)');
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'display_name'")->fetch();
            if (!$cols) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `display_name` VARCHAR(255) NULL AFTER `role`');
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'deadline_reminders_enabled'")->fetch();
            if (!$cols) {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `deadline_reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role_set_at`');
            }
        }

        ensureAiUsageTable($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS `invitations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `org_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `invited_by_user_id` INT UNSIGNED NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `consumed_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_inv_token` (`token_hash`),
            KEY `idx_inv_email` (`email`),
            CONSTRAINT `fk_inv_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_user` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `reminder_sent` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `vendor_item_id` INT NOT NULL,
            `reminder_type` ENUM('t_minus_7','deadline_day','t_plus_7') NOT NULL,
            `sent_on_date` DATE NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_reminder` (`vendor_item_id`, `reminder_type`, `sent_on_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `monthly_renewal_sent` (
            `user_id` INT UNSIGNED NOT NULL,
            `year_month` CHAR(7) NOT NULL,
            `sent_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`, `year_month`),
            CONSTRAINT `fk_mrs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        migrateProjectSchema($pdo);
        migrateCostCalculatorSchema($pdo);
        seedInitialAdminIfNeeded($pdo);
        $migrated = true;
    } catch (PDOException $e) {
        error_log('migrateSchema error: ' . $e->getMessage());
    }
}

/**
 * @param PDO $pdo
 */
function migrateProjectSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `projects` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `org_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_projects_org_name` (`org_id`, `name`),
            KEY `idx_projects_org` (`org_id`),
            KEY `idx_projects_creator` (`created_by`),
            CONSTRAINT `fk_projects_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_projects_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `project_members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `assigned_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_project_members` (`project_id`, `user_id`),
            KEY `idx_project_members_user` (`user_id`),
            CONSTRAINT `fk_project_members_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_project_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_project_members_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('migrateProjectSchema: ' . $e->getMessage());
    }
}

/**
 * @param PDO $pdo
 */
function migrateCostCalculatorSchema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cost_calculator_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `org_id` INT UNSIGNED NOT NULL DEFAULT 1,
        `user_id` INT UNSIGNED NULL,
        `user_email` VARCHAR(255) NOT NULL,
        `manager_user_id` INT UNSIGNED NULL,
        `vendor_name` VARCHAR(255) DEFAULT NULL,
        `cost_per_period` DECIMAL(12, 2) DEFAULT 0.00,
        `frequency` VARCHAR(32) DEFAULT NULL,
        `annual_cost` DECIMAL(12, 2) DEFAULT 0.00,
        `cancel_keep` VARCHAR(10) DEFAULT 'Keep',
        `cancelled_status` TINYINT(1) DEFAULT 0,
        `status` ENUM('pending','unknown','keep','mark_for_cancellation','cancelled') NOT NULL DEFAULT 'pending',
        `visibility` ENUM('public','confidential') NOT NULL DEFAULT 'public',
        `purpose_of_subscription` TEXT NULL,
        `cancellation_deadline` DATE NULL,
        `last_payment_date` DATE NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_cc_org` (`org_id`),
        KEY `idx_cc_user_email` (`user_email`),
        KEY `idx_cc_manager` (`manager_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $addCol = function (PDO $pdo, string $col, string $ddl) {
        $st = $pdo->query("SHOW COLUMNS FROM `cost_calculator_items` LIKE " . $pdo->quote($col));
        if (!$st->fetch()) {
            $pdo->exec("ALTER TABLE `cost_calculator_items` ADD COLUMN $ddl");
        }
    };

    try {
        $addCol($pdo, 'org_id', '`org_id` INT UNSIGNED NOT NULL DEFAULT 1');
        $addCol($pdo, 'project_id', '`project_id` INT UNSIGNED NULL');
        $addCol($pdo, 'user_id', '`user_id` INT UNSIGNED NULL');
        $addCol($pdo, 'manager_user_id', '`manager_user_id` INT UNSIGNED NULL');
        $addCol($pdo, 'visibility', "`visibility` ENUM('public','confidential') NOT NULL DEFAULT 'public'");
        $addCol($pdo, 'cancellation_deadline', '`cancellation_deadline` DATE NULL');
        $addCol($pdo, 'last_payment_date', '`last_payment_date` DATE NULL');
        $addCol(
            $pdo,
            'status',
            "`status` ENUM('pending','unknown','keep','mark_for_cancellation','cancelled') NOT NULL DEFAULT 'pending'"
        );

        // Backfill status from legacy cancel_keep/cancelled_status pair for rows
        // that still hold the default 'pending' value (idempotent).
        $pdo->exec("UPDATE `cost_calculator_items`
            SET `status` = CASE
                WHEN (`cancel_keep` IN ('0','Cancel')) AND `cancelled_status` = 1 THEN 'cancelled'
                WHEN (`cancel_keep` IN ('0','Cancel')) THEN 'mark_for_cancellation'
                ELSE 'keep'
            END
            WHERE `status` IS NULL OR `status` = '' OR `status` = 'pending'");

        try {
            $pdo->exec('CREATE INDEX `idx_cc_status` ON `cost_calculator_items` (`status`)');
        } catch (PDOException $e) {
            // ignore duplicate index attempts
        }

        $notesCol = $pdo->query("SHOW COLUMNS FROM `cost_calculator_items` LIKE 'notes'")->fetch();
        $purposeCol = $pdo->query("SHOW COLUMNS FROM `cost_calculator_items` LIKE 'purpose_of_subscription'")->fetch();
        if ($notesCol && !$purposeCol) {
            $pdo->exec('ALTER TABLE `cost_calculator_items` CHANGE COLUMN `notes` `purpose_of_subscription` TEXT NULL');
        } elseif (!$purposeCol) {
            $addCol($pdo, 'purpose_of_subscription', '`purpose_of_subscription` TEXT NULL');
        }

        $pdo->exec('UPDATE `cost_calculator_items` SET `org_id` = 1 WHERE `org_id` IS NULL OR `org_id` = 0');

        $pdo->exec("UPDATE `cost_calculator_items` cci
            INNER JOIN `users` u ON LOWER(TRIM(cci.user_email)) = LOWER(TRIM(u.email))
            SET cci.user_id = u.id
            WHERE cci.user_id IS NULL");

        $pdo->exec("UPDATE `cost_calculator_items` SET `manager_user_id` = `user_id` WHERE `manager_user_id` IS NULL AND `user_id` IS NOT NULL");
        try {
            $pdo->exec('CREATE INDEX `idx_cc_project` ON `cost_calculator_items` (`project_id`)');
        } catch (PDOException $e) {
            // ignore duplicate index attempts
        }
    } catch (PDOException $e) {
        error_log('migrateCostCalculatorSchema: ' . $e->getMessage());
    }

    migrateVendorDetailSchema($pdo);
    migrateVendorRawTransactionSchema($pdo);
    migrateVendorChatSchema($pdo);
}

/**
 * @param PDO $pdo
 */
function migrateVendorDetailSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_detail` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `org_id` INT UNSIGNED NOT NULL,
            `name_1` VARCHAR(255) NOT NULL,
            `name_2` VARCHAR(255) NULL,
            `name_3` VARCHAR(255) NULL,
            `name_4` VARCHAR(255) NULL,
            `name_5` VARCHAR(255) NULL,
            `purpose` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_vendor_detail_org` (`org_id`),
            KEY `idx_vendor_detail_name_1` (`name_1`),
            KEY `idx_vendor_detail_name_2` (`name_2`),
            KEY `idx_vendor_detail_name_3` (`name_3`),
            KEY `idx_vendor_detail_name_4` (`name_4`),
            KEY `idx_vendor_detail_name_5` (`name_5`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('migrateVendorDetailSchema: ' . $e->getMessage());
    }
}

/**
 * @param PDO $pdo
 */
function migrateVendorRawTransactionSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_raw_transactions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `org_id` INT UNSIGNED NOT NULL,
            `project_id` INT UNSIGNED NULL,
            `uploaded_by_user_id` INT UNSIGNED NULL,
            `upload_batch_id` VARCHAR(64) NOT NULL,
            `vendor_name` VARCHAR(255) NOT NULL,
            `vendor_name_normalized` VARCHAR(255) NOT NULL,
            `transaction_date` DATE NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `transaction_type` VARCHAR(128) NULL,
            `account` VARCHAR(255) NULL,
            `memo` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_vrt_org_vendor` (`org_id`, `project_id`, `vendor_name_normalized`),
            KEY `idx_vrt_org_date` (`org_id`, `project_id`, `transaction_date`),
            KEY `idx_vrt_batch` (`upload_batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $st = $pdo->query("SHOW COLUMNS FROM `vendor_raw_transactions` LIKE 'project_id'");
        if (!$st->fetch()) {
            $pdo->exec('ALTER TABLE `vendor_raw_transactions` ADD COLUMN `project_id` INT UNSIGNED NULL AFTER `org_id`');
        }
    } catch (PDOException $e) {
        error_log('migrateVendorRawTransactionSchema: ' . $e->getMessage());
    }
}

/**
 * @param PDO $pdo
 */
function migrateVendorChatSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_item_chat_messages` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `org_id` INT UNSIGNED NOT NULL,
            `project_id` INT UNSIGNED NOT NULL,
            `vendor_item_id` INT NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `username_snapshot` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_vicm_vendor_created` (`vendor_item_id`, `created_at`),
            KEY `idx_vicm_scope_vendor_created` (`org_id`, `project_id`, `vendor_item_id`, `created_at`),
            KEY `idx_vicm_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('migrateVendorChatSchema: ' . $e->getMessage());
    }
}

/**
 * @param PDO $pdo
 */
function seedInitialAdminIfNeeded(PDO $pdo) {
    if (!defined('SEED_ADMIN_USERNAME') || !defined('SEED_ADMIN_EMAIL')) {
        return;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM users WHERE password_hash IS NOT NULL')->fetch()['c'];
    if ($count > 0) {
        return;
    }
    $username = SEED_ADMIN_USERNAME;
    $email = strtolower(trim(SEED_ADMIN_EMAIL));
    $pass = defined('SEED_ADMIN_PASSWORD') ? (string) SEED_ADMIN_PASSWORD : '';
    if ($pass === '') {
        return;
    }
    $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $dup->execute([$username, $email]);
    if ($dup->fetch()) {
        return;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (org_id, username, email, password_hash, role, display_name)
        VALUES (1, :u, :e, :p, :admin, :dn)');
    $stmt->execute([
        ':u' => $username,
        ':e' => $email,
        ':p' => $hash,
        ':admin' => 'admin',
        ':dn' => 'Test Admin',
    ]);
}

/**
 * Member cap for an organization (invites + registration must respect this).
 *
 * @return int
 */
function getOrganizationMaxUsers(PDO $pdo, int $orgId): int
{
    $st = $pdo->prepare('SELECT `max_users` FROM `organizations` WHERE `id` = ?');
    $st->execute([$orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $m = (int) ($row['max_users'] ?? 10);

    return $m > 0 ? $m : 10;
}

/**
 * @param string $email
 */
function ensureUserExists($email) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO users (org_id, email, role) VALUES (1, :email, \'member\')');
        $stmt->execute([':email' => $email]);
    } catch (PDOException $e) {
        error_log('Error ensuring user exists: ' . $e->getMessage());
    }
}

/**
 * @param string $email
 * @return string|null
 */
function getUserRoleFromDB($email) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare('SELECT user_role FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch();
        if (!$result || !isset($result['user_role'])) {
            return null;
        }
        $role = $result['user_role'];
        return ($role !== null && $role !== '') ? $role : null;
    } catch (PDOException $e) {
        error_log('Error getting user role: ' . $e->getMessage());
        return null;
    }
}

/**
 * @param string $email
 * @param string $role
 * @param string|null $firstName
 * @param string|null $lastName
 * @return string
 */
function saveUserRoleToDB($email, $role, $firstName = null, $lastName = null) {
    try {
        $pdo = getDBConnection();
        $existingRole = getUserRoleFromDB($email);
        if ($existingRole !== null) {
            return $existingRole;
        }

        ensureUserExists($email);

        $stmt = $pdo->prepare('
            UPDATE users
            SET user_role = :role,
                first_name = :first_name,
                last_name = :last_name,
                role_set_at = CURRENT_TIMESTAMP
            WHERE email = :email
        ');
        $stmt->execute([
            ':role' => $role,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
        ]);

        return $role;
    } catch (PDOException $e) {
        error_log('Error saving user role: ' . $e->getMessage());
        throw new Exception('Failed to save user role');
    }
}
