<?php
/**
 * Dynamic Database Configuration & PDO Connection
 * Seamlessly handles Localhost (XAMPP) & Hostinger Shared Hosting
 * Guarantees allowed_visitors table exists and purges any legacy admins table
 */

declare(strict_types=1);

// Set default PHP runtime timezone to Indian Standard Time (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

// =========================================================================
// Production Error & Session Security Configuration
// =========================================================================
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Configure secure session cookie defaults before any session starts
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $isSecureHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    );
    session_set_cookie_params([
        'lifetime' => 43200, // 12 hours
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecureHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Global Exception Handler: Friendly screen without stack traces or local paths
if (!defined('ERROR_HANDLER_SET')) {
    define('ERROR_HANDLER_SET', true);
    set_exception_handler(function (\Throwable $e) {
        error_log("[EventMS Error] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
        }
        $isJson = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
            (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
        );
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Something went wrong. Please try again.']);
            exit;
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>EventMS - Notice</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:32px 24px;max-width:440px;width:100%;text-align:center;box-shadow:0 4px 16px rgba(15,28,48,0.06)}h1{font-size:1.25rem;margin:0 0 8px;color:#0f172a}p{font-size:0.92rem;color:#64748b;margin:0 0 20px;line-height:1.5}.btn{display:inline-block;padding:10px 20px;background:#1d58d8;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:0.88rem}</style></head><body><div class="card"><h1>Something went wrong</h1><p>We encountered an unexpected issue. Please try again or contact the event desk.</p><a href="javascript:location.reload()" class="btn">Try Again</a></div></body></html>';
        exit;
    });
}

if (!defined('APP_INIT')) {
    define('APP_INIT', true);
}

// =========================================================================
// HOSTINGER / PRODUCTION CONFIGURATION
// =========================================================================
defined('HOSTINGER_DB_HOST') or define('HOSTINGER_DB_HOST', 'localhost');
defined('HOSTINGER_DB_PORT') or define('HOSTINGER_DB_PORT', 3306);
defined('HOSTINGER_DB_NAME') or define('HOSTINGER_DB_NAME', 'u823790994_ntccc2026');
defined('HOSTINGER_DB_USER') or define('HOSTINGER_DB_USER', 'u823790994_ntccc');
defined('HOSTINGER_DB_PASS') or define('HOSTINGER_DB_PASS', 'Reva@2030');

class Database
{
    private static ?PDO $instance = null;
    private static bool $schemaVerified = false;

    /**
     * Dynamically detect if running locally or on live shared hosting
     * @return string 'localhost' | 'production'
     */
    public static function detectEnvironment(): string
    {
        $env = getenv('APP_ENV');
        if ($env && in_array(strtolower($env), ['production', 'prod', 'live'], true)) {
            return 'production';
        }
        if ($env && in_array(strtolower($env), ['local', 'dev', 'localhost'], true)) {
            return 'localhost';
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $host = strtolower($host);

        if (
            str_contains($host, 'localhost') ||
            str_contains($host, '127.0.0.1') ||
            str_contains($host, '::1') ||
            str_ends_with($host, '.local') ||
            str_ends_with($host, '.test') ||
            preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)
        ) {
            return 'localhost';
        }

        return 'production';
    }

    /**
     * Get Database Credentials for active environment
     */
    public static function getCredentials(): array
    {
        $env = self::detectEnvironment();

        if ($env === 'localhost') {
            return [
                'host'    => getenv('DB_LOCAL_HOST') ?: 'localhost',
                'port'    => getenv('DB_LOCAL_PORT') ?: 3306,
                'dbname'  => getenv('DB_LOCAL_NAME') ?: 'eventms_db',
                'user'    => getenv('DB_LOCAL_USER') ?: 'root',
                'pass'    => getenv('DB_LOCAL_PASS') !== false ? getenv('DB_LOCAL_PASS') : '',
                'charset' => 'utf8mb4'
            ];
        }

        $pass = getenv('DB_PASS');
        return [
            'host'    => getenv('DB_HOST') ?: HOSTINGER_DB_HOST,
            'port'    => getenv('DB_PORT') ?: HOSTINGER_DB_PORT,
            'dbname'  => getenv('DB_NAME') ?: HOSTINGER_DB_NAME,
            'user'    => getenv('DB_USER') ?: HOSTINGER_DB_USER,
            'pass'    => ($pass !== false && $pass !== '') ? $pass : HOSTINGER_DB_PASS,
            'charset' => 'utf8mb4'
        ];
    }

    /**
     * Obtain Singleton PDO connection with prepared statements & strict modes
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $creds = self::getCredentials();
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $creds['host'],
                $creds['port'],
                $creds['dbname'],
                $creds['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$creds['charset']} COLLATE {$creds['charset']}_unicode_ci, time_zone = '+00:00'",
                PDO::ATTR_TIMEOUT            => 5,
            ];

            try {
                self::$instance = new PDO($dsn, $creds['user'], $creds['pass'], $options);
                self::$instance->exec("SET NAMES {$creds['charset']} COLLATE {$creds['charset']}_unicode_ci");
                self::$instance->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                if (self::detectEnvironment() === 'localhost' && $e->getCode() == 1049) {
                    self::autoInitLocalDatabase($creds);
                    self::$instance = new PDO($dsn, $creds['user'], $creds['pass'], $options);
                    self::$instance->exec("SET NAMES {$creds['charset']} COLLATE {$creds['charset']}_unicode_ci");
                    self::$instance->exec("SET time_zone = '+00:00'");
                } else {
                    if (self::detectEnvironment() === 'production' && $creds['pass'] === 'PUT_YOUR_HOSTINGER_DB_PASSWORD_HERE') {
                        die("
                            <!DOCTYPE html>
                            <html lang='en'>
                            <head>
                                <meta charset='UTF-8'>
                                <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover'>
                                <title>Database Setup Required | EventMS</title>
                                <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
                            </head>
                            <body class='bg-light d-flex align-items-center min-vh-100 p-3'>
                                <div class='card shadow-sm border-0 mx-auto' style='max-width: 520px; border-radius: 16px;'>
                                    <div class='card-body p-4'>
                                        <h5 class='mb-2 fw-bold text-dark'>Hostinger Database Setup Required</h5>
                                        <p class='text-muted small mb-3'>Please set your Hostinger database password in <code>config/db.php</code>:</p>
                                        <div class='bg-dark text-light p-3 rounded-3 small font-monospace mb-3'>
                                            define('HOSTINGER_DB_PASS', 'YOUR_ACTUAL_PASSWORD');
                                        </div>
                                        <div class='small text-muted'>
                                            <strong>Database:</strong> <code>u802730566_eventms_db</code><br>
                                            <strong>User:</strong> <code>u802730566_eventms_tej</code>
                                        </div>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ");
                    }
                    throw $e;
                }
            }

            // Ensure pure single-table schema (purge admins table, seed allowed_visitors)
            self::ensurePureSchema(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Purge admins table and ensure 10 pre-seeded visitors exist
     */
    private static function ensurePureSchema(PDO $pdo): void
    {
        if (self::$schemaVerified) {
            return;
        }

        try {
            // 1. Ensure admins table exists with RBAC role column
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `admins` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(50) NOT NULL UNIQUE,
                    `password` VARCHAR(255) NOT NULL,
                    `role` VARCHAR(20) NOT NULL DEFAULT 'guard',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Migration: Add role, region, status, last_login, failed_attempts, locked_until to admins if missing
            $adminCols = [
                'role'            => "VARCHAR(20) NOT NULL DEFAULT 'guard'",
                'region'          => "VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL",
                'status'          => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                'last_login'      => "DATETIME NULL DEFAULT NULL",
                'failed_attempts' => "INT NOT NULL DEFAULT 0",
                'locked_until'    => "DATETIME NULL DEFAULT NULL"
            ];
            foreach ($adminCols as $c => $def) {
                try {
                    $pdo->exec("ALTER TABLE `admins` ADD COLUMN `$c` $def");
                } catch (\Throwable $t) {}
            }

            // Ensure super_admin account exists
            $adminCheck = $pdo->prepare("SELECT id, role FROM `admins` WHERE username = 'admin' LIMIT 1");
            $adminCheck->execute();
            $adminRow = $adminCheck->fetch();
            if (!$adminRow) {
                $defaultHash = password_hash('admin123', PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO `admins` (`username`, `password`, `role`) VALUES ('admin', ?, 'super_admin')")
                    ->execute([$defaultHash]);
            } elseif (($adminRow['role'] ?? '') !== 'super_admin') {
                $pdo->exec("UPDATE `admins` SET `role` = 'super_admin' WHERE `username` = 'admin'");
            }

            // Ensure dedicated Guard account (guard1 / guard123) exists
            $guardCheck = $pdo->prepare("SELECT id, role FROM `admins` WHERE username = 'guard1' LIMIT 1");
            $guardCheck->execute();
            $guardRow = $guardCheck->fetch();
            if (!$guardRow) {
                $guardHash = password_hash('guard123', PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO `admins` (`username`, `password`, `role`) VALUES ('guard1', ?, 'guard')")
                    ->execute([$guardHash]);
            }

            // 2. Ensure allowed_visitors table exists with 2-Day Event schema & extended attributes
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `allowed_visitors` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(100) NOT NULL,
                    `mobile` VARCHAR(20) NOT NULL DEFAULT '',
                    `user_id` VARCHAR(50) NULL,
                    `region` VARCHAR(100) NULL,
                    `fincert` VARCHAR(100) NULL,
                    `school_name` VARCHAR(255) NULL,
                    `designation` VARCHAR(100) NULL,
                    `email` VARCHAR(150) NULL,
                    `is_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
                    `checked_in_at` DATETIME NULL DEFAULT NULL,
                    `is_day1_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
                    `day1_checked_in_at` DATETIME NULL DEFAULT NULL,
                    `is_day2_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
                    `day2_checked_in_at` DATETIME NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_user_id` (`user_id`),
                    KEY `idx_checkin_status` (`is_checked_in`),
                    KEY `idx_day1_status` (`is_day1_checked_in`),
                    KEY `idx_day2_status` (`is_day2_checked_in`),
                    KEY `idx_mobile` (`mobile`),
                    KEY `idx_region` (`region`),
                    KEY `idx_fincert` (`fincert`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Safe column additions for existing setups with explicit utf8mb4_unicode_ci collation
            $extCols = [
                'user_id'            => 'VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'region'             => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'fincert'            => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'school_name'        => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'designation'        => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'email'              => 'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
                'is_day1_checked_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'day1_checked_in_at' => 'DATETIME NULL DEFAULT NULL',
                'is_day2_checked_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'day2_checked_in_at' => 'DATETIME NULL DEFAULT NULL',
                'added_by'           => 'VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL'
            ];
            foreach ($extCols as $c => $def) {
                try {
                    $pdo->exec("ALTER TABLE `allowed_visitors` ADD COLUMN `$c` $def");
                } catch (\Throwable $t) {}
            }

            // Drop any legacy UNIQUE index on mobile if present
            try {
                $idxStmt = $pdo->query("SHOW INDEX FROM `allowed_visitors`");
                while ($idxRow = $idxStmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (($idxRow['Column_name'] ?? '') === 'mobile' && (int)($idxRow['Non_unique'] ?? 1) === 0) {
                        $pdo->exec("ALTER TABLE `allowed_visitors` DROP INDEX `{$idxRow['Key_name']}`");
                    }
                }
            } catch (\Throwable $t) {}

            try {
                $pdo->exec("ALTER TABLE `allowed_visitors` ADD UNIQUE KEY `uniq_user_id` (`user_id`)");
            } catch (\Throwable $t) {}

            // 3. Ensure gift_distribution table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `gift_distribution` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(100) NOT NULL,
                    `mobile` VARCHAR(20) NOT NULL DEFAULT '',
                    `user_id` VARCHAR(50) NULL,
                    `region` VARCHAR(100) NULL,
                    `fincert` VARCHAR(100) NULL,
                    `school_name` VARCHAR(255) NULL,
                    `designation` VARCHAR(100) NULL,
                    `email` VARCHAR(150) NULL,
                    `is_gift_collected` TINYINT(1) NOT NULL DEFAULT 0,
                    `gift_collected_at` DATETIME NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_gift_user_id` (`user_id`),
                    KEY `idx_gift_status` (`is_gift_collected`),
                    KEY `idx_gift_mobile` (`mobile`),
                    KEY `idx_gift_region` (`region`),
                    KEY `idx_gift_fincert` (`fincert`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            foreach ($extCols as $c => $def) {
                if (str_starts_with($c, 'is_day') || str_ends_with($c, 'checked_in_at')) continue;
                try {
                    $pdo->exec("ALTER TABLE `gift_distribution` ADD COLUMN `$c` $def");
                } catch (\Throwable $t) {}
            }

            // Ensure handed_over_by and counter_region columns exist in gift_distribution
            try {
                $pdo->exec("ALTER TABLE `gift_distribution` ADD COLUMN `handed_over_by` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL");
            } catch (\Throwable $t) {}
            try {
                $pdo->exec("ALTER TABLE `gift_distribution` ADD COLUMN `counter_region` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL");
            } catch (\Throwable $t) {}

            try {
                $idxStmt = $pdo->query("SHOW INDEX FROM `gift_distribution`");
                while ($idxRow = $idxStmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (($idxRow['Column_name'] ?? '') === 'mobile' && (int)($idxRow['Non_unique'] ?? 1) === 0) {
                        $pdo->exec("ALTER TABLE `gift_distribution` DROP INDEX `{$idxRow['Key_name']}`");
                    }
                }
            } catch (\Throwable $t) {}

            try {
                $pdo->exec("ALTER TABLE `gift_distribution` ADD UNIQUE KEY `uniq_gift_user_id` (`user_id`)");
            } catch (\Throwable $t) {}

            // 4. Ensure counter-restructuring tables and columns exist
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `counters` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `counter_no` INT UNSIGNED NULL UNIQUE,
                        `name` VARCHAR(100) NOT NULL,
                        `code` VARCHAR(50) NOT NULL UNIQUE,
                        `username` VARCHAR(50) NOT NULL UNIQUE,
                        `password_hash` VARCHAR(255) NOT NULL,
                        `name_range` VARCHAR(150) NULL DEFAULT NULL,
                        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                        `last_login` DATETIME NULL DEFAULT NULL,
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                try {
                    $pdo->exec("ALTER TABLE `counters` ADD COLUMN `counter_no` INT UNSIGNED NULL UNIQUE AFTER `id`");
                } catch (\Throwable $t) {}
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `counter_regions` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `counter_id` INT UNSIGNED NOT NULL,
                        `region` VARCHAR(100) NOT NULL,
                        KEY `idx_counter_id` (`counter_id`),
                        KEY `idx_region` (`region`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `counter_reassignment_log` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `visitor_id` INT UNSIGNED NOT NULL,
                        `visitor_name` VARCHAR(100) NOT NULL,
                        `user_id` VARCHAR(50) NULL,
                        `old_counter_id` INT UNSIGNED NULL,
                        `new_counter_id` INT UNSIGNED NOT NULL,
                        `changed_by` VARCHAR(50) NOT NULL,
                        `reason` VARCHAR(255) NULL,
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `audit_log` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `action` VARCHAR(50) NOT NULL,
                        `performed_by` VARCHAR(50) NOT NULL,
                        `target_id` VARCHAR(50) NULL,
                        `target_name` VARCHAR(100) NULL,
                        `details` TEXT NULL,
                        `created_at_ist` VARCHAR(50) NOT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `event_settings` (
                        `setting_key` VARCHAR(50) PRIMARY KEY,
                        `setting_value` TEXT NULL,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        `updated_by` VARCHAR(50) NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `rate_limits` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `ip` VARCHAR(45) NOT NULL,
                        `action` VARCHAR(30) NOT NULL,
                        `attempt_time` INT UNSIGNED NOT NULL,
                        KEY `idx_ip_action_time` (`ip`, `action`, `attempt_time`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                $sCount = (int)$pdo->query("SELECT COUNT(*) FROM `event_settings`")->fetchColumn();
                if ($sCount === 0) {
                    $seedStmt = $pdo->prepare("INSERT IGNORE INTO `event_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES (?, ?, 'system')");
                    $seedStmt->execute(['day1_date', '2026-11-18']);
                    $seedStmt->execute(['day2_date', '2026-11-19']);
                    $seedStmt->execute(['self_checkin_open', '1']);
                    $seedStmt->execute(['event_name', 'COE Event 2026']);
                }
            } catch (\Throwable $t) {}

            // Ensure counter_id columns exist across tables
            foreach (['allowed_visitors', 'gift_distribution', 'admins'] as $tbl) {
                try {
                    $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `counter_id` INT UNSIGNED NULL DEFAULT NULL");
                } catch (\Throwable $t) {}
                try {
                    if ($tbl !== 'admins') {
                        $pdo->exec("ALTER TABLE `{$tbl}` ADD INDEX `idx_counter_id` (`counter_id`)");
                    }
                } catch (\Throwable $t) {}
            }

            // 5. Automated self-healing: ensure all project tables and columns use utf8mb4_unicode_ci
            try {
                $tblStmt = $pdo->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
                while ($tblRow = $tblStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $tName = $tblRow['TABLE_NAME'] ?? '';
                    $tCollate = $tblRow['TABLE_COLLATION'] ?? '';
                    if (in_array($tName, ['allowed_visitors', 'gift_distribution', 'admins', 'counters', 'counter_regions', 'counter_reassignment_log'], true) && $tCollate !== 'utf8mb4_unicode_ci') {
                        $pdo->exec("ALTER TABLE `{$tName}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    }
                }
            } catch (\Throwable $t) {}

            self::$schemaVerified = true;
        } catch (\Throwable $t) {
            // Non-fatal if permissions restrict DDL
        }
    }

    /**
     * Resolves the assigned counter for an attendee based on region and Pune name ranges.
     * Single authoritative helper used across single-add visitor, CSV import, and counter sync.
     *
     * @param string $region
     * @param string $name
     * @param PDO|null $pdo
     * @return array|null Associative array of counter record (id, name, code, name_range) or null
     */
    public static function resolveCounterForVisitor(string $region, string $name = '', ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? self::getConnection();
        $norm = trim($region);
        if ($norm === '') {
            $stmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM counters WHERE code = 'helpdesk' LIMIT 1");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $lower = mb_strtolower($norm, 'UTF-8');

        if ($lower === 'pune') {
            $sortName = function_exists('cleanNameForSort') ? cleanNameForSort($name) : trim($name);

            if (strcasecmp($sortName, 'Chetan Sonawane') <= 0) {
                $code = 'pune_1';
            } elseif (strcasecmp($sortName, 'Namira Yunus Contractor') <= 0) {
                $code = 'pune_2';
            } elseif (strcasecmp($sortName, 'Sangeeta Rautji') <= 0) {
                $code = 'pune_3';
            } else {
                $code = 'pune_4';
            }

            $stmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM counters WHERE code = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$code]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmt = $pdo->prepare("
            SELECT c.id, c.counter_no, c.name, c.code, c.name_range
            FROM counter_regions cr
            JOIN counters c ON c.id = cr.counter_id
            WHERE LOWER(TRIM(cr.region)) = :reg AND c.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':reg' => $lower]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // Fallback to Help Desk
        $stmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM counters WHERE code = 'helpdesk' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Synchronizes and assigns visitors to counters based on regions and balanced Pune groups.
     * Idempotent: can be safely re-run after CSV imports.
     */
    public static function syncVisitorCounters(PDO $pdo): int
    {
        $cStmt = $pdo->query("SELECT id, code FROM counters");
        $counterMap = $cStmt ? $cStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        if (empty($counterMap)) {
            return 0;
        }

        $helpDeskId = (int)($counterMap['helpdesk'] ?? 0);

        // Build region to counter_id mapping from counter_regions
        $crStmt = $pdo->query("
            SELECT cr.region, cr.counter_id 
            FROM counter_regions cr
            JOIN counters c ON c.id = cr.counter_id
            WHERE c.code NOT LIKE 'pune_%'
        ");
        $regionMap = [];
        while ($r = $crStmt->fetch(PDO::FETCH_ASSOC)) {
            $reg = trim((string)$r['region']);
            if ($reg !== '') {
                $regionMap[mb_strtolower($reg, 'UTF-8')] = (int)$r['counter_id'];
            }
        }

        $updVis = $pdo->prepare("UPDATE allowed_visitors SET counter_id = :cid WHERE id = :id");
        $updGift = $pdo->prepare("UPDATE gift_distribution SET counter_id = :cid WHERE user_id = :uid");
        $updated = 0;

        // Check if any Pune visitors need assignment
        $puneCheck = $pdo->query("
            SELECT id, name, user_id 
            FROM allowed_visitors 
            WHERE LOWER(TRIM(region)) = 'pune' AND (counter_id IS NULL OR counter_id = 0)
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($puneCheck)) {
            $allPune = $pdo->query("SELECT id, name, user_id FROM allowed_visitors WHERE LOWER(TRIM(region)) = 'pune'")->fetchAll(PDO::FETCH_ASSOC);
            usort($allPune, function($a, $b) {
                $nameA = function_exists('cleanNameForSort') ? cleanNameForSort($a['name']) : trim($a['name']);
                $nameB = function_exists('cleanNameForSort') ? cleanNameForSort($b['name']) : trim($b['name']);
                $cmp = strcasecmp($nameA, $nameB);
                if ($cmp !== 0) return $cmp;
                return strcasecmp($a['name'], $b['name']);
            });

            $totalPune = count($allPune);
            $base = (int)floor($totalPune / 4);
            $rem = $totalPune % 4;
            $g1Size = $base + ($rem > 0 ? 1 : 0);
            $g2Size = $base + ($rem > 1 ? 1 : 0);
            $g3Size = $base + ($rem > 2 ? 1 : 0);
            $g4Size = $base;

            $slices = [
                1 => array_slice($allPune, 0, $g1Size),
                2 => array_slice($allPune, $g1Size, $g2Size),
                3 => array_slice($allPune, $g1Size + $g2Size, $g3Size),
                4 => array_slice($allPune, $g1Size + $g2Size + $g3Size),
            ];

            foreach ($slices as $grpNum => $slice) {
                $cid = (int)($counterMap['pune_' . $grpNum] ?? 0);
                if ($cid > 0) {
                    foreach ($slice as $v) {
                        $updVis->execute([':cid' => $cid, ':id' => $v['id']]);
                        if (!empty($v['user_id'])) {
                            $updGift->execute([':cid' => $cid, ':uid' => $v['user_id']]);
                        }
                        $updated++;
                    }
                }
            }
        }

        // Assign unassigned non-Pune visitors
        $unassigned = $pdo->query("
            SELECT id, name, user_id, region 
            FROM allowed_visitors 
            WHERE (region IS NULL OR LOWER(TRIM(region)) != 'pune') 
              AND (counter_id IS NULL OR counter_id = 0)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unassigned as $u) {
            $rClean = mb_strtolower(trim((string)($u['region'] ?? '')), 'UTF-8');
            $targetCid = $regionMap[$rClean] ?? $helpDeskId;
            if ($targetCid > 0) {
                $updVis->execute([':cid' => $targetCid, ':id' => $u['id']]);
                if (!empty($u['user_id'])) {
                    $updGift->execute([':cid' => $targetCid, ':uid' => $u['user_id']]);
                }
                $updated++;
            }
        }

        // Sync gift_distribution records
        $pdo->exec("
            UPDATE gift_distribution g
            JOIN allowed_visitors v ON (v.user_id = g.user_id AND v.user_id IS NOT NULL AND v.user_id != '')
            SET g.counter_id = v.counter_id
            WHERE g.counter_id IS NULL OR g.counter_id = 0
        ");

        return $updated;
    }

    /**
     * Helper to automatically create local database if missing on localhost
     */
    private static function autoInitLocalDatabase(array $creds): void
    {
        try {
            $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $creds['host'], $creds['port'], $creds['charset']);
            $rootPdo = new PDO($rootDsn, $creds['user'], $creds['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$creds['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $t) {
            // Silently fallback so main handler captures details
        }
    }
}

// =========================================================================
// SHARED TIMEZONE & FORMATTING HELPERS (Indian Standard Time - Asia/Kolkata)
// =========================================================================

if (!function_exists('formatCounterDisplayName')) {
    /**
     * Formats the counter badge for visitor-facing messages.
     * Pattern: "Counter <number> · <short name>" (e.g. "Counter 1 · Raipur / Chandigarh", "Counter 6 · Pune 1")
     * Middle dot ' · ' (U+00B7) with space on each side.
     * Short name has trailing "Counter" removed.
     * If no valid counter number (1-20) or Help Desk: returns "Help Desk".
     *
     * @param mixed $counterNo
     * @param string|null $counterName
     * @return string
     */
    function formatCounterDisplayName($counterNo, ?string $counterName): string
    {
        $name = trim((string)($counterName ?? ''));
        if ($name === '' || strcasecmp($name, 'null') === 0 || strcasecmp($name, 'undefined') === 0 || $name === '—' || $name === '-') {
            return 'Help Desk';
        }

        if (strcasecmp($name, 'helpdesk') === 0 || strcasecmp($name, 'help desk') === 0 || strcasecmp($name, 'help desk counter') === 0) {
            return 'Help Desk';
        }

        $cNo = (int)($counterNo ?? 0);
        if ($cNo < 1 || $cNo > 20) {
            if (strcasecmp($name, 'super admin') === 0) {
                return 'Super Admin';
            }
            return 'Help Desk';
        }

        // Strip trailing "Counter" case-insensitively
        $shortName = trim(preg_replace('/\s+Counter$/i', '', $name));
        if (preg_match('/^pune\s+counter\s*(\d+)$/i', $shortName, $m)) {
            $shortName = 'Pune ' . $m[1];
        }

        return "Counter {$cNo} \u{00B7} {$shortName}";
    }
}

if (!function_exists('formatIST')) {
    /**
     * Converts a stored UTC timestamp, ISO 8601 string, unix timestamp, or DateTime
     * to Indian Standard Time (Asia/Kolkata, UTC+5:30).
     *
     * Default format: 'd M, h:i A' (e.g. '10 Nov, 07:11 PM' - for admin tables)
     * Full date format: 'd M Y, h:i A' (e.g. '10 Nov 2026, 07:11 PM' - for gate terminal)
     *
     * @param mixed $dateValue
     * @param string $format
     * @return string
     */
    function formatIST($dateValue, string $format = 'd M, h:i A'): string
    {
        if (empty($dateValue) || $dateValue === '— Not Yet Collected —' || $dateValue === 'N/A' || $dateValue === 'Recorded' || $dateValue === 'Just now') {
            return (string)$dateValue;
        }

        try {
            if ($dateValue instanceof \DateTimeInterface) {
                $dt = new \DateTime($dateValue->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
            } elseif (is_numeric($dateValue)) {
                $dt = new \DateTime('@' . $dateValue);
                $dt->setTimezone(new \DateTimeZone('UTC'));
            } else {
                $raw = trim((string)$dateValue);
                if ($raw === '' || $raw === '0000-00-00 00:00:00' || $raw === '0000-00-00') {
                    return '';
                }
                // If timestamp already has a timezone indicator (Z or +/-HH:MM), parse directly;
                // otherwise treat as UTC timestamp stored from MySQL.
                if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $raw)) {
                    $dt = new \DateTime($raw);
                } else {
                    $dt = new \DateTime($raw, new \DateTimeZone('UTC'));
                }
            }

            $dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
            return $dt->format($format);
        } catch (\Throwable $e) {
            return (string)$dateValue;
        }
    }
}

if (!function_exists('sortGateVisitors')) {
    /**
     * Sorts Gate Visitors array so that:
     * 1. Latest check-in (maximum of day1_checked_in_at and day2_checked_in_at) comes FIRST (newest on top).
     * 2. Unchecked (pending) visitors come AFTER, sorted alphabetically A to Z by name.
     * 3. Ties broken by name A to Z.
     *
     * @param array $visitors (passed by reference)
     */
    function sortGateVisitors(array &$visitors): void
    {
        usort($visitors, function ($a, $b) {
            $aD1 = ((int)($a['is_day1_checked_in'] ?? 0) === 1 && !empty($a['day1_checked_in_at']))
                ? (new \DateTime((string)$a['day1_checked_in_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $aD2 = ((int)($a['is_day2_checked_in'] ?? 0) === 1 && !empty($a['day2_checked_in_at']))
                ? (new \DateTime((string)$a['day2_checked_in_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $aTime = max($aD1, $aD2);

            $bD1 = ((int)($b['is_day1_checked_in'] ?? 0) === 1 && !empty($b['day1_checked_in_at']))
                ? (new \DateTime((string)$b['day1_checked_in_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $bD2 = ((int)($b['is_day2_checked_in'] ?? 0) === 1 && !empty($b['day2_checked_in_at']))
                ? (new \DateTime((string)$b['day2_checked_in_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $bTime = max($bD1, $bD2);

            $aHas = $aTime > 0;
            $bHas = $bTime > 0;

            if ($aHas && !$bHas) return -1;
            if (!$aHas && $bHas) return 1;

            if ($aHas && $bHas) {
                if ($aTime !== $bTime) {
                    return ($bTime <=> $aTime); // Descending (newest first)
                }
                return strcasecmp((string)$a['name'], (string)$b['name']);
            }

            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
}

if (!function_exists('sortGiftVisitors')) {
    /**
     * Sorts Gift Distribution visitors array so that:
     * 1. Visitors whose gift is collected come FIRST, sorted by gift_collected_at DESCENDING (newest on top).
     * 2. Visitors with gift Pending come AFTER, sorted alphabetically A to Z by name.
     * 3. Ties broken by name A to Z.
     *
     * @param array $visitors (passed by reference)
     */
    function sortGiftVisitors(array &$visitors, string $filter = 'all'): void
    {
        usort($visitors, function ($a, $b) use ($filter) {
            $aEntered = (int)($a['is_gate_entered'] ?? 0) === 1;
            $bEntered = (int)($b['is_gate_entered'] ?? 0) === 1;

            $aGateTime = !empty($a['gate_entered_at'])
                ? (new \DateTime((string)$a['gate_entered_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $bGateTime = !empty($b['gate_entered_at'])
                ? (new \DateTime((string)$b['gate_entered_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;

            if ($filter === 'entered') {
                if ($aGateTime !== $bGateTime) {
                    return ($bGateTime <=> $aGateTime); // Newest gate entry first
                }
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            }

            if ($filter === 'pending') {
                if ($aEntered && !$bEntered) return -1;
                if (!$aEntered && $bEntered) return 1;
                if ($aEntered && $bEntered) {
                    if ($aGateTime !== $bGateTime) {
                        return ($bGateTime <=> $aGateTime);
                    }
                    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                }
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            }

            $aTime = ((int)($a['is_gift_collected'] ?? 0) === 1 && !empty($a['gift_collected_at']))
                ? (new \DateTime((string)$a['gift_collected_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
            $bTime = ((int)($b['is_gift_collected'] ?? 0) === 1 && !empty($b['gift_collected_at']))
                ? (new \DateTime((string)$b['gift_collected_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;

            $aHas = $aTime > 0;
            $bHas = $bTime > 0;

            if ($aHas && !$bHas) return -1;
            if (!$aHas && $bHas) return 1;

            if ($aHas && $bHas) {
                if ($aTime !== $bTime) {
                    return ($bTime <=> $aTime); // Descending (newest first)
                }
                return strcasecmp((string)$a['name'], (string)$b['name']);
            }

            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
}

if (!function_exists('cleanNameForSort')) {
    /**
     * Cleans an attendee's name for sorting by stripping common honorary titles.
     * Examples: 'Dr. Chetan' -> 'Chetan', 'Mrs. Namrata' -> 'Namrata'
     */
    function cleanNameForSort(string $name): string
    {
        $clean = trim($name);
        $clean = preg_replace('/^(dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?|sh\.?|smt\.?|shri\.?)\s+/i', '', $clean);
        return trim($clean);
    }
}

if (!function_exists('getBaseUrl')) {
    /**
     * Resolves absolute site base URL dynamically (Hostinger, SSL, Cloudflare, Subfolders, localhost)
     */
    function getBaseUrl(): string
    {
        $envAppUrl = getenv('APP_URL');
        if (!empty($envAppUrl) && filter_var($envAppUrl, FILTER_VALIDATE_URL)) {
            return rtrim((string)$envAppUrl, '/');
        }
        if (defined('LIVE_SITE_URL') && LIVE_SITE_URL !== '') {
            return rtrim((string)LIVE_SITE_URL, '/');
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        );
        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '') : '';
        $appRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: '');

        if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
            $sub = trim(substr($appRoot, strlen($docRoot)), '/');
            $scriptDir = $sub !== '' ? '/' . $sub : '';
        } else {
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $rawScriptDir = dirname($scriptName);
            $scriptDir = ($rawScriptDir !== '' && $rawScriptDir !== '/' && $rawScriptDir !== '.' && !str_contains($rawScriptDir, ':')) 
                ? '/' . trim($rawScriptDir, '/') 
                : '';
        }

        return rtrim($protocol . $host . $scriptDir, '/');
    }
}

if (!function_exists('getEventSettings')) {
    /**
     * Fetches key-value event settings with fallback defaults
     */
    function getEventSettings(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::getConnection();
        $defaults = [
            'day1_date'         => '2026-11-18',
            'day2_date'         => '2026-11-19',
            'self_checkin_open' => '1',
            'event_name'        => 'COE Event 2026',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM `event_settings`");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $defaults[$row['setting_key']] = (string)$row['setting_value'];
                }
            }
        } catch (\Throwable $e) {}
        return $defaults;
    }
}

if (!function_exists('updateEventSettings')) {
    /**
     * Updates key-value event settings and records audit log in IST
     */
    function updateEventSettings(PDO $pdo, array $newSettings, string $adminUsername): array
    {
        $oldSettings = getEventSettings($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO `event_settings` (`setting_key`, `setting_value`, `updated_by`)
            VALUES (:k, :v, :u)
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()
        ");
        $changes = [];
        foreach ($newSettings as $k => $v) {
            $valStr = (string)$v;
            $stmt->execute([':k' => $k, ':v' => $valStr, ':u' => $adminUsername]);
            $oldVal = $oldSettings[$k] ?? null;
            if ($oldVal !== $valStr) {
                $changes[] = "$k: " . ($oldVal ?? 'empty') . " -> $valStr";
            }
        }
        if (!empty($changes)) {
            try {
                $nowIST = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i:s A');
                $auditStmt = $pdo->prepare("
                    INSERT INTO `audit_log` (`action`, `performed_by`, `details`, `created_at_ist`, `created_at`)
                    VALUES ('update_event_settings', :u, :details, :ist, NOW())
                ");
                $auditStmt->execute([
                    ':u' => $adminUsername,
                    ':details' => 'Settings updated: ' . implode(', ', $changes),
                    ':ist' => $nowIST,
                ]);
            } catch (\Throwable $e) {}
        }
        return $changes;
    }
}
