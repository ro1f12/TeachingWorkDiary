<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/exporter.php';
require_once __DIR__ . '/lib/importer.php';

require_login();
$pdo = db();
$u = user();
$isAdmin = ($u['role'] ?? '') === 'admin';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$module = $_GET['module'] ?? $_POST['module'] ?? 'timetable';

$validModules = [
    'timetable', 'teachers', 'programmes', 'semesters', 'papers',
    'rooms', 'class_strength', 'holidays', 'duty_types', 'additional_works', 'duties', 'diary'
];

if (!in_array($module, $validModules, true)) {
    exit('Invalid module specified.');
}

$exporter = new AcademicExporter($pdo);
$importer = new AcademicImporter($pdo);

// 1. Download Template
if ($action === 'template') {
    $exporter->downloadTemplate($module);
    exit;
}

// 2. Export Excel
if ($action === 'export') {
    $filters = $_GET;
    // For non-admin, force teacher filter to own ID for diary, work, duties
    if (!$isAdmin && in_array($module, ['diary', 'additional_works', 'duties'], true)) {
        $filters['teacher_id'] = (int)($u['teacher_id'] ?? 0);
    }
    $exporter->exportData($module, $filters);
    exit;
}

// 3. Import UI & Processing (Admin only for masters & timetable; teachers can import own works/duties)
if (in_array($module, ['timetable', 'teachers', 'programmes', 'semesters', 'papers', 'rooms', 'class_strength', 'holidays', 'duty_types'], true)) {
    require_admin();
}

$previewData = null;
$importResult = null;
$errorMsg = '';

// Step A: Handle Upload & Validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'validate_upload') {
    check_csrf();
    try {
        if (!isset($_FILES['excel_file'])) {
            throw new Exception('Please select an Excel file to upload.');
        }
        $rawRows = $importer->parseUploadedFile($_FILES['excel_file']);
        $previewData = $importer->validate($module, $rawRows);

        // Store validated rows in session for confirmation
        $_SESSION['import_preview_' . $module] = $previewData;
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

// Step B: Handle Confirmation & Commit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_import') {
    check_csrf();
    $cached = $_SESSION['import_preview_' . $module] ?? null;
    if (!$cached || empty($cached['valid_rows'])) {
        $errorMsg = 'Import session expired or no valid rows found. Please upload again.';
    } else {
        $importMode = $_POST['import_mode'] ?? 'add_new';
        $importResult = $importer->commitImport($module, $cached['valid_rows'], ['mode' => $importMode]);
        unset($_SESSION['import_preview_' . $module]);
    }
}

page_header('Excel Import &amp; Export — ' . ucfirst(str_replace('_', ' ', $module)));
?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <h2 style="margin:0;color:#112a4a;font-size:18px;">
                Excel Data Management: <?=esc(ucfirst(str_replace('_', ' ', $module)))?>
            </h2>
            <div class="small" style="color:#555;margin-top:2px;">
                Download official templates, export authoritative records, or bulk import with strict validation and audit.
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="excel_io.php?action=template&module=<?=urlencode($module)?>" class="btn secondary" style="font-weight:600;">
                📥 Download Template (.xlsx)
            </a>
            <a href="excel_io.php?action=export&module=<?=urlencode($module)?>" class="btn success" style="font-weight:600;">
                📤 Export Current Data (.xlsx)
            </a>
        </div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="notice error"><?=esc($errorMsg)?></div>
    <?php endif; ?>

    <?php if ($importResult): ?>
        <?php if ($importResult['success']): ?>
            <div class="notice" style="background:#e8f5e9;border-color:#2e7d32;color:#1b5e20;">
                <strong>Import Completed Successfully!</strong><br>
                Records Imported: <strong><?=$importResult['imported']?></strong> &bull; 
                Records Updated: <strong><?=$importResult['updated']?></strong> &bull; 
                Records Skipped: <strong><?=$importResult['skipped']?></strong>
            </div>
            <div style="margin-top:14px;">
                <?php
                $returnUrl = 'index.php';
                if ($module === 'timetable') $returnUrl = 'timetable.php';
                elseif ($module === 'holidays') $returnUrl = 'holidays.php';
                elseif ($module === 'additional_works') $returnUrl = 'work.php';
                elseif ($module === 'duties') $returnUrl = 'duties.php';
                elseif (in_array($module, ['teachers','programmes','semesters','papers','rooms','class_strength','duty_types'])) $returnUrl = 'masters.php?type=' . $module;
                ?>
                <a href="<?=$returnUrl?>" class="btn" style="background:#17365d;color:#fff;">Back to <?=ucfirst(str_replace('_', ' ', $module))?></a>
            </div>
        <?php else: ?>
            <div class="notice error">
                <strong>Import Transaction Failed &amp; Rolled Back:</strong><br>
                <?=esc($importResult['error'])?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Step 1: Upload Form -->
    <?php if (!$previewData && !$importResult): ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="validate_upload">
            <input type="hidden" name="module" value="<?=esc($module)?>">

            <div style="background:#f8fafc;border:1px dashed #cbd5e1;padding:24px;border-radius:6px;text-align:center;">
                <div style="font-size:15px;font-weight:600;color:#1e293b;margin-bottom:8px;">
                    Select an Excel Spreadsheet (.xlsx or .xls) to Import
                </div>
                <p class="small" style="color:#64748b;max-width:550px;margin:0 auto 16px;">
                    Please make sure your spreadsheet follows the format and column headers defined in the downloadable template. 
                    All data is validated prior to insertion.
                </p>
                <input type="file" name="excel_file" accept=".xlsx,.xls" required style="margin-bottom:16px;">
                <div>
                    <button type="submit" class="btn success" style="padding:10px 24px;font-weight:600;">
                        🔍 Upload &amp; Validate Preview
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <!-- Step 2: Validation Preview -->
    <?php if ($previewData): ?>
        <div style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <h3 style="margin:0;color:#112a4a;font-size:16px;">
                    Import Validation Preview
                </h3>
                <div style="font-size:13px;">
                    Total Rows: <strong><?=$previewData['total']?></strong> &bull;
                    Valid: <strong style="color:#2e7d32;"><?=$previewData['valid_count']?></strong> &bull;
                    Errors: <strong style="color:#c62828;"><?=$previewData['error_count']?></strong> &bull;
                    Warnings: <strong style="color:#f57c00;"><?=$previewData['warning_count']?></strong>
                </div>
            </div>

            <?php if ($previewData['error_count'] > 0): ?>
                <div class="notice error" style="margin-bottom:16px;">
                    <strong>Validation Errors Detected:</strong> The following rows contain formatting or foreign key errors and cannot be imported safely.
                    <ul style="margin:8px 0 0;padding-left:20px;">
                        <?php foreach (array_slice($previewData['errors'], 0, 10) as $err): ?>
                            <li><strong>Row <?=$err['row']?>:</strong> <?=esc(implode('; ', $err['messages']))?></li>
                        <?php endforeach; ?>
                        <?php if (count($previewData['errors']) > 10): ?>
                            <li><em>...and <?=count($previewData['errors']) - 10?> more error rows.</em></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($previewData['warning_count'] > 0): ?>
                <div class="notice" style="background:#fff8e1;border-color:#ffb300;color:#e65100;margin-bottom:16px;">
                    <strong>Notice / Warnings:</strong>
                    <ul style="margin:6px 0 0;padding-left:20px;">
                        <?php foreach ($previewData['warnings'] as $w): ?>
                            <li><strong>Row <?=$w['row']?>:</strong> <?=esc(implode('; ', $w['messages']))?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Confirmation form -->
            <?php if ($previewData['valid_count'] > 0): ?>
                <form method="post" style="margin-top:16px;background:#f8fafc;padding:16px;border:1px solid #cbd5e1;border-radius:6px;">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="confirm_import">
                    <input type="hidden" name="module" value="<?=esc($module)?>">

                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                        <label style="font-weight:600;font-size:13px;color:#1e293b;">Import Mode:</label>
                        <label style="font-size:13px;cursor:pointer;">
                            <input type="radio" name="import_mode" value="add_new" checked> Add New Only (Skip Duplicates)
                        </label>
                        <label style="font-size:13px;cursor:pointer;">
                            <input type="radio" name="import_mode" value="update_existing"> Update Existing Records
                        </label>
                        <?php if ($module === 'timetable'): ?>
                            <label style="font-size:13px;cursor:pointer;color:#b91c1c;">
                                <input type="radio" name="import_mode" value="replace_session" onchange="if(this.checked && !confirm('WARNING: This will replace the timetable entries for this academic session. Continue?')) { document.querySelector('input[value=add_new]').checked = true; }">
                                Replace Current Timetable
                            </label>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn success" style="padding:10px 22px;font-weight:700;">
                            ✅ Confirm &amp; Import <?=$previewData['valid_count']?> Valid Rows
                        </button>
                        <a href="excel_io.php?module=<?=urlencode($module)?>" class="btn secondary">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <div style="margin-top:12px;">
                    <a href="excel_io.php?module=<?=urlencode($module)?>" class="btn secondary">Cancel &amp; Upload Another File</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
