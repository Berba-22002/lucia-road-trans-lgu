<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'resident') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$resident_id = $_SESSION['user_id'];
$ticket_id = intval($_POST['ticket_id'] ?? 0);
$payment_method = $_POST['payment_method'] ?? '';
$reference_number = $_POST['reference_number'] ?? '';

if (!$ticket_id || !$payment_method) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

try {
    // Verify ticket belongs to resident
    $stmt = $pdo->prepare("SELECT * FROM violation_tickets WHERE id = ? AND resident_id = ?");
    $stmt->execute([$ticket_id, $resident_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit();
    }

    if ($ticket['payment_status'] === 'paid') {
        echo json_encode(['success' => false, 'message' => 'Ticket already paid']);
        exit();
    }

    // Create payment record
    $stmt = $pdo->prepare("
        INSERT INTO payments (ticket_id, resident_id, amount, payment_method, reference_number, payment_date, status)
        VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
    ");
    $stmt->execute([$ticket_id, $resident_id, $ticket['fine_amount'], $payment_method, $reference_number]);

    // Update ticket status
    $stmt = $pdo->prepare("
        UPDATE violation_tickets 
        SET payment_status = 'paid', payment_date = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$ticket_id]);

    echo json_encode(['success' => true, 'message' => 'Payment submitted successfully']);
} catch (PDOException $e) {
    error_log("Payment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
