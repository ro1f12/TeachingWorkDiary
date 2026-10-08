<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/db.php';
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/materializer.php';
require_once __DIR__.'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Build list of dates in the range that have legitimate academic or additional work activity.
 * Strict Sunday Rule: Sundays are NEVER included in official PDF reports.
 * Empty weekdays without classes or work are also excluded.
 */
function get_active_report_dates(PDO $pdo, string $from, string $to, int $teacherId): array {
    $activeDates = [];
    $start = new DateTime($from);
    $end = new DateTime($to);

    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dow = (int)$d->format('N'); // 1 = Monday ... 7 = Sunday

        // CRITICAL FINAL REQUIREMENT: Sunday must NEVER appear in official PDF reports
        if ($dow === 7) {
            continue;
        }

        $dateStr = $d->format('Y-m-d');

        // Check diary classes for this date (filtered by actual teacher if teacherId > 0)
        $qDiary = 'SELECT COUNT(*) FROM diary WHERE diary_date = ?';
        $argsDiary = [$dateStr];
        if ($teacherId > 0) {
            $qDiary .= ' AND teacher_id = ?';
            $argsDiary[] = $teacherId;
        }
        $stD = $pdo->prepare($qDiary);
        $stD->execute($argsDiary);
        $diaryCount = (int)$stD->fetchColumn();

        // Check additional works for this date
        $qWorks = 'SELECT COUNT(*) FROM additional_works WHERE work_date = ?';
        $argsWorks = [$dateStr];
        if ($teacherId > 0) {
            $qWorks .= ' AND teacher_id = ?';
            $argsWorks[] = $teacherId;
        }
        $stW = $pdo->prepare($qWorks);
        $stW->execute($argsWorks);
        $worksCount = (int)$stW->fetchColumn();

        // Include date if there are classes OR additional works
        if ($diaryCount > 0 || $worksCount > 0) {
            $activeDates[] = $dateStr;
        } else {
            // Weekday with an official/local holiday for whole department report
            if ($teacherId === 0) {
                $hSt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date=? AND active=1 AND holiday_type IN ('official','local')");
                $hSt->execute([$dateStr]);
                if ((int)$hSt->fetchColumn() > 0) {
                    $activeDates[] = $dateStr;
                }
            }
        }
    }
    return $activeDates;
}

/**
 * Dynamically budget height for Topic and Remarks writing areas.
 * Light days expand to fill the A4 Landscape sheet; dense days compress gracefully.
 */
function calculate_page_box_heights(int $maxClasses, int $maxWorks): array {
    if ($maxClasses <= 1) {
        $hTopic = 44;
        $hRemarks = 28;
    } elseif ($maxClasses <= 2) {
        $hTopic = 40;
        $hRemarks = 26;
    } elseif ($maxClasses <= 3) {
        $hTopic = 35;
        $hRemarks = 23;
    } elseif ($maxClasses <= 4) {
        $hTopic = 30;
        $hRemarks = 20;
    } elseif ($maxClasses <= 5) {
        $hTopic = 25;
        $hRemarks = 17;
    } elseif ($maxClasses <= 6) {
        $hTopic = 20;
        $hRemarks = 13;
    } else { // 7+ classes (Dense Day)
        $hTopic = 13;
        $hRemarks = 9;
    }

    if ($maxWorks >= 2) {
        $hTopic = max(11, $hTopic - 4);
        $hRemarks = max(8, $hRemarks - 3);
    }

    return [$hTopic, $hRemarks];
}

/**
 * Render single day column HTML.
 */
function render_day_panel_html(PDO $pdo, ?string $dateStr, int $teacherId, int $hTopic = 35, int $hRemarks = 23): string {
    if (!$dateStr) {
        return '
        <td class="blank-panel" style="width:50%;vertical-align:top;padding:0 2.5mm;">
            <table style="width:100%;height:152mm;border:1px dashed #cbd5e1;background:#fafbfc;border-collapse:collapse;">
                <tr>
                    <td style="text-align:center;vertical-align:middle;color:#94a3b8;font-size:8.5pt;font-style:italic;">
                        — Intentionally Blank —
                    </td>
                </tr>
            </table>
        </td>';
    }

    // Query academic classes (Actual teacher = teacherId if filtered)
    $q = '
        SELECT d.*, 
               t.name teacher_name, t.short_code teacher_code,
               ot.name original_teacher_name, ot.short_code original_code,
               p.name programme_name, p.short_code prog_code, s.name semester_name, 
               pa.name paper_name, pa.code paper_code, 
               r.name room_name
        FROM diary d
        LEFT JOIN teachers t ON t.id = d.teacher_id
        LEFT JOIN teachers ot ON ot.id = d.original_teacher_id
        JOIN programmes p ON p.id = d.programme_id
        JOIN semesters s ON s.id = d.semester_id
        JOIN papers pa ON pa.id = d.paper_id
        LEFT JOIN rooms r ON r.id = d.room_id
        WHERE d.diary_date = ?
    ';
    $args = [$dateStr];
    if ($teacherId > 0) {
        $q .= ' AND d.teacher_id = ?';
        $args[] = $teacherId;
    }
    $q .= ' ORDER BY d.start_time, d.id';
    $st = $pdo->prepare($q);
    $st->execute($args);
    $classes = $st->fetchAll();

    $totalClasses = count($classes);
    $conductedCount = count(array_filter($classes, fn($c) => $c['status'] === 'Conducted'));
    $cancelledCount = count(array_filter($classes, fn($c) => $c['status'] === 'Cancelled'));

    // Query additional works
    $qW = 'SELECT * FROM additional_works WHERE work_date = ?';
    $argsW = [$dateStr];
    if ($teacherId > 0) {
        $qW .= ' AND teacher_id = ?';
        $argsW[] = $teacherId;
    }
    $qW .= ' ORDER BY start_time, id';
    $stW = $pdo->prepare($qW);
    $stW->execute($argsW);
    $works = $stW->fetchAll();

    $formattedDate = date('l, d F Y', strtotime($dateStr));

    // Collect Topic entries
    $topicEntries = [];
    foreach ($classes as $c) {
        if (!empty(trim($c['topic'] ?? ''))) {
            $topicEntries[] = [
                'paper_code' => $c['paper_code'] ?: ($c['prog_code'].' '.$c['semester_name']),
                'topic' => trim($c['topic'])
            ];
        }
    }

    // Collect Remark entries
    $remarkEntries = [];
    foreach ($classes as $c) {
        if (!empty(trim($c['remarks'] ?? ''))) {
            $remarkEntries[] = [
                'source' => $c['prog_code'].' '.$c['semester_name'].($c['paper_code'] ? ' ('.$c['paper_code'].')' : ''),
                'remark' => trim($c['remarks'])
            ];
        }
    }
    foreach ($works as $w) {
        if (!empty(trim($w['remarks'] ?? ''))) {
            $remarkEntries[] = [
                'source' => $w['work_type'],
                'remark' => trim($w['remarks'])
            ];
        }
    }

    // Calculate ruled lines for Topic box
    $topicLinesUsed = count($topicEntries);
    $topicRemainingHeight = max(0, $hTopic - ($topicLinesUsed * 4.2) - 2.5);
    $topicRuledCount = (int)floor($topicRemainingHeight / 5.0);

    // Calculate ruled lines for Remarks box
    $remarksLinesUsed = count($remarkEntries);
    $remarksRemainingHeight = max(0, $hRemarks - ($remarksLinesUsed * 4.2) - 2.5);
    $remarksRuledCount = (int)floor($remarksRemainingHeight / 5.0);

    ob_start();
    ?>
    <td class="day-panel" style="width:50%;vertical-align:top;padding:0 2.5mm;">
        <div class="day-banner" style="background:#e2e8f0;border:1px solid #475569;font-size:9pt;font-weight:bold;text-align:center;padding:1.4mm;margin-bottom:1.2mm;color:#0f172a;">
            <?=htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8')?>
        </div>
        
        <div class="section-label" style="font-size:7.5pt;font-weight:bold;background:#f1f5f9;border:1px solid #64748b;padding:0.7mm 1.8mm;margin-bottom:0.7mm;color:#1e293b;letter-spacing:0.3px;">
            ACADEMIC WORKS
        </div>
        <table class="report-table" style="width:100%;border-collapse:collapse;font-size:7.2pt;margin-bottom:1mm;line-height:1.15;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:6%;text-align:center;">Sl.</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:12%;">Time</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:18%;">Programme</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:30%;">Paper / Subject</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:9%;text-align:center;">P/T</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:10%;text-align:center;">Room</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:15%;text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php $sl = 1; foreach ($classes as $c): ?>
                <tr>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;"><?=$sl++?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;"><?=substr($c['start_time'], 0, 5).'–'.substr($c['end_time'], 0, 5)?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;"><?=htmlspecialchars($c['prog_code'].' '.$c['semester_name'], ENT_QUOTES, 'UTF-8')?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;word-break:break-word;">
                        <?=htmlspecialchars($c['paper_name'], ENT_QUOTES, 'UTF-8')?>
                    </td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;font-weight:bold;"><?=$c['present']?>/<?=$c['total']?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;"><?=htmlspecialchars($c['room_name'] ?? '—', ENT_QUOTES, 'UTF-8')?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;font-size:6.8pt;">
                        <?php if ($c['status'] === 'Conducted'): ?>
                            <span style="color:#166534;font-weight:bold;">Conducted</span>
                        <?php elseif ($c['status'] === 'Cancelled'): ?>
                            <span style="color:#991b1b;font-weight:bold;">Cancelled</span>
                        <?php elseif (str_contains($c['status'], 'Holiday')): ?>
                            <span style="color:#6b21a8;font-weight:bold;line-height:1.05;display:inline-block;">Scheduled<br>– Holiday</span>
                        <?php else: ?>
                            <span style="font-weight:bold;"><?=htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8')?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($totalClasses === 0): ?>
                <tr>
                    <td colspan="7" style="border:1px solid #64748b;padding:2mm;text-align:center;color:#64748b;font-style:italic;">No academic classes scheduled / conducted.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="totals-strip" style="font-size:7.2pt;font-weight:bold;background:#f8fafc;border:1px solid #64748b;padding:0.7mm 1.8mm;text-align:center;margin-bottom:1.1mm;color:#1e293b;">
            TOTAL CLASS: <?=$totalClasses?> &nbsp;|&nbsp; ALLOTTED: <?=$totalClasses?> &nbsp;|&nbsp; CONDUCTED: <?=$conductedCount?> &nbsp;|&nbsp; CANCELLED: <?=$cancelledCount?>
        </div>

        <div class="section-label" style="font-size:7.5pt;font-weight:bold;background:#f1f5f9;border:1px solid #64748b;padding:0.7mm 1.8mm;margin-bottom:0.7mm;color:#1e293b;letter-spacing:0.3px;">
            ADDITIONAL WORKS / MEETINGS
        </div>
        <table class="report-table" style="width:100%;border-collapse:collapse;font-size:7.2pt;margin-bottom:1.2mm;line-height:1.15;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:6%;text-align:center;">Sl.</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:14%;">Time</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:66%;">Details / Work</th>
                    <th style="border:1px solid #64748b;padding:0.8mm 0.7mm;width:14%;text-align:center;">Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php $wSl = 1; foreach ($works as $w): ?>
                <tr>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;"><?=$wSl++?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;"><?=htmlspecialchars(($w['start_time'] ? substr($w['start_time'],0,5).'–'.substr($w['end_time']?:'',0,5) : '—'), ENT_QUOTES, 'UTF-8')?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;"><?=htmlspecialchars($w['work_type'].': '.$w['details'], ENT_QUOTES, 'UTF-8')?></td>
                    <td style="border:1px solid #64748b;padding:0.8mm 0.7mm;text-align:center;"><?=htmlspecialchars($w['duration'] ?? '—', ENT_QUOTES, 'UTF-8')?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($works) === 0): ?>
                <tr><td colspan="4" style="border:1px solid #64748b;padding:1.4mm;text-align:center;color:#64748b;font-style:italic;">— Nil —</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="section-label" style="font-size:7.5pt;font-weight:bold;background:#f1f5f9;border:1px solid #64748b;padding:0.7mm 1.8mm;margin-bottom:0.7mm;color:#1e293b;letter-spacing:0.3px;">
            TOPIC / ACADEMIC NOTES
        </div>
        <div class="diary-box topic-box" style="height:<?=$hTopic?>mm;border:1px solid #64748b;background:#ffffff;padding:1mm 1.8mm;margin-bottom:1.1mm;font-size:6.8pt;color:#1e293b;overflow:hidden;box-sizing:border-box;">
            <?php if (!empty($topicEntries)): ?>
                <?php foreach ($topicEntries as $tItem): ?>
                    <div style="margin-bottom:0.6mm;line-height:1.15;">
                        <strong><?=htmlspecialchars($tItem['paper_code'], ENT_QUOTES, 'UTF-8')?>:</strong> 
                        <?=htmlspecialchars($tItem['topic'], ENT_QUOTES, 'UTF-8')?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php for ($i = 0; $i < $topicRuledCount; $i++): ?>
                <div style="border-bottom:1px dotted #cbd5e1;height:5.0mm;"></div>
            <?php endfor; ?>
        </div>

        <div class="section-label" style="font-size:7.5pt;font-weight:bold;background:#f1f5f9;border:1px solid #64748b;padding:0.7mm 1.8mm;margin-bottom:0.7mm;color:#1e293b;letter-spacing:0.3px;">
            REMARKS
        </div>
        <div class="diary-box remarks-box" style="height:<?=$hRemarks?>mm;border:1px solid #64748b;background:#ffffff;padding:1mm 1.8mm;margin-bottom:1.5mm;font-size:6.8pt;color:#1e293b;overflow:hidden;box-sizing:border-box;">
            <?php if (!empty($remarkEntries)): ?>
                <?php foreach ($remarkEntries as $rItem): ?>
                    <div style="margin-bottom:0.6mm;line-height:1.15;">
                        <strong><?=htmlspecialchars($rItem['source'], ENT_QUOTES, 'UTF-8')?>:</strong> 
                        <?=htmlspecialchars($rItem['remark'], ENT_QUOTES, 'UTF-8')?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php for ($i = 0; $i < $remarksRuledCount; $i++): ?>
                <div style="border-bottom:1px dotted #cbd5e1;height:5.0mm;"></div>
            <?php endfor; ?>
        </div>

        <table style="width:100%;border-collapse:collapse;margin-top:1.5mm;">
            <tr>
                <td style="width:50%;text-align:center;vertical-align:bottom;padding-top:4.5mm;">
                    <div style="border-top:1px solid #1e293b;width:82%;margin:0 auto;padding-top:0.8mm;font-size:7.5pt;font-weight:bold;color:#1e293b;">
                        Signature of Teacher
                    </div>
                </td>
                <td style="width:50%;text-align:center;vertical-align:bottom;padding-top:4.5mm;">
                    <div style="border-top:1px solid #1e293b;width:82%;margin:0 auto;padding-top:0.8mm;font-size:7.5pt;font-weight:bold;color:#1e293b;">
                        Signature of HoD / Coordinator
                    </div>
                </td>
            </tr>
        </table>
    </td>
    <?php
    return ob_get_clean();
}

/**
 * Generate full two-day-per-page HTML document for Dompdf / Print.
 */
function generate_report_html(PDO $pdo, string $from, string $to, int $teacherId): string {
    // 1. Materialize entire requested range first (never pre-filter by teacher)
    materialize_range($from, $to);

    // 2. Fetch settings
    $settings = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }

    // Dynamic Teacher Label
    if ($teacherId > 0) {
        $tSt = $pdo->prepare('SELECT name, designation FROM teachers WHERE id=?');
        $tSt->execute([$teacherId]);
        $tInfo = $tSt->fetch();
        $teacherName = $tInfo ? ($tInfo['name'] . ($tInfo['designation'] ? ' ('.$tInfo['designation'].')' : '')) : 'All Teachers';
    } else {
        $teacherName = 'All Teachers';
    }

    // 3. Filter active dates (Sundays strictly excluded, empty weekdays excluded)
    $dates = get_active_report_dates($pdo, $from, $to, $teacherId);

    $institution = $settings['institution_name'] ?? 'LALIT CHANDRA BHARALI COLLEGE, MALIGAON, GUWAHATI – 781011';
    $department = $settings['department'] ?? 'Department of Information Technology';
    $reportTitle = $settings['report_title'] ?? 'TEACHING & WORK DIARY';

    $pagesHtml = '';
    $totalDays = count($dates);

    if ($totalDays === 0) {
        $pagesHtml = '
        <div class="pdf-page">
            <div class="institution-header" style="text-align:center;border-bottom:1.5px solid #112a4a;padding-bottom:1.2mm;margin-bottom:1.5mm;">
                <div style="font-size:11.5pt;font-weight:bold;text-transform:uppercase;color:#112a4a;">'.htmlspecialchars($institution, ENT_QUOTES, 'UTF-8').'</div>
                <div style="font-size:13pt;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#0f172a;margin:0.4mm 0;">'.htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8').'</div>
                <div style="font-size:9.5pt;font-weight:bold;color:#334155;">'.htmlspecialchars($department, ENT_QUOTES, 'UTF-8').'</div>
                <div style="text-align:left;font-size:8.5pt;font-weight:bold;margin-top:0.8mm;">Teacher: '.htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8').'</div>
            </div>
            <div style="text-align:center;padding:40px;font-size:12px;color:#64748b;">
                No scheduled academic classes or additional works found for the selected criteria and date range.
            </div>
        </div>';
    } else {
        $chunks = array_chunk($dates, 2);
        $pairIndex = 0;
        foreach ($chunks as $chunk) {
            $d1 = $chunk[0];
            $d2 = $chunk[1] ?? null;
            $pBreak = ($pairIndex > 0) ? 'page-break-before: always;' : '';
            $pairIndex++;

            // Count classes and works for d1 and d2 to dynamically size topic & remarks
            $c1Count = (int)$pdo->query("SELECT COUNT(*) FROM diary WHERE diary_date='$d1'" . ($teacherId>0?" AND teacher_id=$teacherId":""))->fetchColumn();
            $w1Count = (int)$pdo->query("SELECT COUNT(*) FROM additional_works WHERE work_date='$d1'" . ($teacherId>0?" AND teacher_id=$teacherId":""))->fetchColumn();

            $c2Count = $d2 ? (int)$pdo->query("SELECT COUNT(*) FROM diary WHERE diary_date='$d2'" . ($teacherId>0?" AND teacher_id=$teacherId":""))->fetchColumn() : 0;
            $w2Count = $d2 ? (int)$pdo->query("SELECT COUNT(*) FROM additional_works WHERE work_date='$d2'" . ($teacherId>0?" AND teacher_id=$teacherId":""))->fetchColumn() : 0;

            $maxClasses = max($c1Count, $c2Count);
            $maxWorks = max($w1Count, $w2Count);

            [$hTopic, $hRemarks] = calculate_page_box_heights($maxClasses, $maxWorks);

            $col1 = render_day_panel_html($pdo, $d1, $teacherId, $hTopic, $hRemarks);
            $col2 = render_day_panel_html($pdo, $d2, $teacherId, $hTopic, $hRemarks);

            $pagesHtml .= '
            <div class="pdf-page" style="'.$pBreak.'">
                <div class="institution-header" style="text-align:center;border-bottom:1.5px solid #112a4a;padding-bottom:1.2mm;margin-bottom:1.5mm;">
                    <div style="font-size:11.5pt;font-weight:bold;text-transform:uppercase;color:#112a4a;">'.htmlspecialchars($institution, ENT_QUOTES, 'UTF-8').'</div>
                    <div style="font-size:13pt;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#0f172a;margin:0.4mm 0;">'.htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8').'</div>
                    <div style="font-size:9.5pt;font-weight:bold;color:#334155;">'.htmlspecialchars($department, ENT_QUOTES, 'UTF-8').'</div>
                    <div style="text-align:left;font-size:8.5pt;font-weight:bold;margin-top:0.8mm;">Teacher: '.htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8').'</div>
                </div>
                <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
                    <tr>
                        '.$col1.'
                        '.$col2.'
                    </tr>
                </table>
            </div>';
        }
    }

    ob_start();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?=htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8')?></title>
        <style>
            @page {
                size: 297mm 210mm;
                margin: 7mm 8mm 7mm 8mm;
            }
            * {
                box-sizing: border-box;
                -webkit-box-sizing: border-box;
            }
            body {
                font-family: Helvetica, Arial, sans-serif;
                margin: 0;
                padding: 0;
                color: #111827;
                background: #fff;
                font-size: 7.5pt;
                line-height: 1.2;
            }
            .pdf-page {
                width: 100%;
                page-break-inside: avoid;
            }
        </style>
    </head>
    <body>
        <?=$pagesHtml?>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generate PDF binary string using Dompdf.
 */
function generate_dompdf_binary(string $html): string {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    $options->set('dpi', 96);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return $dompdf->output();
}

// Direct browser download handler
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'report_pdf.php') {
    require_login();
    $pdo = db();
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $teacherId = (int)($_GET['teacher_id'] ?? 0);

    // Security check: teacher can only download own report unless admin
    $u = user();
    if (($u['role'] ?? '') !== 'admin') {
        $teacherId = (int)($u['teacher_id'] ?? 0);
    }

    $html = generate_report_html($pdo, $from, $to, $teacherId);
    $pdfBinary = generate_dompdf_binary($html);

    $teacherNameForFile = 'All_Teachers';
    if ($teacherId > 0) {
        $tSt = $pdo->prepare('SELECT name FROM teachers WHERE id=?');
        $tSt->execute([$teacherId]);
        $tName = $tSt->fetchColumn();
        if ($tName) {
            $teacherNameForFile = preg_replace('/[^A-Za-z0-9_-]/', '_', $tName);
        }
    }
    $filename = 'Teaching_Work_Diary_' . $teacherNameForFile . '_' . date('Y-m-d', strtotime($from)) . '_to_' . date('Y-m-d', strtotime($to)) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}
