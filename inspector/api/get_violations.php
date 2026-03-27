<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

try {
    $pdo = (new Database())->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

$action = $_GET['action'] ?? '';

if ($action === 'get_violations_by_category') {
    $category = $_GET['category'] ?? '';
    
    if (!$category) {
        http_response_code(400);
        exit(json_encode(['error' => 'Category required']));
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, violation_name, ordinance_code, first_offense, second_offense, third_offense FROM violations WHERE category = ? ORDER BY violation_name");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . implode(' ', $pdo->errorInfo()));
        }
        $stmt->execute([$category]);
        $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($violations);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} elseif ($action === 'get_fine') {
    $violation_id = $_GET['violation_id'] ?? '';
    $offense_count = $_GET['offense_count'] ?? 1;
    
    if (!$violation_id) {
        http_response_code(400);
        exit(json_encode(['error' => 'Violation ID required']));
    }
    
    try {
        $stmt = $pdo->prepare("SELECT first_offense, second_offense, third_offense, ordinance_code FROM violations WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . implode(' ', $pdo->errorInfo()));
        }
        $stmt->execute([$violation_id]);
        $violation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$violation) {
            http_response_code(404);
            exit(json_encode(['error' => 'Violation not found']));
        }
        
        $fineMap = [
            1 => $violation['first_offense'],
            2 => $violation['second_offense'],
            3 => $violation['third_offense'],
            4 => $violation['third_offense']
        ];
        
        $fine = $fineMap[$offense_count] ?? $violation['first_offense'];
        
        echo json_encode([
            'fine' => $fine,
            'ordinance_code' => $violation['ordinance_code']
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
?>
