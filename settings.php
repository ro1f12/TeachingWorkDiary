<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/vendor/autoload.php';
require_admin();
check_csrf();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = db();
$msg = '';
$err = '';

// Load current settings
$settings = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $x) {
    $settings[$x['setting_key']] = $x['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    
    if ($action === 'test_smtp') {
        $host = trim($_POST['smtp_host'] ?? ($settings['smtp_host'] ?? ''));
        $port = (int)($_POST['smtp_port'] ?? ($settings['smtp_port'] ?? 587));
        $sec = trim($_POST['smtp_security'] ?? ($settings['smtp_security'] ?? 'tls'));
        $user = trim($_POST['smtp_username'] ?? ($settings['smtp_username'] ?? ''));
        $pass = trim($_POST['smtp_password'] ?? '');
        if ($pass === '') {
            $pass = $settings['smtp_password'] ?? '';
        }
        $fromEmail = trim($_POST['from_email'] ?? ($settings['from_email'] ?? $user));
        $fromName = trim($_POST['from_name'] ?? ($settings['from_name'] ?? 'Teaching & Work Diary'));
        $testRecipient = trim($_POST['test_recipient'] ?? $fromEmail);

        if (!$host || !$user) {
            $err = 'Please configure SMTP Host and Username before testing.';
        } elseif (!$testRecipient) {
            $err = 'Please provide a test recipient email address.';
        } else {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
                $mail->SMTPSecure = ($sec === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $port;
                $mail->Timeout = 10;
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($testRecipient);
                $mail->Subject = 'SMTP Test — Teaching & Work Diary';
                $mail->Body = "Congratulations!\n\nYour SMTP configuration is working properly for Teaching & Work Diary (LCB College, Dept of IT).\n\nTimestamp: " . date('Y-m-d H:i:s');
                $mail->send();
                $msg = "SMTP test email sent successfully to $testRecipient!";
            } catch (Throwable $e) {
                $err = "SMTP Test Failed: " . $e->getMessage();
            }
        }
    } else { // Save settings
        $keys = [
            'institution_name', 'institution_address', 'department', 'report_title',
            'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_password',
            'from_name', 'from_email', 'reply_to'
        ];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            // Security: if smtp_password submitted empty, retain existing saved password
            if ($k === 'smtp_password' && $v === '') {
                continue;
            }
            $pdo->prepare('INSERT INTO settings(setting_key, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
            $settings[$k] = $v;
        }
        $msg = 'Settings saved successfully.';
    }
}

page_header('System &amp; SMTP Settings');
?>
<?php if ($msg): ?><div class="notice"><?=esc($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="notice error"><?=esc($err)?></div><?php endif; ?>

<div class="card">
    <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="save">
        
        <h2>Institution &amp; Report Header</h2>
        <p class="small">These values appear on all official PDF printouts, generated reports, and institutional email notifications.</p>
        <div class="grid">
            <div class="field" style="grid-column: span 2;">
                <label>Institution Name</label>
                <input name="institution_name" value="<?=esc($settings['institution_name']??'LALIT CHANDRA BHARALI COLLEGE, MALIGAON, GUWAHATI – 781011')?>" required>
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Institution Address</label>
                <input name="institution_address" value="<?=esc($settings['institution_address']??'Maligaon, Guwahati – 781011, Assam')?>">
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Department</label>
                <input name="department" value="<?=esc($settings['department']??'Department of Information Technology')?>" required>
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Report Title</label>
                <input name="report_title" value="<?=esc($settings['report_title']??'TEACHING & WORK DIARY')?>" required>
            </div>
        </div>

        <br><hr style="border:0;border-top:1px solid #e0e0e0;"><br>

        <h2>Email &amp; SMTP Configuration</h2>
        <p class="small">For Gmail, use a <strong>Google App Password</strong> (16 letters generated from Google Account Security), not your regular Gmail account password.</p>
        
        <div class="grid">
            <div class="field">
                <label>SMTP Host</label>
                <input name="smtp_host" id="smtp_host" value="<?=esc($settings['smtp_host']??'smtp.gmail.com')?>" required>
            </div>
            <div class="field">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" id="smtp_port" value="<?=esc($settings['smtp_port']??'587')?>" required>
            </div>
            <div class="field">
                <label>Encryption / Security</label>
                <select name="smtp_security" id="smtp_security">
                    <option value="tls" <?=(($settings['smtp_security']??'tls')==='tls')?'selected':''?>>STARTTLS (Port 587)</option>
                    <option value="ssl" <?=(($settings['smtp_security']??'')==='ssl')?'selected':''?>>SSL/TLS (Port 465)</option>
                </select>
            </div>
            <div class="field">
                <label>SMTP Username / Email</label>
                <input name="smtp_username" id="smtp_username" value="<?=esc($settings['smtp_username']??'')?>" placeholder="e.g. department@lcbc.ac.in">
            </div>
            <div class="field">
                <label>App Password</label>
                <input type="password" name="smtp_password" id="smtp_password" placeholder="<?=!empty($settings['smtp_password']) ? '•••••••••••••••• (Leave blank to keep current password)' : '16-character App Password'?>">
                <?php if (!empty($settings['smtp_password'])): ?>
                    <div class="small" style="color:#2e7d32;margin-top:3px;">✓ SMTP Password configured &amp; hidden for security</div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>From Name</label>
                <input name="from_name" id="from_name" value="<?=esc($settings['from_name']??'Teaching & Work Diary')?>">
            </div>
            <div class="field">
                <label>From Email</label>
                <input type="email" name="from_email" id="from_email" value="<?=esc($settings['from_email']??'')?>" placeholder="department@lcbc.ac.in">
            </div>
            <div class="field">
                <label>Reply-To Email</label>
                <input type="email" name="reply_to" value="<?=esc($settings['reply_to']??'')?>">
            </div>
        </div>

        <br>
        <button class="btn success" style="padding:10px 20px;font-weight:bold;">Save Settings</button>
    </form>
</div>

<div class="card">
    <h2>Test SMTP Connection</h2>
    <p class="small">Send a test email using the configured SMTP credentials to verify server connectivity.</p>
    <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="test_smtp">
        
        <div class="field" style="min-width:300px;">
            <label>Send Test Email To</label>
            <input type="email" name="test_recipient" value="<?=esc($settings['from_email'] ?: ($settings['smtp_username'] ?: ''))?>" required placeholder="test-recipient@example.com">
        </div>
        <button class="btn" style="background:#0277bd;color:#fff;padding:9px 18px;font-weight:bold;">
            Send Test Email
        </button>
    </form>
</div>

<?php page_footer(); ?>
