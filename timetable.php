<?php
require_once __DIR__.'/lib/layout.php';
require_admin();
check_csrf();

$pdo = db();
$msg = '';
$err = '';

$days = ['1'=>'Monday', '2'=>'Tuesday', '3'=>'Wednesday', '4'=>'Thursday', '5'=>'Friday', '6'=>'Saturday', '7'=>'Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE timetable SET active = 1 - active WHERE id=?')->execute([$id]);
        $msg = 'Timetable slot status updated.';
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM timetable WHERE id=?')->execute([$id]);
        $msg = 'Timetable slot deleted.';
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $pId = (int)$_POST['programme_id'];
        $sId = (int)$_POST['semester_id'];
        $paperId = (int)$_POST['paper_id'];
        
        // Server-side validation: paper must belong to programme and semester
        $chkP = $pdo->prepare('SELECT id FROM papers WHERE id=? AND programme_id=? AND semester_id=?');
        $chkP->execute([$paperId, $pId, $sId]);
        if (!$chkP->fetchColumn()) {
            $err = 'Selected paper does not belong to the selected Programme and Semester.';
        } else {
            $pdo->prepare('
                UPDATE timetable 
                SET day_of_week=?, start_time=?, end_time=?, teacher_id=?, programme_id=?, semester_id=?, paper_id=?, room_id=?, class_type=?, notes=?, active=?
                WHERE id=?
            ')->execute([
                (int)$_POST['day_of_week'],
                $_POST['start_time'],
                $_POST['end_time'],
                (int)$_POST['teacher_id'],
                $pId,
                $sId,
                $paperId,
                ($_POST['room_id'] ?: null),
                $_POST['class_type'],
                trim($_POST['notes'] ?? ''),
                isset($_POST['active']) ? 1 : 0,
                $id
            ]);
            $msg = 'Timetable slot updated successfully.';
        }
    } else { // ADD
        $pId = (int)$_POST['programme_id'];
        $sId = (int)$_POST['semester_id'];
        $paperId = (int)$_POST['paper_id'];

        // Server-side validation: paper must belong to programme and semester
        $chkP = $pdo->prepare('SELECT id FROM papers WHERE id=? AND programme_id=? AND semester_id=?');
        $chkP->execute([$paperId, $pId, $sId]);
        if (!$chkP->fetchColumn()) {
            $err = 'Invalid combination: The selected paper does not belong to the selected Programme and Semester.';
        } else {
            $pdo->prepare('
                INSERT INTO timetable(day_of_week, start_time, end_time, teacher_id, programme_id, semester_id, paper_id, room_id, class_type, notes, active)
                VALUES(?,?,?,?,?,?,?,?,?,?,1)
            ')->execute([
                (int)$_POST['day_of_week'],
                $_POST['start_time'],
                $_POST['end_time'],
                (int)$_POST['teacher_id'],
                $pId,
                $sId,
                $paperId,
                ($_POST['room_id'] ?: null),
                $_POST['class_type'],
                trim($_POST['notes'] ?? '')
            ]);
            $msg = 'Timetable entry added successfully.';
        }
    }
}

$teachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll();
$progs = $pdo->query('SELECT id, name, short_code FROM programmes WHERE active=1 ORDER BY name')->fetchAll();
$sems = $pdo->query('SELECT id, name FROM semesters WHERE active=1 ORDER BY id')->fetchAll();
$allPapers = $pdo->query('SELECT id, name, code, programme_id, semester_id FROM papers WHERE active=1 ORDER BY name')->fetchAll();
$rooms = $pdo->query('SELECT id, name FROM rooms WHERE active=1 ORDER BY name')->fetchAll();

// Day filter
$filterDay = isset($_GET['day']) ? (int)$_GET['day'] : 0;
$filterTeacher = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

$whereClause = 'WHERE 1=1';
$queryArgs = [];
if ($filterDay > 0) {
    $whereClause .= ' AND t.day_of_week = ?';
    $queryArgs[] = $filterDay;
}
if ($filterTeacher > 0) {
    $whereClause .= ' AND t.teacher_id = ?';
    $queryArgs[] = $filterTeacher;
}

$rows = $pdo->prepare('
    SELECT t.*, tr.name teacher, tr.short_code teacher_code, p.name programme, s.name semester, pa.name paper, pa.code paper_code, r.name room
    FROM timetable t
    JOIN teachers tr ON tr.id = t.teacher_id
    JOIN programmes p ON p.id = t.programme_id
    JOIN semesters s ON s.id = t.semester_id
    JOIN papers pa ON pa.id = t.paper_id
    LEFT JOIN rooms r ON r.id = t.room_id
    '.$whereClause.'
    ORDER BY t.day_of_week, t.start_time, tr.name
');
$rows->execute($queryArgs);
$allSlots = $rows->fetchAll();

// Group by day for visual academic schedule representation
$slotsByDay = [];
foreach ($allSlots as $slot) {
    $dow = $slot['day_of_week'];
    if (!isset($slotsByDay[$dow])) $slotsByDay[$dow] = [];
    $slotsByDay[$dow][] = $slot;
}

page_header('Academic Timetable Master');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error"><?=esc($err)?></div><?php endif; ?>

<!-- Timetable Schedule Filter & Add Section -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <div>
            <h2 style="margin:0;border:0;padding:0;">Weekly Timetable Schedule</h2>
            <div class="small" style="color:#64748b;margin-top:2px;">Programme &bull; Semester &bull; Paper &bull; Faculty &bull; Room Grid</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="excel_io.php?action=template&module=timetable" class="btn secondary" style="font-size:12px;padding:5px 10px;">
                📥 Download Template
            </a>
            <a href="excel_io.php?module=timetable" class="btn" style="background:#00695c;color:#fff;font-size:12px;padding:5px 10px;">
                📊 Import Timetable (.xlsx)
            </a>
            <a href="excel_io.php?action=export&module=timetable" class="btn success" style="font-size:12px;padding:5px 10px;">
                📤 Export Timetable (.xlsx)
            </a>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;border-top:1px solid #eef2f6;padding-top:10px;">
        <div class="actions">
            <span class="small" style="font-weight:600;color:#555;margin-right:4px;">Filter Day:</span>
            <a class="btn <?= $filterDay===0?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="timetable.php<?=$filterTeacher?'?teacher_id='.$filterTeacher:''?>">All Days</a>
            <?php foreach ($days as $k => $v): ?>
                <?php if ((int)$k === 7) continue; // Exclude Sunday ?>
                <a class="btn <?= $filterDay==(int)$k?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="timetable.php?day=<?=$k?><?=$filterTeacher?'&teacher_id='.$filterTeacher:''?>"><?=substr($v,0,3)?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Day by Day Visual Timetable -->
    <?php if ($slotsByDay): ?>
        <?php foreach ($slotsByDay as $dowKey => $daySlots): ?>
            <div style="margin-bottom:16px;">
                <div style="background:#1e3a61;color:#fff;padding:6px 12px;font-weight:700;font-size:13px;border-radius:4px 4px 0 0;">
                    <?=esc($days[$dowKey] ?? 'Day '.$dowKey)?> (<?=count($daySlots)?> Slots)
                </div>
                <div class="table-wrap">
                    <table style="border-top:0;">
                        <thead>
                            <tr>
                                <th style="width:14%;">Time</th>
                                <th style="width:18%;">Faculty Member</th>
                                <th style="width:16%;">Class / Semester</th>
                                <th style="width:26%;">Paper / Subject</th>
                                <th style="width:10%;">Room</th>
                                <th style="width:8%;">Type</th>
                                <th style="width:8%;text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($daySlots as $r): ?>
                            <tr>
                                <td><strong><?=esc(substr($r['start_time'],0,5).'–'.substr($r['end_time'],0,5))?></strong></td>
                                <td><strong><?=esc($r['teacher'])?></strong> <span class="small">(<?=esc($r['teacher_code'])?>)</span></td>
                                <td><?=esc($r['programme'].' '.$r['semester'])?></td>
                                <td>
                                    <strong><?=esc($r['paper'])?></strong>
                                    <?php if ($r['paper_code']): ?><span class="small" style="color:#64748b;">(<?=esc($r['paper_code'])?>)</span><?php endif; ?>
                                </td>
                                <td><?=esc($r['room'] ?: '—')?></td>
                                <td><?=esc($r['class_type'])?></td>
                                <td style="text-align:center;white-space:nowrap;">
                                    <form method="post" style="display:inline-block;">
                                        <?=csrf_field()?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?=$r['id']?>">
                                        <button class="btn secondary" style="padding:2px 6px;font-size:11px;"><?= $r['active'] ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this slot? Historical diary records remain safe.');">
                                        <?=csrf_field()?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?=$r['id']?>">
                                        <button class="btn danger" style="padding:2px 6px;font-size:11px;">Del</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="color:#64748b;font-style:italic;padding:15px;text-align:center;">No timetable slots configured for this filter.</p>
    <?php endif; ?>
</div>

<!-- Add Timetable Slot Form -->
<div class="card">
    <h2>Add Scheduled Timetable Slot</h2>
    <p class="small">The weekly timetable serves as the default academic schedule template. Daily teacher substitutions and leaves are managed via Overrides without modifying this master schedule.</p>
    
    <form method="post" id="addForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <div class="grid">
            <div class="field">
                <label>Day of Week</label>
                <select name="day_of_week" required>
                    <?php foreach ($days as $k => $v): ?>
                        <option value="<?=$k?>"><?=$v?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Start Time</label>
                <input type="time" name="start_time" required>
            </div>
            <div class="field">
                <label>End Time</label>
                <input type="time" name="end_time" required>
            </div>
            <div class="field">
                <label>Faculty Member</label>
                <select name="teacher_id" required>
                    <?php foreach ($teachers as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?> (<?=esc($x['short_code'])?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Programme</label>
                <select name="programme_id" id="add_programme_id" required>
                    <option value="">— Select Programme —</option>
                    <?php foreach ($progs as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Semester</label>
                <select name="semester_id" id="add_semester_id" required>
                    <option value="">— Select Semester —</option>
                    <?php foreach ($sems as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Paper / Subject</label>
                <select name="paper_id" id="add_paper_id" required>
                    <option value="">— Select Programme &amp; Semester First —</option>
                    <?php foreach ($allPapers as $x): ?>
                        <option value="<?=$x['id']?>" data-programme="<?=$x['programme_id']?>" data-semester="<?=$x['semester_id']?>">
                            <?=esc($x['name'])?> <?=esc($x['code'] ? '('.$x['code'].')' : '')?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Room / Lab</label>
                <select name="room_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($rooms as $x): ?>
                        <option value="<?=$x['id']?>"><?=esc($x['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Class Type</label>
                <select name="class_type">
                    <option value="Theory">Theory</option>
                    <option value="Practical">Practical</option>
                    <option value="Tutorial">Tutorial</option>
                </select>
            </div>
            <div class="field" style="grid-column: span 3;">
                <label>Timetable Notes</label>
                <input name="notes" placeholder="Optional notes regarding classroom arrangement">
            </div>
        </div>
        <br>
        <button class="btn success">Add Timetable Slot</button>
    </form>
</div>

<script>
(function() {
    const progSelect = document.getElementById('add_programme_id');
    const semSelect = document.getElementById('add_semester_id');
    const paperSelect = document.getElementById('add_paper_id');
    if (!progSelect || !semSelect || !paperSelect) return;

    // Cache original paper options
    const allPaperOptions = Array.from(paperSelect.options).map(opt => ({
        value: opt.value,
        text: opt.textContent,
        prog: opt.getAttribute('data-programme'),
        sem: opt.getAttribute('data-semester')
    })).filter(item => item.value !== '');

    function updatePaperList() {
        const selectedProg = progSelect.value;
        const selectedSem = semSelect.value;
        paperSelect.innerHTML = '';

        if (!selectedProg || !selectedSem) {
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = '— Select Programme & Semester First —';
            paperSelect.appendChild(defOpt);
            return;
        }

        const matching = allPaperOptions.filter(item => item.prog === selectedProg && item.sem === selectedSem);
        if (matching.length === 0) {
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = 'No papers configured for this Programme and Semester';
            defOpt.disabled = true;
            paperSelect.appendChild(defOpt);
        } else {
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = '— Select Paper —';
            paperSelect.appendChild(defOpt);
            matching.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.text;
                paperSelect.appendChild(opt);
            });
        }
    }

    progSelect.addEventListener('change', updatePaperList);
    semSelect.addEventListener('change', updatePaperList);
    updatePaperList();
})();
</script>

<?php page_footer(); ?>
