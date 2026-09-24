<?php
/**
 * TaskFlow Daily Cron Runner
 *
 * Usage:
 *   php bin/cron-daily.php
 *   php bin/cron-daily.php --only=backup
 *   php bin/cron-daily.php --only=backup,cleanup,reminders
 *   php bin/cron-daily.php --dry-run
 */

// 1. CLI Access Check
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. CLI only.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);

// 2. Load Configuration and Database
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/db.php';
require_once $projectRoot . '/includes/branding.php';

// 3. Process CLI Options
$longopts = ['only::', 'dry-run'];
$cliOptions = getopt('', $longopts);

$selectedJobs = null;
if (isset($cliOptions['only'])) {
    $selectedJobs = array_filter(array_map('trim', explode(',', $cliOptions['only'])));
}
$isDryRun = isset($cliOptions['dry-run']);

// 4. Overlap Prevention via Lock File
$lockFile = sys_get_temp_dir() . '/taskflow_cron_' . md5($projectRoot) . '.lock';
$lockFp = @fopen($lockFile, 'c+');

if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[CRON] Another process is running for this install. Exiting.\n");
    exit(0);
}

// 5. Logging Helper
function cron_log(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [CRON] $message\n";
    $logFile = env('LOG_FILE');

    if (!empty($logFile)) {
        @file_put_contents($logFile, $line, FILE_APPEND);
    } else {
        fwrite(STDERR, $line);
    }
    echo $line;
}

// -----------------------------------------------------------------------------
// JOB 1: Database Backup
// -----------------------------------------------------------------------------
function cron_job_backup(PDO $pdo, string $projectRoot, bool $isDryRun): bool {
    cron_log("Starting Job 1: Database Backup" . ($isDryRun ? " (dry-run)" : "") . "...");

    $backupDir = env('BACKUP_DIR');
    if (empty($backupDir)) {
        $folderName = basename($projectRoot);
        $backupDir = dirname($projectRoot) . '/taskflow-backups/' . $folderName;
    }

    if (!$isDryRun) {
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception("Could not create backup directory: {$backupDir}");
            }
        }
        $htaccess = $backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
    }

    $timestamp = date('Y-m-d_Hi');
    $filename = "taskflow-{$timestamp}.sql.gz";
    $filePath = $backupDir . '/' . $filename;

    if ($isDryRun) {
        cron_log("Backup (dry-run): Would create {$filePath}");
        cron_log("Backup (dry-run): Would retain backups according to BACKUP_KEEP_DAYS");
        return true;
    }

    // Open gzipped file stream
    $gz = gzopen($filePath, 'wb9');
    if (!$gz) {
        throw new Exception("Could not open {$filePath} for writing.");
    }

    try {
        gzwrite($gz, "-- TaskFlow Database Backup\n");
        gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        gzwrite($gz, "-- Database: " . DB_NAME . "\n\n");
        gzwrite($gz, "SET NAMES utf8mb4;\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        // Fetch tables
        $tables = [];
        $tStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($tRow = $tStmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $tRow[0];
        }

        foreach ($tables as $table) {
            // Table structure
            $cStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
            $createTableSql = $cRow['Create Table'] ?? '';

            gzwrite($gz, "-- --------------------------------------------------------\n");
            gzwrite($gz, "-- Structure for table `{$table}`\n");
            gzwrite($gz, "-- --------------------------------------------------------\n");
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
            gzwrite($gz, $createTableSql . ";\n\n");

            // Table data
            gzwrite($gz, "-- Data for table `{$table}`\n");
            $dStmt = $pdo->query("SELECT * FROM `{$table}`");
            $batch = [];
            $cols = null;

            while ($row = $dStmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_map(function ($c) { return "`{$c}`"; }, array_keys($row));
                }
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(', ', $vals) . ')';

                if (count($batch) >= 500) {
                    gzwrite($gz, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if (!empty($batch) && !empty($cols)) {
                gzwrite($gz, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzwrite($gz, "-- TASKFLOW_BACKUP_COMPLETED --\n");
        gzclose($gz);
        $gz = null;

        // Verify completion marker by reading end of file
        $verifyGz = gzopen($filePath, 'rb');
        if (!$verifyGz) {
            @unlink($filePath);
            throw new Exception("Verification failed: cannot reopen backup file {$filePath}");
        }

        $tailBuffer = '';
        while (!gzeof($verifyGz)) {
            $chunk = gzread($verifyGz, 8192);
            $tailBuffer = substr($tailBuffer . $chunk, -1024);
        }
        gzclose($verifyGz);

        if (!str_contains($tailBuffer, '-- TASKFLOW_BACKUP_COMPLETED --')) {
            @unlink($filePath);
            throw new Exception("Verification failed: missing completion marker in {$filePath}. Partial file deleted.");
        }

        // Archive uploads/branding if ZipArchive is available
        $brandingDir = $projectRoot . '/uploads/branding';
        if (is_dir($brandingDir) && class_exists('ZipArchive')) {
            $zipFile = $backupDir . "/taskflow-branding-{$timestamp}.zip";
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($brandingDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                $hasZipFiles = false;
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $fPath = $file->getRealPath();
                        $relPath = substr($fPath, strlen(realpath($brandingDir)) + 1);
                        $zip->addFile($fPath, $relPath);
                        $hasZipFiles = true;
                    }
                }
                $zip->close();
                if (!$hasZipFiles && file_exists($zipFile)) {
                    @unlink($zipFile);
                }
            }
        } elseif (!class_exists('ZipArchive')) {
            cron_log("ZipArchive class not available; skipped branding uploads archive.");
        }

        // Retention policy: Keep newest backup always, prune older than BACKUP_KEEP_DAYS
        $keepDays = (int)env('BACKUP_KEEP_DAYS', 14);
        $cutoffTime = time() - ($keepDays * 86400);

        $allBackups = glob($backupDir . '/taskflow-*.sql.gz');
        if ($allBackups) {
            sort($allBackups);
            $newestBackup = end($allBackups);

            foreach ($allBackups as $bFile) {
                if ($bFile === $newestBackup) {
                    continue; // Never delete the newest backup
                }
                if (filemtime($bFile) < $cutoffTime) {
                    @unlink($bFile);
                    $zipCandidate = preg_replace('/\.sql\.gz$/', '.zip', str_replace('taskflow-', 'taskflow-branding-', $bFile));
                    if (file_exists($zipCandidate)) {
                        @unlink($zipCandidate);
                    }
                }
            }
        }

        // Store status in settings
        set_setting('backup_last_success', date('Y-m-d H:i:s'));
        set_setting('backup_last_file', $filename);

        cron_log("Job 1 [Backup] completed successfully: {$filename}");
        return true;

    } catch (Exception $e) {
        if ($gz) {
            gzclose($gz);
        }
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        throw $e;
    }
}

// -----------------------------------------------------------------------------
// JOB 2: Cleanup (Batched DELETE ... LIMIT 1000)
// -----------------------------------------------------------------------------
function cron_job_cleanup(PDO $pdo, bool $isDryRun): bool {
    cron_log("Starting Job 2: Cleanup" . ($isDryRun ? " (dry-run)" : "") . "...");

    $notifReadDays = (int)env('NOTIF_READ_DAYS', 30);
    $notifMaxDays = (int)env('NOTIF_MAX_DAYS', 90);

    $readCutoff = date('Y-m-d H:i:s', time() - ($notifReadDays * 86400));
    $maxCutoff = date('Y-m-d H:i:s', time() - ($notifMaxDays * 86400));
    $loginCutoff = date('Y-m-d H:i:s', time() - 86400);

    // 1. Read notifications older than NOTIF_READ_DAYS
    $deletedRead = 0;
    if ($isDryRun) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 1 AND created_at < ?");
        $stmt->execute([$readCutoff]);
        $deletedRead = (int)$stmt->fetchColumn();
    } else {
        do {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < ? LIMIT 1000");
            $stmt->execute([$readCutoff]);
            $count = $stmt->rowCount();
            $deletedRead += $count;
        } while ($count === 1000);
    }

    // 2. Any notification older than NOTIF_MAX_DAYS (read or unread)
    $deletedMax = 0;
    if ($isDryRun) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE created_at < ?");
        $stmt->execute([$maxCutoff]);
        $deletedMax = (int)$stmt->fetchColumn();
    } else {
        do {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE created_at < ? LIMIT 1000");
            $stmt->execute([$maxCutoff]);
            $count = $stmt->rowCount();
            $deletedMax += $count;
        } while ($count === 1000);
    }

    // 3. Login attempts older than 24 hours
    $deletedLogins = 0;
    if ($isDryRun) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE attempted_at < ?");
        $stmt->execute([$loginCutoff]);
        $deletedLogins = (int)$stmt->fetchColumn();
    } else {
        do {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ? LIMIT 1000");
            $stmt->execute([$loginCutoff]);
            $count = $stmt->rowCount();
            $deletedLogins += $count;
        } while ($count === 1000);
    }

    cron_log("Job 2 [Cleanup] completed: {$deletedRead} read notifs (> {$notifReadDays}d), {$deletedMax} max-age notifs (> {$notifMaxDays}d), {$deletedLogins} login attempts (> 24h)" . ($isDryRun ? " (dry-run)" : "") . ".");
    return true;
}

// -----------------------------------------------------------------------------
// JOB 3: Due-Date Reminders (Idempotent via task_reminders table)
// -----------------------------------------------------------------------------
function cron_job_reminders(PDO $pdo, bool $isDryRun): bool {
    $tzName = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Cairo';
    $tz = new DateTimeZone($tzName);
    $tomorrow = (new DateTime('+1 day', $tz))->format('Y-m-d');

    cron_log("Starting Job 3: Due-Date Reminders" . ($isDryRun ? " (dry-run)" : "") . " for due_date={$tomorrow} (timezone: {$tzName})...");

    $query = "
        SELECT 
            t.id AS task_id,
            t.title AS task_title,
            t.due_date,
            t.assigned_to,
            u.name AS assignee_name,
            u.is_active AS assignee_is_active,
            p.id AS project_id,
            p.title AS project_title,
            p.user_id AS project_owner_id,
            po.is_active AS project_owner_is_active
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users po ON p.user_id = po.id
        WHERE t.due_date = ?
          AND t.status != 'Completed'
        ORDER BY t.id ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$tomorrow]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remindersSent = 0;
    $skippedInactive = 0;

    foreach ($tasks as $task) {
        $taskId = (int)$task['task_id'];
        $taskTitle = $task['task_title'];
        $projectTitle = $task['project_title'];
        $dueDate = $task['due_date'];

        $recipientId = null;
        $msg = '';

        if ($task['assigned_to'] !== null) {
            // Task is assigned to a member
            if ((int)$task['assignee_is_active'] === 1) {
                $recipientId = (int)$task['assigned_to'];
                $msg = "تذكير: المهمة '{$taskTitle}' في مشروع '{$projectTitle}' موعدها بكرة";
            } else {
                // Inactive assignee: do not notify
                $skippedInactive++;
                continue;
            }
        } else {
            // Unassigned task -> notify project owner
            if ((int)$task['project_owner_is_active'] === 1) {
                $recipientId = (int)$task['project_owner_id'];
                $msg = "تذكير: المهمة '{$taskTitle}' في مشروع '{$projectTitle}' موعدها بكرة ومفيش حد مسؤول عنها";
            } else {
                $skippedInactive++;
                continue;
            }
        }

        if ($isDryRun) {
            $checkStmt = $pdo->prepare("SELECT 1 FROM task_reminders WHERE task_id = ? AND reminder_type = 'due_tomorrow' AND due_date = ?");
            $checkStmt->execute([$taskId, $dueDate]);
            if (!$checkStmt->fetch()) {
                $remindersSent++;
                cron_log("Reminders (dry-run): Would notify user #{$recipientId} for task #{$taskId} ('{$taskTitle}')");
            }
        } else {
            // INSERT IGNORE ensures idempotent notification per task, reminder type, and due date
            $insStmt = $pdo->prepare("INSERT IGNORE INTO task_reminders (task_id, reminder_type, due_date, sent_at) VALUES (?, 'due_tomorrow', ?, NOW())");
            $insStmt->execute([$taskId, $dueDate]);

            if ($insStmt->rowCount() > 0) {
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $notifStmt->execute([$recipientId, $msg]);
                $remindersSent++;
            }
        }
    }

    cron_log("Job 3 [Reminders] completed: {$remindersSent} reminders " . ($isDryRun ? "would be sent" : "sent") . ($skippedInactive > 0 ? " ({$skippedInactive} skipped due to inactive user)" : "") . ".");
    return true;
}

// -----------------------------------------------------------------------------
// Runner Dispatch
// -----------------------------------------------------------------------------
$jobsToRun = [
    'backup' => true,
    'cleanup' => false,
    'reminders' => false
];

if ($selectedJobs !== null) {
    $jobsToRun['backup'] = in_array('backup', $selectedJobs, true);
    $jobsToRun['cleanup'] = in_array('cleanup', $selectedJobs, true);
    $jobsToRun['reminders'] = in_array('reminders', $selectedJobs, true);
} else {
    $jobsToRun['cleanup'] = true;
    $jobsToRun['reminders'] = true;
}

$hadFailure = false;
$startTime = date('Y-m-d H:i:s');

cron_log("Daily cron started at {$startTime}");

// JOB 1: Backup
if ($jobsToRun['backup']) {
    try {
        cron_job_backup($pdo, $projectRoot, $isDryRun);
    } catch (Throwable $e) {
        $hadFailure = true;
        cron_log("Job 1 [Backup] FAILED: " . $e->getMessage());
    }
}

// JOB 2: Cleanup
if ($jobsToRun['cleanup']) {
    try {
        cron_job_cleanup($pdo, $isDryRun);
    } catch (Throwable $e) {
        $hadFailure = true;
        cron_log("Job 2 [Cleanup] FAILED: " . $e->getMessage());
    }
}

// JOB 3: Reminders
if ($jobsToRun['reminders']) {
    try {
        cron_job_reminders($pdo, $isDryRun);
    } catch (Throwable $e) {
        $hadFailure = true;
        cron_log("Job 3 [Reminders] FAILED: " . $e->getMessage());
    }
}

// Store cron_last_run setting
if (!$isDryRun) {
    try {
        set_setting('cron_last_run', date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        // Log but don't fail overall run
    }
}

flock($lockFp, LOCK_UN);
fclose($lockFp);

cron_log("Daily cron finished at " . date('Y-m-d H:i:s') . " with " . ($hadFailure ? "ERRORS" : "SUCCESS"));

exit($hadFailure ? 1 : 0);
