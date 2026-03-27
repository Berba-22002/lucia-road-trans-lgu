<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    header('Content-Type: application/json');
    
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $pdo = $database->getConnection();
        
        $user_id = $_SESSION['user_id'];
        $hazard_type = $_POST['hazard_type'] ?? '';
        $description = $_POST['description'] ?? '';
        $address = $_POST['address'] ?? '';
        $contact_number = $_POST['contact_number'] ?? '';
        
        if (empty($hazard_type) || empty($description) || empty($address)) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing']);
            exit();
        }
        
        $stmt = $pdo->prepare("INSERT INTO reports (user_id, hazard_type, description, address, contact_number, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $result = $stmt->execute([$user_id, $hazard_type, $description, $address, $contact_number]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit report']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Report Hazard - Simple</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="p-8">
    <h1 class="text-2xl mb-4">Report Hazard - Simple Test</h1>
    <form id="testForm">
        <div class="mb-4">
            <label class="block mb-2">Hazard Type:</label>
            <input type="radio" name="hazard_type" value="road" required> Road
            <input type="radio" name="hazard_type" value="bridge" required> Bridge
            <input type="radio" name="hazard_type" value="traffic" required> Traffic
        </div>
        <div class="mb-4">
            <label class="block mb-2">Description:</label>
            <textarea name="description" required class="w-full border p-2"></textarea>
        </div>
        <div class="mb-4">
            <label class="block mb-2">Address:</label>
            <input type="text" name="address" required class="w-full border p-2">
        </div>
        <div class="mb-4">
            <label class="block mb-2">Contact Number:</label>
            <input type="text" name="contact_number" class="w-full border p-2">
        </div>
        <button type="submit" class="bg-blue-500 text-white px-4 py-2">Submit</button>
    </form>

    <script>
        document.getElementById('testForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const text = await response.text();
                console.log('Response:', text);
                
                const data = JSON.parse(text);
                alert(data.message);
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error: ' + error.message);
            }
        });
    </script>
</body>
</html>