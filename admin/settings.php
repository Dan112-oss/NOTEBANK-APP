<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';

$admin = require_admin(['super_admin', 'admin']);
$db = get_db();

$editableKeys = [
    'site_name' => 'Site name',
    'site_tagline' => 'Tagline',
    'support_email' => 'Support email',
    'default_currency' => 'Currency code (e.g. XAF)',
    'currency_symbol' => 'Currency symbol',
    'mtn_momo_name' => 'MTN MoMo account name',
    'mtn_momo_number' => 'MTN MoMo number',
    'orange_money_name' => 'Orange Money account name',
    'orange_money_number' => 'Orange Money number',
    'preview_max_pages' => 'Preview page limit',
    'download_limit_per_entitlement' => 'Download limit per document (0 = unlimited)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $formType = $_POST['form'] ?? '';

    if ($formType === 'settings') {
        foreach (array_keys($editableKeys) as $key) {
            if (isset($_POST[$key])) {
                set_setting($key, trim((string) $_POST[$key]));
            }
        }
        log_audit($admin['id'], 'update_settings', 'settings', null);
        flash_set('success', 'Settings saved.');
    } elseif ($formType === 'password') {
        $v = new Validator($_POST);
        $v->required('current_password', 'Current password')->required('new_password', 'New password');
        if ($v->passes() && !is_strong_password($_POST['new_password'])) {
            flash_set('error', 'New password must be at least 8 characters and include a letter and a number.');
        } elseif ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $stmt = $db->prepare('SELECT password_hash FROM admins WHERE id = ?');
            $stmt->execute([$admin['id']]);
            $hash = $stmt->fetchColumn();
            if (!password_verify($_POST['current_password'], $hash)) {
                flash_set('error', 'Current password is incorrect.');
            } else {
                $newHash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
                $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$newHash, $admin['id']]);
                log_audit($admin['id'], 'change_password', 'admin', $admin['id']);
                flash_set('success', 'Password updated.');
            }
        }
    }
    redirect('admin/settings.php');
}

$pageTitle = 'Settings';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:22px;">
    <h3>Platform Settings</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="settings">
      <?php foreach ($editableKeys as $key => $label): ?>
        <div class="nb-field">
          <label class="nb-label" for="<?= e($key) ?>"><?= e($label) ?></label>
          <input class="nb-input" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(setting($key, '')) ?>">
        </div>
      <?php endforeach; ?>
      <button type="submit" class="nb-btn nb-btn--primary">Save Settings</button>
    </form>
  </div>

  <div class="nb-card" style="padding:22px;">
    <h3>Change Password</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <div class="nb-field">
        <label class="nb-label" for="current_password">Current Password</label>
        <input class="nb-input" type="password" id="current_password" name="current_password" required>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="new_password">New Password</label>
        <input class="nb-input" type="password" id="new_password" name="new_password" required>
        <p class="nb-hint">At least 8 characters, with a letter and a number.</p>
      </div>
      <button type="submit" class="nb-btn nb-btn--navy">Update Password</button>
    </form>

    <?php if ($admin['role'] === 'super_admin'): ?>
    <div style="margin-top:26px; padding-top:20px; border-top:1px solid var(--nb-line);">
      <h3>Add Admin User</h3>
      <p class="nb-hint">Manage additional admin accounts directly in the database for now — a dedicated screen can be layered on once the core workflow is confirmed (see the guide's build order, section 3).</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
