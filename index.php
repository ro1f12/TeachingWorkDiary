<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/materializer.php';
require_login();

$pdo = db();
$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$userTeacherId = (int)($u['teacher_id'] ?? 0);

$date = $_GET['date'] ?? date('Y-m-d');
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

// Determine teacher filter
if ($isAdmin) {
    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
} else {
    $teacherId = $userTeacherId;
}

// 1. Materialize entire day for all timetable records without pre-filtering by teacher
materialize_range($date, $date);

// 2. Query academic classes for this date
$q = '
    SELECT d.*, 
           t.name teacher_name, t.short_code teacher_code,
           ot.name original_teacher, ot.short_code original_code,
           p.name programme, s.name semester, 
           pa.name paper, pa.code paper_code, 
           r.name room
    FROM diary d
    LEFT JOIN teachers t ON t.id = d.teacher_id
    LEFT JOIN teachers ot ON ot.id = d.original_teacher_id
    JOIN programmes p ON p.id = d.programme_id
    JOIN semesters s ON s.id = d.semester_id
    JOIN papers pa ON pa.id = d.paper_id
    LEFT JOIN rooms r ON r.id = d.room_id
    WHERE d.diary_date = ?
';
$args = [$date];
if ($teacherId > 0) {
    $q .= ' AND d.teacher_id = ?';
    $args[] = $teacherId;
}
$q .= ' ORDER BY d.start_time, d.id';
$st = $pdo->prepare($q);
$st->execute($args);
$rows = $st->fetchAll();

// 3. Query additional works / duties for this date
$qW = '
    SELECT w.*, t.name teacher_name, t.short_code teacher_code
    FROM additional_works w
    JOIN teachers t ON t.id = w.teacher_id
    WHERE w.work_date = ?
';
$argsW = [$date];
if ($teacherId > 0) {
    $qW .= ' AND w.teacher_id = ?';
    $argsW[] = $teacherId;
}
$qW .= ' ORDER BY w.start_time, w.id';
$stW = $pdo->prepare($qW);
$stW->execute($argsW);
$dayWorks = $stW->fetchAll();

// Check conflicts between classes and additional works/duties (Requirement 34)
$dutyConflicts = [];
$confQ = $pdo->prepare('
    SELECT teacher_id, start_time, end_time, work_type, details 
    FROM additional_works 
    WHERE work_date = ?
    UNION ALL
    SELECT teacher_id, start_time, end_time, duty_type as work_type, details
    FROM duties
    WHERE duty_date = ?
');
$confQ->execute([$date, $date]);
$allDuties = $confQ->fetchAll();

foreach ($rows as $r) {
    $cId = $r['id'];
    $dutyConflicts[$cId] = [];
    foreach ($allDuties as $duty) {
        if (!empty($r['teacher_id']) && (int)$duty['teacher_id'] === (int)$r['teacher_id'] && !empty($duty['start_time']) && !empty($duty['end_time'])) {
            if ($r['start_time'] < $duty['end_time'] && $r['end_time'] > $duty['start_time']) {
                $dutyConflicts[$cId][] = $duty['work_type'] . ' (' . substr($duty['start_time'], 0, 5) . '–' . substr($duty['end_time'], 0, 5) . ')';
            }
        }
    }
}

// Holiday check for header notification
$hSt = $pdo->prepare("SELECT title, holiday_type FROM holidays WHERE holiday_date=? AND active=1 LIMIT 1");
$hSt->execute([$date]);
$holidayInfo = $hSt->fetch();

// Calculate day totals
$totAllotted = count($rows);
$totConducted = count(array_filter($rows, fn($x) => $x['status'] === 'Conducted'));
$totCancelled = count(array_filter($rows, fn($x) => $x['status'] === 'Cancelled'));
$totHoliday = count(array_filter($rows, fn($x) => $x['status'] === 'Scheduled - Holiday'));

$formattedHeaderDate = date('l, d F Y', strtotime($date));
page_header('Teaching &amp; Work Diary — ' . $formattedHeaderDate);
?>

<?php if ($holidayInfo): ?>
    <div class="notice" style="background: <?=$holidayInfo['holiday_type']==='restricted'?'#fef9c3':'#fee2e2'?>; border-color: <?=$holidayInfo['holiday_type']==='restricted'?'#fef08a':'#fecaca'?>; color: <?=$holidayInfo['holiday_type']==='restricted'?'#854d0e':'#991b1b'?>;">
        <strong>Academic Calendar:</strong> <?=esc($holidayInfo['title'])?> (<?=esc(ucfirst($holidayInfo['holiday_type']))?> Holiday)
    </div>
<?php endif; ?>

<!-- Date & Filter Control Strip -->
<div class="card" style="padding:14px 18px;">
    <form class="grid" method="get" style="align-items:end;gap:12px;">
        <div class="field" style="max-width:220px;">
            <label>Select Date</label>
            <input type="date" name="date" value="<?=esc($date)?>">
        </div>

        <?php if ($isAdmin): ?>
            <?php $allTeachers = $pdo->query('SELECT id, name, short_code FROM teachers WHERE active=1 ORDER BY name')->fetchAll(); ?>
            <div class="field" style="max-width:260px;">
                <label>Filter by Faculty</label>
                <select name="teacher_id">
                    <option value="0">All Faculty Members</option>
                    <?php foreach ($allTeachers as $t): ?>
                        <option value="<?=$t['id']?>" <?=$teacherId==$t['id']?'selected':''?>>
                            <?=esc($t['name'])?> (<?=esc($t['short_code'])?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="field" style="max-width:260px;">
                <label>Faculty</label>
                <input type="text" readonly value="<?=esc($u['teacher_name'] ?? $u['display_name'])?>" style="background:#f1f5f9;color:#475569;">
            </div>
        <?php endif; ?>

        <div class="actions" style="margin-bottom:2px;">
            <button class="btn success">View Diary</button>
            <a class="btn secondary" href="index.php?date=<?=date('Y-m-d')?><?=$teacherId?'&teacher_id='.$teacherId:''?>">Today</a>
            <a class="btn secondary" href="index.php?date=<?=$prevDate?><?=$teacherId?'&teacher_id='.$teacherId:''?>">&larr; Prev Day</a>
            <a class="btn secondary" href="index.php?date=<?=$nextDate?><?=$teacherId?'&teacher_id='.$teacherId:''?>">Next Day &rarr;</a>
        </div>
    </form>
</div>

<!-- Summary Metrics Strip -->
<div class="grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
    <div class="card" style="margin:0;padding:12px 16px;border-left:4px solid #1e3a61;">
        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Classes Allotted</div>
        <div style="font-size:22px;font-weight:700;color:#0f243e;margin-top:2px;"><?=$totAllotted?></div>
    </div>
    <div class="card" style="margin:0;padding:12px 16px;border-left:4px solid #16a34a;">
        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Conducted</div>
        <div style="font-size:22px;font-weight:700;color:#166534;margin-top:2px;"><?=$totConducted?></div>
    </div>
    <div class="card" style="margin:0;padding:12px 16px;border-left:4px solid #dc2626;">
        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Cancelled</div>
        <div style="font-size:22px;font-weight:700;color:#991b1b;margin-top:2px;"><?=$totCancelled?></div>
    </div>
    <div class="card" style="margin:0;padding:12px 16px;border-left:4px solid #9333ea;">
        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Scheduled Holiday</div>
        <div style="font-size:22px;font-weight:700;color:#6b21a8;margin-top:2px;"><?=$totHoliday?></div>
    </div>
</div>

<!-- Section 1: Academic Works -->
<div class="card table-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h2 style="margin:0;border:0;padding:0;">Academic Works (<?=$totAllotted?> Classes)</h2>
        <span class="small">Authoritative weekly schedule materialized into daily diary records.</span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:11%;">Time</th>
                <th style="width:16%;">Faculty</th>
                <th style="width:14%;">Class / Semester</th>
                <th style="width:23%;">Paper / Subject</th>
                <th style="width:10%;text-align:center;">Present / Total</th>
                <th style="width:8%;text-align:center;">Room</th>
                <th style="width:10%;">Status</th>
                <th style="width:8%;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $isSubstituted = ((int)$r['original_teacher_id'] > 0 && (int)$r['original_teacher_id'] !== (int)$r['teacher_id']);
            $isRescheduled = ($r['status'] === 'Rescheduled' || ($r['original_date'] && $r['original_date'] !== $r['diary_date']) || ($r['original_start_time'] && $r['original_start_time'] !== $r['start_time']));
            $hasConflict = !empty($dutyConflicts[$r['id']]);

            $badgeClass = 'badge-other';
            if ($r['status'] === 'Conducted') $badgeClass = 'badge-conducted';
            elseif ($r['status'] === 'Cancelled') $badgeClass = 'badge-cancelled';
            elseif ($r['status'] === 'Scheduled - Holiday') $badgeClass = 'badge-holiday';
            elseif ($r['status'] === 'Rescheduled') $badgeClass = 'badge-rescheduled';
            ?>
            <tr style="<?=$hasConflict?'background:#fffbeb;':''?>">
                <td style="white-space:nowrap;">
                    <strong><?=esc(substr($r['start_time'],0,5).'–'.substr($r['end_time'],0,5))?></strong>
                    <?php if ($isRescheduled && $r['original_date']): ?>
                        <div class="small" style="color:#c2410c;font-weight:600;">
                            Orig: <?=esc($r['original_date'])?> (<?=esc(substr($r['original_start_time'],0,5))?>)
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?=esc($r['teacher_name'] ?: 'Unassigned / Common')?></strong>
                    <?php if (!empty($r['teacher_code'])): ?>
                        <span class="small">(<?=esc($r['teacher_code'])?>)</span>
                    <?php endif; ?>
                    <?php if ($isSubstituted): ?>
                        <div class="small" style="color:#0369a1;font-weight:600;">
                            Scheduled: <?=esc($r['original_teacher'] ?: 'Unassigned')?> <?=!empty($r['original_code']) ? '('.esc($r['original_code']).')' : ''?>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasConflict): ?>
                        <div class="small" style="color:#b91c1c;font-weight:700;margin-top:2px;">
                            ⚠️ Duty Conflict: <?=esc(implode(', ', $dutyConflicts[$r['id']]))?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?=esc($r['programme'].' '.$r['semester'])?></td>
                <td>
                    <strong><?=esc($r['paper'])?></strong>
                    <?php if ($r['paper_code']): ?>
                        <span class="small" style="color:#475569;">(<?=esc($r['paper_code'])?>)</span>
                    <?php endif; ?>
                    <div class="small" style="color:#64748b;"><?=esc($r['class_type'])?></div>
                    <?php if (!empty($r['topic'])): ?>
                        <div class="small" style="margin-top:2px;color:#1e293b;"><em>Topic:</em> <?=esc($r['topic'])?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['remarks'])): ?>
                        <div class="small" style="color:#64748b;"><em>Note:</em> <?=esc($r['remarks'])?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <strong><?=esc($r['present'].' / '.$r['total'])?></strong>
                </td>
                <td style="text-align:center;"><?=esc($r['room'] ?: '—')?></td>
                <td>
                    <span class="badge-status <?=$badgeClass?>">
                        <?=esc($r['status'])?>
                    </span>
                </td>
                <td style="text-align:center;">
                    <?php if ($isAdmin || (!empty($r['teacher_id']) && $userTeacherId === (int)$r['teacher_id'])): ?>
                        <a class="btn success" style="padding:4px 9px;font-size:12px;" href="edit_diary.php?id=<?=$r['id']?>">Edit</a>
                    <?php else: ?>
                        <span class="small" style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="8" style="text-align:center;padding:30px;color:#64748b;font-style:italic;">
                    No scheduled academic classes found for <?=esc($formattedHeaderDate)?><?php if ($teacherId > 0): ?> for this faculty member<?php endif; ?>.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Section 2: Additional Works / Meetings performed on this date -->
<div class="card table-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h2 style="margin:0;border:0;padding:0;">Additional Works &amp; Meetings (<?=count($dayWorks)?> Recorded)</h2>
        <a class="btn secondary" style="font-size:12px;padding:4px 10px;" href="work.php">+ Record Additional Work</a>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:12%;">Time</th>
                <th style="width:18%;">Faculty</th>
                <th style="width:20%;">Work Type</th>
                <th style="width:32%;">Details / Academic Commitment</th>
                <th style="width:10%;text-align:center;">Duration</th>
                <th style="width:8%;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dayWorks as $w): ?>
            <tr>
                <td><?=esc($w['start_time'] ? substr($w['start_time'],0,5).'–'.substr($w['end_time']?:'',0,5) : '—')?></td>
                <td><strong><?=esc($w['teacher_name'])?></strong> <span class="small">(<?=esc($w['teacher_code'])?>)</span></td>
                <td><span class="badge-status badge-other" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd;"><?=esc($w['work_type'])?></span></td>
                <td>
                    <?=esc($w['details'])?>
                    <?php if (!empty($w['remarks'])): ?>
                        <div class="small" style="color:#64748b;"><?=esc($w['remarks'])?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;"><?=esc($w['duration'] ?: '—')?></td>
                <td style="text-align:center;">
                    <?php if ($isAdmin || (int)$w['teacher_id'] === $userTeacherId): ?>
                        <a class="btn secondary" style="padding:3px 8px;font-size:11px;" href="work.php">Manage</a>
                    <?php else: ?>
                        <span class="small" style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$dayWorks): ?>
            <tr>
                <td colspan="6" style="text-align:center;padding:20px;color:#64748b;font-style:italic;">
                    No additional works, invigilation duties, or official meetings recorded for this date.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php page_footer(); ?>
