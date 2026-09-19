<?php
/**
 * POST /api/orders.php — create a pending order from the caller's cart.
 * Same logic as student/checkout.php (kept in sync deliberately): prices
 * are re-read from documents, never trusted from the request.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$student = api_require_student();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}
api_csrf_require();

$stmt = $db->prepare(
    "SELECT ci.*, d.price AS current_price, d.status AS document_status
     FROM cart_items ci JOIN documents d ON d.id = ci.document_id WHERE ci.student_id = :id"
);
$stmt->execute(['id' => $student['id']]);
$cartItems = array_values(array_filter($stmt->fetchAll(), fn($i) => $i['document_status'] === 'ACTIVE'));

if (!$cartItems) {
    json_error('Your cart is empty.', 422);
}

$db->beginTransaction();
try {
    $subtotal = 0.0;
    foreach ($cartItems as $item) {
        $subtotal += (float) $item['current_price'] * (int) $item['quantity'];
    }

    $orderNumber = generate_order_number();
    $db->prepare(
        'INSERT INTO orders (student_id, order_number, subtotal, total, currency, status, payment_status, created_at)
         VALUES (:sid, :num, :sub, :total, :cur, "PENDING_PAYMENT", "NOT_SUBMITTED", NOW())'
    )->execute([
        'sid' => $student['id'], 'num' => $orderNumber, 'sub' => $subtotal, 'total' => $subtotal,
        'cur' => setting('default_currency', 'XAF'),
    ]);
    $orderId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare(
        'INSERT INTO order_items (order_id, document_id, quantity, unit_price, total) VALUES (:oid, :did, :qty, :price, :total)'
    );
    foreach ($cartItems as $item) {
        $lineTotal = (float) $item['current_price'] * (int) $item['quantity'];
        $itemStmt->execute([
            'oid' => $orderId, 'did' => $item['document_id'], 'qty' => $item['quantity'],
            'price' => $item['current_price'], 'total' => $lineTotal,
        ]);
    }

    $db->prepare('DELETE FROM cart_items WHERE student_id = :id')->execute(['id' => $student['id']]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    error_log('NOTE BANK api order creation failed: ' . $e->getMessage());
    json_error('Could not create your order. Please try again.', 500);
}

json_ok(['order_id' => $orderId, 'order_number' => $orderNumber, 'total' => $subtotal], 201);
