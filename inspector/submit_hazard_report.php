<?php
// Disable error display in production
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is a resident
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'inspector') {
    header("Location: ../../login.php");
    exit();
}

// Initialize database connection
try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }
}

// Fetch user data for auto-filling form fields
$user_data = null;
try {
    $user_stmt = $pdo->prepare("SELECT address, contact_number FROM users WHERE id = :user_id");
    $user_stmt->execute([':user_id' => $_SESSION['user_id']]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['user_id'];
    
    // Verify user exists in database
    try {
        $user_check = $pdo->prepare("SELECT id, fullname, role FROM users WHERE id = :user_id");
        $user_check->execute([':user_id' => $user_id]);
        $user = $user_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'User not found. Please log in again.',
                'redirect' => '../../login.php'
            ]);
            exit();
        }
        
        // Log for debugging
        error_log("User verified: ID=" . $user['id'] . ", Role=" . $user['role']);
        error_log("Media upload - File: " . ($_FILES['hazard_image']['name'] ?? 'none') . ", Size: " . ($_FILES['hazard_image']['size'] ?? 0));
        
    } catch (PDOException $e) {
        error_log("User verification error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to verify user. Please try again.'
        ]);
        exit();
    }
    
    $hazard_type = isset($_POST['hazard_type']) ? trim($_POST['hazard_type']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $landmark = isset($_POST['landmark']) ? trim($_POST['landmark']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $ai_analysis = isset($_POST['ai_analysis']) ? trim($_POST['ai_analysis']) : null;
    $manual_hazard_level = isset($_POST['manual_hazard_level']) ? trim($_POST['manual_hazard_level']) : null;
    $media_name = null;
    $media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : null;

    // Validate required fields
    if (empty($hazard_type) || empty($description) || empty($address)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'All required fields must be filled!'
        ]);
        exit();
    }

    // Validate hazard type
    $valid_types = ['road', 'bridge', 'traffic'];
    if (!in_array($hazard_type, $valid_types)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid hazard type!'
        ]);
        exit();
    }

    // Log file upload attempt
    error_log("File upload attempt - FILES array: " . json_encode($_FILES));
    
    // Handle file upload (images and videos)
    if (isset($_FILES['hazard_image']) && $_FILES['hazard_image']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['hazard_image'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'jfif', 'webm', 'mp4', 'mov', 'avi', 'gif', 'bmp', 'webp', 'm4v', 'mkv', 'flv', 'wmv', 'ogv'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Different size limits for images and videos
        $is_video = in_array($file_ext, ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv']);
        $max_file_size = $is_video ? 100 * 1024 * 1024 : 10 * 1024 * 1024; // 100MB for videos, 10MB for images
        $media_type = $is_video ? 'video' : 'image';
        
        error_log("File upload detected - Name: {$file['name']}, Size: {$file['size']}, Type: {$media_type}");

        // Validate file extension
        if (!in_array($file_ext, $allowed_extensions)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid file type. Supported formats: JPEG, PNG, WebM, MP4, MOV, AVI, and other common video formats.'
            ]);
            exit();
        }

        // Validate file size
        if ($file['size'] > $max_file_size) {
            $size_limit = $is_video ? '100MB' : '10MB';
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => "File size exceeds {$size_limit} limit. Your file is " . round($file['size'] / (1024 * 1024), 2) . "MB."
            ]);
            exit();
        }

        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . '/../uploads/hazard_reports/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create upload directory.'
                ]);
                exit();
            }
        }

        // Generate unique filename with microseconds for better uniqueness
        $media_name = 'hazard_' . $user_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $media_name;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            error_log("Failed to move uploaded file from {$file['tmp_name']} to {$upload_path}");
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Failed to upload media file. Please try again.'
            ]);
            exit();
        }
        
        // Verify file was actually uploaded
        if (!file_exists($upload_path)) {
            error_log("Uploaded file does not exist at {$upload_path}");
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'File upload verification failed. Please try again.'
            ]);
            exit();
        }
        
        error_log("File successfully uploaded to {$upload_path}, size: " . filesize($upload_path));
    } else {
        if (isset($_FILES['hazard_image'])) {
            error_log("File upload error code: " . $_FILES['hazard_image']['error']);
        } else {
            error_log("No file uploaded in hazard_image field");
        }
    }

    try {
        // Insert hazard report into database using PDO
        $sql = "INSERT INTO reports (user_id, hazard_type, description, address, contact_number, image_path, ai_analysis_result, status, validation_status, created_at) 
                VALUES (:user_id, :hazard_type, :description, :address, :contact_number, :image_path, :ai_analysis_result, :status, :validation_status, NOW())";
        
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':hazard_type', $hazard_type, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':address', $address, PDO::PARAM_STR);
        $stmt->bindValue(':contact_number', !empty($contact_number) ? $contact_number : null, PDO::PARAM_STR);
        $stmt->bindValue(':image_path', $media_name, PDO::PARAM_STR);
        $stmt->bindValue(':ai_analysis_result', $ai_analysis, PDO::PARAM_STR);
        $stmt->bindValue(':status', 'pending', PDO::PARAM_STR);
        $stmt->bindValue(':validation_status', 'pending', PDO::PARAM_STR);
        
        error_log("Inserting report - User: {$user_id}, Media: {$media_name}, Type: {$media_type}, Hazard: {$hazard_type}");
        
        // Execute the statement
        if ($stmt->execute()) {
            $report_id = $pdo->lastInsertId();
            error_log("Report successfully inserted with ID: {$report_id}");
            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Hazard report submitted successfully!',
                'report_id' => $report_id
            ]);
        } else {
            throw new Exception('Failed to execute insert statement');
        }
        exit();

    } catch (PDOException $e) {
        // Log detailed error information
        error_log("PDO Error in report_hazard.php: " . $e->getMessage());
        error_log("Error Code: " . $e->getCode());
        error_log("SQL State: " . (isset($e->errorInfo[0]) ? $e->errorInfo[0] : 'N/A'));
        error_log("Media name being inserted: " . ($media_name ?? 'NULL'));
        
        // Delete uploaded media if database insert fails
        if (isset($upload_dir) && isset($media_name) && $media_name && file_exists($upload_dir . $media_name)) {
            error_log("Deleting uploaded file due to DB error: " . $upload_dir . $media_name);
            unlink($upload_dir . $media_name);
        }
        
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while submitting the report. Please try again.'
        ]);
        exit();
    } catch (Exception $e) {
        error_log("General error in report_hazard.php: " . $e->getMessage());
        error_log("Media name at error: " . ($media_name ?? 'NULL'));
        
        // Delete uploaded media if there's an error
        if (isset($upload_dir) && $media_name && file_exists($upload_dir . $media_name)) {
            error_log("Deleting uploaded file due to general error: " . $upload_dir . $media_name);
            unlink($upload_dir . $media_name);
        }
        
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while submitting the report. Please try again.'
        ]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Hazard - LGU Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest/dist/tf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@teachablemachine/image@latest/dist/teachablemachine-image.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'lgu-bg': '#f2f7f5',
                        'lgu-headline': '#00473e',
                        'lgu-paragraph': '#475d5b',
                        'lgu-button': '#faae2b',
                        'lgu-button-text': '#00473e',
                        'lgu-stroke': '#00332c',
                        'lgu-main': '#f2f7f5',
                        'lgu-highlight': '#faae2b',
                        'lgu-secondary': '#ffa8ba',
                        'lgu-tertiary': '#fa5246'
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
        }
        
        .camera-preview {
            width: 100%;
            max-width: 400px;
            border-radius: 12px;
            border: 3px solid #faae2b;
            box-shadow: 0 8px 25px rgba(250, 174, 43, 0.2);
            transition: all 0.3s ease;
        }
        
        .camera-preview:hover {
            transform: scale(1.02);
            box-shadow: 0 12px 35px rgba(250, 174, 43, 0.3);
        }

        .image-preview {
            max-width: 300px;
            max-height: 300px;
            border-radius: 12px;
            margin-top: 15px;
            border: 2px solid #e5e7eb;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .image-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .file-input-wrapper input[type="file"] {
            display: none;
        }

        .drag-drop-area {
            border: 3px dashed #faae2b;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            background: linear-gradient(135deg, rgba(250, 174, 43, 0.05), rgba(250, 174, 43, 0.1));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .drag-drop-area::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(250, 174, 43, 0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
            opacity: 0;
        }

        .drag-drop-area:hover::before,
        .drag-drop-area.drag-over::before {
            opacity: 1;
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .drag-drop-area:hover,
        .drag-drop-area.drag-over {
            background: linear-gradient(135deg, rgba(250, 174, 43, 0.1), rgba(250, 174, 43, 0.2));
            border-color: #f59e0b;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(250, 174, 43, 0.2);
        }

        .form-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(250, 174, 43, 0.3);
            border-color: #faae2b;
            transform: translateY(-1px);
        }
        
        .form-input {
            transition: all 0.3s ease;
        }
        
        .hazard-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .hazard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(250, 174, 43, 0.1), transparent);
            transition: left 0.5s ease;
        }
        
        .hazard-card:hover::before {
            left: 100%;
        }
        
        .hazard-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 71, 62, 0.15);
            border-color: #faae2b;
        }
        
        .hazard-card input[type="radio"]:checked + div {
            color: #00473e;
        }
        
        .hazard-card input[type="radio"]:checked {
            accent-color: #faae2b;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #faae2b, #f59e0b);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(250, 174, 43, 0.4);
        }
        
        .btn-secondary {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .upload-tab-btn {
            transition: all 0.3s ease;
            position: relative;
        }
        
        .upload-tab-btn.active {
            background: linear-gradient(135deg, rgba(250, 174, 43, 0.1), rgba(250, 174, 43, 0.05));
            border-radius: 8px 8px 0 0;
        }
        
        .form-section {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .main-card {
            background: linear-gradient(135deg, #ffffff, #f9fafb);
            border: 1px solid rgba(250, 174, 43, 0.1);
            box-shadow: 0 10px 40px rgba(0, 71, 62, 0.08);
        }
        
        .icon-bounce {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
     <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar - Now included from external file -->
    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center px-4 py-3 lg:px-6">
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="lg:hidden mr-4 p-2 text-lgu-headline hover:bg-gray-100 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-lgu-headline">Report a Hazard</h1>
                    <p class="text-sm text-lgu-paragraph">Submit infrastructure hazard reports</p>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <div class="max-w-3xl mx-auto">
                <!-- Progress Indicator -->
                <div class="mb-8">
                    <div class="flex items-center justify-center space-x-4 mb-4">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-sm">
                                1
                            </div>
                            <span class="ml-2 text-sm font-medium text-lgu-headline">Report Details</span>
                        </div>
                        <div class="w-16 h-1 bg-gray-300 rounded"></div>
                        <div class="flex items-center">
                            <div id="step2-indicator" class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center text-gray-600 font-bold text-sm">
                                2
                            </div>
                            <span id="step2-text" class="ml-2 text-sm font-medium text-gray-500">Submit</span>
                        </div>
                    </div>
                </div>
                
                <!-- Important Reminders -->
                <div class="mb-8 bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500 rounded-r-xl p-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-blue-800 mb-3">Important Reminders</h3>
                            <ul class="space-y-2 text-sm text-blue-700">
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-blue-500 mt-0.5 mr-2 flex-shrink-0"></i>
                                    <span>Provide accurate and truthful information about the hazard</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-blue-500 mt-0.5 mr-2 flex-shrink-0"></i>
                                    <span>Include specific location details to help our response team</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-blue-500 mt-0.5 mr-2 flex-shrink-0"></i>
                                    <span>Report only genuine infrastructure hazards that pose public safety risks</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-blue-500 mt-0.5 mr-2 flex-shrink-0"></i>
                                    <span>Avoid duplicate reports - check if the hazard has already been reported</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="main-card rounded-2xl shadow-xl p-8">
                    <form id="hazardForm" enctype="multipart/form-data">
                        <!-- Hazard Type -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-exclamation-triangle text-lgu-button-text text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Hazard Type <span class="text-lgu-tertiary">*</span>
                                </label>
                            </div>
                            <p class="text-lgu-paragraph text-sm mb-6 ml-11">Select the type of infrastructure hazard you want to report</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 ml-11">
                                <label class="hazard-card flex flex-col items-center p-6 border-2 border-gray-300 rounded-xl cursor-pointer group">
                                    <input type="radio" name="hazard_type" value="road" class="mb-3" required>
                                    <div class="text-center">
                                        <i class="fas fa-road text-3xl text-lgu-headline mb-3 group-hover:text-lgu-button transition-colors"></i>
                                        <span class="font-semibold text-lgu-headline block">Road Hazard</span>
                                        <span class="text-xs text-lgu-paragraph mt-1 block">Potholes, cracks, debris</span>
                                    </div>
                                </label>

                                <label class="hazard-card flex flex-col items-center p-6 border-2 border-gray-300 rounded-xl cursor-pointer group">
                                    <input type="radio" name="hazard_type" value="bridge" class="mb-3" required>
                                    <div class="text-center">
                                        <i class="fas fa-water text-3xl text-lgu-headline mb-3 group-hover:text-lgu-button transition-colors"></i>
                                        <span class="font-semibold text-lgu-headline block">Bridge Issue</span>
                                        <span class="text-xs text-lgu-paragraph mt-1 block">Structural damage, barriers</span>
                                    </div>
                                </label>

                                <label class="hazard-card flex flex-col items-center p-6 border-2 border-gray-300 rounded-xl cursor-pointer group">
                                    <input type="radio" name="hazard_type" value="traffic" class="mb-3" required>
                                    <div class="text-center">
                                        <i class="fas fa-traffic-light text-3xl text-lgu-headline mb-3 group-hover:text-lgu-button transition-colors"></i>
                                        <span class="font-semibold text-lgu-headline block">Traffic Issue</span>
                                        <span class="text-xs text-lgu-paragraph mt-1 block">Signals, signs, markings</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-edit text-lgu-button-text text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Description <span class="text-lgu-tertiary">*</span>
                                </label>
                            </div>
                            <div class="ml-11">
                                <textarea
                                    name="description"
                                    id="description"
                                    rows="6"
                                    placeholder="Describe the hazard in detail... What did you observe? When did you notice it? How severe is the issue?"
                                    class="form-input w-full border-2 border-gray-300 rounded-xl p-4 text-lgu-paragraph resize-none"
                                    required></textarea>
                                <div class="flex items-center justify-between mt-2">
                                    <p class="text-xs text-lgu-paragraph">Provide detailed information about what you observed</p>
                                    <span class="text-xs text-gray-400" id="charCount">0 characters</span>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-map-marker-alt text-lgu-button-text text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Location/Address <span class="text-lgu-tertiary">*</span>
                                </label>
                            </div>
                            <div class="ml-11">
                                <input
                                    type="text"
                                    name="address"
                                    id="address"
                                    placeholder="Type the exact address (e.g., 123 Main St, Barangay ABC, City)..."
                                    value="<?php echo htmlspecialchars($user_data['address'] ?? ''); ?>"
                                    class="form-input w-full border-2 border-gray-300 rounded-xl p-4 text-lgu-paragraph"
                                    required>
                                <p class="text-xs text-lgu-paragraph mt-2">Type the exact address - it will be pinned on the map below</p>
                                <button type="button" id="getCurrentLocationBtn" class="mt-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition flex items-center">
                                    <i class="fas fa-location-arrow mr-2"></i>
                                    Use My Current Location
                                </button>
                            </div>
                        </div>

                        <!-- Landmark -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-gray-400 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-landmark text-white text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Landmark <span class="text-gray-400 text-sm font-normal">(Optional)</span>
                                </label>
                            </div>
                            <div class="ml-11">
                                <input
                                    type="text"
                                    name="landmark"
                                    id="landmark"
                                    placeholder="Nearby landmark (e.g., near SM Mall, beside church, in front of school)..."
                                    class="form-input w-full border-2 border-gray-300 rounded-xl p-4 text-lgu-paragraph">
                                <p class="text-xs text-lgu-paragraph mt-2">Provide a nearby landmark to help locate the hazard more easily</p>
                            </div>
                        </div>
                        
                        <!-- Map -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-map text-lgu-button-text text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Location Map
                                </label>
                            </div>
                            <div class="ml-11">
                                <div id="hazardMap" class="w-full h-80 rounded-xl border-2 border-gray-300"></div>
                                <p class="text-xs text-lgu-paragraph mt-2">The red marker shows the location based on your address</p>
                            </div>
                        </div>

                        <!-- Contact Number -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-gray-400 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-phone text-white text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Contact Number <span class="text-gray-400 text-sm font-normal"></span>
                                </label>
                            </div>
                            <div class="ml-11">
                                <input
                                    type="tel"
                                    name="contact_number"
                                    id="contact_number"
                                    placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars($user_data['contact_number'] ?? ''); ?>"
                                    pattern="[0-9]{11}"
                                    class="form-input w-full border-2 border-gray-300 rounded-xl p-4 text-lgu-paragraph bg-gray-100"
                                    readonly>
                                <p class="text-xs text-lgu-paragraph mt-2">Provide your contact number for follow-up (11 digits)</p>
                            </div>
                        </div>

                        <!-- Image Upload -->
                        <div class="form-section mb-8">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-camera text-lgu-button-text text-sm"></i>
                                </div>
                                <label class="text-lgu-headline text-lg font-bold">
                                    Media Evidence <span class="text-gray-400 text-sm font-normal"></span>
                                </label>
                            </div>
                            <p class="text-lgu-paragraph text-sm mb-6 ml-11">Add a photo or video to help us better understand the hazard</p>

                            <!-- Tabs for upload methods -->
                            <div class="flex gap-1 mb-6 ml-11 bg-gray-100 p-1 rounded-xl w-fit">
                                <button type="button" class="upload-tab-btn active px-6 py-3 font-medium text-lgu-headline rounded-lg" data-tab="video">
                                    <i class="fas fa-video mr-2"></i>Capture Video
                                </button>
                                <button type="button" class="upload-tab-btn px-6 py-3 font-medium text-lgu-paragraph rounded-lg hover:text-lgu-headline" data-tab="camera">
                                    <i class="fas fa-camera mr-2"></i>Capture Photo
                                </button>
                            </div>

                            <!-- Capture Video Tab -->
                            <div id="video-tab" class="upload-tab-content ml-11">
                                <div class="text-center bg-gray-50 rounded-2xl p-6">
                                    <video id="videoPreview" class="camera-preview mx-auto mb-6 bg-black" playsinline autoplay muted></video>
                                    <div class="flex gap-3 justify-center mb-6">
                                        <button type="button" id="startVideoBtn" class="btn-primary text-lgu-button-text font-bold py-3 px-6 rounded-xl">
                                            <i class="fas fa-play mr-2"></i>Start Camera
                                        </button>
                                        <button type="button" id="recordVideoBtn" class="bg-red-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-red-700 transition hidden">
                                            <i class="fas fa-record-vinyl mr-2"></i>Record Video
                                        </button>
                                        <button type="button" id="stopVideoBtn" class="bg-gray-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-gray-700 transition hidden">
                                            <i class="fas fa-stop mr-2"></i>Stop Recording
                                        </button>
                                    </div>
                                    
                                    <!-- Recording Timer -->
                                    <div id="recordingTimer" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg hidden">
                                        <div class="flex items-center justify-center text-red-800">
                                            <i class="fas fa-circle text-red-600 mr-2 animate-pulse"></i>
                                            <span class="font-bold text-lg">Recording: <span id="timerDisplay">00:30</span></span>
                                        </div>
                                    </div>
                                    <div style="position: relative; display: inline-block; width: 100%;">
                                        <video id="recordedVideo" class="image-preview mx-auto hidden" controls playsinline webkit-playsinline preload="auto" style="display:none; width: 100%; height: auto;"></video>
                                        <div id="videoTimestampOverlay" style="position: absolute; top: 10px; left: 10px; background: rgba(0, 0, 0, 0.6); color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; display: none;"></div>
                                    </div>
                                    
                                    <!-- Video Timestamp -->
                                    <div id="videoTimestamp" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg hidden">
                                        <div class="flex items-center justify-center text-green-800">
                                            <i class="fas fa-clock mr-2"></i>
                                            <span class="font-medium">Video recorded on: <span id="videoTimestampText"></span></span>
                                        </div>
                                    </div>
                                    
                                    <!-- Manual Hazard Level for Video -->
                                    <div id="manualHazardLevel" class="mt-6 p-4 bg-purple-50 rounded-xl hidden">
                                        <div class="flex items-center justify-center mb-3">
                                            <i class="fas fa-robot text-purple-600 text-xl mr-2"></i>
                                            <h4 class="text-lg font-semibold text-purple-800">AI Analysis - Select Hazard Level</h4>
                                        </div>
                                        <div class="flex gap-4 justify-center flex-wrap">
                                            <label class="flex items-center cursor-pointer">
                                                <input type="radio" name="manual_hazard_level" value="high" class="mr-2">
                                                <span class="px-4 py-2 bg-red-100 text-red-700 rounded-lg font-medium">High</span>
                                            </label>
                                            <label class="flex items-center cursor-pointer">
                                                <input type="radio" name="manual_hazard_level" value="medium" class="mr-2">
                                                <span class="px-4 py-2 bg-yellow-100 text-yellow-700 rounded-lg font-medium">Medium</span>
                                            </label>
                                            <label class="flex items-center cursor-pointer">
                                                <input type="radio" name="manual_hazard_level" value="low" class="mr-2">
                                                <span class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg font-medium">Low</span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <p class="text-sm text-lgu-paragraph mt-4">Record a short video showing the hazard (max 30 seconds). You can play the video to review it before submitting.</p>
                                </div>
                            </div>

                            <!-- Camera Tab -->
                            <div id="camera-tab" class="upload-tab-content hidden ml-11">
                                <div class="text-center bg-gray-50 rounded-2xl p-6">
                                    <video id="cameraVideo" class="camera-preview mx-auto mb-6 bg-black" playsinline autoplay></video>
                                    <div class="flex gap-3 justify-center mb-6">
                                        <button type="button" id="startCameraBtn" class="btn-primary text-lgu-button-text font-bold py-3 px-6 rounded-xl">
                                            <i class="fas fa-play mr-2"></i>Start Camera
                                        </button>
                                        <button type="button" id="stopCameraBtn" class="bg-lgu-tertiary text-white font-bold py-3 px-6 rounded-xl hover:bg-red-600 transition hidden">
                                            <i class="fas fa-stop mr-2"></i>Stop Camera
                                        </button>
                                        <button type="button" id="captureBtn" class="bg-green-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-green-700 transition hidden">
                                            <i class="fas fa-camera mr-2"></i>Capture Photo
                                        </button>
                                    </div>
                                    <canvas id="captureCanvas" style="display: none;"></canvas>
                                    <img id="capturedImage" class="image-preview mx-auto hidden" alt="Captured image">
                                    
                                    <!-- Capture Timestamp -->
                                    <div id="captureTimestamp" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg hidden">
                                        <div class="flex items-center justify-center text-green-800">
                                            <i class="fas fa-clock mr-2"></i>
                                            <span class="font-medium">Photo captured on: <span id="timestampText"></span></span>
                                        </div>
                                    </div>
                                    
                                    <!-- AI Analysis Results -->
                                    <div id="aiAnalysis" class="mt-6 p-4 bg-blue-50 rounded-xl hidden">
                                        <div class="flex items-center justify-center mb-3">
                                            <i class="fas fa-robot text-blue-600 text-xl mr-2"></i>
                                            <h4 class="text-lg font-semibold text-blue-800">Beripikado AI Analysis</h4>
                                        </div>
                                        <div id="aiResults" class="text-sm text-blue-700"></div>
                                    </div>
                                    
                                    <p class="text-sm text-lgu-paragraph mt-4">Make sure the hazard is clearly visible in the photo</p>
                                </div>
                            </div>

                            <!-- Image Preview -->
                            <div id="imagePreview" class="mt-4"></div>
                            
                            <!-- Photo Actions -->
                            <div id="photoActions" class="mt-4 hidden ml-11">
                                <div class="flex gap-3">
                                    <button type="button" id="changePhotoBtn" class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg text-sm hover:bg-yellow-500 transition flex items-center">
                                        <i class="fas fa-sync-alt mr-2"></i>Change Media
                                    </button>
                                    <button type="button" id="removePhotoBtn" class="bg-red-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-600 transition flex items-center">
                                        <i class="fas fa-trash mr-2"></i>Remove Media
                                    </button>
                                </div>
                            </div>
                            
                            <!-- AI Analysis for uploaded images -->
                            <div id="uploadAiAnalysis" class="mt-6 p-4 bg-blue-50 rounded-xl hidden ml-11">
                                <div class="flex items-center justify-center mb-3">
                                    <i class="fas fa-robot text-blue-600 text-xl mr-2"></i>
                                    <h4 class="text-lg font-semibold text-blue-800">Beripikado AI Analysis</h4>
                                </div>
                                <div id="uploadAiResults" class="text-sm text-blue-700"></div>
                            </div>
                        </div>

                        <!-- Legal Notice -->
                        <div class="form-section mb-8">
                            <div class="bg-gradient-to-r from-red-50 to-orange-50 border-l-4 border-red-500 rounded-r-xl p-6">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="text-lg font-semibold text-red-800 mb-3">Legal Notice - False Information</h3>
                                        <div class="text-sm text-red-700 space-y-2">
                                            <p class="font-medium">Under Republic Act No. 10175 (Cybercrime Prevention Act of 2012) and other applicable laws:</p>
                                            <ul class="list-disc list-inside space-y-1 ml-4">
                                                <li>Providing false or misleading information in official reports is punishable by law</li>
                                                <li>Filing fraudulent reports may result in criminal charges and penalties</li>
                                                <li>All submitted reports are subject to verification and investigation</li>
                                                <li>Your identity and contact information may be used for follow-up and verification</li>
                                            </ul>
                                            <p class="font-medium mt-3">By submitting this report, you acknowledge that the information provided is true and accurate to the best of your knowledge.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Acknowledgment Checkbox -->
                        <div class="form-section mb-8">
                            <label class="flex items-start p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition">
                                <input type="checkbox" id="acknowledgment" class="mt-1 mr-3 w-5 h-5 text-lgu-button focus:ring-lgu-button border-gray-300 rounded" required>
                                <span class="text-sm text-gray-700">
                                    <strong>I acknowledge and agree</strong> that I have read and understood the reminders and legal notice above. I confirm that the information I am providing in this report is true, accurate, and complete to the best of my knowledge. I understand that providing false information may result in legal consequences.
                                </span>
                            </label>
                        </div>

                        <!-- Submit Button -->
                        <div class="form-section border-t border-gray-200 pt-8">
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                <button
                                    type="submit"
                                    id="submitBtn"
                                    class="btn-primary text-lgu-button-text font-bold py-4 px-8 rounded-xl flex items-center justify-center gap-3 text-lg min-w-[200px] disabled:opacity-50 disabled:cursor-not-allowed"
                                    disabled>
                                    <i class="fas fa-paper-plane"></i>
                                    Submit Report
                                </button>
                                <a href="inspector-dashboard.php" class="btn-secondary bg-gray-300 text-gray-700 font-bold py-4 px-8 rounded-xl hover:bg-gray-400 transition flex items-center justify-center gap-3 text-lg min-w-[200px]">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Dashboard
                                </a>
                            </div>
                            <p class="text-center text-sm text-lgu-paragraph mt-4">Your report will be reviewed by our team within 24-48 hours</p>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8">
            <div class="container mx-auto px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> Road and Traffic Infrastructure Management System</p>
            </div>
        </footer>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    overlay.classList.toggle('hidden');
                });
            }
        });
        
        let cameraStream = null;
        let capturedImageData = null;
        let model = null;
        let maxPredictions = 0;
        let map = null;
        let marker = null;
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        // Beripikado AI - Teachable Machine Model
        const MODEL_URL = "https://teachablemachine.withgoogle.com/models/aU7iSGCIA/";
        
        // Hazard type keywords for classification
        const HAZARD_KEYWORDS = {
            road: ['pothole', 'crack', 'debris', 'pavement', 'asphalt', 'surface', 'damage', 'broken', 'hole', 'road'],
            bridge: ['bridge', 'structural', 'barrier', 'railing', 'support', 'beam', 'span', 'crossing', 'overpass'],
            traffic: ['signal', 'light', 'sign', 'marking', 'line', 'traffic', 'intersection', 'crosswalk', 'pole']
        };
        
        // Initialize Beripikado AI
        async function initBeripikadoAI() {
            try {
                const modelURL = MODEL_URL + "model.json";
                const metadataURL = MODEL_URL + "metadata.json";
                
                model = await tmImage.load(modelURL, metadataURL);
                maxPredictions = model.getTotalClasses();
                
                console.log('Beripikado AI initialized successfully');
            } catch (error) {
                console.error('Failed to initialize Beripikado AI:', error);
            }
        }
        
        // Classify hazard by type based on AI predictions
        function classifyHazardType(predictions) {
            const selectedType = document.querySelector('input[name="hazard_type"]:checked')?.value;
            if (!selectedType) return null;
            
            const topPrediction = predictions[0]?.className.toLowerCase() || '';
            const keywords = HAZARD_KEYWORDS[selectedType] || [];
            const matchCount = keywords.filter(k => topPrediction.includes(k)).length;
            
            return matchCount > 0 ? selectedType : null;
        }
        
        // Analyze image with Beripikado AI
        async function analyzeImageWithAI(imageElement, resultsContainerId) {
            if (!model) {
                console.log('Beripikado AI not initialized');
                return;
            }
            
            try {
                const resultsContainer = document.getElementById(resultsContainerId);
                const parentContainer = resultsContainer.parentElement;
                
                // Show loading state
                resultsContainer.innerHTML = `
                    <div class="flex items-center justify-center py-4">
                        <i class="fas fa-spinner fa-spin text-blue-600 mr-2"></i>
                        <span>Beripikado AI is analyzing the image...</span>
                    </div>
                `;
                parentContainer.classList.remove('hidden');
                
                // Get predictions
                const predictions = await model.predict(imageElement);
                predictions.sort((a, b) => b.probability - a.probability);
                
                const topPrediction = predictions[0];
                const percentage = (topPrediction.probability * 100).toFixed(1);
                const isInvalidImage = topPrediction.className.toLowerCase().includes('invalid');
                const isValidImage = !isInvalidImage;
                
                // Determine hazard level
                let hazardLevel = 'low';
                if (topPrediction.probability > 0.8) hazardLevel = 'high';
                else if (topPrediction.probability > 0.6) hazardLevel = 'medium';
                
                // Classify by hazard type
                const classifiedType = classifyHazardType(predictions);
                
                // Store AI analysis
                const aiAnalysisData = {
                    predictions: predictions.map(p => ({ className: p.className, probability: p.probability })),
                    topPrediction: predictions[0],
                    hazardLevel: hazardLevel,
                    classifiedType: classifiedType,
                    isValid: isValidImage
                };
                
                let aiInput = document.getElementById('ai_analysis_input');
                if (!aiInput) {
                    aiInput = document.createElement('input');
                    aiInput.type = 'hidden';
                    aiInput.name = 'ai_analysis';
                    aiInput.id = 'ai_analysis_input';
                    document.getElementById('hazardForm').appendChild(aiInput);
                }
                aiInput.value = JSON.stringify(aiAnalysisData);
                
                const levelColors = {
                    high: { bg: 'bg-red-100', text: 'text-red-700', icon: 'fa-exclamation-circle', border: 'border-red-300', label: 'HIGH' },
                    medium: { bg: 'bg-yellow-100', text: 'text-yellow-700', icon: 'fa-exclamation-triangle', border: 'border-yellow-300', label: 'MEDIUM' },
                    low: { bg: 'bg-blue-100', text: 'text-blue-700', icon: 'fa-info-circle', border: 'border-blue-300', label: 'LOW' }
                };
                
                let resultsHTML = '';
                
                if (!isValidImage) {
                    resultsHTML = `
                        <div class="p-4 bg-red-50 border-2 border-red-300 rounded-lg">
                            <div class="flex items-center mb-3">
                                <i class="fas fa-times-circle text-red-600 text-2xl mr-3"></i>
                                <h4 class="text-lg font-bold text-red-700">Retake Photo</h4>
                            </div>
                            <p class="text-red-600 mb-3">The image quality is too low or doesn't clearly show a hazard. Please retake the photo.</p>
                        </div>
                    `;
                } else {
                    const colors = levelColors[hazardLevel];
                    const typeLabel = classifiedType ? classifiedType.toUpperCase() : 'HAZARD';
                    
                    resultsHTML = `
                        <div class="space-y-3">
                            <div class="${colors.bg} p-4 rounded-lg border-l-4 border-blue-500">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="font-semibold ${colors.text}">${topPrediction.className}</span>
                                    <span class="text-sm ${colors.text} font-bold">${percentage}%</span>
                                </div>
                                <div class="text-xs text-blue-600">Primary Detection</div>
                            </div>
                            
                            <div class="${colors.bg} p-4 rounded-lg border-2 ${colors.border}">
                                <div class="flex items-center">
                                    <i class="fas ${colors.icon} ${colors.text} text-2xl mr-3"></i>
                                    <div>
                                        <div class="text-xs ${colors.text} font-semibold">HAZARD LEVEL</div>
                                        <div class="text-2xl font-bold ${colors.text}">${colors.label}</div>
                                    </div>
                                </div>
                            </div>
                            
                            ${classifiedType ? `<div class="bg-purple-50 p-3 border border-purple-200 rounded-lg"><div class="flex items-center text-purple-800"><i class="fas fa-tag mr-2"></i><span class="font-medium">Type: <strong>${typeLabel}</strong></span></div></div>` : ''}
                            
                            <div class="bg-green-50 p-3 border border-green-200 rounded-lg">
                                <div class="flex items-center text-green-800">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <span class="font-medium">Image is valid and ready for submission</span>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                resultsContainer.innerHTML = resultsHTML;
                
            } catch (error) {
                console.error('Error analyzing image:', error);
                const resultsContainer = document.getElementById(resultsContainerId);
                resultsContainer.innerHTML = `
                    <div class="p-4 bg-red-50 border-2 border-red-300 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-600 text-2xl mr-3"></i>
                            <div>
                                <h4 class="font-bold text-red-700">Analysis Failed</h4>
                                <p class="text-sm text-red-600">Please retake the photo and try again.</p>
                            </div>
                        </div>
                    </div>
                `;
            }
        }
        
        // Initialize map
        function initMap() {
            map = L.map('hazardMap').setView([14.6760, 120.9626], 11);
            
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            // Add area markers
            const areas = [
                { lat: 14.6507, lng: 120.9676, name: 'Caloocan City' },
                { lat: 14.7000, lng: 120.9830, name: 'Valenzuela City' },
                { lat: 14.7281, lng: 121.0342, name: 'Novaliches' }
            ];
            
            areas.forEach(area => {
                L.circleMarker([area.lat, area.lng], {
                    radius: 6,
                    fillColor: '#3b82f6',
                    color: 'white',
                    weight: 2,
                    fillOpacity: 0.7
                }).addTo(map).bindPopup(`<b>${area.name}</b>`);
            });
        }
        
        // Geocode address and add marker
        async function geocodeAddress(address) {
            if (!address.trim()) {
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
                return;
            }
            
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                const data = await response.json();
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    // Remove existing marker
                    if (marker) {
                        map.removeLayer(marker);
                    }
                    
                    // Add new marker
                    marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-marker',
                            html: '<div style="background: #ef4444; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
                            iconSize: [25, 25],
                            iconAnchor: [12, 12]
                        })
                    }).addTo(map);
                    
                    marker.bindPopup(`
                        <div class="p-2">
                            <h4 class="font-semibold text-red-600">Hazard Location</h4>
                            <p class="text-sm">${result.address.freeformAddress}</p>
                        </div>
                    `);
                    
                    // Center map on location
                    map.setView([lat, lng], 16);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
        
        // Get current location
        function getCurrentLocation() {
            const btn = document.getElementById('getCurrentLocationBtn');
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Getting Location...';
            btn.disabled = true;
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    async function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        try {
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data && data.address) {
                                        const addr = data.address;
                                        const parts = [];
                                        if (addr.house_number) parts.push(addr.house_number);
                                        if (addr.road) parts.push(addr.road);
                                        if (addr.suburb) parts.push(addr.suburb);
                                        if (addr.city) parts.push(addr.city);
                                        if (addr.province) parts.push(addr.province);
                                        if (addr.postcode) parts.push(addr.postcode);
                                        if (addr.country) parts.push(addr.country);
                                        
                                        if (parts.length > 0) {
                                            document.getElementById('address').value = parts.join(', ');
                                            geocodeAddress(document.getElementById('address').value);
                                        } else if (data.display_name) {
                                            document.getElementById('address').value = data.display_name;
                                            geocodeAddress(data.display_name);
                                        } else {
                                            throw new Error('No address found');
                                        }
                                    } else {
                                        throw new Error('No address found');
                                    }
                                })
                                .catch(() => {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'Address Not Found',
                                        text: 'Could not determine address from your location. Please enter manually.',
                                        confirmButtonColor: '#faae2b'
                                    });
                                })
                                .finally(() => {
                                    btn.innerHTML = originalText;
                                    btn.disabled = false;
                                });
                        } catch (error) {
                            console.error('Error:', error);
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }
                    },
                    function(error) {
                        let errorMsg = 'Unable to get location';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMsg = 'Location access denied. Please enable location permissions in your browser settings.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMsg = 'Location information unavailable. Please try again or enter manually.';
                                break;
                            case error.TIMEOUT:
                                errorMsg = 'Location request timed out. Please try again.';
                                break;
                        }
                        
                        Swal.fire({
                            icon: 'error',
                            title: 'Location Error',
                            text: errorMsg,
                            confirmButtonColor: '#fa5246'
                        });
                        
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 15000,
                        maximumAge: 0
                    }
                );
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Supported',
                    text: 'Geolocation is not supported by this browser',
                    confirmButtonColor: '#fa5246'
                });
                
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
        
        // Initialize AI and map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initBeripikadoAI();
            initMap();
            
            // Address input handler
            const addressInput = document.getElementById('address');
            let timeout;
            addressInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    geocodeAddress(this.value);
                }, 1000);
            });
            
            // Current location button
            document.getElementById('getCurrentLocationBtn').addEventListener('click', getCurrentLocation);
            
            // Quick location buttons
            document.querySelectorAll('.quick-location-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const address = this.getAttribute('data-address');
                    document.getElementById('address').value = address;
                    geocodeAddress(address);
                });
            });
            
            // Photo action buttons
            document.getElementById('changePhotoBtn').addEventListener('click', function() {
                // Switch to video tab and restart recording
                document.querySelector('[data-tab="video"]').click();
                removePhoto();
            });
            
            document.getElementById('removePhotoBtn').addEventListener('click', function() {
                Swal.fire({
                    title: 'Remove Photo?',
                    text: 'Are you sure you want to remove the selected photo?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, remove it',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        removePhoto();
                    }
                });
            });
            
            // Initial geocoding if address exists
            if (addressInput.value.trim()) {
                geocodeAddress(addressInput.value);
            }
        });

        // Character counter for description
        const descriptionTextarea = document.getElementById('description');
        const charCount = document.getElementById('charCount');
        
        descriptionTextarea.addEventListener('input', function() {
            const count = this.value.length;
            charCount.textContent = count + ' characters';
            
            if (count > 500) {
                charCount.classList.add('text-orange-500');
            } else if (count > 800) {
                charCount.classList.add('text-red-500');
                charCount.classList.remove('text-orange-500');
            } else {
                charCount.classList.remove('text-orange-500', 'text-red-500');
            }
        });
        
        // Acknowledgment checkbox handler
        const acknowledgmentCheckbox = document.getElementById('acknowledgment');
        const submitBtn = document.getElementById('submitBtn');
        
        if (acknowledgmentCheckbox && submitBtn) {
            acknowledgmentCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                } else {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });
        }
        
        // Tab switching for upload methods
        document.querySelectorAll('.upload-tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                
                // Remove active class from all tabs
                document.querySelectorAll('.upload-tab-btn').forEach(b => {
                    b.classList.remove('active');
                    b.style.background = 'transparent';
                    b.classList.add('text-lgu-paragraph');
                    b.classList.remove('text-lgu-headline');
                });
                
                // Add active class to clicked tab
                this.classList.add('active');
                this.style.background = 'linear-gradient(135deg, rgba(250, 174, 43, 0.1), rgba(250, 174, 43, 0.05))';
                this.classList.add('text-lgu-headline');
                this.classList.remove('text-lgu-paragraph');
                
                // Hide all tab contents
                document.querySelectorAll('.upload-tab-content').forEach(content => {
                    content.classList.add('hidden');
                });
                
                // Show selected tab content
                document.getElementById(tabName + '-tab').classList.remove('hidden');
                
                // Stop camera/video if switching away
                if (tabName !== 'camera' && cameraStream) {
                    stopCamera();
                }
                if (tabName !== 'video' && videoStream) {
                    // Stop recording if in progress
                    if (mediaRecorder && mediaRecorder.state === 'recording') {
                        stopRecording();
                    }
                    // Stop video stream
                    videoStream.getTracks().forEach(track => track.stop());
                    videoStream = null;
                    if (videoPreview) videoPreview.srcObject = null;
                    if (startVideoBtn) startVideoBtn.classList.remove('hidden');
                    if (recordVideoBtn) recordVideoBtn.classList.add('hidden');
                    if (stopVideoBtn) stopVideoBtn.classList.add('hidden');
                }
            });
        });

        // Video recording functionality
        let videoStream = null;
        let mediaRecorder = null;
        let recordedChunks = [];
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.name = 'hazard_image';
        fileInput.accept = 'image/*,video/*,.webm,.mp4,.mov,.avi';
        fileInput.style.display = 'none';
        document.getElementById('hazardForm').appendChild(fileInput);

        const startVideoBtn = document.getElementById('startVideoBtn');
        const recordVideoBtn = document.getElementById('recordVideoBtn');
        const stopVideoBtn = document.getElementById('stopVideoBtn');
        const videoPreview = document.getElementById('videoPreview');
        const recordedVideo = document.getElementById('recordedVideo');

        if (startVideoBtn) startVideoBtn.addEventListener('click', startVideoCamera);
        if (recordVideoBtn) recordVideoBtn.addEventListener('click', startRecording);
        if (stopVideoBtn) stopVideoBtn.addEventListener('click', stopRecording);

        async function startVideoCamera() {
            try {
                if (videoStream) {
                    videoStream.getTracks().forEach(track => track.stop());
                }
                
                const constraints = {
                    video: { facingMode: 'environment' },
                    audio: true
                };
                
                try {
                    videoStream = await navigator.mediaDevices.getUserMedia(constraints);
                } catch (audioErr) {
                    videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                }
                
                if (videoPreview) {
                    videoPreview.srcObject = videoStream;
                    videoPreview.muted = true;
                    videoPreview.play().catch(e => console.warn('Play error:', e));
                }
                
                if (startVideoBtn) startVideoBtn.classList.add('hidden');
                if (recordVideoBtn) recordVideoBtn.classList.remove('hidden');
                
                Swal.fire({
                    icon: 'success',
                    title: 'Camera Started',
                    text: 'Camera is now active. Click "Record" to start recording.',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (err) {
                console.error('Video camera error:', err);
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access camera. Please check permissions and try again.',
                    confirmButtonColor: '#fa5246'
                });
            }
        }

        let recordingTimer = null;
        let recordingStartTime = null;
        let timestampCanvas = null;
        let timestampCtx = null;
        
        function startRecording() {
            if (!videoStream) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Camera Not Started',
                    text: 'Please start the camera first before recording.',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }
            
            recordedChunks = [];
            
            // Get timestamp for recording
            const now = new Date();
            const options = {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            };
            window.videoTimestamp = now.toLocaleDateString('en-US', options);
            
            let options_recorder = null;
            const mimeTypes = ['video/webm', 'video/mp4', ''];
            
            for (const mimeType of mimeTypes) {
                if (!mimeType || MediaRecorder.isTypeSupported(mimeType)) {
                    options_recorder = mimeType ? { mimeType } : undefined;
                    break;
                }
            }
            
            try {
                mediaRecorder = new MediaRecorder(videoStream, options_recorder);
            } catch (e) {
                try {
                    mediaRecorder = new MediaRecorder(videoStream);
                } catch (e2) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Recording Error',
                        text: 'Your device does not support video recording. Please try using the photo capture instead.',
                        confirmButtonColor: '#fa5246'
                    });
                    return;
                }
            }
            
            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) recordedChunks.push(event.data);
            };
            
            mediaRecorder.onstop = () => {
                addMediaTypeInput('video');
                const timestamp = window.videoTimestamp || 'Unknown time';
                // Clear timer
                if (recordingTimer) {
                    clearInterval(recordingTimer);
                    recordingTimer = null;
                }
                const timerElement = document.getElementById('recordingTimer');
                if (timerElement) timerElement.classList.add('hidden');
                
                // Show manual hazard level selection
                const manualHazardLevel = document.getElementById('manualHazardLevel');
                if (manualHazardLevel) manualHazardLevel.classList.remove('hidden');
                
                // Determine MIME type from recorded chunks
                let mimeType = 'video/webm';
                if (mediaRecorder.mimeType) {
                    mimeType = mediaRecorder.mimeType.split(';')[0];
                }
                
                // Determine file extension
                let fileExt = 'webm';
                if (mimeType.includes('mp4')) fileExt = 'mp4';
                else if (mimeType.includes('quicktime')) fileExt = 'mov';
                else if (mimeType.includes('msvideo')) fileExt = 'avi';
                
                const blob = new Blob(recordedChunks, { type: mimeType });
                const file = new File([blob], `video_${Date.now()}.${fileExt}`, { 
                    type: mimeType,
                    lastModified: new Date().getTime()
                });
                
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;
                
                if (recordedVideo) {
                    const videoURL = URL.createObjectURL(blob);
                    recordedVideo.src = videoURL;
                    recordedVideo.muted = false;
                    recordedVideo.controls = true;
                    recordedVideo.playsInline = true;
                    recordedVideo.style.width = '100%';
                    recordedVideo.style.height = 'auto';
                    recordedVideo.style.display = 'block';
                    recordedVideo.classList.remove('hidden');
                    setTimeout(() => recordedVideo.play().catch(e => console.warn('Play error:', e)), 100);
                }
                
                // Show timestamp for video
                const videoTimestampElement = document.getElementById('videoTimestamp');
                const videoTimestampText = document.getElementById('videoTimestampText');
                if (videoTimestampElement && videoTimestampText) {
                    videoTimestampText.textContent = timestamp;
                    videoTimestampElement.classList.remove('hidden');
                }
                
                const photoActions = document.getElementById('photoActions');
                if (photoActions) photoActions.classList.remove('hidden');
                
                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Video Recorded!',
                    text: 'You can now play the video to review it before submitting.',
                    timer: 3000,
                    showConfirmButton: false
                });
            };
            
            mediaRecorder.onerror = (event) => {
                console.error('Recording error:', event.error);
                Swal.fire({
                    icon: 'error',
                    title: 'Recording Error',
                    text: 'An error occurred during recording. Please try again.',
                    confirmButtonColor: '#fa5246'
                });
            };
            
            mediaRecorder.start();
            recordVideoBtn.classList.add('hidden');
            stopVideoBtn.classList.remove('hidden');
            
            // Show timer and start countdown
            document.getElementById('recordingTimer').classList.remove('hidden');
            recordingStartTime = Date.now();
            
            recordingTimer = setInterval(() => {
                const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
                const remaining = Math.max(0, 30 - elapsed);
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                document.getElementById('timerDisplay').textContent = 
                    `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                if (remaining === 0) {
                    stopRecording();
                }
            }, 1000);
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
            }
            if (recordingTimer) {
                clearInterval(recordingTimer);
                recordingTimer = null;
            }
            if (window.timestampInterval) {
                clearInterval(window.timestampInterval);
                window.timestampInterval = null;
            }
            const timerElement = document.getElementById('recordingTimer');
            if (timerElement) timerElement.classList.add('hidden');
            
            if (recordVideoBtn) recordVideoBtn.classList.remove('hidden');
            if (stopVideoBtn) stopVideoBtn.classList.add('hidden');
        }

        function previewImage(file) {
            const preview = document.getElementById('imagePreview');
            const photoActions = document.getElementById('photoActions');
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'image-preview';
                    img.alt = 'Preview';
                    img.onload = function() {
                        // Analyze with Beripikado AI
                        analyzeImageWithAI(img, 'uploadAiResults');
                    };
                    preview.innerHTML = '';
                    preview.appendChild(img);
                    photoActions.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        }
        
        function removePhoto() {
            const preview = document.getElementById('imagePreview');
            const photoActions = document.getElementById('photoActions');
            const aiAnalysis = document.getElementById('uploadAiAnalysis');
            const capturedImage = document.getElementById('capturedImage');
            const captureTimestamp = document.getElementById('captureTimestamp');
            const recordedVideo = document.getElementById('recordedVideo');
            const videoTimestamp = document.getElementById('videoTimestamp');
            const recordingTimer = document.getElementById('recordingTimer');
            const manualHazardLevel = document.getElementById('manualHazardLevel');
            
            preview.innerHTML = '';
            photoActions.classList.add('hidden');
            fileInput.value = '';
            aiAnalysis.classList.add('hidden');
            capturedImage.classList.add('hidden');
            captureTimestamp.classList.add('hidden');
            recordedVideo.classList.add('hidden');
            if (videoTimestamp) videoTimestamp.classList.add('hidden');
            if (recordingTimer) recordingTimer.classList.add('hidden');
            if (manualHazardLevel) manualHazardLevel.classList.add('hidden');
            
            // Remove AI analysis input
            const aiInput = document.getElementById('ai_analysis_input');
            if (aiInput) {
                aiInput.remove();
            }
        }

        // Camera functionality
        const startCameraBtn = document.getElementById('startCameraBtn');
        const stopCameraBtn = document.getElementById('stopCameraBtn');
        const captureBtn = document.getElementById('captureBtn');
        const cameraVideo = document.getElementById('cameraVideo');
        const captureCanvas = document.getElementById('captureCanvas');
        const capturedImage = document.getElementById('capturedImage');

        startCameraBtn.addEventListener('click', startCamera);
        stopCameraBtn.addEventListener('click', stopCamera);
        captureBtn.addEventListener('click', capturePhoto);

        async function startCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'environment',
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    } 
                });
                
                cameraStream = stream;
                cameraVideo.srcObject = stream;
                cameraVideo.autoplay = true;
                cameraVideo.playsInline = true;
                cameraVideo.setAttribute('playsinline', '');
                cameraVideo.setAttribute('webkit-playsinline', '');
                cameraVideo.setAttribute('x5-playsinline', '');
                cameraVideo.style.width = '100%';
                cameraVideo.style.height = 'auto';
                cameraVideo.style.objectFit = 'cover';
                
                startCameraBtn.classList.add('hidden');
                stopCameraBtn.classList.remove('hidden');
                captureBtn.classList.remove('hidden');
                
                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Camera Started',
                    text: 'Camera is now active. Click "Capture" to take a photo.',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (err) {
                console.error('Camera error:', err);
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access camera. Please check permissions and try again.',
                    confirmButtonColor: '#fa5246'
                });
            }
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            cameraVideo.srcObject = null;
            startCameraBtn.classList.remove('hidden');
            stopCameraBtn.classList.add('hidden');
            captureBtn.classList.add('hidden');
            capturedImage.classList.add('hidden');
            document.getElementById('aiAnalysis').classList.add('hidden');
            document.getElementById('captureTimestamp').classList.add('hidden');
        }

        function capturePhoto() {
            if (!cameraStream) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Camera Not Active',
                    text: 'Please start the camera first.',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }

            const context = captureCanvas.getContext('2d');
            captureCanvas.width = cameraVideo.videoWidth;
            captureCanvas.height = cameraVideo.videoHeight;
            
            // Draw current video frame to canvas
            context.drawImage(cameraVideo, 0, 0, captureCanvas.width, captureCanvas.height);
            
            // Get current date and time
            const now = new Date();
            const options = {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            };
            const timestamp = now.toLocaleDateString('en-US', options);
            
            // Add timestamp overlay to the image
            context.fillStyle = 'rgba(0, 0, 0, 0.7)';
            context.fillRect(10, captureCanvas.height - 60, 400, 50);
            context.fillStyle = '#ffffff';
            context.font = 'bold 16px Arial';
            context.fillText(timestamp, 20, captureCanvas.height - 30);
            
            // Convert canvas to data URL and display
            capturedImageData = captureCanvas.toDataURL('image/jpeg');
            capturedImage.src = capturedImageData;
            capturedImage.classList.remove('hidden');
            
            // Show timestamp
            const timestampElement = document.getElementById('captureTimestamp');
            const timestampText = document.getElementById('timestampText');
            timestampText.textContent = timestamp;
            timestampElement.classList.remove('hidden');
            
            // Analyze captured image with Beripikado AI (only once)
            capturedImage.onload = function() {
                analyzeImageWithAI(capturedImage, 'aiResults');
            };
            
            // Create a blob from canvas and set it as file input
            captureCanvas.toBlob(blob => {
                const file = new File([blob], 'camera_capture_' + Date.now() + '.jpg', { 
                    type: 'image/jpeg',
                    lastModified: new Date().getTime()
                });
                
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;
                addMediaTypeInput('image');
                
                // Show photo actions without calling previewImage to avoid duplicate AI analysis
                document.getElementById('photoActions').classList.remove('hidden');
                
                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Photo Captured!',
                    text: 'Photo captured on ' + timestamp,
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 'image/jpeg', 0.8);
        }

        document.querySelectorAll('input[name="manual_hazard_level"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const hazardLevel = this.value;
                const selectedType = document.querySelector('input[name="hazard_type"]:checked')?.value;
                const aiAnalysisData = {
                    predictions: [],
                    topPrediction: { className: 'Manual Selection', probability: 1 },
                    hazardLevel: hazardLevel,
                    classifiedType: selectedType,
                    isValid: true
                };
                
                let aiInput = document.getElementById('ai_analysis_input');
                if (!aiInput) {
                    aiInput = document.createElement('input');
                    aiInput.type = 'hidden';
                    aiInput.name = 'ai_analysis';
                    aiInput.id = 'ai_analysis_input';
                    document.getElementById('hazardForm').appendChild(aiInput);
                }
                aiInput.value = JSON.stringify(aiAnalysisData);
            });
        });

        // Form validation and submission
        document.getElementById('hazardForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate required fields
            const acknowledgment = document.getElementById('acknowledgment').checked;
            const hazardType = document.querySelector('input[name="hazard_type"]:checked');
            const description = document.getElementById('description').value.trim();
            const address = document.getElementById('address').value.trim();
            
            const manualHazardLevel = document.querySelector('input[name="manual_hazard_level"]:checked');
            const recordedVideo = document.getElementById('recordedVideo');
            if (recordedVideo && recordedVideo.style.display !== 'none' && !manualHazardLevel) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please select a hazard level for the video',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }

            if (!acknowledgment) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Acknowledgment Required',
                    text: 'Please read and acknowledge the reminders and legal notice before submitting',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }

            if (!hazardType) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please select a hazard type',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }

            if (!description) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please provide a description of the hazard',
                    confirmButtonColor: '#faae2b'
                });
                document.getElementById('description').focus();
                return;
            }

            if (!address) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please provide the location/address of the hazard',
                    confirmButtonColor: '#faae2b'
                });
                document.getElementById('address').focus();
                return;
            }

            // Validate contact number if provided
            const contactNumber = document.getElementById('contact_number').value.trim();
            if (contactNumber && !/^[0-9]{11}$/.test(contactNumber)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Contact Number',
                    text: 'Please enter a valid 11-digit contact number',
                    confirmButtonColor: '#faae2b'
                });
                document.getElementById('contact_number').focus();
                return;
            }

            // Show confirmation dialog
            Swal.fire({
                title: 'Submit Report?',
                text: 'Are you sure you want to submit this hazard report?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#00473e',
                cancelButtonColor: '#fa5246',
                confirmButtonText: 'Yes, submit it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm();
                }
            });
        });

        function submitForm() {
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            // Update progress indicator
            const step2Indicator = document.getElementById('step2-indicator');
            const step2Text = document.getElementById('step2-text');
            if (step2Indicator && step2Text) {
                step2Indicator.classList.remove('bg-gray-300');
                step2Indicator.classList.add('bg-lgu-button');
                step2Text.classList.remove('text-gray-500');
                step2Text.classList.add('text-lgu-headline');
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="flex items-center justify-center gap-3"><svg class="animate-spin h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Submitting Report...</span>';

            const formData = new FormData(document.getElementById('hazardForm'));
            
            // Ensure file input is properly included
            if (fileInput.files && fileInput.files.length > 0) {
                formData.set('hazard_image', fileInput.files[0]);
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Report Submitted Successfully!',
                        text: data.message,
                        confirmButtonColor: '#00473e',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.href = 'inspector-dashboard.php';
                    });
                } else {
                    // Check if redirect is needed
                    if (data.redirect) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Session Expired',
                            text: data.message,
                            confirmButtonColor: '#fa5246'
                        }).then(() => {
                            window.location.href = data.redirect;
                        });
                    } else {
                        throw new Error(data.message || 'Submission failed');
                    }
                }
            })
            .catch(error => {
                console.error('Submission error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Submission Failed',
                    text: error.message || 'An error occurred while submitting the report. Please try again.',
                    confirmButtonColor: '#fa5246'
                });
                // Reset progress indicator
                const step2Indicator = document.getElementById('step2-indicator');
                const step2Text = document.getElementById('step2-text');
                if (step2Indicator && step2Text) {
                    step2Indicator.classList.remove('bg-lgu-button');
                    step2Indicator.classList.add('bg-gray-300');
                    step2Text.classList.remove('text-lgu-headline');
                    step2Text.classList.add('text-gray-500');
                }
                
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }

        // Add media_type tracking function
        function addMediaTypeInput(type) {
            let mediaTypeInput = document.getElementById('media_type_input');
            if (!mediaTypeInput) {
                mediaTypeInput = document.createElement('input');
                mediaTypeInput.type = 'hidden';
                mediaTypeInput.name = 'media_type';
                mediaTypeInput.id = 'media_type_input';
                document.getElementById('hazardForm').appendChild(mediaTypeInput);
            }
            mediaTypeInput.value = type;
        }
        
        // Stop camera when leaving the page
        window.addEventListener('beforeunload', () => {
            if (cameraStream) {
                stopCamera();
            }
        });
    </script>
</body>
</html>
