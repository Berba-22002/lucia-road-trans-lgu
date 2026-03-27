<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

try {
    $project_id = $_GET['project_id'] ?? '';
    
    if (empty($project_id)) {
        echo json_encode(['success' => false, 'message' => 'Project ID is required']);
        exit();
    }
    
    // Query project_requests table for the status
    $stmt = $conn->prepare("
        SELECT 
            pr.id,
            pr.project_title,
            pr.status,
            pr.created_at,
            pr.updated_at,
            ba.id as bid_id,
            ba.bid_title,
            bapp.status as bid_status,
            u.fullname as contractor_name
        FROM project_requests pr
        LEFT JOIN bid_announcements ba ON pr.id = ba.project_id
        LEFT JOIN bid_applications bapp ON ba.id = bapp.bid_id
        LEFT JOIN users u ON bapp.contractor_id = u.id
        WHERE pr.id = ?
        ORDER BY pr.created_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if ($project) {
        echo json_encode([
            'success' => true,
            'data' => [
                'project_id' => $project['id'],
                'project_title' => $project['project_title'],
                'status' => $project['status'],
                'bid_status' => $project['bid_status'],
                'contractor_name' => $project['contractor_name'],
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
                'bid_status' => $project['bid_status'],
                'contractor_name' => $project['contractor_name'],
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?><?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

try {
    $project_id = $_GET['project_id'] ?? '';
    
    if (empty($project_id)) {
        echo json_encode(['success' => false, 'message' => 'Project ID is required']);
        exit();
    }
    
    // Query project_requests table for the status
    $stmt = $conn->prepare("
        SELECT 
            pr.id,
            pr.project_title,
            pr.status,
            pr.created_at,
            pr.updated_at,
            ba.id as bid_id,
            ba.bid_title,
            bapp.status as bid_status,
            u.fullname as contractor_name
        FROM project_requests pr
        LEFT JOIN bid_announcements ba ON pr.id = ba.project_id
        LEFT JOIN bid_applications bapp ON ba.id = bapp.bid_id
        LEFT JOIN users u ON bapp.contractor_id = u.id
        WHERE pr.id = ?
        ORDER BY pr.created_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if ($project) {
        echo json_encode([
            'success' => true,
            'data' => [
                'project_id' => $project['id'],
                'project_title' => $project['project_title'],
                'status' => $project['status'],
                'bid_status' => $project['bid_status'],
                'contractor_name' => $project['contractor_name'],
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>