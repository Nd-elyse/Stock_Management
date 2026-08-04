<?php
$DB_HOST = getenv('DB_HOST') ?: 'localhost';      
$DB_NAME = getenv('DB_NAME') ?: 'management';     
$DB_USER = getenv('DB_USER') ?: 'root';           
$DB_PASS = getenv('DB_PASS') ?: '';               

function reindex_table_ids(PDO $pdo, string $table, string $idColumn): void {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $idColumn);

    if ($safeTable === '' || $safeColumn === '') {
        throw new InvalidArgumentException('Invalid table or column name.');
    }

    $maxId = (int) $pdo->query("SELECT COALESCE(MAX(`{$safeColumn}`), 0) FROM `{$safeTable}`")->fetchColumn();
    $pdo->exec("SET @row_number := 0");
    $pdo->exec("UPDATE `{$safeTable}` SET `{$safeColumn}` = (@row_number := @row_number + 1) ORDER BY `{$safeColumn}`");
    $pdo->exec("ALTER TABLE `{$safeTable}` AUTO_INCREMENT = " . ($maxId > 0 ? $maxId + 1 : 1));
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            // Throw exceptions on errors instead of silent failures
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Return rows as associative arrays: $row['ColumnName']
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use real prepared statements (safer against SQL injection)
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Optional: reuse a connection per PHP worker instead of a fresh
            // TCP/auth handshake every request. Off by default because this
            // codebase runs "SELECT ... FOR UPDATE" inside transactions
            // (see backend/api/inventory.php); if a request ever dies
            // mid-transaction, a persistent connection can carry an open
            // transaction (and its row locks) into the next request that
            // reuses it. Turn on with DB_PERSISTENT=1 once you've confirmed
            // your hosting's PHP process model handles that safely - the
            // stray-transaction rollback below guards against the common
            // case either way.
            PDO::ATTR_PERSISTENT         => (getenv('DB_PERSISTENT') === '1'),
            // Don't hang the whole page if the DB is briefly unreachable.
            PDO::ATTR_TIMEOUT            => 5,
        ]
    );

    // Safety net for persistent connections: if a prior request on this
    // same pooled connection crashed before COMMIT/ROLLBACK, clear that
    // state before this request runs any queries of its own.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // ------------------------------------------------------------------
    // One-time schema migration for stocktransactions tracking columns.
    //
    // NOTE: this used to run 4x "ALTER TABLE ... ADD COLUMN IF NOT EXISTS"
    // on EVERY page load / API call. ALTER TABLE takes a metadata lock
    // and MySQL still has to check the information_schema each time even
    // when the column already exists, so this was adding real, avoidable
    // latency to literally every request in the system. It only ever
    // needs to run once per database. It's now gated behind a marker
    // file so it fires once and never again.
    // ------------------------------------------------------------------
    $migrationMarker = __DIR__ . '/.stocktransactions_migrated';
    if (!file_exists($migrationMarker)) {
        try {
            $pdo->exec("ALTER TABLE stocktransactions ADD COLUMN IF NOT EXISTS BeforeQty INT(11) DEFAULT NULL");
            $pdo->exec("ALTER TABLE stocktransactions ADD COLUMN IF NOT EXISTS AfterQty INT(11) DEFAULT NULL");
            $pdo->exec("ALTER TABLE stocktransactions ADD COLUMN IF NOT EXISTS UserID INT(11) DEFAULT NULL");
            $pdo->exec("ALTER TABLE stocktransactions ADD INDEX IF NOT EXISTS idx_stocktransactions_user (UserID)");
            @file_put_contents($migrationMarker, date('c'));
        } catch (PDOException $e) {
            // If migration fails, log but don't break the connection
            error_log('Stock transactions table migration failed: ' . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    // Log detailed error server-side
    error_log('Database connection failed: ' . $e->getMessage());
    
    // Return generic message to client
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please contact support.'
    ]);
    exit;
}
