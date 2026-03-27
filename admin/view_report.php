<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
// Debug session information
error_log("=== ADMIN REPORT VIEW ===");
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Session user_role: " . ($_SESSION['user_role'] ?? 'not set'));

// Only allow admins
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    error_log("Access denied - Redirecting to login");
    header("Location: ../login.php");
    exit();
}

$report_id = (int)($_GET['id'] ?? 0);
if (!$report_id) {
    header("Location: incoming_reports.php");
    exit();
}

// Check if database configuration exists
$config_path = __DIR__ . '/../config/database.php';
if (!file_exists($config_path)) {
    die("Database configuration file not found. Please check the file path: " . $config_path);
}

require_once $config_path;

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to connect to database");
    }
    
    error_log("Database connected successfully");
    
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

$report = null;
$error_message = '';

try {
    // Get detailed report information with maintenance and inspection data
    $sql = "
        SELECT 
            r.id AS report_id,
            r.hazard_type,
            r.description,
            r.address,
            r.landmark,
            r.contact_number,
            r.image_path,
            r.ai_analysis_result,
            r.status,
            r.validation_status,
            r.created_at,
            u.fullname AS reporter_name,
            u.email AS reporter_email,
            ri.inspector_id,
            ri.assigned_at,
            ri.completed_at,
            ri.notes AS inspector_notes,
            ui.fullname AS assigned_inspector_name,
            ui.contact_number AS inspector_contact,
            -- Maintenance assignment data
            ma.id AS maintenance_id,
            ma.status AS maintenance_status,
            ma.team_type,
            ma.completion_deadline,
            ma.notes AS maintenance_notes,
            ma.started_at AS maintenance_started,
            ma.completed_at AS maintenance_completed,
            um.fullname AS maintenance_assignee_name,
            um.contact_number AS maintenance_contact,
            rf.team_type AS forwarded_team_type,
            rf.notes AS forward_notes
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN report_inspectors ri ON r.id = ri.report_id AND ri.status IN ('assigned', 'completed')
        LEFT JOIN users ui ON ri.inspector_id = ui.id
        LEFT JOIN report_forwards rf ON r.id = rf.report_id
        LEFT JOIN maintenance_assignments ma ON r.id = ma.report_id AND ma.status IN ('assigned', 'in_progress', 'completed')
        LEFT JOIN users um ON ma.assigned_to = um.id
        WHERE r.id = ?
    ";
    
    error_log("Executing report query for report_id: $report_id");
    $stmt = $pdo->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . implode(", ", $pdo->errorInfo()));
    }
    
    if (!$stmt->execute([$report_id])) {
        throw new Exception("Failed to execute query: " . implode(", ", $stmt->errorInfo()));
    }
    
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        header("Location: incoming_reports.php");
        exit();
    }
    
    error_log("Successfully fetched report details");

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching report: " . $e->getMessage();
}

// Debug final state
error_log("=== FINAL STATE ===");
error_log("Report found: " . ($report ? 'Yes' : 'No'));
error_log("Error message: " . ($error_message ?: 'None'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report #<?php echo $report_id; ?> - RTIM Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'poppins': ['Poppins', 'sans-serif']
                    },
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
        * { font-family: 'Poppins', sans-serif; }
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        
        .card-hover:hover {
            transform: translateY(-2px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-in_progress { border-left: 4px solid #3b82f6; }
        .status-done { border-left: 4px solid #10b981; }
        .status-escalated { border-left: 4px solid #ef4444; }
        
        .validation-pending { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .validation-validated { background: linear-gradient(135deg, #10b981, #059669); }
        .validation-rejected { background: linear-gradient(135deg, #ef4444, #dc2626); }
        
        .maintenance-assigned { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .maintenance-in_progress { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .maintenance-completed { background: linear-gradient(135deg, #10b981, #059669); }
        .maintenance-cancelled { background: linear-gradient(135deg, #6b7280, #4b5563); }
        
        .team-badge-road { background-color: #dc2626; color: white; }
        .team-badge-traffic { background-color: #ea580c; color: white; }
        .team-badge-bridge { background-color: #7c3aed; color: white; }
        
        .ai-analysis {
            background: linear-gradient(135deg, #00473e 0%, #00332c 100%);
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin: 20px 0;
        }
        
        .ai-analysis h3 {
            color: #fff;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .ai-analysis-content {
            background: rgba(250, 174, 43, 0.1);
            border-radius: 8px;
            padding: 15px;
            backdrop-filter: blur(10px);
        }
        
        .prediction-item {
            background: rgba(250, 174, 43, 0.15);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            border-left: 4px solid rgba(250, 174, 43, 0.3);
        }
        
        .prediction-item.top-prediction {
            border-left-color: #faae2b;
            background: rgba(250, 174, 43, 0.3);
        }
        
        .probability-bar {
            height: 6px;
            background: rgba(250, 174, 43, 0.2);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .probability-fill {
            height: 100%;
            background: linear-gradient(90deg, #faae2b, #f5a217);
            border-radius: 3px;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <?php include 'sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <main class="flex-1 p-6">
            <div class="max-w-7xl mx-auto">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-lgu-headline">Report #<?php echo $report_id; ?></h1>
                        <p class="text-lgu-paragraph mt-1">Detailed report information and AI analysis</p>
                    </div>
                    <a href="incoming_reports.php" class="bg-lgu-button text-lgu-button-text px-6 py-3 rounded-lg font-semibold hover:bg-yellow-500 transition flex items-center">
                        <i class="fa fa-arrow-left mr-2"></i>Back to Reports
                    </a>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                        <i class="fa fa-exclamation-triangle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php elseif ($report): ?>
                    <!-- Report Status Card -->
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover status-<?php echo strtolower($report['status']); ?>">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 rounded-full bg-lgu-button flex items-center justify-center">
                                    <i class="fa fa-exclamation-triangle text-lgu-button-text text-xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-lgu-headline"><?php echo ucfirst(str_replace('_', ' ', $report['hazard_type'])); ?></h2>
                                    <p class="text-lgu-paragraph"><i class="fa fa-calendar mr-1"></i><?php echo date('M d, Y', strtotime($report['created_at'])); ?> <i class="fa fa-clock ml-2 mr-1"></i><?php echo date('h:i A', strtotime($report['created_at'])); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center space-x-3">
                                <!-- Status Badge -->
                                <span class="px-4 py-2 rounded-full text-sm font-semibold
                                    <?php 
                                    switch($report['status']) {
                                        case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                        case 'in_progress': echo 'bg-blue-100 text-blue-800'; break;
                                        case 'done': echo 'bg-green-100 text-green-800'; break;
                                        case 'escalated': echo 'bg-red-100 text-red-800'; break;
                                        default: echo 'bg-gray-100 text-gray-800';
                                    }
                                    ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                </span>
                                
                                <!-- Validation Badge -->
                                <span class="px-3 py-1 rounded-full text-xs font-medium text-white validation-<?php echo $report['validation_status'] ?? 'pending'; ?>">
                                    <?php echo ucfirst($report['validation_status'] ?? 'Pending'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- AI Analysis Section -->
                    <?php if (!empty($report['ai_analysis_result'])): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-robot mr-2 text-lgu-button"></i>
                            Beripikado AI Analysis
                        </h3>
                        
                        <?php 
                        // Try to decode JSON if it's structured data
                        $ai_data = json_decode($report['ai_analysis_result'], true);
                        if ($ai_data && isset($ai_data['predictions'])): 
                        ?>
                            <div class="bg-blue-50 rounded-lg p-4">
                                <!-- Primary Detection Only -->
                                <?php if (isset($ai_data['topPrediction'])): ?>
                                <div class="mb-4">
                                    <div class="bg-blue-100 p-3 rounded-lg border-l-4 border-blue-500">
                                        <div class="flex justify-between items-center">
                                            <span class="font-semibold text-blue-800"><?php echo htmlspecialchars($ai_data['topPrediction']['className']); ?></span>
                                            <span class="text-sm font-bold text-blue-700"><?php echo number_format($ai_data['topPrediction']['probability'] * 100, 1); ?>%</span>
                                        </div>
                                        <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Hazard Level -->
                                <?php if (isset($ai_data['hazardLevel'])): ?>
                                <div class="mb-4 p-3 rounded-lg border-l-4 border-purple-500 bg-purple-50">
                                    <div class="font-semibold text-purple-800">Hazard Level: <?php echo ucfirst($ai_data['hazardLevel']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- AI Recommendation -->
                                <?php 
                                $topPrediction = $ai_data['topPrediction'];
                                if ($topPrediction['probability'] > 0.6): ?>
                                <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                                    <div class="flex items-center text-green-800">
                                        <i class="fa fa-check-circle mr-2"></i>
                                        <span class="font-medium text-sm">AI Recommendation: Image appears to show <?php echo htmlspecialchars($topPrediction['className']); ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <div class="flex items-center text-yellow-800">
                                        <i class="fa fa-exclamation-triangle mr-2"></i>
                                        <span class="font-medium text-sm">AI Recommendation: Hazard classification requires manual verification</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Fallback for plain text AI analysis -->
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-lgu-paragraph leading-relaxed"><?php echo nl2br(htmlspecialchars($report['ai_analysis_result'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Report Details -->
                        <div class="bg-white rounded-xl shadow-lg p-6 card-hover">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                                <i class="fa fa-info-circle mr-2 text-lgu-button"></i>
                                Report Details
                            </h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Type:</span>
                                    <span class="text-lgu-headline font-semibold"><?php echo ucfirst(str_replace('_', ' ', $report['hazard_type'])); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Address:</span>
                                    <span class="text-lgu-headline text-right"><?php echo htmlspecialchars($report['address']); ?></span>
                                </div>
                                <?php if (!empty($report['landmark'])): ?>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Landmark:</span>
                                    <span class="text-lgu-headline text-right"><?php echo htmlspecialchars($report['landmark']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Contact:</span>
                                    <span class="text-lgu-headline"><?php echo htmlspecialchars($report['contact_number'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Reported Date:</span>
                                    <span class="text-lgu-headline"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="font-medium text-lgu-paragraph">Reported Time:</span>
                                    <span class="text-lgu-headline"><?php echo date('h:i A', strtotime($report['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reporter Information -->
                        <div class="bg-white rounded-xl shadow-lg p-6 card-hover">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                                <i class="fa fa-user mr-2 text-lgu-button"></i>
                                Reporter Information
                            </h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Name:</span>
                                    <span class="text-lgu-headline font-semibold"><?php echo htmlspecialchars($report['reporter_name'] ?? 'Unknown'); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="font-medium text-lgu-paragraph">Email:</span>
                                    <span class="text-lgu-headline"><?php echo htmlspecialchars($report['reporter_email'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="font-medium text-lgu-paragraph">Phone:</span>
                                    <span class="text-lgu-headline"><?php echo htmlspecialchars($report['contact_number'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-file-text mr-2 text-lgu-button"></i>
                            Description
                        </h3>
                        <div class="bg-lgu-bg p-4 rounded-lg">
                            <p class="text-lgu-paragraph leading-relaxed"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                        </div>
                    </div>
                    
                    <!-- Location Map -->
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-map-marker-alt mr-2 text-lgu-button"></i>
                            Hazard Location
                        </h3>
                        <div id="reportMap" class="w-full h-80 rounded-lg border-2 border-gray-300"></div>
                        <p class="text-xs text-lgu-paragraph mt-2">Red marker shows the reported hazard location</p>
                    </div>
                    
                    <!-- Media (Image/Video) -->
                    <?php if ($report['image_path']): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <?php 
                        $file_path = $report['image_path'];
                        $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                        $is_video = in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'ogv']);
                        ?>
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-<?php echo $is_video ? 'video' : 'image'; ?> mr-2 text-lgu-button"></i>
                            Hazard <?php echo $is_video ? 'Video' : 'Image'; ?>
                        </h3>
                        <div class="text-center">
                            <?php if ($is_video): ?>
                                <video controls class="max-w-full h-auto rounded-lg shadow-lg mx-auto" style="max-height: 500px;">
                                    <source src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>">
                                    Your browser does not support the video tag.
                                </video>
                                <p class="text-sm text-lgu-paragraph mt-2"><i class="fa fa-video mr-1 text-red-500"></i>Video File</p>
                            <?php else: ?>
                                <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>" 
                                     alt="Hazard Image" 
                                     class="max-w-full h-auto rounded-lg cursor-pointer hover:opacity-90 transition shadow-lg" 
                                     onclick="window.open(this.src, '_blank')">
                                <p class="text-sm text-lgu-paragraph mt-2"><i class="fa fa-image mr-1 text-blue-500"></i>Image File - Click to view full size</p>
                            <?php endif; ?>
                            <div class="mt-4 p-3 bg-gray-50 rounded-lg text-left">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-lgu-paragraph"><i class="fa fa-file mr-2"></i>File Type:</span>
                                    <span class="font-semibold text-lgu-headline"><?php echo strtoupper($file_ext); ?></span>
                                </div>
                                <div class="flex items-center justify-between text-sm mt-2">
                                    <span class="text-lgu-paragraph"><i class="fa fa-clock mr-2"></i>Uploaded:</span>
                                    <span class="font-semibold text-lgu-headline"><?php echo date('M d, Y h:i A', strtotime($report['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Inspector Assignment -->
                    <?php if ($report['assigned_inspector_name']): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-user-check mr-2 text-lgu-button"></i>
                            Inspector Assignment
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-lgu-paragraph">Assigned Inspector</p>
                                <p class="font-semibold text-lgu-headline"><?php echo htmlspecialchars($report['assigned_inspector_name']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Contact</p>
                                <p class="font-semibold text-lgu-headline"><?php echo htmlspecialchars($report['inspector_contact'] ?? 'N/A'); ?></p>
                            </div>
                            <?php if ($report['assigned_at']): ?>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Assigned Date</p>
                                <p class="font-semibold text-lgu-headline"><?php echo date('M d, Y h:i A', strtotime($report['assigned_at'])); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($report['completed_at']): ?>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Completed Date</p>
                                <p class="font-semibold text-lgu-headline"><?php echo date('M d, Y h:i A', strtotime($report['completed_at'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($report['inspector_notes']): ?>
                        <div class="mt-4 p-4 bg-lgu-bg rounded-lg">
                            <p class="text-sm text-lgu-paragraph mb-1">Inspector Notes:</p>
                            <p class="text-lgu-headline"><?php echo nl2br(htmlspecialchars($report['inspector_notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Maintenance Assignment -->
                    <?php if ($report['maintenance_assignee_name'] || $report['forwarded_team_type']): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6 card-hover">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-tools mr-2 text-lgu-button"></i>
                            Maintenance Assignment
                        </h3>
                        
                        <?php if ($report['forwarded_team_type']): ?>
                        <div class="mb-4 p-4 bg-blue-50 rounded-lg border-l-4 border-blue-400">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-semibold text-blue-800">Forwarded to Team</p>
                                    <span class="inline-block px-3 py-1 rounded-full text-sm font-medium team-badge-<?php echo strtolower($report['forwarded_team_type']); ?>">
                                        <?php echo ucfirst($report['forwarded_team_type']); ?> Team
                                    </span>
                                </div>
                            </div>
                            <?php if ($report['forward_notes']): ?>
                            <div class="mt-2">
                                <p class="text-sm text-blue-700"><?php echo nl2br(htmlspecialchars($report['forward_notes'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($report['maintenance_assignee_name']): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-lgu-paragraph">Assigned To</p>
                                <p class="font-semibold text-lgu-headline"><?php echo htmlspecialchars($report['maintenance_assignee_name']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Team Type</p>
                                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium team-badge-<?php echo strtolower($report['team_type'] ?? 'road'); ?>">
                                    <?php echo ucfirst($report['team_type'] ?? 'Road'); ?> Team
                                </span>
                            </div>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Status</p>
                                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium text-white maintenance-<?php echo strtolower($report['maintenance_status'] ?? 'assigned'); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $report['maintenance_status'] ?? 'Assigned')); ?>
                                </span>
                            </div>
                            <?php if ($report['completion_deadline']): ?>
                            <div>
                                <p class="text-sm text-lgu-paragraph">Deadline</p>
                                <p class="font-semibold text-lgu-headline"><?php echo date('M d, Y', strtotime($report['completion_deadline'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($report['maintenance_notes']): ?>
                        <div class="mt-4 p-4 bg-lgu-bg rounded-lg">
                            <p class="text-sm text-lgu-paragraph mb-1">Maintenance Notes:</p>
                            <p class="text-lgu-headline"><?php echo nl2br(htmlspecialchars($report['maintenance_notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        let map = null;
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
        
        function initMap() {
            // Initialize map centered on Philippines
            map = L.map('reportMap').setView([14.5995, 120.9842], 12);
            
            // Add TomTom tile layer
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            // Geocode the address and add marker
            const address = <?php echo json_encode($report['address'] ?? ''); ?>;
            if (address) {
                geocodeAndMarkLocation(address);
            }
        }
        
        async function geocodeAndMarkLocation(address) {
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                const data = await response.json();
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    // Add hazard marker
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-marker',
                            html: '<div style="background: #ef4444; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-sm"></i></div>',
                            iconSize: [30, 30],
                            iconAnchor: [15, 15]
                        })
                    }).addTo(map);
                    
                    // Create popup content
                    const popupContent = `
                        <div class="p-3 min-w-[200px]">
                            <h4 class="font-bold text-red-600 mb-2">Hazard Location</h4>
                            <p class="text-sm mb-2"><strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $report['hazard_type'] ?? '')); ?></p>
                            <p class="text-sm mb-2"><strong>Address:</strong> ${result.address.freeformAddress}</p>
                            <?php if (!empty($report['landmark'])): ?>
                            <p class="text-sm mb-2"><strong>Landmark:</strong> <?php echo htmlspecialchars($report['landmark']); ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-600">Reported: <?php echo date('M d, Y', strtotime($report['created_at'] ?? '')); ?></p>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent);
                    
                    // Center map on location
                    map.setView([lat, lng], 16);
                } else {
                    console.log('No geocoding results found for address:', address);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
    </script>
</body>
</html>