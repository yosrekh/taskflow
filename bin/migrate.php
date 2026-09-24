<?php
/**
 * TaskFlow Database Migration CLI Runner
 *
 * Usage:
 *   php bin/migrate.php --status
 *   php bin/migrate.php --apply
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. CLI only.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/db.php';
require_once $projectRoot . '/includes/migrations.php';

$options = getopt('', ['status', 'apply']);

if (!isset($options['status']) && !isset($options['apply'])) {
    echo "TaskFlow Database Migration Tool\n";
    echo "Usage:\n";
    echo "  php bin/migrate.php --status   Show status of all migrations\n";
    echo "  php bin/migrate.php --apply    Apply all pending migrations\n";
    exit(0);
}

if (isset($options['status'])) {
    echo "Checking migrations for database [" . DB_NAME . "]...\n\n";
    $all = get_all_migrations_status($pdo);
    $pendingCount = 0;
    $appliedCount = 0;

    printf("%-30s | %-10s | %-20s\n", "Migration File", "Status", "Applied At");
    echo str_repeat("-", 68) . "\n";

    foreach ($all as $m) {
        if ($m['status'] === 'applied') {
            $appliedCount++;
            printf("%-30s | \033[32m%-10s\033[0m | %-20s\n", $m['filename'], "APPLIED", $m['applied_at'] ?? 'N/A');
        } else {
            $pendingCount++;
            printf("%-30s | \033[33m%-10s\033[0m | %-20s\n", $m['filename'], "PENDING", "-");
        }
    }

    echo str_repeat("-", 68) . "\n";
    echo "Total: " . count($all) . " | Applied: {$appliedCount} | Pending: {$pendingCount}\n";
    exit(0);
}

if (isset($options['apply'])) {
    echo "Starting database migration for [" . DB_NAME . "]...\n";
    $pending = get_pending_migrations($pdo, true);

    if (empty($pending)) {
        echo "No pending migrations to apply. Database is up to date.\n";
        exit(0);
    }

    echo "Found " . count($pending) . " pending migration(s):\n";
    foreach ($pending as $p) {
        echo "  - {$p}\n";
    }
    echo "\n";

    $result = apply_pending_migrations($pdo);

    if (!empty($result['applied'])) {
        foreach ($result['applied'] as $mig) {
            echo "  [OK] Applied: {$mig}\n";
        }
    }

    if ($result['failed'] !== null) {
        fwrite(STDERR, "  [ERROR] Failed at {$result['failed']['migration']}: {$result['failed']['error']}\n");
        fwrite(STDERR, "Migration stopped due to error.\n");
        exit(1);
    }

    echo "\nAll pending migrations applied successfully.\n";
    exit(0);
}
