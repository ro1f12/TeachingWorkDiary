<?php
declare(strict_types=1);
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/report_pdf.php';
require_login();

$pdo = db();
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$teacherId = (int)($_GET['teacher_id'] ?? 0);

$u = user();
if (($u['role'] ?? '') !== 'admin') {
    $teacherId = (int)($u['teacher_id'] ?? 0);
}

// Generate the authoritative two-day-per-page HTML
$html = generate_report_html($pdo, $from, $to, $teacherId);

// Inject a print toolbar at the top for browser convenience
$toolbar = '
<div class="no-print" style="position:fixed;top:0;left:0;right:0;background:#17365d;color:#fff;padding:8px 16px;display:flex;justify-content:space-between;align-items:center;z-index:9999;box-shadow:0 2px 5px rgba(0,0,0,0.2);">
    <div>
        <strong>Teaching &amp; Work Diary — Print Preview</strong> (A4 Landscape &bull; 2 Days Per Physical Page)
    </div>
    <div style="display:flex;gap:10px;">
        <button onclick="window.print()" style="background:#2e7d32;color:#fff;border:0;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:bold;">🖨️ Print Now</button>
        <a href="report_pdf.php?from='.urlencode($from).'&to='.urlencode($to).'&teacher_id='.$teacherId.'" style="background:#0277bd;color:#fff;text-decoration:none;padding:6px 14px;border-radius:4px;font-weight:bold;">📥 Download Official PDF</a>
        <button onclick="window.close()" style="background:#6c757d;color:#fff;border:0;padding:6px 14px;border-radius:4px;cursor:pointer;">Close</button>
    </div>
</div>
<style>
@media print {
    .no-print { display: none !important; }
    body { padding: 0 !important; margin: 0 !important; background: #fff !important; }
    @page {
        size: 297mm 210mm;
        margin: 7mm 8mm 7mm 8mm;
    }
}
@media screen {
    body { padding-top: 50px !important; background: #e2e8f0 !important; }
    .pdf-page { margin: 20px auto !important; background: #fff !important; box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important; padding: 7mm 8mm 7mm 8mm !important; max-width: 297mm !important; min-height: 210mm !important; }
}
</style>
';

// Insert toolbar right after <body>
$outputHtml = preg_replace('/<body[^>]*>/i', '$0' . $toolbar, $html, 1);
echo $outputHtml;
