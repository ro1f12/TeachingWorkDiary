<?php
require_once __DIR__.'/lib/layout.php';
require_login();
check_csrf();

$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$q = $pdo->prepare('
    SELECT d.*, 
           t.name teacher_name, 
           ot.name original_teacher_name, 
           p.name programme_name, 
           s.name semester_name, 
           pa.name paper_name, 
           pa.code paper_code, 
           r.name room_name
    FROM diary d
    LEFT JOIN teachers t ON t.id = d.teacher_id
    LEFT JOIN teachers ot ON ot.id = d.original_teacher_id
    JOIN programmes p ON p.id = d.programme_id
    JOIN semesters s ON s.id = d.semester_id
    JOIN papers pa ON pa.id = d.paper_id
    LEFT JOIN rooms r ON r.id = d.room_id
    WHERE d.id=?
');
$q->execute([$id]);
$d = $q->fetch();

if (!$d) {
    exit('Diary record not found.');
}

$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$userTeacherId = (int)($u['teacher_id'] ?? 0);

// Teacher can only edit records where they are the actual teacher, unless admin
if (!$isAdmin && (empty($d['teacher_id']) || $userTeacherId !== (int)$d['teacher_id'])) {
    http_response_code(403);
    exit('Access denied: You can only edit your own diary records.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pId = (int)$_POST['programme_id'];
    $sId = (int)$_POST['semester_id'];
    $paperId = (int)$_POST['paper_id'];
    $newDate = $_POST['diary_date'];
    $newStart = $_POST['start_time'];
    $newEnd = $_POST['end_time'];
    $status = $_POST['status'];

    // Verify paper belongs to programme and semester
    $chkP = $pdo->prepare('SELECT id FROM papers WHERE id=? AND programme_id=? AND semester_id=?');
    $chkP->execute([$paperId, $pId, $sId]);
    if (!$chkP->fetchColumn()) {
        $error = 'Invalid selection: The selected paper does not belong to the selected Programme and Semester.';
    } else {
        // Preserve original schedule if rescheduled or date/time changed
        $origDate = $d['original_date'] ?: $d['diary_date'];
        $origStart = $d['original_start_time'] ?: $d['start_time'];
        $origEnd = $d['original_end_time'] ?: $d['end_time'];

        $sql = '
            UPDATE diary 
            SET diary_date=?, start_time=?, end_time=?, teacher_id=?, programme_id=?, semester_id=?, paper_id=?, room_id=?,
                class_type=?, topic=?, present=?, total=?, status=?, remarks=?,
                original_date=?, original_start_time=?, original_end_time=?,
                is_edited=1
            WHERE id=?
        ';
        $pdo->prepare($sql)->execute([
            $newDate,
            $newStart,
            $newEnd,
            (!empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null),
            $pId,
            $sId,
            $paperId,
            ($_POST['room_id'] ?: null),
            $_POST['class_type'],
            trim($_POST['topic'] ?? ''),
            max(0, (int)$_POST['present']),
            max(0, (int)$_POST['total']),
            $status,
            trim($_POST['remarks'] ?? ''),
            $origDate,
            $origStart,
            $origEnd,
            $id
        ]);

        header('Location: index.php?date=' . urlencode($newDate));
        exit;
    }
}

$teachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll();
$progs = $pdo->query('SELECT id, name FROM programmes WHERE active=1 ORDER BY name')->fetchAll();
$sems = $pdo->query('SELECT id, name FROM semesters WHERE active=1 ORDER BY id')->fetchAll();
$allPapers = $pdo->query('SELECT id, name, code, programme_id, semester_id FROM papers WHERE active=1 ORDER BY name')->fetchAll();
$rooms = $pdo->query('SELECT id, name FROM rooms WHERE active=1 ORDER BY name')->fetchAll();

page_header('Edit Diary Record — '.date('d M Y', strtotime($d['diary_date'])));
?>

<?php if ($error): ?><div class="notice error"><?=esc($error)?></div><?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h2>Class Diary Details</h2>
        <div class="small" style="background:#e8f0fe;padding:6px 12px;border-radius:4px;border:1px solid #b6d4fe;">
            <strong>Original Schedule:</strong> 
            <?=esc($d['original_date'] ?: $d['diary_date'])?> 
            (<?=esc(substr($d['original_start_time']?:$d['start_time'],0,5))?>–<?=esc(substr($d['original_end_time']?:$d['end_time'],0,5))?>)
            <?php if (!empty($d['original_teacher_name']) && $d['original_teacher_id'] != $d['teacher_id']): ?>
                &bull; Scheduled Teacher: <strong><?=esc($d['original_teacher_name'])?></strong>
            <?php endif; ?>
            &bull; State: <strong><?= $d['is_edited'] ? 'Manually Confirmed / Edited' : 'Untouched Auto-Generated' ?></strong>
        </div>
    </div>

    <form method="post" id="diaryForm">
        <?=csrf_field()?>
        <input type="hidden" name="id" value="<?=$id?>">
        <div class="grid">
            <div class="field">
                <label>Actual Date</label>
                <input type="date" name="diary_date" id="diary_date" value="<?=esc($d['diary_date'])?>" required>
            </div>
            <div class="field">
                <label>Actual Start Time</label>
                <input type="time" name="start_time" id="start_time" value="<?=esc($d['start_time'])?>" required>
            </div>
            <div class="field">
                <label>Actual End Time</label>
                <input type="time" name="end_time" id="end_time" value="<?=esc($d['end_time'])?>" required>
            </div>
            <div class="field">
                <label>Actual Teacher</label>
                <select name="teacher_id">
                    <option value="">— Unassigned / Common —</option>
                    <?php foreach ($teachers as $x): ?>
                        <option value="<?=$x['id']?>" <?=$d['teacher_id']==$x['id']?'selected':''?>>
                            <?=esc($x['name'])?> (<?=esc($x['short_code'])?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Programme</label>
                <select name="programme_id" id="edit_programme_id" required>
                    <?php foreach ($progs as $x): ?>
                        <option value="<?=$x['id']?>" <?=$d['programme_id']==$x['id']?'selected':''?>>
                            <?=esc($x['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Semester</label>
                <select name="semester_id" id="edit_semester_id" required>
                    <?php foreach ($sems as $x): ?>
                        <option value="<?=$x['id']?>" <?=$d['semester_id']==$x['id']?'selected':''?>>
                            <?=esc($x['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Paper / Subject</label>
                <select name="paper_id" id="edit_paper_id" required>
                    <?php foreach ($allPapers as $x): ?>
                        <option value="<?=$x['id']?>" 
                                data-programme="<?=$x['programme_id']?>" 
                                data-semester="<?=$x['semester_id']?>" 
                                <?=$d['paper_id']==$x['id']?'selected':''?>>
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
                        <option value="<?=$x['id']?>" <?=$d['room_id']==$x['id']?'selected':''?>>
                            <?=esc($x['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Class Type</label>
                <select name="class_type">
                    <option <?=$d['class_type']=='Theory'?'selected':''?>>Theory</option>
                    <option <?=$d['class_type']=='Practical'?'selected':''?>>Practical</option>
                    <option <?=$d['class_type']=='Tutorial'?'selected':''?>>Tutorial</option>
                </select>
            </div>
            <div class="field">
                <label>Students Present</label>
                <input type="number" min="0" name="present" id="present" value="<?=$d['present']?>" required>
            </div>
            <div class="field">
                <label>Total Strength</label>
                <input type="number" min="0" name="total" id="total" value="<?=$d['total']?>" required>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status" id="status" required>
                    <?php foreach (['Conducted', 'Scheduled - Holiday', 'Cancelled', 'Rescheduled', 'Not Conducted', 'Other'] as $s): ?>
                        <option value="<?=esc($s)?>" <?=$d['status']===$s?'selected':''?>><?=esc($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <br>
        <div class="field">
            <label>Topic / Curriculum Covered</label>
            <input name="topic" value="<?=esc($d['topic']??'')?>" placeholder="e.g. Unit 2: Pointers and Memory Allocation in C">
        </div>
        <br>
        <div class="field">
            <label>Remarks</label>
            <textarea name="remarks" placeholder="Any remarks regarding class conduct, rescheduling, cancellation, or substitution"><?=esc($d['remarks']??'')?></textarea>
        </div>
        <br>
        <button class="btn success" style="font-weight:bold;padding:10px 18px;">Save Changes</button>
        <a class="btn secondary" href="index.php?date=<?=esc($d['diary_date'])?>">Cancel</a>
    </form>
</div>

<script>
(function() {
    const progSelect = document.getElementById('edit_programme_id');
    const semSelect = document.getElementById('edit_semester_id');
    const paperSelect = document.getElementById('edit_paper_id');
    const statusSelect = document.getElementById('status');
    const presentInput = document.getElementById('present');
    const totalInput = document.getElementById('total');

    // Cache paper options
    const allPaperOptions = Array.from(paperSelect.options).map(opt => ({
        value: opt.value,
        text: opt.textContent,
        prog: opt.getAttribute('data-programme'),
        sem: opt.getAttribute('data-semester')
    })).filter(item => item.value !== '');

    const currentPaperId = "<?=$d['paper_id']?>";

    function updatePaperList() {
        const selectedProg = progSelect.value;
        const selectedSem = semSelect.value;
        const previousSelection = paperSelect.value || currentPaperId;
        paperSelect.innerHTML = '';

        const matching = allPaperOptions.filter(item => item.prog === selectedProg && item.sem === selectedSem);
        if (matching.length === 0) {
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = 'No papers configured for this Programme and Semester';
            defOpt.disabled = true;
            paperSelect.appendChild(defOpt);
        } else {
            let found = false;
            matching.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.text;
                if (item.value === previousSelection) {
                    opt.selected = true;
                    found = true;
                }
                paperSelect.appendChild(opt);
            });
            if (!found && matching.length > 0) {
                matching[0].selected = true;
            }
        }
    }

    // Auto adjust attendance when status is changed to Cancelled or Scheduled - Holiday
    statusSelect.addEventListener('change', function() {
        if (statusSelect.value === 'Cancelled' || statusSelect.value === 'Scheduled - Holiday') {
            if (parseInt(presentInput.value) > 0) {
                presentInput.dataset.prevPresent = presentInput.value;
                presentInput.value = '0';
            }
        } else if (statusSelect.value === 'Conducted') {
            if (parseInt(presentInput.value) === 0 && parseInt(totalInput.value) > 0) {
                presentInput.value = totalInput.value;
            }
        }
    });

    progSelect.addEventListener('change', updatePaperList);
    semSelect.addEventListener('change', updatePaperList);
    updatePaperList();
})();
</script>

<?php page_footer(); ?>
