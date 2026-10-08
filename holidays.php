<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/materializer.php';
require_admin();
check_csrf();

$pdo = db();
$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'add';
        
        if ($action === 'update') {
            $id = (int)$_POST['id'];
            $date = $_POST['holiday_date'];
            $title = trim($_POST['title']);
            $htype = $_POST['holiday_type'];
            $active = isset($_POST['active']) ? 1 : 0;
            
            $pdo->prepare('UPDATE holidays SET holiday_date=?, title=?, holiday_type=?, active=? WHERE id=?')
                ->execute([$date, $title, $htype, $active, $id]);
            sync_untouched_diary_records($pdo, $date, $date);
            $message = 'Holiday record updated successfully.';

        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            $h = $pdo->prepare('SELECT holiday_date FROM holidays WHERE id=?');
            $h->execute([$id]);
            $date = $h->fetchColumn();
            
            $pdo->prepare('UPDATE holidays SET active = IF(active=1,0,1) WHERE id=?')->execute([$id]);
            if ($date) {
                sync_untouched_diary_records($pdo, $date, $date);
            }
            $message = 'Holiday status toggled.';

        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $h = $pdo->prepare('SELECT holiday_date FROM holidays WHERE id=?');
            $h->execute([$id]);
            $date = $h->fetchColumn();
            
            $pdo->prepare('DELETE FROM holidays WHERE id=?')->execute([$id]);
            if ($date) {
                sync_untouched_diary_records($pdo, $date, $date);
            }
            $message = 'Holiday deleted.';

        } else { // ADD
            $date = $_POST['holiday_date'];
            $title = trim($_POST['title']);
            $htype = $_POST['holiday_type'];
            
            $pdo->prepare('
                INSERT INTO holidays(holiday_date, title, holiday_type, active) 
                VALUES(?,?,?,1) 
                ON DUPLICATE KEY UPDATE title=VALUES(title), holiday_type=VALUES(holiday_type), active=1
            ')->execute([$date, $title, $htype]);
            
            sync_untouched_diary_records($pdo, $date, $date);
            $message = 'Holiday saved successfully.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$filterType = $_GET['type'] ?? 'all';
$query = "SELECT * FROM holidays";
if ($filterType === 'official') {
    $query .= " WHERE holiday_type = 'official'";
} elseif ($filterType === 'restricted') {
    $query .= " WHERE holiday_type = 'restricted'";
} elseif ($filterType === 'local') {
    $query .= " WHERE holiday_type = 'local'";
}
$query .= " ORDER BY holiday_date, id";
$rows = $pdo->query($query)->fetchAll();

page_header('Academic Calendar Holidays Master');
?>
<?php if ($message): ?><div class="notice"><?=esc($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?=esc($error)?></div><?php endif; ?>

<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
    <a href="excel_io.php?action=template&module=holidays" class="btn" style="background:#0284c7;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📥 Download Template</a>
    <a href="excel_io.php?action=import_view&module=holidays" class="btn" style="background:#16a34a;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📊 Import Holidays</a>
    <a href="excel_io.php?action=export&module=holidays" class="btn" style="background:#475569;color:#fff;font-size:12px;padding:6px 12px;text-decoration:none;border-radius:4px;font-weight:600;">📤 Export Holidays</a>
</div>

<div class="card">
    <h2>Add Academic Holiday</h2>
    <p class="small">
        Pre-filled with Gauhati University 2026 Academic Calendar holidays. 
        <strong>Official</strong> and <strong>Local</strong> holidays automatically mark scheduled timetable classes as <code>Scheduled - Holiday</code> in the daily diary. 
        <strong>Restricted</strong> holidays are recorded for reference / optional individual leave and do NOT cancel classes.
    </p>

    <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <div class="grid">
            <div class="field"><label>Holiday Date</label><input type="date" name="holiday_date" required></div>
            <div class="field" style="grid-column: span 2;"><label>Holiday Title</label><input name="title" required placeholder="e.g. Birthday of Mahatma Gandhi"></div>
            <div class="field">
                <label>Holiday Classification</label>
                <select name="holiday_type">
                    <option value="official">Official / General Holiday</option>
                    <option value="local">Local Holiday</option>
                    <option value="restricted">Restricted Holiday</option>
                </select>
            </div>
        </div>
        <br><button class="btn success">Add Holiday to Calendar</button>
    </form>
</div>

<div class="card table-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <h2 style="margin:0;border:0;padding:0;">Academic Calendar Holidays (<?=count($rows)?>)</h2>
        <div class="actions">
            <a class="btn <?= $filterType==='all'?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="holidays.php?type=all">All (<?= $pdo->query("SELECT COUNT(*) FROM holidays")->fetchColumn() ?>)</a>
            <a class="btn <?= $filterType==='official'?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="holidays.php?type=official">Official</a>
            <a class="btn <?= $filterType==='local'?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="holidays.php?type=local">Local</a>
            <a class="btn <?= $filterType==='restricted'?'':'secondary' ?>" style="font-size:12px;padding:4px 9px;" href="holidays.php?type=restricted">Restricted</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:14%;">Date</th>
                <th style="width:10%;">Day</th>
                <th style="width:40%;">Holiday Title</th>
                <th style="width:16%;">Classification</th>
                <th style="width:10%;">Status</th>
                <th style="width:10%;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $typeBadge = 'badge-conducted';
            if ($r['holiday_type'] === 'official') $typeBadge = 'badge-cancelled';
            elseif ($r['holiday_type'] === 'restricted') $typeBadge = 'badge-holiday';
            elseif ($r['holiday_type'] === 'local') $typeBadge = 'badge-rescheduled';
            ?>
            <tr>
                <form method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <td>
                        <input type="date" name="holiday_date" value="<?=esc($r['holiday_date'])?>" required style="width:140px;padding:4px 8px;font-size:12.5px;">
                    </td>
                    <td><strong><?=date('l', strtotime($r['holiday_date']))?></strong></td>
                    <td>
                        <input name="title" value="<?=esc($r['title'])?>" required style="min-width:240px;padding:4px 8px;font-size:12.5px;">
                    </td>
                    <td>
                        <select name="holiday_type" style="padding:4px 8px;font-size:12px;">
                            <option value="official" <?=$r['holiday_type']==='official'?'selected':''?>>Official</option>
                            <option value="local" <?=$r['holiday_type']==='local'?'selected':''?>>Local</option>
                            <option value="restricted" <?=$r['holiday_type']==='restricted'?'selected':''?>>Restricted</option>
                        </select>
                    </td>
                    <td>
                        <label style="font-size:12px;font-weight:600;cursor:pointer;">
                            <input type="checkbox" name="active" <?=$r['active']?'checked':''?>> Active
                        </label>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <button class="btn success" style="padding:3px 8px;font-size:11px;">Save</button>
                    </td>
                </form>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;font-style:italic;">No holidays found for this filter.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php page_footer(); ?>
