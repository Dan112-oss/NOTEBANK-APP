<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../config/mail.php';

if (current_student()) {
    redirect('student/dashboard.php');
}

$db = get_db();
$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $v = new Validator($_POST);
    $v->required('full_name', 'Full name')->maxLength('full_name', 150, 'Full name')
      ->required('email', 'Email')->email('email')
      ->required('university_id', 'University')
      ->required('password', 'Password')
      ->matches('password_confirm', 'password', 'Password confirmation');

    if ($v->passes() && !is_strong_password($_POST['password'])) {
        $errors[] = 'Password must be at least 8 characters and include a letter and a number.';
    }

    if ($v->fails()) {
        $errors[] = $v->firstError();
    }

    if (!$errors) {
        $dupe = $db->prepare('SELECT id FROM students WHERE email = ?');
        $dupe->execute([trim($_POST['email'])]);
        if ($dupe->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $stmt = $db->prepare(
            'INSERT INTO students (university_id, full_name, email, password_hash, student_number, department_id, level_id, email_verified, status)
             VALUES (:university_id, :full_name, :email, :password_hash, :student_number, :department_id, :level_id, 0, "active")'
        );
        $stmt->execute([
            'university_id' => (int) $_POST['university_id'],
            'full_name' => trim($_POST['full_name']),
            'email' => trim($_POST['email']),
            'password_hash' => password_hash($_POST['password'], PASSWORD_BCRYPT),
            'student_number' => trim($_POST['student_number'] ?? '') ?: null,
            'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
            'level_id' => !empty($_POST['level_id']) ? (int) $_POST['level_id'] : null,
        ]);
        $studentId = (int) $db->lastInsertId();

        // Email verification token — student can browse immediately,
        // but checkout requires a verified email (guide, section 8).
        $rawToken = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO email_verifications (student_id, token_hash, expires_at) VALUES (:sid, :hash, DATE_ADD(NOW(), INTERVAL 48 HOUR))')
           ->execute(['sid' => $studentId, 'hash' => hash('sha256', $rawToken)]);

        $verifyUrl = base_url('student/verify-email.php?token=' . $rawToken . '&id=' . $studentId);
        $html = '<p>Welcome to Note Bank!</p><p>Please verify your email to unlock purchasing:</p>'
            . '<p><a href="' . $verifyUrl . '">Verify my email</a></p>'
            . '<p>This link expires in 48 hours.</p>';
        send_mail(trim($_POST['email']), 'Verify your Note Bank email', $html, 'verify_email', $studentId);

        [$ok] = student_attempt_login(trim($_POST['email']), $_POST['password']);
        flash_set('success', "Welcome to Note Bank, {$_POST['full_name']}! You're all set — browse courses and start purchasing right away.");
        redirect('student/dashboard.php');
    }
}

$universities = $db->query("SELECT id, name FROM universities WHERE status = 'active' ORDER BY name")->fetchAll();
$departments = $db->query(
    "SELECT d.id, d.name, d.faculty_id, f.university_id FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE d.status='active' ORDER BY d.name"
)->fetchAll();
$levels = $db->query("SELECT id, name, department_id FROM levels WHERE status='active' ORDER BY code + 0")->fetchAll();
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create your account — Note Bank</title>
<link rel="icon" href="<?= asset_url('images/favicon-32.png') ?>" type="image/png">
<link rel="stylesheet" href="<?= asset_url('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/site.css') ?>">
</head>
<body>
<div class="nb-auth">
  <div class="nb-auth__card" style="max-width:460px;">
    <div class="nb-auth__brand">
      <img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="">
      <span>Note Bank</span>
    </div>
    <h1>Create your account</h1>
    <p class="nb-auth__sub">Verified notes and past questions, unlocked the moment your payment is confirmed.</p>

    <?php foreach ($errors as $err): ?>
      <div class="nb-alert nb-alert--error" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="nb-field">
        <label class="nb-label" for="full_name">Full name</label>
        <input class="nb-input" id="full_name" name="full_name" required value="<?= e($old['full_name'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="email">Email</label>
        <input class="nb-input" type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="university_id">University</label>
        <select class="nb-select" id="university_id" name="university_id" required>
          <option value="">Select university</option>
          <?php foreach ($universities as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (string) ($old['university_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="department_id">Department (optional)</label>
        <select class="nb-select" id="department_id" name="department_id">
          <option value="">Select department</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['id'] ?>" data-university="<?= (int) $d['university_id'] ?>" <?= (string) ($old['department_id'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="level_id">Level (optional)</label>
        <select class="nb-select" id="level_id" name="level_id">
          <option value="">Select level</option>
          <?php foreach ($levels as $lv): ?>
            <option value="<?= (int) $lv['id'] ?>" data-department="<?= (int) $lv['department_id'] ?>" <?= (string) ($old['level_id'] ?? '') === (string) $lv['id'] ? 'selected' : '' ?>><?= e($lv['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="student_number">Student number (optional)</label>
        <input class="nb-input" id="student_number" name="student_number" value="<?= e($old['student_number'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="password">Password</label>
        <input class="nb-input" type="password" id="password" name="password" required>
        <p class="nb-hint">At least 8 characters, with a letter and a number.</p>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="password_confirm">Confirm password</label>
        <input class="nb-input" type="password" id="password_confirm" name="password_confirm" required>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block" data-loading-text="Creating account…">Create account</button>
    </form>
    <p class="nb-auth__foot">Already have an account? <a href="login.php">Sign in</a></p>
    <p class="nb-auth__foot"><a href="<?= base_url('index.php') ?>">← Back to Note Bank</a></p>
  </div>
</div>
<script src="<?= asset_url('js/main.js') ?>"></script>
</body>
</html>
