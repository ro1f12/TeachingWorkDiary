<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/materializer.php';
require_admin();
check_csrf();

$pdo = db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $ov = $pdo->prepare('SELECT start_date, end_date FROM overrides WHERE id=?');
        $ov->execute([$id]);
        $row = $ov->fetch();
        $pdo->prepare('DELETE FROM overrides WHERE id=?')->execute([$id]);
        if ($row) {
            sync_untouched_diary_records($pdo, $row['start_date'], $row['end_date']);
        }
        $msg = 'Override arrangement removed and diary synced.';
    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $ov = $pdo->prepare('SELECT start_date, end_date FROM overrides WHERE id=?');
        $ov->execute([$id]);
        $row = $ov->fetch();
        $pdo->prepare('UPDATE overrides SET active = 1 - active WHERE id=?')->execute([$id]);
        if ($row) {
            sync_untouched_diary_records($pdo, $row['start_date'], $row['end_date']);
        }
        $msg = 'Override status toggled.';
    } else { // ADD
        $teacherId = (int)$_POST['teacher_id'];
        $timetableId = !empty($_POST['timetable_id']) ? (int)$_POST['timetable_id'] : null;
        $startDate = $_POST['start_date'];
        $endDate = $_POST['end_date'];
        $overrideAction = $_POST['override_action']; // 'cancel' or 'substitute'
        $subTeacherId = ($overrideAction === 'substitute' && !empty($_POST['substitute_teacher_id'])) ? (int)$_POST['substitute_teacher_id'] : null;
        $reason = trim($_POST['reason'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($overrideAction === 'substitute' && !$subTeacherId) {
            $err = 'Please select a substitute teacher.';
        } else {
            $ins = $pdo->prepare('
                INSERT INTO overrides(teacher_id, timetable_id, start_date, end_date, action, substitute_teacher_id, reason, remarks, active)
                VALUES(?,?,?,?,?,?,?,?,1)
            ');
            $ins->execute([
                $teacherId,
                $timetableId,
                $startDate,
                $endDate,
                $overrideAction,
                $subTeacherId,
                $reason,
                $remarks
            ]);
            // Immediately sync untouched diary records in range
            sync_untouched_diary_records($pdo, $startDate, $endDate);
            $msg = 'Override arrangement saved and applied to untouched daily diary records.';
        }
    }
}

$teachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll();

// Get timetable slots with full details for class-level granularity selection
$ttSlots = $pdo->query('
    SELECT t.id, t.teacher_id, t.day_of_week, t.start_time, t.end_time, p.name prog, s.name sem, pa.name paper, pa.code paper_code
    FROM timetable t
    JOIN programmes p ON p.id = t.programme_id
    JOIN semesters s ON s.id = t.semester_id
    JOIN papers pa ON pa.id = t.paper_id
    WHERE t.active=1
    ORDER BY t.teacher_id, t.day_of_week, t.start_time
')->fetchAll();

$dayNames = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];

$rows = $pdo->query('
    SELECT o.*, t.name teacher, t.short_code teacher_code, s.name substitute, s.short_code sub_code,
           tt.day_of_week, tt.start_time, tt.end_time, p.name prog, sem.name sem, pa.name paper
    FROM overrides o
    JOIN teachers t ON t.id = o.teacher_id
    LEFT JOIN teachers s ON s.id = o.substitute_teacher_id
    LEFT JOIN timetable tt ON tt.id = o.timetable_id
    LEFT JOIN programmes p ON p.id = tt.programme_id
    LEFT JOIN semesters sem ON sem.id = tt.semester_id
    LEFT JOIN papers pa ON pa.id = tt.paper_id
    ORDER BY o.start_date DESC, o.id DESC
')->fetchAll();

page_header('Faculty Leave &amp; Substitution Overrides');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error"><?=esc($err)?></div><?php endif; ?>

<div class="card">
    <h2>Record Faculty Absence / Class Substitution</h2>
    <p class="small">
        Overrides handle temporary leave, absence, and substitute arrangements. Overrides support <strong>class-level granularity</strong>: 
        an absence on a date can substitute one specific class to a colleague, cancel another, and leave other commitments untouched. The weekly master timetable remains unchanged.
    </p>

    <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <div class="grid">
            <div class="field">
                <label>Affected Faculty Member</label>
                <select name="teacher_id" id="teacher_id" required>
                    <?php foreach ($teachers as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?> (<?=esc($x['short_code'])?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Affected Class Scope (Granularity)</label>
                <select name="timetable_id" id="timetable_id">
                    <option value="">— All Classes on Affected Dates —</option>
                    <?php foreach ($ttSlots as $slot): ?>
                        <option value="<?=$slot['id']?>" data-teacher="<?=$slot['teacher_id']?>">
                            <?=$dayNames[$slot['day_of_week']]?> <?=substr($slot['start_time'],0,5)?>–<?=substr($slot['end_time'],0,5)?>: <?=esc($slot['prog'])?> <?=esc($slot['sem'])?> - <?=esc($slot['paper'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Action</label>
                <select name="override_action" id="override_action" required>
                    <option value="substitute">Assign Substitute Faculty</option>
                    <option value="cancel">Cancel Class(es)</option>
                </select>
            </div>

            <div class="field">
                <label>Start Date</label>
                <input type="date" name="start_date" required value="<?=date('Y-m-d')?>">
            </div>

            <div class="field">
                <label>End Date</label>
                <input type="date" name="end_date" required value="<?=date('Y-m-d')?>">
            </div>

            <div class="field" id="substitute_field">
                <label>Substitute Faculty</label>
                <select name="substitute_teacher_id">
                    <option value="">— Select Substitute —</option>
                    <?php foreach ($teachers as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?> (<?=esc($x['short_code'])?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Reason for Leave / Arrangement</label>
                <input name="reason" placeholder="e.g. Duty leave, Medical, CAS review">
            </div>
        </div>

        <br>
        <div class="field">
            <label>Notes / Official Remarks</label>
            <textarea name="remarks" placeholder="Optional remarks regarding syllabus continuity or internal departmental arrangement"></textarea>
        </div>
        <br>
        <button class="btn success">Save Override Arrangement</button>
    </form>
</div>

<!-- Configured Overrides Table -->
<div class="card table-wrap">
    <h2>Active &amp; Historical Overrides (<?=count($rows)?>)</h2>
    <table>
        <thead>
            <tr>
                <th style="width:18%;">Scheduled Faculty</th>
                <th style="width:22%;">Class Scope</th>
                <th style="width:14%;">Dates</th>
                <th style="width:10%;">Action</th>
                <th style="width:16%;">Substitute Faculty</th>
                <th style="width:12%;">Reason</th>
                <th style="width:8%;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?=esc($r['teacher'])?></strong> <span class="small">(<?=esc($r['teacher_code'])?>)</span></td>
                <td>
                    <?php if ($r['timetable_id']): ?>
                        <span style="font-weight:600;color:#0284c7;">
                            <?=$dayNames[$r['day_of_week']]?> <?=substr($r['start_time'],0,5)?>–<?=substr($r['end_time'],0,5)?><br>
                            <?=esc($r['prog'])?> <?=esc($r['sem'])?> - <?=esc($r['paper'])?>
                        </span>
                    <?php else: ?>
                        <span style="color:#64748b;font-style:italic;">All scheduled classes</span>
                    <?php endif; ?>
                </td>
                <td><?=esc($r['start_date'])?> <span class="small">to</span> <?=esc($r['end_date'])?></td>
                <td>
                    <?php if ($r['action'] === 'substitute'): ?>
                        <span class="badge-status badge-conducted" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd;">Substitute</span>
                    <?php else: ?>
                        <span class="badge-status badge-cancelled">Cancel</span>
                    <?php endif; ?>
                </td>
                <td><?=esc($r['substitute'] ? $r['substitute'].' ('.$r['sub_code'].')' : '—')?></td>
                <td>
                    <?=esc($r['reason'] ?: '—')?>
                    <?php if (!empty($r['remarks'])): ?>
                        <div class="small" style="color:#64748b;"><?=esc($r['remarks'])?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;white-space:nowrap;">
                    <form method="post" style="display:inline-block;">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?=$r['id']?>">
                        <button class="btn secondary" style="padding:2px 6px;font-size:11px;"><?= $r['active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this override?');">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?=$r['id']?>">
                        <button class="btn danger" style="padding:2px 6px;font-size:11px;">Del</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center;padding:20px;color:#64748b;font-style:italic;">No leave or substitution overrides recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function() {
    const teacherSelect = document.getElementById('teacher_id');
    const ttSelect = document.getElementById('timetable_id');
    const actSelect = document.getElementById('override_action');
    const subField = document.getElementById('substitute_field');

    function filterSlots() {
        const tId = teacherSelect.value;
        Array.from(ttSelect.options).forEach(opt => {
            if (!opt.value) return; // "All classes"
            if (opt.getAttribute('data-teacher') === tId) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
                if (opt.selected) ttSelect.value = '';
            }
        });
    }

    function toggleSubField() {
        if (actSelect.value === 'cancel') {
            subField.style.display = 'none';
        } else {
            subField.style.display = '';
        }
    }

    teacherSelect.addEventListener('change', filterSlots);
    actSelect.addEventListener('change', toggleSubField);
    filterSlots();
    toggleSubField();
})();
</script>

<?php page_footer(); ?>
