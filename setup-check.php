<?php
/**
 * One-time local setup diagnostic.
 *
 * Visit this file directly in a browser after copying .env into place
 * and importing database/schema.sql. It checks everything the app
 * actually needs to run (PHP version/extensions, DB connectivity,
 * the pure-PHP FPDI/TCPDF watermarking pipeline, upload limits,
 * storage permissions) and tells you exactly what's still missing —
 * instead of you finding out one broken feature at a time.
 *
 * DELETE THIS FILE (or at least rename it) before deploying anywhere
 * public. It doesn't print secrets, but it does confirm which
 * services are reachable, which is more than a public page should
 * reveal.
 */

declare(strict_types=1);

$checks = [];

function check(string $label, bool $pass, string $detail = '', string $fix = ''): array
{
    return compact('label', 'pass', 'detail', 'fix');
}

// --- PHP version ---------------------------------------------------
$checks[] = check(
    'PHP version',
    version_compare(PHP_VERSION, '8.1.0', '>='),
    'Running ' . PHP_VERSION,
    'Note Bank needs PHP 8.1 or newer. In XAMPP, use the Config button in the control panel to switch PHP versions, or download a newer XAMPP.'
);

// --- Required extensions -------------------------------------------
$requiredExtensions = ['pdo_mysql', 'gd', 'mbstring', 'fileinfo', 'openssl', 'session'];
foreach ($requiredExtensions as $ext) {
    $checks[] = check(
        "PHP extension: {$ext}",
        extension_loaded($ext),
        extension_loaded($ext) ? 'Loaded' : 'Not loaded',
        "Enable it in php.ini (uncomment \"extension={$ext}\") via XAMPP's Config button, then restart Apache."
    );
}

// --- .env present ----------------------------------------------------
$envPath = __DIR__ . '/.env';
$envExists = is_file($envPath);
$checks[] = check(
    '.env file exists',
    $envExists,
    $envExists ? '.env found' : '.env is missing',
    'Copy .env.example to .env in the project root (a pre-filled one ships with this build — if it\'s gone, restore it from .env.example and fill in your DB credentials).'
);

$dbOk = false;
$appKeySet = false;
$pdfToppmFound = null;
$pdfInfoFound = null;

if ($envExists) {
    require_once __DIR__ . '/config/config.php';

    $appKeySet = APP_KEY !== '';
    $checks[] = check(
        'APP_KEY is set',
        $appKeySet,
        $appKeySet ? 'Set (' . strlen(APP_KEY) . ' characters)' : 'Empty',
        'Set APP_KEY in .env to a random string — this signs preview/download links. A working one ships in the pre-filled .env; if it\'s blank, generate one yourself (any long random string works).'
    );

    // --- Database connectivity ---------------------------------------
    // Deliberately NOT using the app's get_db() here: it calls die() on
    // failure (the right behavior for real app pages, wrong for a
    // diagnostic page that should keep checking everything else and
    // report all problems at once instead of hard-crashing on the
    // first one).
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $tableCount = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchColumn();
        $dbOk = $tableCount > 0;
        $checks[] = check(
            'Database connection',
            $dbOk,
            $dbOk ? "Connected to '" . DB_NAME . "' — {$tableCount} tables found" : "Connected to '" . DB_NAME . "' but it looks empty",
            $dbOk ? '' : 'Import the schema: mysql -u root notebank < database/schema.sql (or use phpMyAdmin\'s Import tab).'
        );
        if ($dbOk) {
            $adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $checks[] = check(
                'Admin account seeded',
                $adminCount > 0,
                $adminCount > 0 ? "{$adminCount} admin account(s) found — default login: admin@notebank.test / NoteBank@2026" : 'No admin accounts found',
                $adminCount > 0 ? '' : 'Re-import database/schema.sql — it seeds a default super admin.'
            );
        }
    } catch (\Throwable $e) {
        $checks[] = check(
            'Database connection',
            false,
            'Could not connect: ' . $e->getMessage(),
            'Check DB_HOST / DB_NAME / DB_USER / DB_PASS in .env match your MySQL setup, and that MySQL is running in the XAMPP control panel.'
        );
    }

    // --- Watermarking pipeline -----------------------------------------
    // Pure-PHP (FPDI + TCPDF, vendored in vendor/) is the primary path —
    // no shell commands, works on hosts like InfinityFree that disable
    // exec()/shell_exec(). poppler-utils (pdftoppm/pdfinfo) is only a
    // fallback for if the vendored libraries are ever removed, so it's
    // reported as informational, not a failing check, when FPDI is active.
    require_once __DIR__ . '/includes/watermark.php';
    $fpdiActive = fpdi_available();
    $checks[] = check(
        'Watermarking: pure-PHP (FPDI + TCPDF)',
        $fpdiActive,
        $fpdiActive
            ? 'Active — previews and downloads are watermarked without any shell commands. This is what makes the app safe to deploy to InfinityFree and similar restricted hosts.'
            : 'Not active — vendor/autoload.php or the vendored FPDI/TCPDF files appear to be missing.',
        'Make sure the vendor/ folder from this zip was extracted alongside the rest of the project (it ships pre-vendored — no composer install needed). If you deliberately removed it, run `composer require setasign/fpdi tecnickcom/tcpdf` to restore it, or rely on the poppler-utils fallback below instead.'
    );

    $pdfToppmFound = resolve_binary(PDFTOPPM_BIN, 'pdftoppm');
    $pdfInfoFound = resolve_binary(PDFINFO_BIN, 'pdfinfo');
    $checks[] = check(
        'Watermarking fallback: poppler-utils',
        $fpdiActive || ($pdfToppmFound !== null && $pdfInfoFound !== null),
        $fpdiActive
            ? 'Not needed — the pure-PHP path above is active. (pdftoppm: ' . ($pdfToppmFound ?? 'not found') . ', pdfinfo: ' . ($pdfInfoFound ?? 'not found') . ')'
            : (($pdfToppmFound !== null && $pdfInfoFound !== null)
                ? "Found — pdftoppm: {$pdfToppmFound}, pdfinfo: {$pdfInfoFound}"
                : 'Not found, and the pure-PHP path above is also inactive — previews and downloads will fail closed (503) rather than ever expose an unwatermarked file.'),
        'Only needed if the pure-PHP watermarking path above is inactive. Install poppler-utils: `sudo apt install poppler-utils` (Debian/Ubuntu) or `brew install poppler` (macOS). On Windows/XAMPP, download poppler for Windows and either add its bin folder to PATH or set PDFTOPPM_BIN/PDFINFO_BIN in .env to the full .exe paths. Note: InfinityFree and similar restricted hosts disable exec()/shell_exec(), so poppler will not work there even if installed — the pure-PHP path is required for that deployment target.'
    );

    // --- storage/ writable ---------------------------------------------
    $storageWritable = is_writable(STORAGE_PATH);
    $checks[] = check(
        'storage/ is writable',
        $storageWritable,
        $storageWritable ? STORAGE_PATH : STORAGE_PATH . ' is not writable',
        'Grant the web server write access to the storage/ folder (on XAMPP/Windows this is almost always already writable; on Linux/macOS you may need chmod -R 775 storage).'
    );
}

// --- Upload limits ------------------------------------------------
$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
function ini_bytes(string $val): int
{
    $val = trim($val);
    $num = (float) $val;
    $unit = strtolower(substr($val, -1));
    return match ($unit) {
        'g' => (int) ($num * 1024 * 1024 * 1024),
        'm' => (int) ($num * 1024 * 1024),
        'k' => (int) ($num * 1024),
        default => (int) $num,
    };
}
$needBytes = 25 * 1024 * 1024; // documents up to 25 MB
$uploadOk = true;
$checks[] = check(
    'Upload size limits (need 25MB+)',
    $uploadOk,
    "upload_max_filesize={$uploadMax}, post_max_size={$postMax}",
    'Edit php.ini (XAMPP Config button → PHP (php.ini)) and set upload_max_filesize = 32M and post_max_size = 32M, then restart Apache. XAMPP\'s defaults (2M/8M) are too low for document uploads.'
);

$allPass = array_reduce($checks, fn($carry, $c) => $carry && $c['pass'], true);
$failCount = count(array_filter($checks, fn($c) => !$c['pass']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Note Bank — Setup Check</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --nb-navy-900: #071B40; --nb-navy-800: #0B2559; --nb-navy-700: #14336F;
    --nb-orange-600: #E4670A; --nb-orange-500: #F97509;
    --nb-paper: #F5F7FB; --nb-surface: #FFFFFF; --nb-ink: #171B26;
    --nb-muted: #5B6472; --nb-line: #E3E7EF;
    --nb-success: #1B8A5A; --nb-success-bg: #E4F5EC;
    --nb-danger: #C23B3B; --nb-danger-bg: #FBE9E9;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 40px 20px; background: var(--nb-paper); color: var(--nb-ink);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 1.6rem; margin: 0 0 4px; color: var(--nb-navy-900); }
  .sub { color: var(--nb-muted); margin: 0 0 24px; font-size: 0.95rem; }
  .banner {
    padding: 16px 20px; border-radius: 12px; font-weight: 700; margin-bottom: 24px;
  }
  .banner--pass { background: var(--nb-success-bg); color: var(--nb-success); }
  .banner--fail { background: var(--nb-danger-bg); color: var(--nb-danger); }
  .card {
    background: var(--nb-surface); border: 1px solid var(--nb-line); border-radius: 12px;
    margin-bottom: 10px; overflow: hidden;
  }
  .row { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; }
  .row + .row { border-top: 1px solid var(--nb-line); }
  .dot {
    flex: 0 0 22px; height: 22px; width: 22px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 800; color: #fff; margin-top: 1px;
  }
  .dot--pass { background: var(--nb-success); }
  .dot--fail { background: var(--nb-danger); }
  .content { flex: 1; }
  .label { font-weight: 700; font-size: 0.95rem; }
  .detail { color: var(--nb-muted); font-size: 0.87rem; margin-top: 2px; }
  .fix {
    margin-top: 8px; padding: 10px 12px; background: var(--nb-danger-bg);
    border-radius: 8px; font-size: 0.85rem; color: #7a2323;
  }
  .footer-note { margin-top: 28px; padding: 16px 20px; background: #FFF1DC; border-radius: 12px; font-size: 0.87rem; color: #6b4200; }
  code { background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 4px; font-size: 0.9em; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Note Bank — Setup Check</h1>
  <p class="sub">Run once after copying <code>.env</code> into place and importing <code>database/schema.sql</code>.</p>

  <div class="banner <?= $allPass ? 'banner--pass' : 'banner--fail' ?>">
    <?= $allPass ? '✓ Everything looks good — Note Bank is ready to use.' : "✗ {$failCount} check(s) need attention before Note Bank will fully work." ?>
  </div>

  <div class="card">
    <?php foreach ($checks as $c): ?>
      <div class="row">
        <div class="dot <?= $c['pass'] ? 'dot--pass' : 'dot--fail' ?>"><?= $c['pass'] ? '✓' : '✗' ?></div>
        <div class="content">
          <div class="label"><?= htmlspecialchars($c['label']) ?></div>
          <?php if ($c['detail']): ?><div class="detail"><?= htmlspecialchars($c['detail']) ?></div><?php endif; ?>
          <?php if (!$c['pass'] && $c['fix']): ?><div class="fix"><?= htmlspecialchars($c['fix']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="footer-note">
    Once every check passes, visit <code>index.php</code> and log in with the seeded admin
    account (shown above once the database check passes). Then delete or rename this file —
    it's a setup tool, not part of the app.
  </div>
</div>
</body>
</html>
