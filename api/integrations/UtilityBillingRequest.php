<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/database.php';

if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            $user_id = $_GET['user_id'] ?? null;
            
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM utility_billing_requests WHERE id = ?");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($data ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => 'Not found']);
            } elseif ($user_id) {
                $stmt = $pdo->prepare("SELECT * FROM utility_billing_requests WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$user_id]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                $stmt = $pdo->query("SELECT * FROM utility_billing_requests ORDER BY created_at DESC");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $data]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) throw new Exception('Invalid JSON input');
            
            $required_fields = ['user_id', 'system_name', 'event_type', 'start_date', 'end_date', 'location', 'description'];
            foreach ($required_fields as $field) {
                if (empty($input[$field])) throw new Exception("Missing required field: $field");
            }
            
            $stmt = $pdo->prepare("INSERT INTO utility_billing_requests 
                (user_id, system_name, event_type, start_date, end_date, location, landmark, description, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            
            $stmt->execute([
                $input['user_id'], $input['system_name'], $input['event_type'],
                $input['start_date'], $input['end_date'], $input['location'],
                $input['landmark'] ?? null, $input['description']
            ]);
            
            echo json_encode(['success' => true, 'request_id' => $pdo->lastInsertId(), 'message' => 'Request created']);
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || empty($input['id'])) throw new Exception('Invalid input or missing ID');
            
            $fields = [];
            $values = [];
            $allowed = ['system_name', 'event_type', 'start_date', 'end_date', 'location', 'landmark', 'description', 'status', 'remarks'];
            
            foreach ($allowed as $field) {
                if (isset($input[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }
            
            if (empty($fields)) throw new Exception('No fields to update');
            
            $values[] = $input['id'];
            $stmt = $pdo->prepare("UPDATE utility_billing_requests SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            echo json_encode(['success' => true, 'message' => 'Request updated']);
            break;
            
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? $_GET['id'] ?? null;
            
            if (!$id) throw new Exception('Missing ID');
            
            $stmt = $pdo->prepare("DELETE FROM utility_billing_requests WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Request deleted']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
