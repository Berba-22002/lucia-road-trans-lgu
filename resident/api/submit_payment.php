<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$ticket_id = intval($_POST['ticket_id'] ?? 0);
$payment_method = $_POST['payment_method'] ?? '';
$reference_number = $_POST['reference_number'] ?? '';
$resident_id = $_SESSION['user_id'];

if (!$ticket_id || !$payment_method || !$reference_number) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

try {
    $pdo = (new Database())->getConnection();
    
    $stmt = $pdo->prepare("SELECT * FROM ovr_tickets WHERE id = ? AND resident_id = ?");
    $stmt->execute([$ticket_id, $resident_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO manual_payments (ticket_id, resident_id, payment_method, reference_number, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$ticket_id, $resident_id, $payment_method, $reference_number]);

    echo json_encode(['success' => true, 'message' => 'Payment submitted. Admin will verify within 24 hours.']);

} catch (Exception $e) {
    error_log("Payment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
