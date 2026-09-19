<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';

$student = require_student();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $formType = $_POST['form'] ?? '';

    if ($formType === 'profile') {
        $v = new Validator($_POST);
        $v->required('full_name', 'Full name')->maxLength('full_name', 150, 'Full name');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $db->prepare('UPDATE students SET full_name = :name, student_number = :num, department_id = :dept, level_id = :lvl WHERE id = :id')
               ->execute([
                   'name' => trim($_POST['full_name']),
                   'num' => trim($_POST['student_number'] ?? '') ?: null,
                   'dept' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
                   'lvl' => !empty($_POST['level_id']) ? (int) $_POST['level_id'] : null,
                   'id' => $student['id'],
               ]);
            refresh_student_session($student['id']);
            flash_set('success', 'Profile updated.');
        }
    } elseif ($formType === 'password') {
        $v = new Validator($_POST);
        $v->required('current_password', 'Current password')->required('new_password', 'New password');
        if ($v->passes() && !is_strong_password($_POST['new_password'])) {
            flash_set('error', 'New password must be at least 8 characters and include a letter and a number.');
        } elseif ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $stmt = $db->prepare('SELECT password_hash FROM students WHERE id = ?');
            $stmt->execute([$student['id']]);
            $hash = $stmt->fetchColumn();
            if (!password_verify($_POST['current_password'], $hash)) {
                flash_set('error', 'Current password is incorrect.');
            } else {
                $newHash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
                $db->prepare('UPDATE students SET password_hash = ? WHERE id = ?')->execute([$newHash, $student['id']]);
                flash_set('success', 'Password updated.');
            }
        }
    }
    redirect('student/profile.php');
}

$stmt = $db->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$student['id']]);
$fullStudent = $stmt->fetch();

$departments = $db->prepare(
    "SELECT d.id, d.name FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE f.university_id = ? AND d.status='active' ORDER BY d.name"
);
$departments->execute([$student['university_id']]);
$departments = $departments->fetchAll();

$levels = [];
if ($fullStudent['department_id']) {
    $levelsStmt = $db->prepare("SELECT id, name FROM levels WHERE department_id = ? AND status='active' ORDER BY code + 0");
    $levelsStmt->execute([$fullStudent['department_id']]);
    $levels = $levelsStmt->fetchAll();
}

$pageTitle = 'Profile';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>Profile</h1>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:22px;">
    <h3>Account Details</h3>
    <p class="nb-hint">Email: <?= e($fullStudent['email']) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="profile">
      <div class="nb-field">
        <label class="nb-label" for="full_name">Full Name</label>
        <input class="nb-input" id="full_name" name="full_name" required value="<?= e($fullStudent['full_name']) ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="student_number">Student Number</label>
        <input class="nb-input" id="student_number" name="student_number" value="<?= e($fullStudent['student_number'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="department_id">Department</label>
        <select class="nb-select" id="department_id" name="department_id">
          <option value="">Select department</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $fullStudent['department_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="level_id">Level</label>
        <select class="nb-select" id="level_id" name="level_id">
          <option value="">Select level</option>
          <?php foreach ($levels as $lv): ?>
            <option value="<?= (int) $lv['id'] ?>" <?= (int) $fullStudent['level_id'] === (int) $lv['id'] ? 'selected' : '' ?>><?= e($lv['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary">Save Changes</button>
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
  </div>
</div>

<div class="nb-card" style="padding:22px; margin-top:20px;">
  <h3>Session</h3>
  <p class="nb-hint">Signed in as <?= e($fullStudent['email']) ?>.</p>
  <a href="logout.php" class="nb-btn nb-btn--ghost">Log out</a>
</div>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
