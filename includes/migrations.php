<?php
/**
 * TaskFlow Database Migration Runner & Baseline Manager
 * Handles listing, baseline detection, and idempotent execution of schema migrations.
 */

require_once __DIR__ . '/db.php';

function get_migrations_dir(): string {
    return dirname(__DIR__) . '/db/migrations';
}

/**
 * Checks whether the changes in a migration file are already present in the database.
 */
function migration_is_already_present(PDO $pdo, string $filename, string $sqlContent): bool {
    // 1. Direct known migration checks for reliability
    if ($filename === '002_user_admin.sql') {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'
        ");
        return (int)$stmt->fetchColumn() > 0;
    }

    if ($filename === '003_settings.sql') {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'settings'
        ");
        return (int)$stmt->fetchColumn() > 0;
    }

    if ($filename === '004_task_reminders.sql') {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'task_reminders'
        ");
        return (int)$stmt->fetchColumn() > 0;
    }

    if ($filename === '005_task_comments.sql') {
        $tStmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'task_comments'
        ");
        $hasComments = (int)$tStmt->fetchColumn() > 0;

        $cStmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'link'
        ");
        $hasLink = (int)$cStmt->fetchColumn() > 0;

        return $hasComments && $hasLink;
    }

    // 2. Generic AST / Regex detector for arbitrary migration files
    $hasChecks = false;

    // Check CREATE TABLE statements
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sqlContent, $tableMatches)) {
        foreach ($tableMatches[1] as $table) {
            $hasChecks = true;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $chk->execute([$table]);
            if ((int)$chk->fetchColumn() === 0) {
                return false;
            }
        }
    }

    // Check ALTER TABLE ... ADD COLUMN statements
    if (preg_match_all('/ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD\s+(?:COLUMN\s+)?`?([a-zA-Z0-9_]+)`?/i', $sqlContent, $colMatches, PREG_SET_ORDER)) {
        foreach ($colMatches as $match) {
            $hasChecks = true;
            $table = $match[1];
            $col = $match[2];
            $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $chk->execute([$table, $col]);
            if ((int)$chk->fetchColumn() === 0) {
                return false;
            }
        }
    }

    return $hasChecks;
}

/**
 * Ensures schema_migrations table exists and performs baseline detection for existing tables.
 */
function init_migrations_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Fetch already recorded migrations
    $recorded = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $recordedSet = array_flip($recorded);

    $migrationsDir = get_migrations_dir();
    if (!is_dir($migrationsDir)) {
        return;
    }

    $files = glob($migrationsDir . '/*.sql');
    if (!$files) {
        return;
    }
    sort($files, SORT_STRING);

    // Baseline detection: mark existing tables/columns as applied without re-running
    $ins = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (?, NOW())");
    foreach ($files as $filePath) {
        $basename = basename($filePath);
        if (isset($recordedSet[$basename])) {
            continue;
        }

        $sqlContent = file_get_contents($filePath);
        if ($sqlContent !== false && migration_is_already_present($pdo, $basename, $sqlContent)) {
            $ins->execute([$basename]);
        }
    }
}

/**
 * Returns all migration files with status and applied timestamp.
 */
function get_all_migrations_status(PDO $pdo): array {
    init_migrations_table($pdo);

    $migrationsDir = get_migrations_dir();
    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    $stmt = $pdo->query("SELECT filename, applied_at FROM schema_migrations");
    $applied = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $applied[$row['filename']] = $row['applied_at'];
    }

    $result = [];
    foreach ($files as $filePath) {
        $basename = basename($filePath);
        $isApplied = isset($applied[$basename]);
        $result[] = [
            'filename' => $basename,
            'status' => $isApplied ? 'applied' : 'pending',
            'applied_at' => $isApplied ? $applied[$basename] : null
        ];
    }
    return $result;
}

/**
 * Returns list of pending migration filenames in order.
 * Caches result statically per request.
 */
function get_pending_migrations(PDO $pdo, bool $fresh = false): array {
    static $cachedPending = null;
    if (!$fresh && $cachedPending !== null) {
        return $cachedPending;
    }

    $all = get_all_migrations_status($pdo);
    $pending = [];
    foreach ($all as $m) {
        if ($m['status'] === 'pending') {
            $pending[] = $m['filename'];
        }
    }

    $cachedPending = $pending;
    return $pending;
}

function reset_migrations_cache(): void {
    get_pending_migrations(new PDO('sqlite::memory:'), true); // dummy reset
}

/**
 * Executes a single SQL migration file with idempotency checks.
 */
function execute_migration_file(PDO $pdo, string $filePath): void {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new Exception("Could not read migration file: " . basename($filePath));
    }

    // Strip comments
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Split on semicolons
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));

    foreach ($statements as $stmt) {
        if ($stmt === '') continue;

        // Idempotent column check for ALTER TABLE ... ADD COLUMN ...
        if (preg_match('/ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD\s+(?:COLUMN\s+)?`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) {
            $tableName = $m[1];
            $colName = $m[2];
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            ");
            $chk->execute([$tableName, $colName]);
            if ((int)$chk->fetchColumn() > 0) {
                // Column already exists, skip
                continue;
            }
        }

        $pdo->exec($stmt);
    }
}

/**
 * Applies all pending migrations in order, stopping at the first failure.
 * Returns array: ['applied' => [...], 'failed' => ['migration' => '...', 'error' => '...']|null]
 */
function apply_pending_migrations(PDO $pdo): array {
    init_migrations_table($pdo);
    $pending = get_pending_migrations($pdo, true);
    $migrationsDir = get_migrations_dir();

    $applied = [];
    $ins = $pdo->prepare("INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())");

    foreach ($pending as $filename) {
        $filePath = $migrationsDir . '/' . $filename;
        try {
            execute_migration_file($pdo, $filePath);
            $ins->execute([$filename]);
            $applied[] = $filename;
        } catch (Throwable $e) {
            // Stop at first failure
            get_pending_migrations($pdo, true); // Refresh cache
            return [
                'applied' => $applied,
                'failed' => [
                    'migration' => $filename,
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    get_pending_migrations($pdo, true); // Refresh cache
    return [
        'applied' => $applied,
        'failed' => null
    ];
}
