<?php
require_once __DIR__.'/lib/layout.php';
require_admin();
check_csrf();

$pdo = db();
$msg = '';
$err = '';

// Download Backup Handler
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    // Dynamically retrieve all tables in the current database
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    
    $dump = "-- Teaching & Work Diary Database Backup\n";
    $dump .= "-- Lalit Chandra Bharali College, Department of Information Technology\n";
    $dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
    $dump .= "/*!40101 SET NAMES utf8mb4 */;\n";
    $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $t) {
        $dump .= "-- Table structure for `$t`\n";
        $createRow = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
        if ($createRow && isset($createRow['Create Table'])) {
            $dump .= "DROP TABLE IF EXISTS `$t`;\n";
            $dump .= $createRow['Create Table'] . ";\n\n";
        }

        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        if ($rows) {
            $dump .= "-- Dumping data for table `$t`\n";
            foreach ($rows as $r) {
                $cols = array_map(fn($c) => "`$c`", array_keys($r));
                $vals = array_map(function($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string)$v);
                }, array_values($r));
                $dump .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dump .= "\n";
        }
    }
    $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'teaching_work_diary_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));
    echo $dump;
    exit;
}

// Restore Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please upload a valid .sql backup file.';
    } else {
        $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            $err = 'Invalid file format. Only .sql files are accepted.';
        } elseif ($_FILES['backup_file']['size'] > 25 * 1024 * 1024) {
            $err = 'Backup file exceeds maximum allowed size (25MB).';
        } else {
            $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
                $statements = preg_split('/;\s*[\r\n]+/', $sql);
                $count = 0;
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt && !str_starts_with($stmt, '--')) {
                        $pdo->exec($stmt);
                        $count++;
                    }
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
                $msg = "Database successfully restored ($count SQL statements executed).";
            } catch (Throwable $e) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
                $err = 'Restore failed: ' . $e->getMessage();
            }
        }
    }
}

page_header('Database Backup &amp; Restore');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error"><?=esc($err)?></div><?php endif; ?>

<div class="card">
    <h2>Export Full Database Backup</h2>
    <p>Download a complete SQL dump of the <strong>Teaching &amp; Work Diary</strong> database, including all Masters, Timetables, Holidays, Overrides, Duty Records, and confirmed historical Diary records.</p>
    <a class="btn success" href="backup.php?action=download" style="padding:10px 18px;font-weight:bold;">
        📥 Download SQL Backup
    </a>
</div>

<div class="card">
    <h2>Restore Database Backup</h2>
    <p class="small" style="color:#c62828;"><strong>Warning:</strong> Restoring a backup will overwrite the current database tables with the contents of the uploaded SQL file.</p>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Are you sure you want to restore this database? Current tables will be replaced.');">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="restore">
        <div class="field" style="max-width:400px;">
            <label>Select .SQL Backup File</label>
            <input type="file" name="backup_file" accept=".sql" required>
        </div>
        <br>
        <button class="btn danger" style="padding:9px 18px;font-weight:bold;">
            Upload &amp; Restore Database
        </button>
    </form>
</div>

<?php page_footer(); ?>
