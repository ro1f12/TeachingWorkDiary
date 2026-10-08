<?php
require_once __DIR__.'/lib/layout.php';
require_login();
check_csrf();

$pdo = db();
$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$userTeacherId = (int)($u['teacher_id'] ?? 0);

$msg = '';
$err = '';
$workTypes = [
    'Invigilation Duty',
    'Examination Duty',
    'Admission Duty',
    'Official Meeting',
    'Special Duty',
    'Departmental Work',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $chk = $pdo->prepare('SELECT teacher_id FROM additional_works WHERE id=?');
        $chk->execute([$id]);
        $ownerId = (int)$chk->fetchColumn();
        if ($isAdmin || $ownerId === $userTeacherId) {
            $pdo->prepare('DELETE FROM additional_works WHERE id=?')->execute([$id]);
            $msg = 'Work entry deleted.';
        } else {
            $err = 'Permission denied.';
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $chk = $pdo->prepare('SELECT teacher_id FROM additional_works WHERE id=?');
        $chk->execute([$id]);
        $ownerId = (int)$chk->fetchColumn();
        if ($isAdmin || $ownerId === $userTeacherId) {
            $tId = $isAdmin ? (int)$_POST['teacher_id'] : $userTeacherId;
            $pdo->prepare('
                UPDATE additional_works 
                SET work_date=?, start_time=?, end_time=?, teacher_id=?, work_type=?, details=?, duration=?, remarks=?
                WHERE id=?
            ')->execute([
                $_POST['work_date'],
                $_POST['start_time'] ?: null,
                $_POST['end_time'] ?: null,
                $tId,
                $_POST['work_type'],
                trim($_POST['details'] ?? ''),
                trim($_POST['duration'] ?? ''),
                trim($_POST['remarks'] ?? ''),
                $id
            ]);
            $msg = 'Work entry updated successfully.';
        } else {
            $err = 'Permission denied.';
        }
    } else { // ADD
        $tId = $isAdmin ? (int)$_POST['teacher_id'] : $userTeacherId;
        if (!$tId) {
            $err = 'Please select a valid teacher.';
        } else {
            $stTime = $_POST['start_time'] ?: null;
            $endTime = $_POST['end_time'] ?: null;
            $duration = trim($_POST['duration'] ?? '');
            if (!$duration && $stTime && $endTime) {
                $diff = (strtotime($endTime) - strtotime($stTime)) / 3600;
                if ($diff > 0) {
                    $duration = round($diff, 1) . ' hr' . ($diff > 1 ? 's' : '');
                }
            }
            $pdo->prepare('
                INSERT INTO additional_works(work_date, start_time, end_time, teacher_id, work_type, details, duration, remarks)
                VALUES(?,?,?,?,?,?,?,?)
            ')->execute([
                $_POST['work_date'],
                $stTime,
                $endTime,
                $tId,
                $_POST['work_type'],
                trim($_POST['details'] ?? ''),
                $duration,
                trim($_POST['remarks'] ?? '')
            ]);
            $msg = 'Additional work / meeting saved.';
        }
    }
}

$teachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll();

$listQ = '
    SELECT w.*, t.name teacher, t.short_code teacher_code
    FROM additional_works w
    JOIN teachers t ON t.id = w.teacher_id
';
$args = [];
if (!$isAdmin && $userTeacherId > 0) {
    $listQ .= ' WHERE w.teacher_id = ?';
    $args[] = $userTeacherId;
}
$listQ .= ' ORDER BY w.work_date DESC, w.start_time DESC';
$st = $pdo->prepare($listQ);
$st->execute($args);
$rows = $st->fetchAll();

page_header('Additional Works / Meetings');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error"><?=esc($err)?></div><?php endif; ?>

<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
    <a href="excel_io.php?action=template&module=additional_works" class="btn" style="background:#0284c7;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📥 Download Template</a>
    <?php if ($isAdmin): ?>
    <a href="excel_io.php?action=import_view&module=additional_works" class="btn" style="background:#16a34a;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📊 Import Works</a>
    <?php endif; ?>
    <a href="excel_io.php?action=export&module=additional_works" class="btn" style="background:#475569;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📤 Export Works</a>
</div>

<div class="card">
    <h2>Record Additional Work / Meeting</h2>
    <p class="small">Invigilation, examination, admission, official meetings, departmental duties, and other academic commitments appear under <strong>ADDITIONAL WORKS / MEETINGS</strong> in the official report.</p>
    <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <div class="grid">
            <div class="field">
                <label>Date</label>
                <input type="date" name="work_date" required value="<?=date('Y-m-d')?>">
            </div>
            <div class="field">
                <label>Start Time</label>
                <input type="time" name="start_time" placeholder="10:00">
            </div>
            <div class="field">
                <label>End Time</label>
                <input type="time" name="end_time" placeholder="12:00">
            </div>
            <div class="field">
                <label>Teacher</label>
                <?php if ($isAdmin): ?>
                    <select name="teacher_id" required>
                        <?php foreach ($teachers as $x): ?>
                            <option value="<?=$x['id']?>"><?=esc($x['name'])?> (<?=esc($x['short_code'])?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" readonly value="<?=esc($u['teacher_name'] ?? $u['display_name'])?>" style="background:#f0f0f0;">
                    <input type="hidden" name="teacher_id" value="<?=$userTeacherId?>">
                <?php endif; ?>
            </div>

            <div class="field">
                <label>Work Type</label>
                <select name="work_type" required>
                    <?php foreach ($workTypes as $wt): ?>
                        <option value="<?=esc($wt)?>"><?=esc($wt)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Duration</label>
                <input name="duration" placeholder="e.g. 2 hrs, 1.5 hrs">
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Details / Subject</label>
                <input name="details" required placeholder="e.g. Semester End Examination Room C222, Departmental Staff Meeting">
            </div>
        </div>
        <br>
        <div class="field">
            <label>Remarks</label>
            <textarea name="remarks" placeholder="Optional notes regarding the duty or meeting"></textarea>
        </div>
        <br>
        <button class="btn success">Save Additional Work</button>
    </form>
</div>

<div class="card table-wrap">
    <h2>Recorded Duties &amp; Works (<?=count($rows)?>)</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Teacher</th>
                <th>Work Type</th>
                <th>Details</th>
                <th>Duration</th>
                <th>Remarks</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?=esc($r['work_date'])?></strong></td>
                <td><?=esc($r['start_time'] ? substr($r['start_time'],0,5).'–'.substr($r['end_time']?:'',0,5) : '—')?></td>
                <td><?=esc($r['teacher'])?> (<?=esc($r['teacher_code'])?>)</td>
                <td><span style="background:#e3f2fd;padding:2px 6px;border-radius:3px;font-size:12px;font-weight:bold;"><?=esc($r['work_type'])?></span></td>
                <td><?=esc($r['details'])?></td>
                <td><?=esc($r['duration'] ?: '—')?></td>
                <td><?=esc($r['remarks'] ?: '—')?></td>
                <td>
                    <?php if ($isAdmin || (int)$r['teacher_id'] === $userTeacherId): ?>
                        <form method="post" onsubmit="return confirm('Delete this record?');">
                            <?=csrf_field()?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?=$r['id']?>">
                            <button class="btn danger" style="padding:4px 8px;font-size:12px;">Delete</button>
                        </form>
                    <?php else: ?>
                        <span class="small" style="color:#888;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="8">No additional works recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php page_footer(); ?>
