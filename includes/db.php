<?php
declare(strict_types=1);

/** PDO singleton with strict, prepared-statement-only configuration. */
final class DB
{
    private static ?PDO $pdo = null;

    /** Return shared PDO connection. */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]);
            } catch (Throwable $e) {
                error_log('DB connect failed: ' . $e->getMessage());
                http_response_code(500);
                exit('Database unavailable.');
            }
        }
        return self::$pdo;
    }
}
