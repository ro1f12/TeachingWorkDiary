<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/materializer.php';
require_admin();
check_csrf();

$pdo = db();
$type = $_GET['type'] ?? 'teachers';
$validTypes = ['teachers', 'programmes', 'semesters', 'papers', 'rooms', 'strength', 'duty_types'];
if (!in_array($type, $validTypes, true)) {
    $type = 'teachers';
}

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'add';
        $postedType = $_POST['type'] ?? $type;

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            switch ($postedType) {
                case 'teachers':
                    $pdo->prepare('UPDATE teachers SET name=?, email=?, short_code=?, designation=?, active=? WHERE id=?')
                        ->execute([trim($_POST['name']), trim($_POST['email']), trim($_POST['short_code']), trim($_POST['designation']), isset($_POST['active']) ? 1 : 0, $id]);
                    break;

                case 'programmes':
                    $pdo->prepare('UPDATE programmes SET name=?, short_code=?, active=? WHERE id=?')
                        ->execute([trim($_POST['name']), trim($_POST['short_code']), isset($_POST['active']) ? 1 : 0, $id]);
                    break;

                case 'semesters':
                    $pdo->prepare('UPDATE semesters SET name=?, active=? WHERE id=?')
                        ->execute([trim($_POST['name']), isset($_POST['active']) ? 1 : 0, $id]);
                    break;

                case 'rooms':
                    $pdo->prepare('UPDATE rooms SET name=?, room_type=?, active=? WHERE id=?')
                        ->execute([trim($_POST['name']), trim($_POST['room_type']), isset($_POST['active']) ? 1 : 0, $id]);
                    break;

                case 'papers':
                    $programmeId = empty($_POST['programme_id']) ? null : (int)$_POST['programme_id'];
                    $semesterId = empty($_POST['semester_id']) ? null : (int)$_POST['semester_id'];
                    $pdo->prepare('UPDATE papers SET programme_id=?, semester_id=?, name=?, code=?, active=? WHERE id=?')
                        ->execute([$programmeId, $semesterId, trim($_POST['name']), trim($_POST['code']), isset($_POST['active']) ? 1 : 0, $id]);
                    break;

                case 'strength':
                    $pId = (int)$_POST['programme_id'];
                    $sId = (int)$_POST['semester_id'];
                    $newStr = max(0, (int)$_POST['strength']);
                    $sess = trim($_POST['session_name']);
                    $act = isset($_POST['active']) ? 1 : 0;
                    $pdo->prepare('UPDATE class_strength SET programme_id=?, semester_id=?, strength=?, session_name=?, active=? WHERE id=?')
                        ->execute([$pId, $sId, $newStr, $sess, $act, $id]);
                    // Automatic Propagation to untouched records:
                    if ($act === 1) {
                        $updatedCount = propagate_class_strength($pdo, $pId, $sId, $sess, $newStr);
                        $message = "Class strength updated to $newStr. Automatically propagated to $updatedCount untouched diary records (confirmed historical records preserved).";
                    } else {
                        $message = 'Class strength updated.';
                    }
                    break;

                case 'duty_types':
                    $pdo->prepare('UPDATE duty_types SET name=?, description=?, active=? WHERE id=?')
                        ->execute([trim($_POST['name']), trim($_POST['description'] ?? ''), isset($_POST['active']) ? 1 : 0, $id]);
                    break;
            }
            if (empty($message)) {
                $message = 'Record updated successfully.';
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $tables = ['teachers', 'programmes', 'semesters', 'papers', 'rooms', 'class_strength', 'duty_types'];
            if (in_array($postedType, $tables, true)) {
                $pdo->prepare("UPDATE `$postedType` SET active = IF(active=1,0,1) WHERE id=?")->execute([$id]);
                $message = 'Status toggled successfully.';
            }

        } else { // ADD
            switch ($postedType) {
                case 'teachers':
                    $pdo->prepare('INSERT INTO teachers(name, email, short_code, designation, active) VALUES(?,?,?,?,1)')
                        ->execute([trim($_POST['name']), trim($_POST['email']), trim($_POST['short_code']), trim($_POST['designation'])]);
                    // Create matching user account
                    $tid = (int)$pdo->lastInsertId();
                    $uName = strtolower(trim($_POST['short_code'] ?: 'teacher'.$tid));
                    $chkU = $pdo->prepare('SELECT id FROM users WHERE username=?');
                    $chkU->execute([$uName]);
                    if (!$chkU->fetchColumn()) {
                        $pdo->prepare('INSERT INTO users(username, password_hash, display_name, role, teacher_id) VALUES(?,?,?,?,?)')
                            ->execute([$uName, password_hash($uName.'123', PASSWORD_DEFAULT), trim($_POST['name']), 'teacher', $tid]);
                    }
                    break;

                case 'programmes':
                    $pdo->prepare('INSERT INTO programmes(name, short_code, active) VALUES(?,?,1)')
                        ->execute([trim($_POST['name']), trim($_POST['short_code'])]);
                    break;

                case 'semesters':
                    $pdo->prepare('INSERT INTO semesters(name, active) VALUES(?,1)')
                        ->execute([trim($_POST['name'])]);
                    break;

                case 'rooms':
                    $pdo->prepare('INSERT INTO rooms(name, room_type, active) VALUES(?,?,1)')
                        ->execute([trim($_POST['name']), trim($_POST['room_type'])]);
                    break;

                case 'papers':
                    $programmeId = empty($_POST['programme_id']) ? null : (int)$_POST['programme_id'];
                    $semesterId = empty($_POST['semester_id']) ? null : (int)$_POST['semester_id'];
                    $pdo->prepare('INSERT INTO papers(programme_id, semester_id, name, code, active) VALUES(?,?,?,?,1)')
                        ->execute([$programmeId, $semesterId, trim($_POST['name']), trim($_POST['code'])]);
                    break;

                case 'strength':
                    $pId = (int)$_POST['programme_id'];
                    $sId = (int)$_POST['semester_id'];
                    $newStr = max(0, (int)$_POST['strength']);
                    $sess = trim($_POST['session_name']);
                    $pdo->prepare('INSERT INTO class_strength(programme_id, semester_id, strength, session_name, active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE strength=VALUES(strength), active=1')
                        ->execute([$pId, $sId, $newStr, $sess]);
                    // Propagate to untouched records
                    $updatedCount = propagate_class_strength($pdo, $pId, $sId, $sess, $newStr);
                    $message = "Class strength saved ($newStr). Automatically propagated to $updatedCount untouched diary records.";
                    break;

                case 'duty_types':
                    $pdo->prepare('INSERT INTO duty_types(name, description, active) VALUES(?,?,1)')
                        ->execute([trim($_POST['name']), trim($_POST['description'] ?? '')]);
                    $message = 'Duty type added successfully.';
                    break;
            }
            if (empty($message)) {
                $message = 'Record added successfully.';
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$allProgs = $pdo->query('SELECT id, name, short_code, active FROM programmes ORDER BY name')->fetchAll();
$allSems = $pdo->query('SELECT id, name, active FROM semesters ORDER BY id')->fetchAll();
$progs = array_values(array_filter($allProgs, fn($x) => (int)$x['active'] === 1));
$sems = array_values(array_filter($allSems, fn($x) => (int)$x['active'] === 1));

page_header('Departmental Masters');
?>
<?php if ($message): ?><div class="notice"><?=esc($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?=esc($error)?></div><?php endif; ?>

<!-- Sub-Navigation & Global Import/Export Bar for Masters -->
<div class="card" style="padding:14px 18px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div class="actions">
            <a class="btn <?= $type==='teachers'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=teachers">Faculty Members</a>
            <a class="btn <?= $type==='programmes'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=programmes">Programmes</a>
            <a class="btn <?= $type==='semesters'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=semesters">Semesters</a>
            <a class="btn <?= $type==='papers'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=papers">Papers / Courses</a>
            <a class="btn <?= $type==='rooms'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=rooms">Classrooms &amp; Labs</a>
            <a class="btn <?= $type==='strength'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=strength">Class Strength</a>
            <a class="btn <?= $type==='duty_types'?'':'secondary' ?>" style="font-size:12.5px;padding:6px 12px;" href="masters.php?type=duty_types">Duty Types</a>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="excel_io.php?action=template&module=<?=urlencode($type==='strength'?'class_strength':$type)?>" class="btn secondary" style="font-size:12px;padding:5px 10px;">
                📥 Download Template
            </a>
            <a href="excel_io.php?module=<?=urlencode($type==='strength'?'class_strength':$type)?>" class="btn" style="background:#00695c;color:#fff;font-size:12px;padding:5px 10px;">
                📊 Import Excel (.xlsx)
            </a>
            <a href="excel_io.php?action=export&module=<?=urlencode($type==='strength'?'class_strength':$type)?>" class="btn success" style="font-size:12px;padding:5px 10px;">
                📤 Export Excel (.xlsx)
            </a>
        </div>
    </div>
</div>

<?php if ($type === 'teachers'): ?>
    <div class="card">
        <h2>Add Faculty Member</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="teachers">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field"><label>Full Name</label><input name="name" required placeholder="e.g. Dr. Manisha Deka"></div>
                <div class="field"><label>Institutional Email</label><input type="email" name="email" placeholder="faculty@lcbc.ac.in"></div>
                <div class="field"><label>Short Code</label><input name="short_code" required placeholder="e.g. MD"></div>
                <div class="field"><label>Designation</label><input name="designation" placeholder="e.g. Associate Professor"></div>
            </div>
            <br><button class="btn success">Add Faculty Member</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Department Faculty Roster</h2>
        <table>
            <thead>
                <tr><th>Full Name</th><th>Short Code</th><th>Email</th><th>Designation</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM teachers ORDER BY name') as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="teachers">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="padding:4px 8px;font-size:13px;"></td>
                        <td><input name="short_code" value="<?=esc($x['short_code'])?>" style="width:75px;padding:4px 8px;font-size:13px;"></td>
                        <td><input type="email" name="email" value="<?=esc($x['email'])?>" style="padding:4px 8px;font-size:13px;"></td>
                        <td><input name="designation" value="<?=esc($x['designation'])?>" style="padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td style="white-space:nowrap;"><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'programmes'): ?>
    <div class="card">
        <h2>Add Academic Programme</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="programmes">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field"><label>Programme Name</label><input name="name" required placeholder="e.g. BCA"></div>
                <div class="field"><label>Short Code</label><input name="short_code" placeholder="e.g. BCA"></div>
            </div>
            <br><button class="btn success">Add Programme</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Offered Programmes</h2>
        <table>
            <thead><tr><th>Programme Name</th><th>Short Code</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($allProgs as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="programmes">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="padding:4px 8px;font-size:13px;"></td>
                        <td><input name="short_code" value="<?=esc($x['short_code']??'')?>" style="width:90px;padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'semesters'): ?>
    <div class="card">
        <h2>Add Semester</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="semesters">
            <input type="hidden" name="action" value="add">
            <div class="field" style="max-width:300px;"><label>Semester Name</label><input name="name" placeholder="e.g. 1st, 3rd, 5th" required></div>
            <br><button class="btn success">Add Semester</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Semesters</h2>
        <table>
            <thead><tr><th>Semester Name</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($allSems as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="semesters">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'rooms'): ?>
    <div class="card">
        <h2>Add Classroom / Laboratory</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="rooms">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field"><label>Room / Lab Name</label><input name="name" required placeholder="e.g. C219, LAB III"></div>
                <div class="field"><label>Type</label><input name="room_type" placeholder="Classroom / Lab"></div>
            </div>
            <br><button class="btn success">Add Room / Lab</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Classrooms &amp; Laboratories</h2>
        <table>
            <thead><tr><th>Room / Lab Name</th><th>Type</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM rooms ORDER BY name') as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="rooms">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="padding:4px 8px;font-size:13px;"></td>
                        <td><input name="room_type" value="<?=esc($x['room_type']??'')?>" style="padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'papers'): ?>
    <div class="card">
        <h2>Add Paper / Subject</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="papers">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field"><label>Programme</label><select name="programme_id" required><option value="">— Select Programme —</option><?php foreach ($progs as $x): ?><option value="<?=$x['id']?>"><?=esc($x['name'])?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Semester</label><select name="semester_id" required><option value="">— Select Semester —</option><?php foreach ($sems as $x): ?><option value="<?=$x['id']?>"><?=esc($x['name'])?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Paper Name</label><input name="name" required placeholder="e.g. C Programming"></div>
                <div class="field"><label>Paper Code</label><input name="code" placeholder="e.g. PGDCA-C"></div>
            </div>
            <br><button class="btn success">Add Paper</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Papers &amp; Subjects Curriculum</h2>
        <table>
            <thead><tr><th>Paper Name</th><th>Code</th><th>Programme</th><th>Semester</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT pa.*, p.name programme, s.name semester FROM papers pa LEFT JOIN programmes p ON p.id=pa.programme_id LEFT JOIN semesters s ON s.id=pa.semester_id ORDER BY p.name, s.id, pa.name') as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="papers">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="padding:4px 8px;font-size:13px;"></td>
                        <td><input name="code" value="<?=esc($x['code']??'')?>" style="width:110px;padding:4px 8px;font-size:13px;"></td>
                        <td>
                            <select name="programme_id" style="padding:4px 8px;font-size:12.5px;">
                                <option value="">—</option>
                                <?php foreach ($allProgs as $p): ?>
                                    <option value="<?=$p['id']?>" <?=$x['programme_id']==$p['id']?'selected':''?>><?=esc($p['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="semester_id" style="padding:4px 8px;font-size:12.5px;">
                                <option value="">—</option>
                                <?php foreach ($allSems as $s): ?>
                                    <option value="<?=$s['id']?>" <?=$x['semester_id']==$s['id']?'selected':''?>><?=esc($s['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'strength'): ?>
    <div class="card">
        <h2>Configure Class Strength</h2>
        <p class="small">
            Class Strength is session-aware (e.g. <code>2026-27</code>). 
            When Class Strength is updated, it <strong>automatically propagates to future and untouched auto-generated records</strong> (<code>is_edited = 0</code>). 
            Manually confirmed historical records (<code>is_edited = 1</code>) retain their genuine recorded student counts.
        </p>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="strength">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field"><label>Programme</label><select name="programme_id" required><?php foreach ($progs as $x): ?><option value="<?=$x['id']?>"><?=esc($x['name'])?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Semester</label><select name="semester_id" required><?php foreach ($sems as $x): ?><option value="<?=$x['id']?>"><?=esc($x['name'])?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Strength (Students)</label><input type="number" name="strength" min="1" required placeholder="e.g. 30"></div>
                <div class="field"><label>Academic Session</label><input name="session_name" value="2026-27" required placeholder="YYYY-YY e.g. 2026-27"></div>
            </div>
            <br><button class="btn success">Save Class Strength</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2>Current Class Strengths</h2>
        <table>
            <thead><tr><th>Programme</th><th>Semester</th><th>Strength</th><th>Academic Session</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT c.*, p.name programme, s.name semester FROM class_strength c JOIN programmes p ON p.id=c.programme_id JOIN semesters s ON s.id=c.semester_id ORDER BY p.name, s.id, c.session_name') as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="strength">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td>
                            <select name="programme_id" style="padding:4px 8px;font-size:12.5px;">
                                <?php foreach ($allProgs as $p): ?>
                                    <option value="<?=$p['id']?>" <?=$x['programme_id']==$p['id']?'selected':''?>><?=esc($p['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="semester_id" style="padding:4px 8px;font-size:12.5px;">
                                <?php foreach ($allSems as $s): ?>
                                    <option value="<?=$s['id']?>" <?=$x['semester_id']==$s['id']?'selected':''?>><?=esc($s['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="strength" min="0" value="<?=esc($x['strength'])?>" required style="width:80px;padding:4px 8px;font-size:13px;"></td>
                        <td><input name="session_name" value="<?=esc($x['session_name'])?>" required style="width:110px;padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($type === 'duty_types'): ?>
    <?php
    $dutyTypesList = $pdo->query('SELECT * FROM duty_types ORDER BY name')->fetchAll();
    ?>
    <div class="card">
        <h2>Add Duty Type</h2>
        <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="type" value="duty_types">
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div class="field">
                    <label>Duty Type Name *</label>
                    <input name="name" placeholder="e.g. Invigilation Duty" required>
                </div>
                <div class="field">
                    <label>Description / Scope</label>
                    <input name="description" placeholder="Description of duties">
                </div>
                <div class="field" style="align-self:center;padding-top:16px;">
                    <label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" checked> Active</label>
                </div>
            </div>
            <br><button class="btn success">Add Duty Type</button>
        </form>
    </div>

    <div class="card table-wrap">
        <h2>Duty Types Roster</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:220px;">Duty Type Name</th>
                    <th>Description</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:80px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dutyTypesList as $x): ?>
                <tr>
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="type" value="duty_types">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?=$x['id']?>">
                        <td style="color:#64748b;font-weight:600;"><?=$x['id']?></td>
                        <td><input name="name" value="<?=esc($x['name'])?>" required style="width:100%;padding:4px 8px;font-size:13px;"></td>
                        <td><input name="description" value="<?=esc($x['description'] ?? '')?>" style="width:100%;padding:4px 8px;font-size:13px;"></td>
                        <td><label style="font-size:12px;font-weight:600;"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active</label></td>
                        <td><button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php page_footer(); ?>
