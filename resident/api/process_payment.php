<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $method = $_POST['payment_method'] ?? 'Manual';
    $ref_no = $_POST['reference_number'] ?? '';
    $resident_id = $_SESSION['user_id'];

    // Handle File Upload
    $proof_path = null;
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === 0) {
        $upload_dir = '../uploads/proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file_ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
        $file_name = "proof_" . $ticket_id . "_" . time() . "." . $file_ext;
        $proof_path = 'uploads/proofs/' . $file_name;
        
        move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $file_name);
    }

    $database = new Database();
    $pdo = $database->getConnection();

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare("
            UPDATE ovr_tickets 
            SET payment_status = 'paid', 
                paid_at = NOW(),
                payment_method = ?,
                reference_number = ?,
                payment_proof = ?
            WHERE id = ? AND resident_id = ?
        ");
        
        if ($update->execute([$method, $ref_no, $proof_path, $ticket_id, $resident_id])) {
            $pdo->commit();
            echo json_encode(['success' => true]);
        } else {
            throw new Exception("Database update failed.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}