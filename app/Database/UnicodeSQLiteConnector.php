<?php

namespace App\Database;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;

/**
 * SQLite's built-in lower() only folds ASCII, so a search for "école" would not find "École".
 * Every SQLite connection gets a Unicode lower() (mb_strtolower) in its place. MySQL is unaffected:
 * it never goes through this connector.
 */
class UnicodeSQLiteConnector extends SQLiteConnector
{
    public function connect(array $config)
    {
        $pdo = parent::connect($config);
        self::registerFunctions($pdo);

        return $pdo;
    }

    public static function registerFunctions(PDO $pdo): void
    {
        $lower = static fn ($value) => is_string($value) ? mb_strtolower($value, 'UTF-8') : $value;
        $flags = defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0;

        if (class_exists(\Pdo\Sqlite::class) && $pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction('lower', $lower, 1, $flags);
        } else {
            $pdo->sqliteCreateFunction('lower', $lower, 1, $flags);
        }
    }
}
