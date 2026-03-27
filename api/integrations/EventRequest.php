<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['user_id', 'system_name', 'event_type', 'start_date', 'end_date', 'location', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("$field is required");
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO event_requests 
            (user_id, system_name, event_type, start_date, end_date, location, landmark, description, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['system_name'],
            $data['event_type'],
            $data['start_date'],
            $data['end_date'],
            $data['location'],
            $data['landmark'] ?? null,
            $data['description']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Event request created successfully',
            'id' => $pdo->lastInsertId()
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = $_GET['id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $status = $_GET['status'] ?? null;
        
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM event_requests WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'data' => $result ?: null
            ]);
        } else {
            $query = "SELECT * FROM event_requests WHERE 1=1";
            $params = [];
            
            if ($user_id) {
                $query .= " AND user_id = ?";
                $params[] = $user_id;
            }
            
            if ($status) {
                $query .= " AND status = ?";
                $params[] = $status;
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $results
            ]);
        }
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
