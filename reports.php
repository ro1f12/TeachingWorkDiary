<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/materializer.php';
require_once __DIR__.'/report_pdf.php';
require_login();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = db();
$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$userTeacherId = (int)($u['teacher_id'] ?? 0);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$teacherId = $isAdmin ? (int)($_GET['teacher_id'] ?? 0) : $userTeacherId;

$emailMsg = '';
$emailErr = '';

// Handle Email PDF action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_report') {
    check_csrf();
    $targetTeacherId = $isAdmin ? (int)($_POST['target_teacher_id'] ?? $teacherId) : $userTeacherId;
    if ($targetTeacherId <= 0) {
        $emailErr = 'Please select a specific teacher to email the report to.';
    } else {
        $tSt = $pdo->prepare('SELECT name, email FROM teachers WHERE id=?');
        $tSt->execute([$targetTeacherId]);
        $tRow = $tSt->fetch();
        if (!$tRow || empty($tRow['email'])) {
            $emailErr = 'The selected teacher (' . ($tRow['name'] ?? 'ID '.$targetTeacherId) . ') does not have an email address configured in Teachers Master.';
        } else {
            // Load SMTP settings
            $stSet = [];
            foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $s) {
                $stSet[$s['setting_key']] = $s['setting_value'];
            }
            if (empty($stSet['smtp_host']) || empty($stSet['smtp_username'])) {
                $emailErr = 'SMTP settings are not configured. Please configure SMTP in Settings.';
            } else {
                try {
                    $html = generate_report_html($pdo, $from, $to, $targetTeacherId);
                    $pdfBinary = generate_dompdf_binary($html);

                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = $stSet['smtp_host'];
                    $mail->SMTPAuth = true;
                    $mail->Username = $stSet['smtp_username'];
                    $mail->Password = $stSet['smtp_password'] ?? '';
                    $mail->SMTPSecure = ($stSet['smtp_security'] === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = (int)($stSet['smtp_port'] ?: 587);
                    $mail->setFrom($stSet['from_email'] ?: $stSet['smtp_username'], $stSet['from_name'] ?: APP_NAME);
                    if (!empty($stSet['reply_to'])) {
                        $mail->addReplyTo($stSet['reply_to']);
                    }
                    $mail->addAddress($tRow['email'], $tRow['name']);
                    $mail->Subject = 'Official Teaching & Work Diary: ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));
                    $mail->Body = "Respected " . $tRow['name'] . ",\n\nPlease find attached your official Teaching & Work Diary report for the period " . date('d M Y', strtotime($from)) . " to " . date('d M Y', strtotime($to)) . ".\n\nDepartment of Information Technology\nLalit Chandra Bharali College";

                    $pdfFileName = 'Teaching_Diary_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tRow['name']) . '_' . date('Ymd', strtotime($from)) . '.pdf';
                    $mail->addStringAttachment($pdfBinary, $pdfFileName, 'base64', 'application/pdf');
                    $mail->send();
                    $emailMsg = 'Official PDF report successfully emailed to ' . $tRow['name'] . ' (' . $tRow['email'] . ').';
                } catch (Throwable $e) {
                    $emailErr = 'Failed to send email: ' . $e->getMessage();
                }
            }
        }
    }
}

// Materialize full date range first (never pre-filter by teacher)
materialize_range($from, $to);

// Calculate statistics for preview
$dates = get_active_report_dates($pdo, $from, $to, $teacherId);

$qClasses = 'SELECT status, present, total FROM diary WHERE diary_date BETWEEN ? AND ?';
$argsC = [$from, $to];
if ($teacherId > 0) {
    $qClasses .= ' AND teacher_id = ?';
    $argsC[] = $teacherId;
}
$stC = $pdo->prepare($qClasses);
$stC->execute($argsC);
$allC = $stC->fetchAll();

$totalAllotted = count($allC);
$totalConducted = count(array_filter($allC, fn($x) => $x['status'] === 'Conducted'));
$totalCancelled = count(array_filter($allC, fn($x) => $x['status'] === 'Cancelled'));
$totalHoliday = count(array_filter($allC, fn($x) => $x['status'] === 'Scheduled - Holiday'));

$qW = 'SELECT COUNT(*) FROM additional_works WHERE work_date BETWEEN ? AND ?';
$argsW = [$from, $to];
if ($teacherId > 0) {
    $qW .= ' AND teacher_id = ?';
    $argsW[] = $teacherId;
}
$stW = $pdo->prepare($qW);
$stW->execute($argsW);
$totalWorks = (int)$stW->fetchColumn();

$teachers = $pdo->query('SELECT id, name, short_code, email FROM teachers WHERE active=1 ORDER BY name')->fetchAll();

page_header('Teaching &amp; Work Diary Report');
?>
<?php if ($emailMsg): ?><div class="notice"><?=esc($emailMsg)?></div><?php endif; ?>
<?php if ($emailErr): ?><div class="notice error"><?=esc($emailErr)?></div><?php endif; ?>

<div class="card">
    <div style="border-bottom: 2px solid #17365d; padding-bottom: 8px; margin-bottom: 18px;">
        <h2 style="margin: 0; color: #17365d; font-size: 19px; letter-spacing: 0.5px;">TEACHING &amp; WORK DIARY REPORT</h2>
        <div class="small" style="color: #555; margin-top: 2px;">
            Lalit Chandra Bharali College &bull; Department of Information Technology &bull; Maligaon, Guwahati – 781011
        </div>
    </div>

    <form method="get" id="reportForm">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px;">
            <div class="field">
                <label>Teacher</label>
                <?php if ($isAdmin): ?>
                    <select name="teacher_id" id="rep_teacher_id">
                        <option value="0">All Teachers (Departmental)</option>
                        <?php foreach ($teachers as $x): ?>
                            <option value="<?=$x['id']?>" <?=$teacherId==$x['id']?'selected':''?>>
                                <?=esc($x['name'])?> (<?=esc($x['short_code'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" readonly value="<?=esc($u['teacher_name'] ?? $u['display_name'])?>" style="background:#f4f6f9;">
                    <input type="hidden" name="teacher_id" id="rep_teacher_id" value="<?=$userTeacherId?>">
                <?php endif; ?>
            </div>

            <div class="field">
                <label>From Date</label>
                <input type="date" name="from" id="rep_from" value="<?=esc($from)?>" required>
            </div>

            <div class="field">
                <label>To Date</label>
                <input type="date" name="to" id="rep_to" value="<?=esc($to)?>" required>
            </div>

            <div class="field">
                <label>Report Type</label>
                <select name="report_type" id="rep_type">
                    <option value="teaching_diary" selected>Teaching &amp; Work Diary</option>
                </select>
            </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid #eef2f6;align-items:center;">
            <button type="submit" class="btn" style="background:#17365d;color:#fff;padding:9px 18px;font-weight:600;">
                📊 View Preview
            </button>
            <button type="button" class="btn success" id="btnGenPdf" style="padding:9px 18px;font-weight:600;">
                📥 Generate PDF
            </button>
            <button type="button" class="btn secondary" id="btnPrintPrev" style="padding:9px 18px;font-weight:600;">
                🖨️ Print Preview
            </button>
            <button type="button" class="btn" id="btnExportXlsx" style="background:#15803d;color:#fff;padding:9px 18px;font-weight:600;">
                📤 Export to Excel (.xlsx)
            </button>
            <?php if ($teacherId > 0 || $isAdmin): ?>
                <button type="button" class="btn" id="btnShowEmail" style="background:#00695c;color:#fff;padding:9px 18px;font-weight:600;">
                    ✉️ Email Report
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Report Summary Metrics -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px;">
    <div class="card" style="text-align:center;padding:12px 8px;">
        <div style="font-size:11px;color:#555;text-transform:uppercase;font-weight:700;">Total Allotted</div>
        <div style="font-size:24px;font-weight:700;color:#17365d;margin-top:2px;"><?=$totalAllotted?></div>
    </div>
    <div class="card" style="text-align:center;padding:12px 8px;">
        <div style="font-size:11px;color:#555;text-transform:uppercase;font-weight:700;">Conducted</div>
        <div style="font-size:24px;font-weight:700;color:#2e7d32;margin-top:2px;"><?=$totalConducted?></div>
    </div>
    <div class="card" style="text-align:center;padding:12px 8px;">
        <div style="font-size:11px;color:#555;text-transform:uppercase;font-weight:700;">Cancelled</div>
        <div style="font-size:24px;font-weight:700;color:#c62828;margin-top:2px;"><?=$totalCancelled?></div>
    </div>
    <div class="card" style="text-align:center;padding:12px 8px;">
        <div style="font-size:11px;color:#555;text-transform:uppercase;font-weight:700;">Holidays</div>
        <div style="font-size:24px;font-weight:700;color:#6a1b9a;margin-top:2px;"><?=$totalHoliday?></div>
    </div>
    <div class="card" style="text-align:center;padding:12px 8px;">
        <div style="font-size:11px;color:#555;text-transform:uppercase;font-weight:700;">Additional Works</div>
        <div style="font-size:24px;font-weight:700;color:#0277bd;margin-top:2px;"><?=$totalWorks?></div>
    </div>
</div>

<!-- Active Days & Pagination Preview -->
<div class="card table-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div>
            <h3 style="margin:0;font-size:16px;color:#17365d;">
                Report Days Included: <strong><?=count($dates)?> Calendar Working Days</strong>
            </h3>
            <span class="small" style="color:#555;">
                Exact Physical Sheets: <strong><?=ceil(count($dates)/2)?> A4 Landscape Page(s)</strong> (2 Days Per Physical Page, Sundays excluded)
            </span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th style="width:120px;">Date</th>
                <th style="width:100px;">Day</th>
                <th>Academic Classes (Scheduled / Conducted)</th>
                <th>Additional Works / Duties</th>
                <th style="width:140px;">Physical Page</th>
            </tr>
        </thead>
        <tbody>
        <?php 
        $pageNo = 1;
        $dayIndex = 0;
        foreach ($dates as $i => $dStr): 
            $dowName = date('l', strtotime($dStr));
            $isOdd = ($i % 2 === 0);
            $colLabel = $isOdd ? 'Day 1 (Left)' : 'Day 2 (Right)';
            $currentPage = (int)floor($i / 2) + 1;

            // Class summary for this day
            $cq = 'SELECT status, COUNT(*) c FROM diary WHERE diary_date=?';
            $cargs = [$dStr];
            if ($teacherId > 0) { $cq .= ' AND teacher_id=?'; $cargs[] = $teacherId; }
            $cq .= ' GROUP BY status';
            $cst = $pdo->prepare($cq);
            $cst->execute($cargs);
            $cStatuses = $cst->fetchAll(PDO::FETCH_KEY_PAIR);

            // Works summary for this day
            $wq = 'SELECT work_type, details FROM additional_works WHERE work_date=?';
            $wargs = [$dStr];
            if ($teacherId > 0) { $wq .= ' AND teacher_id=?'; $wargs[] = $teacherId; }
            $wst = $pdo->prepare($wq);
            $wst->execute($wargs);
            $wItems = $wst->fetchAll();
        ?>
            <tr>
                <td><?=$i + 1?></td>
                <td><strong><?=date('d-m-Y', strtotime($dStr))?></strong></td>
                <td><?=esc($dowName)?></td>
                <td>
                    <?php if ($cStatuses): ?>
                        <?php foreach ($cStatuses as $stName => $cnt): ?>
                            <span class="badge-status badge-<?=strtolower(explode(' ', $stName)[0])?>" style="margin-right:4px;">
                                <?=esc($stName)?>: <?=$cnt?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="small" style="color:#888;">No timetable classes</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($wItems): ?>
                        <ul style="margin:0;padding-left:18px;font-size:12px;">
                            <?php foreach ($wItems as $w): ?>
                                <li><strong><?=esc($w['work_type'])?>:</strong> <?=esc($w['details'])?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span class="small" style="color:#888;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="font-weight:600;color:#17365d;">Page <?=$currentPage?></span> 
                    <span class="small" style="color:#666;">(<?=$colLabel?>)</span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$dates): ?>
            <tr>
                <td colspan="6" style="text-align:center;padding:20px;color:#666;">
                    No academic classes or additional duties found in the selected date range.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Email Section -->
<?php if ($teacherId > 0 || $isAdmin): ?>
    <div class="card" id="emailSection" style="margin-top:16px;">
        <h3 style="margin:0 0 6px;color:#17365d;">Email Official PDF Report via SMTP</h3>
        <p class="small">The generated A4 landscape PDF will be attached and sent directly to the teacher's registered institutional email.</p>
        <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="email_report">
            <?php if ($isAdmin): ?>
                <div class="field" style="min-width:240px;">
                    <label>Recipient Teacher</label>
                    <select name="target_teacher_id" required>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?=$t['id']?>" <?=$teacherId==$t['id']?'selected':''?>>
                                <?=esc($t['name'])?> (<?=esc($t['email'] ?: 'No email')?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="target_teacher_id" value="<?=$userTeacherId?>">
            <?php endif; ?>
            <button class="btn" style="background:#00695c;color:#fff;padding:9px 18px;font-weight:600;">
                ✉️ Send Report by Email
            </button>
        </form>
    </div>
<?php endif; ?>

<script>
(function() {
    function getParams() {
        const from = document.getElementById('rep_from').value;
        const to = document.getElementById('rep_to').value;
        const tId = document.getElementById('rep_teacher_id').value;
        return `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&teacher_id=${encodeURIComponent(tId)}`;
    }

    const btnPdf = document.getElementById('btnGenPdf');
    if (btnPdf) {
        btnPdf.addEventListener('click', function() {
            window.open('report_pdf.php?' + getParams(), '_blank');
        });
    }

    const btnPrint = document.getElementById('btnPrintPrev');
    if (btnPrint) {
        btnPrint.addEventListener('click', function() {
            window.open('report_print.php?' + getParams(), '_blank');
        });
    }

    const btnXlsx = document.getElementById('btnExportXlsx');
    if (btnXlsx) {
        btnXlsx.addEventListener('click', function() {
            window.location.href = 'excel_io.php?action=export&module=diary&' + getParams();
        });
    }

    const btnEmail = document.getElementById('btnShowEmail');
    if (btnEmail) {
        btnEmail.addEventListener('click', function() {
            const sec = document.getElementById('emailSection');
            if (sec) {
                sec.scrollIntoView({ behavior: 'smooth' });
            }
        });
    }
})();
</script>

<?php page_footer(); ?>
