<?php
require_once __DIR__ . '/../config/database.php';

$query = $_GET['q'] ?? '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $stmt = $pdo->prepare("
        SELECT id, fullname, contact_number as contact
        FROM users 
        WHERE role = 'resident' 
        AND (fullname LIKE ? OR id LIKE ?)
        LIMIT 10
    ");
    
    $searchTerm = '%' . $query . '%';
    $stmt->execute([$searchTerm, $searchTerm]);
    $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($residents);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
