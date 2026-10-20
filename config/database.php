<?php
// =====================================================
// config/database.php — PDO Singleton
// =====================================================

class DB
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        // rowCount() after an UPDATE counts the rows the
                        // WHERE matched, not only the ones whose values
                        // changed. Otherwise saving a record unchanged
                        // looked like "not found", and code worked around it
                        // with rowCount() >= 0, which reported success for a
                        // row that did not exist or belonged to someone else.
                        PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                        // Run the session on PHP's clock. The server
                        // defaults to UTC while PHP uses TIMEZONE, so
                        // CURDATE()/NOW() disagreed with date() for part of
                        // every day. An offset like '+07:00' needs no
                        // time zone tables on the server.
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . date('P') . "'"
                    ]
                );
            } catch (PDOException $e) {
                // Do not expose credentials in error messages
                http_response_code(500);
                die(json_encode(['error' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้']));
            }
        }
        return self::$pdo;
    }

    /**
     * Convenience: prepare + execute, return statement
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
