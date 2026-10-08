<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/lib/auth.php';

$msg = '';
$ok = false;

// Detect if application is already installed
$isInstalled = false;
try {
    $checkPdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
    ]);
    $st = $checkPdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1");
    if ($st && (int)$st->fetchColumn() > 0) {
        $isInstalled = true;
    }
} catch (Throwable $e) {
    $isInstalled = false;
}

$currentUser = user();
$isAdminLoggedIn = ($currentUser['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If already installed, only authenticated administrators may re-run repair
    if ($isInstalled && !$isAdminLoggedIn) {
        http_response_code(403);
        exit('Access Denied: The application is already installed. Only an authenticated Administrator can re-run database installation or repair.');
    }

    // Enforce CSRF check if installed/session active
    if ($isInstalled) {
        check_csrf();
    }

    try {
        $pdo = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Execute schema
        $schema = file_get_contents(__DIR__.'/schema.sql');
        $statements = preg_split('/;\s*[\r\n]+/', $schema);
        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql) {
                $pdo->exec($sql);
            }
        }
        $pdo->exec('USE `'.DB_NAME.'`');

        // Column migration helper for older builds
        $hasColumn = function($table, $column) use ($pdo) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $q->execute([$table, $column]);
            return (bool)$q->fetchColumn();
        };
        $addColumn = function($table, $column, $definition) use ($pdo, $hasColumn) {
            if (!$hasColumn($table, $column)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        };

        $addColumn('teachers', 'short_code', 'VARCHAR(30) NULL');
        $addColumn('teachers', 'email', 'VARCHAR(190) NULL');
        $addColumn('teachers', 'designation', 'VARCHAR(100) NULL');
        $addColumn('teachers', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('programmes', 'short_code', 'VARCHAR(40) NULL');
        $addColumn('programmes', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('semesters', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('papers', 'programme_id', 'INT NULL');
        $addColumn('papers', 'semester_id', 'INT NULL');
        $addColumn('papers', 'code', 'VARCHAR(80) NULL');
        $addColumn('papers', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('rooms', 'room_type', 'VARCHAR(40) NULL');
        $addColumn('rooms', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('class_strength', 'session_name', 'VARCHAR(80) NOT NULL DEFAULT "2026-27"');
        $addColumn('class_strength', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('timetable', 'room_id', 'INT NULL');
        $addColumn('timetable', 'class_type', "VARCHAR(30) NULL DEFAULT 'Theory'");
        $addColumn('timetable', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        $addColumn('timetable', 'notes', 'VARCHAR(255) NULL');
        $addColumn('diary', 'original_teacher_id', 'INT NULL');
        $addColumn('diary', 'original_date', 'DATE NULL');
        $addColumn('diary', 'original_start_time', 'TIME NULL');
        $addColumn('diary', 'original_end_time', 'TIME NULL');
        $addColumn('diary', 'timetable_id', 'INT NULL');
        $addColumn('diary', 'materialized_from', "VARCHAR(30) DEFAULT 'timetable'");
        $addColumn('diary', 'is_edited', 'TINYINT(1) NOT NULL DEFAULT 0');
        $addColumn('overrides', 'timetable_id', 'INT NULL');

        // Execute seed file
        $seed = file_get_contents(__DIR__.'/seed.sql');
        $seedStatements = preg_split('/;\s*[\r\n]+/', $seed);
        foreach ($seedStatements as $sql) {
            $sql = trim($sql);
            if ($sql) {
                $pdo->exec($sql);
            }
        }

        // Admin User (preserve existing password if admin already exists)
        $chkAdmin = $pdo->query("SELECT id FROM users WHERE username='admin' LIMIT 1")->fetch();
        if (!$chkAdmin) {
            $pdo->prepare('INSERT INTO users(username, password_hash, display_name, role) VALUES(?,?,?,?)')->execute([
                'admin',
                password_hash('admin123', PASSWORD_DEFAULT),
                'Administrator',
                'admin'
            ]);
        }

        // Helper functions
        $getT = function($c) use ($pdo) {
            $q = $pdo->prepare('SELECT id FROM teachers WHERE short_code=?');
            $q->execute([$c]);
            return $q->fetchColumn();
        };
        $getProg = function($c) use ($pdo) {
            $q = $pdo->prepare('SELECT id FROM programmes WHERE short_code=?');
            $q->execute([$c]);
            return $q->fetchColumn();
        };
        $getSem = function($n) use ($pdo) {
            $q = $pdo->prepare('SELECT id FROM semesters WHERE name=?');
            $q->execute([$n]);
            return $q->fetchColumn();
        };
        $getP = function($name, $code, $progCode, $semName) use ($pdo, $getProg, $getSem) {
            $pid = $getProg($progCode);
            $sid = $getSem($semName);
            $q = $pdo->prepare('SELECT id FROM papers WHERE name=? AND programme_id=? AND semester_id=?');
            $q->execute([$name, $pid, $sid]);
            $id = $q->fetchColumn();
            if (!$id) {
                $pdo->prepare('INSERT INTO papers(programme_id, semester_id, name, code) VALUES(?,?,?,?)')->execute([$pid, $sid, $name, $code]);
                $id = $pdo->lastInsertId();
            }
            return $id;
        };
        $getR = function($n) use ($pdo) {
            $q = $pdo->prepare('SELECT id FROM rooms WHERE name=?');
            $q->execute([$n]);
            return $q->fetchColumn() ?: null;
        };

        // Seed timetable entries
        $seedTimetable = [
            [1,'10:00','11:00','PLP','PGDCA','1st','ICT Hardware','PGDCA-IH',null,'Theory'],
            [1,'11:00','12:00','MD','PGDCA','1st','Operating System','PGDCA-OS',null,'Theory'],
            [1,'12:00','13:00','AC','PGDCA','1st','Database Management Systems','PGDCA-DBMS',null,'Theory'],
            [3,'10:00','11:00','MN','PGDCA','1st','Office Automation','PGDCA-OA',null,'Theory'],
            [3,'11:00','12:00','MD','PGDCA','1st','Operating System','PGDCA-OS',null,'Theory'],
            [3,'12:00','13:00','MAR','PGDCA','1st','C Programming','PGDCA-C',null,'Theory'],
            [6,'10:00','11:00','MN','PGDCA','1st','Office Automation','PGDCA-OA',null,'Theory'],
            [6,'11:00','12:00','MAR','PGDCA','1st','C Programming','PGDCA-C',null,'Theory'],
            [6,'12:00','13:00','AC','PGDCA','1st','Database Management Systems','PGDCA-DBMS',null,'Theory'],
            [6,'13:00','14:00','PLP','PGDCA','1st','ICT Hardware','PGDCA-IH',null,'Theory'],
            [1,'11:00','12:00','MAR','BCA','3rd','Object Oriented Programming using C++','OOP','C222','Theory'],
            [2,'11:00','12:00','MAR','BCA','3rd','Object Oriented Programming using C++','OOP','C222','Theory'],
            [3,'10:00','11:00','MAR','BCA','3rd','Object Oriented Programming using C++','OOP','C222','Theory'],
            [4,'11:00','12:00','MAR','BCA','3rd','Object Oriented Programming using C++','OOP','C222','Practical'],
            [5,'12:00','13:00','MAR','BCA','3rd','Skill Enhancement Course','SEC','C224','Practical'],
            [5,'14:00','15:00','MAR','BCA','3rd','Object Oriented Programming using C++','OOP',null,'Practical'],
            [1,'12:00','13:00','MAR','BSc-IT','1st','Introduction to C-Programming','CIT0100104-N','C224','Practical'],
            [2,'14:00','15:00','MAR','BSc-IT','1st','Introduction to C-Programming','CIT0100104-N',null,'Practical'],
            [3,'13:00','14:00','MAR','BSc-IT','1st','Introduction to C-Programming','CIT0100104-N','C220','Theory'],
            [4,'12:00','13:00','MAR','BSc-IT','1st','Introduction to C-Programming','CIT0100104-N','C224','Theory'],
            [5,'11:00','12:00','MAR','BSc-IT','1st','Introduction to C-Programming','CIT0100104-N','C219','Theory'],
            [5,'10:00','11:00','MAR','BSc-IT','3rd','Object Oriented Programming using C++','OOP','C225','Practical'],
            [1,'13:00','14:00','MAR','BSc-IT','3rd','Object Oriented Programming using C++','OOP','C225','Theory'],
            [2,'13:00','14:00','MAR','BSc-IT','3rd','Object Oriented Programming using C++','OOP','C225','Practical'],
            [3,'11:00','12:00','MAR','BSc-IT','3rd','Object Oriented Programming using C++','OOP','C225','Theory'],
            [4,'10:00','11:00','MAR','BSc-IT','3rd','Object Oriented Programming using C++','OOP','C225','Theory']
        ];

        // Clean up any legacy mismatched timetable rows where paper does not belong to programme
        $pdo->exec("UPDATE timetable t JOIN papers p ON p.id = t.paper_id SET t.active = 0 WHERE t.programme_id != p.programme_id");

        foreach ($seedTimetable as $r) {
            [$dow, $st, $et, $tc, $pc, $sc, $pn, $code, $room, $ct] = $r;
            $tid = $getT($tc);
            $pid = $getProg($pc);
            $sid = $getSem($sc);
            $paperId = $getP($pn, $code, $pc, $sc);
            $rid = $room ? $getR($room) : null;
            $chk = $pdo->prepare('SELECT id FROM timetable WHERE day_of_week=? AND start_time=? AND end_time=? AND teacher_id=? AND programme_id=? AND semester_id=? AND paper_id=? AND active=1');
            $chk->execute([$dow, $st, $et, $tid, $pid, $sid, $paperId]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare('INSERT INTO timetable(day_of_week, start_time, end_time, teacher_id, programme_id, semester_id, paper_id, room_id, class_type) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$dow, $st, $et, $tid, $pid, $sid, $paperId, $rid, $ct]);
            }
        }

        // Class Strengths per specification:
        // BCA: 1st=60, 3rd=60, 5th=60 | B.Sc.-IT: 1st=30, 3rd=30, 5th=30 | PGDCA: 1st=40
        $strengths = [
            ['BCA', '1st', 60],
            ['BCA', '3rd', 60],
            ['BCA', '5th', 60],
            ['BSc-IT', '1st', 30],
            ['BSc-IT', '3rd', 30],
            ['BSc-IT', '5th', 30],
            ['PGDCA', '1st', 40]
        ];
        foreach ($strengths as $x) {
            $pid = $getProg($x[0]);
            $sid = $getSem($x[1]);
            $q = $pdo->prepare('SELECT id FROM class_strength WHERE programme_id=? AND semester_id=? AND session_name=?');
            $q->execute([$pid, $sid, '2026-27']);
            if (!$q->fetchColumn()) {
                $pdo->prepare('INSERT INTO class_strength(programme_id, semester_id, strength, session_name) VALUES(?,?,?,?)')
                    ->execute([$pid, $sid, $x[2], '2026-27']);
            }
        }

        // Seed Duty Types
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS duty_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                active TINYINT(1) DEFAULT 1
            );
        ");
        $defaultDutyTypes = [
            ['Invigilation Duty', 'Internal / Semester examination invigilation'],
            ['Examination Duty', 'Examination evaluation, scrutiny or administrative duty'],
            ['Admission Duty', 'Student counseling, verification and admission desk duty'],
            ['Official Meeting', 'Departmental, Governing Body, or Academic Committee meeting'],
            ['Special Duty', 'Institutional events, NAAC, or special assignments'],
            ['Departmental Work', 'Curriculum planning, departmental routine, logbook verification'],
            ['Other', 'Other approved academic or official duty']
        ];
        $insDt = $pdo->prepare("INSERT IGNORE INTO duty_types (name, description, active) VALUES (?, ?, 1)");
        foreach ($defaultDutyTypes as $dt) {
            $insDt->execute([$dt[0], $dt[1]]);
        }

        // Ensure teacher user accounts exist for seamless login
        $allTeachers = $pdo->query('SELECT id, name, short_code, email FROM teachers WHERE active=1')->fetchAll();
        foreach ($allTeachers as $t) {
            $uname = strtolower($t['short_code']);
            $chkU = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $chkU->execute([$uname]);
            if (!$chkU->fetchColumn()) {
                $pdo->prepare('INSERT INTO users(username, password_hash, display_name, role, teacher_id) VALUES(?,?,?,?,?)')
                    ->execute([$uname, password_hash($uname.'123', PASSWORD_DEFAULT), $t['name'], 'teacher', $t['id']]);
            }
        }

        $ok = true;
        $msg = 'Database verification and updates completed successfully.';
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/app.css">
    <title>Install &amp; Repair — <?=htmlspecialchars(APP_NAME)?></title>
</head>
<body>
<main class="container" style="max-width: 600px; margin-top: 50px;">
    <div class="card">
        <h1>Teaching &amp; Work Diary — Installer</h1>
        <p class="small">Lalit Chandra Bharali College, Maligaon, Guwahati – 781011<br>Department of Information Technology</p>
        <hr style="border:0;border-top:1px solid #e0e0e0;margin:15px 0;">
        <?php if ($msg): ?>
            <div class="notice <?= $ok ? '' : 'error' ?>"><?=htmlspecialchars($msg)?></div>
        <?php endif; ?>
        
        <?php if ($isInstalled && !$isAdminLoggedIn): ?>
            <div class="notice" style="background:#e8f4fd;border-left:4px solid #0288d1;color:#01579b;">
                <strong>System Installed &amp; Protected:</strong> The application database is already initialized and active.
                <br><br>
                For security reasons, repeated installation is disabled. To re-run database verification or repairs, please log in as an <strong>Administrator</strong>.
            </div>
            <br>
            <a class="btn success" href="login.php" style="display:block;text-align:center;padding:12px;font-weight:700;">Proceed to Login</a>
        <?php elseif (!$ok): ?>
            <p>
                <?= $isInstalled 
                    ? 'Administrator Access Verified. Click below to re-verify tables and apply any schema or master updates.' 
                    : 'Click below to initialize database tables and seed authoritative masters.' ?>
            </p>
            <form method="post">
                <?= $isInstalled ? csrf_field() : '' ?>
                <button class="btn success" style="width:100%;padding:12px;font-size:15px;font-weight:700;">
                    <?= $isInstalled ? 'Re-verify / Repair Database' : 'Install Database' ?>
                </button>
            </form>
        <?php else: ?>
            <p>Database is up to date.</p>
            <a class="btn success" href="login.php" style="display:block;text-align:center;padding:12px;font-weight:700;">Proceed to Login</a>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
