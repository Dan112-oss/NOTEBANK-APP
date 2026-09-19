<?php
/**
 * POST /api/payments.php — submit payment proof for one of the
 * caller's own orders. multipart/form-data (a file upload), not JSON,
 * mirroring student/payment.php's form handler.
 *
 * Fields: order_id, method (MTN_MOMO|ORANGE_MONEY), reference?, proof (file)
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$student = api_require_student();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}
csrf_require(); // multipart bodies keep csrf_token in $_POST, not JSON

$orderId = (int) ($_POST['order_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND student_id = :sid');
$stmt->execute(['id' => $orderId, 'sid' => $student['id']]);
$order = $stmt->fetch();
if (!$order) {
    json_error('Order not found.', 404);
}
if ($order['status'] === 'APPROVED') {
    json_error('This order is already approved.', 409);
}

$v = new Validator($_POST);
$v->required('method', 'Payment method')->in('method', ['MTN_MOMO', 'ORANGE_MONEY'], 'Payment method');

$uploadError = null;
if (empty($_FILES['proof']) || ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $uploadError = 'Please attach your payment evidence.';
} else {
    $uploadError = validate_upload($_FILES['proof'], ALLOWED_PROOF_MIME, MAX_PROOF_UPLOAD_BYTES);
}

if ($v->fails() || $uploadError) {
    json_error($v->firstError() ?? $uploadError, 422);
}

$ext = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION) ?: 'dat';
$destName = random_filename($ext);
$destPath = STORAGE_RECEIPTS . '/' . $destName;

if (!move_uploaded_file($_FILES['proof']['tmp_name'], $destPath)) {
    json_error('Could not save your upload. Please try again.', 500);
}
@chmod($destPath, 0640);

$db->prepare(
    'INSERT INTO payment_proofs (order_id, method, reference, amount, proof_path, status, created_at)
     VALUES (:order_id, :method, :reference, :amount, :proof_path, "PENDING", NOW())'
)->execute([
    'order_id' => $order['id'],
    'method' => $_POST['method'],
    'reference' => trim($_POST['reference'] ?? '') ?: null,
    'amount' => $order['total'],
    'proof_path' => $destPath,
]);

$db->prepare('UPDATE orders SET status = "PAYMENT_SUBMITTED", payment_status = "SUBMITTED" WHERE id = :id')
   ->execute(['id' => $order['id']]);

json_ok(['order_id' => $order['id'], 'status' => 'PAYMENT_SUBMITTED'], 201);
