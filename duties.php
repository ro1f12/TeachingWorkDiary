<?php
require_once __DIR__.'/lib/layout.php';
require_login();
check_csrf();

$pdo = db();
$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$userTeacherId = (int)($u['teacher_id'] ?? 0);

$msg = '';
$types = ['Invigilation Duty','Examination Duty','Admission Duty','Official Meeting','Special Duty','Departmental Work','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $chk = $pdo->prepare('SELECT teacher_id FROM duties WHERE id=?');
        $chk->execute([$id]);
        $ownerId = (int)$chk->fetchColumn();
        if ($isAdmin || $ownerId === $userTeacherId) {
            $pdo->prepare('DELETE FROM duties WHERE id=?')->execute([$id]);
            $msg = 'Duty deleted.';
        } else {
            $msg = 'Permission denied: Cannot delete another teacher\'s duty.';
        }
    } elseif ($action === 'status') {
        $id = (int)$_POST['id'];
        $chk = $pdo->prepare('SELECT teacher_id FROM duties WHERE id=?');
        $chk->execute([$id]);
        $ownerId = (int)$chk->fetchColumn();
        if ($isAdmin || $ownerId === $userTeacherId) {
            $pdo->prepare('UPDATE duties SET status=? WHERE id=?')->execute([$_POST['status'], $id]);
            $msg = 'Status updated.';
        } else {
            $msg = 'Permission denied: Cannot update another teacher\'s duty.';
        }
    } else {
        $tId = $isAdmin ? (int)$_POST['teacher_id'] : $userTeacherId;
        $pdo->prepare('
            INSERT INTO duties(duty_date, start_time, end_time, teacher_id, duty_type, details, remarks, status)
            VALUES(?,?,?,?,?,?,?,?)
        ')->execute([
            $_POST['duty_date'],
            $_POST['start_time'] ?: null,
            $_POST['end_time'] ?: null,
            $tId,
            $_POST['duty_type'],
            trim($_POST['details'] ?? ''),
            trim($_POST['remarks'] ?? ''),
            $_POST['status'] ?? 'Planned'
        ]);
        // Also ensure it is recorded in additional_works if completed or planned
        $dur = '';
        if (!empty($_POST['start_time']) && !empty($_POST['end_time'])) {
            $diff = (strtotime($_POST['end_time']) - strtotime($_POST['start_time'])) / 3600;
            if ($diff > 0) $dur = round($diff, 1) . ' hrs';
        }
        $pdo->prepare('
            INSERT INTO additional_works(work_date, start_time, end_time, teacher_id, work_type, details, duration, remarks)
            VALUES(?,?,?,?,?,?,?,?)
        ')->execute([
            $_POST['duty_date'],
            $_POST['start_time'] ?: null,
            $_POST['end_time'] ?: null,
            $tId,
            $_POST['duty_type'],
            trim($_POST['details'] ?? ''),
            $dur,
            trim($_POST['remarks'] ?? '')
        ]);
        $msg = 'Duty recorded and linked to Additional Works.';
    }
}

$teachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll();
$q = 'SELECT d.*, t.name teacher, t.short_code teacher_code FROM duties d JOIN teachers t ON t.id=d.teacher_id';
$args = [];
if (!$isAdmin && $userTeacherId > 0) {
    $q .= ' WHERE d.teacher_id=?';
    $args[] = $userTeacherId;
}
$q .= ' ORDER BY d.duty_date DESC, d.start_time DESC';
$st = $pdo->prepare($q);
$st->execute($args);
$rows = $st->fetchAll();

page_header('Duties Management');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>

<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
    <a href="excel_io.php?action=template&module=duties" class="btn" style="background:#0284c7;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📥 Download Template</a>
    <?php if ($isAdmin): ?>
    <a href="excel_io.php?action=import_view&module=duties" class="btn" style="background:#16a34a;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📊 Import Duties</a>
    <?php endif; ?>
    <a href="excel_io.php?action=export&module=duties" class="btn" style="background:#475569;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📤 Export Duties</a>
</div>

<div class="card">
    <h2>Assign / Record Duty</h2>
    <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <div class="grid">
            <div class="field"><label>Duty Date</label><input type="date" name="duty_date" required value="<?=date('Y-m-d')?>"></div>
            <div class="field"><label>Start Time</label><input type="time" name="start_time"></div>
            <div class="field"><label>End Time</label><input type="time" name="end_time"></div>
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
                <label>Duty Type</label>
                <select name="duty_type">
                    <?php foreach ($types as $x): ?><option><?=esc($x)?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option>Planned</option>
                    <option>Completed</option>
                    <option>Cancelled</option>
                </select>
            </div>
            <div class="field" style="grid-column:span 2;">
                <label>Details</label>
                <input name="details" placeholder="e.g. Invigilation at Hall 2">
            </div>
        </div>
        <br>
        <div class="field"><label>Remarks</label><textarea name="remarks"></textarea></div>
        <br>
        <button class="btn success">Save Duty</button>
    </form>
</div>

<div class="card table-wrap">
    <h2>Recorded Duties (<?=count($rows)?>)</h2>
    <table>
        <thead>
            <tr><th>Date</th><th>Time</th><th>Teacher</th><th>Duty Type</th><th>Details</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?=esc($r['duty_date'])?></td>
                <td><?=esc(($r['start_time']?substr($r['start_time'],0,5):'').'–'.($r['end_time']?substr($r['end_time'],0,5):''))?></td>
                <td><?=esc($r['teacher'])?> (<?=esc($r['teacher_code'])?>)</td>
                <td><?=esc($r['duty_type'])?></td>
                <td><?=esc($r['details'])?></td>
                <td>
                    <span style="padding:2px 6px;border-radius:3px;font-size:12px;font-weight:bold;background:<?=$r['status']==='Completed'?'#e8f5e9':($r['status']==='Cancelled'?'#ffebee':'#fff8e1')?>;">
                        <?=esc($r['status'])?>
                    </span>
                </td>
                <td>
                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete duty?');">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?=$r['id']?>">
                        <button class="btn danger" style="padding:3px 7px;font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7">No duties recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php page_footer(); ?>
