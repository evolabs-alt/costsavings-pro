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
 * @param string $email
 */
function ensureUserExists($email) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO users (email) VALUES (:email)');
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
