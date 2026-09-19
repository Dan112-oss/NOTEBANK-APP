<?php
/**
 * POST /api/cart.php  { action: "add"|"remove"|"update", document_id?, cart_item_id?, quantity? }
 * Prices are always read fresh from documents — never trusted from the
 * request — and duplicate document rows are prevented at the DB level
 * (cart_items has a unique (student_id, document_id) key).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$student = api_require_student();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

api_csrf_require();
$input = json_input() ?: $_POST;
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':
        $documentId = (int) ($input['document_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, price, status FROM documents WHERE id = :id AND status = 'ACTIVE'");
        $stmt->execute(['id' => $documentId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            json_error('This document is not available.', 404);
        }

        $owned = student_owns_entitlement($student['id'], $documentId);
        if ($owned) {
            json_error('You already own this document.', 409);
        }

        $db->prepare(
            'INSERT INTO cart_items (student_id, document_id, quantity, unit_price, created_at)
             VALUES (:sid, :did, 1, :price, NOW())
             ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price)'
        )->execute(['sid' => $student['id'], 'did' => $documentId, 'price' => $doc['price']]);
        break;

    case 'remove':
        $cartItemId = (int) ($input['cart_item_id'] ?? 0);
        $db->prepare('DELETE FROM cart_items WHERE id = :id AND student_id = :sid')
           ->execute(['id' => $cartItemId, 'sid' => $student['id']]);
        break;

    case 'update':
        $cartItemId = (int) ($input['cart_item_id'] ?? 0);
        $quantity = max(1, min(5, (int) ($input['quantity'] ?? 1)));
        $db->prepare('UPDATE cart_items SET quantity = :q WHERE id = :id AND student_id = :sid')
           ->execute(['q' => $quantity, 'id' => $cartItemId, 'sid' => $student['id']]);
        break;

    default:
        json_error('Unknown cart action.', 422);
}

$totalStmt = $db->prepare(
    'SELECT COALESCE(SUM(ci.quantity * d.price), 0) AS total, COALESCE(SUM(ci.quantity), 0) AS count
     FROM cart_items ci JOIN documents d ON d.id = ci.document_id WHERE ci.student_id = :sid'
);
$totalStmt->execute(['sid' => $student['id']]);
$totals = $totalStmt->fetch();

json_ok([
    'cart_count' => (int) $totals['count'],
    'total' => (float) $totals['total'],
    'total_formatted' => format_money($totals['total']),
]);
