<?php
/**
 * DMS - Distribution Management System
 * Database Connection Provider (PDO Singleton)
 */

require_once __DIR__ . '/constants.php';

class Database {
    private static ?PDO $instance = null;

    /**
     * Get active database connection
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . (defined('DB_TIMEZONE') ? DB_TIMEZONE : '+05:00') . "'"
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Log technical error privately
                $logFile = LOGS_PATH . DIRECTORY_SEPARATOR . 'db_errors.log';
                $timestamp = date('Y-m-d H:i:s');
                $message = "[{$timestamp}] PDO Connection Failed: " . $e->getMessage() . PHP_EOL;
                @file_put_contents($logFile, $message, FILE_APPEND);

                // Throw standardized exception without leaking credentials to client
                throw new Exception("Database connection failure. Please verify MySQL service status or run setup.");
            }
        }

        return self::$instance;
    }

    /**
     * Check if database exists and connection succeeds
     *
     * @return bool
     */
    public static function testConnection(): bool {
        try {
            self::getConnection();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Functional shortcut for getting PDO connection
 *
 * @return PDO
 */
function db(): PDO {
    return Database::getConnection();
}
