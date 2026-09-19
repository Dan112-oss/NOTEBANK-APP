<?php
/**
 * PDO connection singleton. Every query in the application goes through
 * get_db() and uses prepared statements — no exceptions.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('NOTE BANK DB CONNECTION FAILED: ' . $e->getMessage());
        http_response_code(500);
        if (!IS_PRODUCTION) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        die('Note Bank is temporarily unavailable. Please try again shortly.');
    }

    return $pdo;
}
