<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$inspector_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle inspection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $report_id = intval($_POST['report_id']);
        
        try {
            if ($_POST['action'] === 'submit_findings') {
                $severity = trim($_POST['severity'] ?? '');
                $findings_notes = trim($_POST['findings_notes'] ?? '');
                
                if (empty($severity) || empty($findings_notes)) {
                    throw new Exception('Severity and findings notes are required.');
                }
                
                // Start transaction
                $pdo->beginTransaction();
                
                // Update report_inspectors with notes
                $stmt = $pdo->prepare("UPDATE report_inspectors SET notes = ? WHERE report_id = ? AND inspector_id = ?");
                $stmt->execute([$findings_notes, $report_id, $inspector_id]);
                
                // Insert findings record
                $stmt = $pdo->prepare("INSERT INTO inspection_findings (report_id, inspector_id, severity, notes, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$report_id, $inspector_id, $severity, $findings_notes]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = 'Inspection findings submitted successfully!';
                header("Location: conduct_inspection.php");
                exit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Inspection action error: " . $e->getMessage());
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
}

// Fetch all assigned reports for this inspector with their latest findings
$reports = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'done' => 0,
    'escalated' => 0
];

try {
    $query = "SELECT r.*, ri.status as assignment_status, ri.assigned_at, ri.notes as assignment_notes,
              u.fullname as reporter_name, u.contact_number as reporter_contact,
              f.severity as last_severity, f.notes as findings_notes, f.created_at as findings_date
              FROM reports r
              INNER JOIN report_inspectors ri ON r.id = ri.report_id
              INNER JOIN users u ON r.user_id = u.id
              LEFT JOIN inspection_findings f ON r.id = f.report_id 
                AND f.created_at = (SELECT MAX(created_at) FROM inspection_findings WHERE report_id = r.id)
              WHERE ri.inspector_id = ?
              ORDER BY 
                CASE r.status
                    WHEN 'in_progress' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'done' THEN 3
                    WHEN 'escalated' THEN 4
                END,
                r.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$inspector_id]);
    $reports = $stmt->fetchAll();
    
    // Calculate statistics
    $stats['total'] = count($reports);
    foreach ($reports as $report) {
        if (isset($stats[$report['status']])) {
            $stats[$report['status']]++;
        }
    }
} catch (PDOException $e) {
    error_log("Fetch reports error: " . $e->getMessage());
    $reports = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conduct Inspection - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-in_progress { border-left: 4px solid #3b82f6; }
        .status-done { border-left: 4px solid #10b981; }
        .status-escalated { border-left: 4px solid #ef4444; }
        
        .badge-pending { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .badge-in_progress { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .badge-done { background: linear-gradient(135deg, #10b981, #059669); }
        .badge-escalated { background: linear-gradient(135deg, #ef4444, #dc2626); }
        
        .filter-btn {
            background-color: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        
        .filter-btn:hover {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .filter-btn.active {
            background-color: #faae2b;
            color: #00473e;
            border-color: #faae2b;
        }
        
        .report-card.hidden {
            display: none;
        }
        
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Map styles */
        .leaflet-container {
            height: 100%;
            width: 100%;
            border-radius: 0.5rem;
            z-index: 1 !important;
        }
        
        .leaflet-control-container {
            z-index: 2 !important;
        }
        
        [id^="reportMap_"] {
            height: 192px !important;
            min-height: 192px;
            width: 100%;
            z-index: 1;
        }
        
        .custom-hazard-marker {
            background: transparent !important;
            border: none !important;
        }
        
        .custom-popup .leaflet-popup-content-wrapper {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .custom-popup .leaflet-popup-tip {
            background: white;
        }
        
        /* Ensure SweetAlert appears above everything */
        .swal2-container {
            z-index: 99999 !important;
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Include Inspector Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Conduct Inspections</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage and complete your assigned inspection reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $stats['total']; ?></div>
                    <div class="text-xs">Total Reports</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-check-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Success</p>
                        <p class="text-sm"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex flex-col sm:flex-row gap-4 items-center">
                    <div class="flex items-center gap-2">
                        <i class="fa fa-filter text-lgu-button"></i>
                        <span class="font-semibold text-lgu-headline">Filter:</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button onclick="filterReports('all')" class="filter-btn active px-4 py-2 rounded-lg font-medium transition" data-type="all">
                            <i class="fa fa-list mr-1"></i>All Reports
                        </button>
                        <button onclick="filterReports('road')" class="filter-btn px-4 py-2 rounded-lg font-medium transition" data-type="road">
                            <i class="fa fa-road mr-1"></i>Road
                        </button>
                        <button onclick="filterReports('traffic')" class="filter-btn px-4 py-2 rounded-lg font-medium transition" data-type="traffic">
                            <i class="fa fa-traffic-light mr-1"></i>Traffic
                        </button>
                        <button onclick="filterReports('bridge')" class="filter-btn px-4 py-2 rounded-lg font-medium transition" data-type="bridge">
                            <i class="fa fa-water mr-1"></i>Bridge
                        </button>
                        <button onclick="filterReports('completed')" class="filter-btn px-4 py-2 rounded-lg font-medium transition" data-type="completed">
                            <i class="fa fa-check-double mr-1"></i>Completed
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stats Section -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Reports</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo $stats['total']; ?></p>
                        </div>
                        <i class="fa fa-clipboard-list text-3xl text-lgu-button opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Pending</p>
                            <p class="text-2xl font-bold text-orange-600"><?php echo $stats['pending']; ?></p>
                        </div>
                        <i class="fa fa-clock text-3xl text-orange-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $stats['in_progress']; ?></p>
                        </div>
                        <i class="fa fa-spinner text-3xl text-blue-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $stats['done']; ?></p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Escalated</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo $stats['escalated']; ?></p>
                        </div>
                        <i class="fa fa-exclamation-triangle text-3xl text-red-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <!-- Reports Grid -->
            <?php if (count($reports) > 0): ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($reports as $report): 
                    $status_class = 'status-' . $report['status'];
                    $has_findings = !empty($report['findings_notes']);
                ?>
                <div class="report-card bg-white rounded-lg shadow-md overflow-hidden card-hover <?php echo $status_class; ?>" data-hazard-type="<?php echo htmlspecialchars($report['hazard_type']); ?>" data-has-findings="<?php echo $has_findings ? 'true' : 'false'; ?>">
                    <div class="p-6">
                        <!-- Header -->
                        <div class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-xl font-bold text-lgu-headline">
                                        Report #<?php echo $report['id']; ?>
                                    </h3>
                                    <?php if ($report['status'] === 'pending'): ?>
                                        <span class="badge-pending text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                            <span class="w-2 h-2 bg-white rounded-full mr-2 animate-pulse"></span>
                                            Pending
                                        </span>
                                    <?php elseif ($report['status'] === 'in_progress'): ?>
                                        <span class="badge-in_progress text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                            <i class="fa fa-spinner mr-1 fa-spin"></i>
                                            In Progress
                                        </span>
                                    <?php elseif ($report['status'] === 'done'): ?>
                                        <span class="badge-done text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                            <i class="fa fa-check-circle mr-1"></i>
                                            Completed
                                        </span>
                                    <?php elseif ($report['status'] === 'escalated'): ?>
                                        <span class="badge-escalated text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                            <i class="fa fa-exclamation-triangle mr-1"></i>
                                            Escalated
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-lgu-paragraph">
                                    <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-xs">
                                        <?php echo strtoupper(substr($report['reporter_name'], 0, 1)); ?>
                                    </div>
                                    <span class="font-semibold"><?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                    <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">
                                        <i class="fa fa-user-check mr-1"></i>Assigned to You
                                    </span>
                                </div>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 whitespace-nowrap">
                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                <?php echo ucfirst($report['hazard_type']); ?>
                            </span>
                        </div>

                        <!-- Inspection Findings -->
                        <?php if ($has_findings): ?>
                        <div class="mb-4 p-4 bg-green-50 rounded-lg border-l-4 border-green-500 hidden">
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-clipboard-check mr-2"></i> 
                                Inspection Findings (<?php echo ucfirst($report['last_severity']); ?>)
                                <?php if ($report['findings_date']): ?>
                                    <span class="text-xs text-lgu-paragraph ml-2">
                                        - <?php echo date('M d, Y h:i A', strtotime($report['findings_date'])); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars($report['findings_notes']); ?></p>
                        </div>
                        <?php endif; ?>

                        <!-- Details Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-xs font-semibold text-lgu-headline mb-1 flex items-center">
                                    <i class="fa fa-map-marker-alt mr-2 text-lgu-tertiary"></i> Location
                                </p>
                                <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars($report['address']); ?></p>
                                <?php if (!empty($report['landmark'])): ?>
                                <p class="text-xs text-gray-600 mt-1 flex items-center">
                                    <i class="fa fa-landmark mr-1"></i> <?php echo htmlspecialchars($report['landmark']); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-xs font-semibold text-lgu-headline mb-1 flex items-center">
                                    <i class="fa fa-phone mr-2 text-green-600"></i> Contact
                                </p>
                                <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars($report['reporter_contact']); ?></p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-xs font-semibold text-lgu-headline mb-1 flex items-center">
                                    <i class="fa fa-calendar mr-2 text-blue-600"></i> Reported
                                </p>
                                <p class="text-sm text-lgu-paragraph"><?php echo date('M d, Y h:i A', strtotime($report['created_at'])); ?></p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-xs font-semibold text-lgu-headline mb-1 flex items-center">
                                    <i class="fa fa-tasks mr-2 text-orange-600"></i> Assigned
                                </p>
                                <p class="text-sm text-lgu-paragraph"><?php echo date('M d, Y h:i A', strtotime($report['assigned_at'])); ?></p>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-4 bg-blue-50 rounded-lg p-4 border-l-4 border-blue-500">
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-file-alt mr-2"></i> Description
                            </p>
                            <p class="text-sm text-lgu-paragraph leading-relaxed"><?php echo htmlspecialchars($report['description']); ?></p>
                        </div>
                        
                        <!-- Location Map -->
                        <div class="mb-4 bg-white rounded-lg p-4 border border-gray-200">
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-map mr-2 text-lgu-button"></i> Location Map
                            </p>
                            <div id="reportMap_<?php echo $report['id']; ?>" class="w-full h-48 rounded-lg border border-gray-300"></div>
                            <p class="text-xs text-lgu-paragraph mt-1">Red marker shows hazard location</p>
                        </div>

                        <!-- AI Analysis Section -->
                        <?php if (!empty($report['ai_analysis_result'])): ?>
                        <div class="mb-4 bg-white rounded-lg shadow-md p-4 border-l-4 border-purple-500">
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-robot mr-2 text-purple-600"></i> Beripikado AI Analysis
                            </p>
                            
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
                                    <p class="text-sm text-lgu-paragraph leading-relaxed"><?php echo nl2br(htmlspecialchars($report['ai_analysis_result'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Media (Image/Video) -->
                        <?php if ($report['image_path']): ?>
                        <div class="mb-4">
                            <?php 
                            $file_path = $report['image_path'];
                            $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                            $is_video = in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'ogv']);
                            ?>
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-<?php echo $is_video ? 'video' : 'image'; ?> mr-2"></i> <?php echo $is_video ? 'Evidence Video' : 'Evidence Photo'; ?>
                            </p>
                            <div class="flex items-center gap-3">
                                <?php if ($is_video): ?>
                                    <video controls class="rounded-lg h-16 w-auto object-cover cursor-pointer hover:opacity-90 transition shadow-md border-2 border-gray-200" style="max-height: 64px;">
                                        <source src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>">
                                        Your browser does not support the video tag.
                                    </video>
                                    <div class="text-xs text-lgu-paragraph">
                                        <p class="font-medium">Video Evidence</p>
                                        <p class="text-gray-500">Video file attached</p>
                                    </div>
                                <?php else: ?>
                                    <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>" 
                                         alt="Report Image" 
                                         class="rounded-lg h-16 w-16 object-cover cursor-pointer hover:opacity-90 transition shadow-md border-2 border-gray-200"
                                         onclick="openImageModal('../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>')">
                                    <div class="text-xs text-lgu-paragraph">
                                        <p class="font-medium">Click to view full size</p>
                                        <p class="text-gray-500">Evidence photo attached</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Assignment Notes -->
                        <?php if ($report['assignment_notes']): ?>
                        <div class="mb-4 p-4 bg-yellow-50 rounded-lg border-l-4 border-yellow-500">
                            <p class="text-xs font-semibold text-lgu-headline mb-2 flex items-center">
                                <i class="fa fa-sticky-note mr-2"></i> Assignment Notes
                            </p>
                            <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars($report['assignment_notes']); ?></p>
                        </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-3 mt-6 pt-4 border-t border-gray-200">
                            <?php if (!$has_findings && ($report['status'] === 'pending' || $report['status'] === 'in_progress')): ?>
                            <button onclick="openFindingsModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['hazard_type']); ?>', '<?php echo htmlspecialchars($report['reporter_name']); ?>')" 
                                    class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-6 py-3 rounded-lg font-bold transition flex items-center shadow-md hover:shadow-lg">
                                <i class="fa fa-clipboard-check mr-2"></i> Submit Findings
                            </button>
                            <?php elseif ($has_findings): ?>
                            <span class="text-green-600 font-bold flex items-center px-4 py-2 bg-green-50 rounded-lg">
                                <i class="fa fa-check-double mr-2"></i> Findings Submitted
                            </span>
                            <button onclick="openFindingsModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['hazard_type']); ?>', '<?php echo htmlspecialchars($report['reporter_name']); ?>')" 
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-bold transition flex items-center shadow-md hover:shadow-lg">
                                <i class="fa fa-edit mr-2"></i> Update Findings
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg shadow-md p-12 text-center">
                <i class="fa fa-clipboard-check text-6xl text-gray-300 mb-4 block"></i>
                <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Assigned Reports</h3>
                <p class="text-lgu-paragraph mb-4">You don't have any reports assigned to you at the moment.</p>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 max-w-md mx-auto">
                    <i class="fa fa-info-circle text-blue-500 text-lg mb-2 block"></i>
                    <p class="text-blue-700 text-sm">Reports will appear here once they are assigned to you by an administrator.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- DONE Section - Reports with Findings Submitted -->
            <?php 
            $completed_reports = array_filter($reports, function($r) {
                return !empty($r['findings_notes']);
            });
            ?>
            <?php if (count($completed_reports) > 0): ?>
            <div class="mt-12 pt-8 border-t-2 border-gray-300 hidden" data-completed-section>
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-600 rounded-lg flex items-center justify-center shadow-md">
                        <i class="fa fa-check-double text-2xl text-white"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-lgu-headline">Completed Inspections</h2>
                        <p class="text-sm text-lgu-paragraph">Reports with findings already submitted</p>
                    </div>
                    <span class="ml-auto bg-green-100 text-green-800 px-4 py-2 rounded-full font-bold text-lg">
                        <?php echo count($completed_reports); ?>
                    </span>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($completed_reports as $report): ?>
                    <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500 hover:shadow-lg transition">
                        <div class="flex flex-col sm:flex-row justify-between items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <h3 class="text-lg font-bold text-lgu-headline">Report #<?php echo $report['id']; ?></h3>
                                    <span class="badge-done text-white px-2 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                        <i class="fa fa-check-circle mr-1"></i>Completed
                                    </span>
                                </div>
                                <p class="text-sm text-lgu-paragraph mb-2"><?php echo htmlspecialchars($report['reporter_name']); ?> - <?php echo htmlspecialchars($report['address']); ?></p>
                                <div class="bg-green-50 p-3 rounded-lg border-l-4 border-green-500">
                                    <p class="text-xs font-semibold text-lgu-headline mb-1 flex items-center">
                                        <i class="fa fa-clipboard-check mr-2"></i>Findings (<?php echo ucfirst($report['last_severity']); ?>)
                                    </p>
                                    <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars(substr($report['findings_notes'], 0, 150)); ?><?php echo strlen($report['findings_notes']) > 150 ? '...' : ''; ?></p>
                                </div>
                            </div>
                            <div class="flex gap-2 flex-shrink-0">
                                <a href="view_report.php?id=<?php echo $report['id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-sm font-bold transition flex items-center">
                                    <i class="fa fa-eye mr-1"></i>View
                                </a>
                                <button onclick="openFindingsModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['hazard_type']); ?>', '<?php echo htmlspecialchars($report['reporter_name']); ?>')" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded-lg text-sm font-bold transition flex items-center">
                                    <i class="fa fa-edit mr-1"></i>Edit
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
                
            </div>
        </footer>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 hidden">
        <div class="max-w-4xl max-h-full p-4">
            <div class="relative">
                <button onclick="closeImageModal()" class="absolute -top-12 right-0 text-white text-2xl hover:text-gray-300">
                    <i class="fa fa-times"></i>
                </button>
                <img id="modalImage" src="" alt="Enlarged view" class="max-w-full max-h-screen rounded-lg">
            </div>
        </div>
    </div>

    <!-- Submit Findings Modal -->
    <div id="findingsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9999] flex items-center justify-center p-4">
        <div class="bg-white rounded-lg max-w-md w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-lgu-button rounded-full flex items-center justify-center mr-4">
                    <i class="fa fa-clipboard-check text-2xl text-lgu-button-text"></i>
                </div>
                <h3 class="text-xl font-bold text-lgu-headline">Submit Inspection Findings</h3>
            </div>
            
            <form method="POST" id="findingsForm">
                <input type="hidden" name="report_id" id="findings_report_id">
                <input type="hidden" name="action" value="submit_findings">
                
                <!-- Report Information -->
                <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                    <h4 class="font-semibold text-lgu-headline mb-2">Report Information</h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-lgu-paragraph font-medium">Report ID:</span>
                            <span class="font-semibold" id="modal_report_id"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-lgu-paragraph font-medium">Hazard Type:</span>
                            <span class="font-semibold" id="modal_hazard_type"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-lgu-paragraph font-medium">Reporter:</span>
                            <span class="font-semibold" id="modal_reporter_name"></span>
                        </div>
                    </div>
                </div>
                
                <!-- Severity Selection -->
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">
                        Severity Assessment <span class="text-red-500">*</span>
                    </label>
                    <select name="severity" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button focus:border-transparent">
                        <option value="">Select Severity</option>
                        <option value="minor">Minor - Can be handled routinely</option>
                        <option value="major">Major - Requires escalation</option>
                    </select>
                    <p class="text-xs text-lgu-paragraph mt-1">
                        <span class="font-semibold">Minor:</span> Routine maintenance needed<br>
                        <span class="font-semibold">Major:</span> Serious issue requiring immediate attention
                    </p>
                </div>
                
                <!-- Findings Notes -->
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">
                        Inspection Findings <span class="text-red-500">*</span>
                    </label>
                    <textarea name="findings_notes" 
                              rows="4" 
                              required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button focus:border-transparent"
                              placeholder="Describe your inspection findings, observations, and recommendations..."></textarea>
                </div>
                
                <div class="flex space-x-3">
                    <button type="submit" 
                            class="flex-1 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-4 py-3 rounded-lg font-bold transition shadow-md hover:shadow-lg">
                        <i class="fa fa-paper-plane mr-2"></i> Submit Findings
                    </button>
                    <button type="button" 
                            onclick="closeFindingsModal()" 
                            class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-3 rounded-lg font-bold transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebar = document.getElementById('inspector-sidebar');
            const toggle = document.getElementById('mobile-sidebar-toggle');
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
                });
            }

            // Show success message with SweetAlert
            <?php if (isset($_SESSION['success_message'])): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: '<?php echo addslashes($_SESSION['success_message']); ?>',
                    confirmButtonColor: '#10b981',
                    timer: 3000,
                    timerProgressBar: true
                });
            <?php endif; ?>

            // Add SweetAlert confirmation for form submission
            const findingsForm = document.getElementById('findingsForm');
            if (findingsForm) {
                findingsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const severity = document.querySelector('select[name="severity"]').value;
                    const notes = document.querySelector('textarea[name="findings_notes"]').value;
                    
                    if (!severity || !notes) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Missing Information',
                            text: 'Please fill in all required fields.',
                            confirmButtonColor: '#f59e0b'
                        });
                        return;
                    }
                    
                    const severityText = severity === 'major' ? 'Major (Will be escalated)' : 'Minor';
                    
                    Swal.fire({
                        title: 'Confirm Submission',
                        html: `
                            <div class="text-left">
                                <p class="mb-2"><strong>Severity:</strong> ${severityText}</p>
                                <p class="mb-2"><strong>Findings:</strong></p>
                                <p class="text-sm bg-gray-100 p-2 rounded">${notes.substring(0, 200)}${notes.length > 200 ? '...' : ''}</p>
                            </div>
                        `,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Submit Findings',
                        cancelButtonText: 'Review Again',
                        confirmButtonColor: '#faae2b',
                        cancelButtonColor: '#475d5b'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            findingsForm.submit();
                        }
                    });
                });
            }
        });

        function openFindingsModal(reportId, hazardType, reporterName) {
            document.getElementById('findings_report_id').value = reportId;
            document.getElementById('modal_report_id').textContent = '#' + reportId;
            document.getElementById('modal_hazard_type').textContent = hazardType;
            document.getElementById('modal_reporter_name').textContent = reporterName;
            document.getElementById('findingsModal').classList.remove('hidden');
        }

        function closeFindingsModal() {
            document.getElementById('findingsModal').classList.add('hidden');
            document.getElementById('findingsForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('findingsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFindingsModal();
            }
        });

        // Image modal functions
        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
        }

        // Close image modal when clicking outside image
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageModal();
            }
        });

        // Close image modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });
        
        // Filter functionality
        function filterReports(type) {
            const reportCards = document.querySelectorAll('.report-card');
            const completedSection = document.querySelector('[data-completed-section]');
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterBtns.forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('data-type') === type) {
                    btn.classList.add('active');
                }
            });
            
            if (type === 'completed') {
                reportCards.forEach(card => card.classList.add('hidden'));
                if (completedSection) completedSection.classList.remove('hidden');
            } else {
                if (completedSection) completedSection.classList.add('hidden');
                reportCards.forEach(card => {
                    const hazardType = card.getAttribute('data-hazard-type');
                    if (type === 'all') {
                        card.classList.remove('hidden');
                    } else if (hazardType === type) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            }
        }
        
        // Initialize maps for all reports
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        const reportMaps = {};
        
        // Initialize maps with delay to ensure DOM is ready
        setTimeout(() => {
            <?php foreach ($reports as $report): ?>
            initReportMap(<?php echo $report['id']; ?>, <?php echo json_encode($report['address']); ?>, <?php echo json_encode($report['hazard_type']); ?>, <?php echo json_encode($report['landmark'] ?? ''); ?>);
            <?php endforeach; ?>
        }, 100);
        
        function initReportMap(reportId, address, hazardType, landmark) {
            const mapId = 'reportMap_' + reportId;
            const mapElement = document.getElementById(mapId);
            
            if (!mapElement || !address) {
                console.warn(`Map element not found or no address for report ${reportId}`);
                return;
            }
            
            try {
                // Initialize map with Philippines center
                const map = L.map(mapId, {
                    zoomControl: true,
                    scrollWheelZoom: false
                }).setView([14.5995, 120.9842], 12);
                
                // Add OpenStreetMap tile layer as fallback
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(map);
                
                reportMaps[reportId] = map;
                
                // Force map resize after initialization
                setTimeout(() => {
                    map.invalidateSize();
                    geocodeAndMarkLocation(map, address, hazardType, landmark);
                }, 200);
                
            } catch (error) {
                console.error(`Error initializing map for report ${reportId}:`, error);
                mapElement.innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 text-gray-500"><i class="fa fa-map-marker-alt mr-2"></i>Map unavailable</div>';
            }
        }
        
        async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
            try {
                // Try TomTom API first
                let response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                let data = await response.json();
                
                // Fallback to Nominatim if TomTom fails
                if (!data.results || data.results.length === 0) {
                    response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address + ', Philippines')}&limit=1`);
                    const nominatimData = await response.json();
                    
                    if (nominatimData && nominatimData.length > 0) {
                        data = {
                            results: [{
                                position: {
                                    lat: parseFloat(nominatimData[0].lat),
                                    lon: parseFloat(nominatimData[0].lon)
                                },
                                address: {
                                    freeformAddress: nominatimData[0].display_name
                                }
                            }]
                        };
                    }
                }
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    // Add hazard marker with custom icon
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-hazard-marker',
                            html: '<div style="background: #ef4444; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-sm"></i></div>',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        })
                    }).addTo(map);
                    
                    // Create detailed popup content
                    let popupContent = `
                        <div class="p-3 min-w-[200px]">
                            <h4 class="font-bold text-red-600 mb-2 flex items-center">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Hazard Location
                            </h4>
                            <div class="space-y-1 text-sm">
                                <p><strong>Type:</strong> <span class="text-red-600">${hazardType}</span></p>
                                <p><strong>Address:</strong> ${address}</p>
                    `;
                    
                    if (landmark && landmark.trim()) {
                        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `
                            </div>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent, {
                        maxWidth: 300,
                        className: 'custom-popup'
                    });
                    
                    // Center map on location with appropriate zoom
                    map.setView([lat, lng], 16);
                    
                    // Open popup by default
                    marker.openPopup();
                    
                } else {
                    // If geocoding fails, show address as text overlay
                    const mapContainer = map.getContainer();
                    const overlay = document.createElement('div');
                    overlay.className = 'absolute inset-0 bg-gray-100 bg-opacity-90 flex items-center justify-center text-center p-4';
                    overlay.innerHTML = `
                        <div>
                            <i class="fa fa-map-marker-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm font-semibold text-gray-600">Location: ${address}</p>
                            <p class="text-xs text-gray-500 mt-1">Unable to show on map</p>
                        </div>
                    `;
                    mapContainer.style.position = 'relative';
                    mapContainer.appendChild(overlay);
                }
                
            } catch (error) {
                console.error('Geocoding error:', error);
                // Show error message on map
                const mapContainer = map.getContainer();
                const errorOverlay = document.createElement('div');
                errorOverlay.className = 'absolute inset-0 bg-red-50 bg-opacity-90 flex items-center justify-center text-center p-4';
                errorOverlay.innerHTML = `
                    <div>
                        <i class="fa fa-exclamation-triangle text-2xl text-red-400 mb-2"></i>
                        <p class="text-sm font-semibold text-red-600">Map Error</p>
                        <p class="text-xs text-red-500 mt-1">${address}</p>
                    </div>
                `;
                mapContainer.style.position = 'relative';
                mapContainer.appendChild(errorOverlay);
            }
        }
    </script>

</body>
</html>