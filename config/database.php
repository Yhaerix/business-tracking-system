<?php
/**
 * Database Connection Pool & PDO Factory
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

class Database {
    private static ?PDO $instance = null;

    // Database Credentials for local XAMPP environment
    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'business_tracking_db';
    private const DB_USER = 'root';
    private const DB_PASS = '';

    /**
     * Singleton instance accessor
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
            } catch (PDOException $e) {
                // Defensive Error Shielding: Log details securely without exposing credentials or stack traces to client
                error_log('[Database Connection Error] ' . $e->getMessage());
                die(json_encode([
                    'success' => false,
                    'message' => 'Database connection unavailable. Please ensure MySQL is running in XAMPP and the schema is imported.',
                    'data'    => null,
                    'errors'  => []
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        return self::$instance;
    }

    /**
     * Prevent direct cloning and instantiation
     */
    private function __construct() {}
    private function __clone() {}
}

/**
 * Global helper function to retrieve active PDO instance
 */
function get_db(): PDO {
    return Database::getConnection();
}

