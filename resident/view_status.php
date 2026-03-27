<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../includes/bootstrap.php';

// Debug session information
error_log("=== RESIDENT STATUS VIEW ===");
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Session user_role: " . ($_SESSION['user_role'] ?? 'not set'));

// Only allow residents
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'resident') {
    error_log("Access denied - Redirecting to login");
    header("Location: login.php");
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

$reports = [];
$error_message = '';

try {
    // Get all reports for the current resident user with maintenance information
    $user_id = (int)$_SESSION['user_id'];
    
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
            ma.completion_image_path,
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
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
    ";
    
    error_log("Executing reports query for user_id: $user_id");
    $stmt = $pdo->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . implode(", ", $pdo->errorInfo()));
    }
    
    if (!$stmt->execute([$user_id])) {
        throw new Exception("Failed to execute query: " . implode(", ", $stmt->errorInfo()));
    }
    
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Successfully fetched " . count($reports) . " reports for resident");

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching your reports: " . $e->getMessage();
}

// Debug final state
error_log("=== FINAL STATE ===");
error_log("Reports count: " . count($reports));
error_log("Error message: " . ($error_message ?: 'None'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports Status - RTIM Resident</title>
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
        
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.5s ease;
        }
        
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
        
        .team-badge-road { background-color: #dc2626; color: white; }
        .team-badge-traffic { background-color: #ea580c; color: white; }
        .team-badge-bridge { background-color: #7c3aed; color: white; }
        
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
        
        /* Mobile sidebar styles */
        @media (max-width: 1023px) {
            .sidebar-collapsed {
                transform: translateX(-100%);
            }
            .sidebar-expanded {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar - Now included from external file -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="lg:ml-64 min-h-screen">

        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center space-x-4">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline hover:text-lgu-highlight">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-lgu-headline">My Reports Status</h1>
                        <p class="text-sm text-lgu-paragraph">Track the progress of your submitted reports</p>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-4 py-2 rounded-lg font-bold text-center shadow-lg">
                    <div class="text-2xl"><?php echo count($reports); ?></div>
                    <div class="text-xs">My Reports</div>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            
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

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-exclamation-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Error</p>
                        <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex flex-col sm:flex-row gap-4 items-center">
                    <div class="flex items-center gap-2">
                        <i class="fa fa-filter text-lgu-button"></i>
                        <span class="font-semibold text-lgu-headline">Filter by Hazard Type:</span>
                    </div>
                    <button onclick="showStatusGuide()" class="bg-lgu-button text-lgu-button-text px-3 py-1 rounded text-sm font-medium hover:bg-yellow-500 transition">
                        <i class="fa fa-info-circle mr-1"></i>Status Guide
                    </button>
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
                    </div>
                </div>
            </div>

            <!-- Stats Section -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Reports</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo count($reports); ?></p>
                        </div>
                        <i class="fa fa-file-alt text-3xl text-lgu-button opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Pending Validation</p>
                            <p class="text-2xl font-bold text-orange-600">
                                <?php 
                                $pending_validation = count(array_filter($reports, function($r) { 
                                    return $r['validation_status'] === 'pending'; 
                                }));
                                echo $pending_validation;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-clock text-3xl text-orange-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600">
                                <?php 
                                $in_progress = count(array_filter($reports, function($r) { 
                                    return $r['status'] === 'in_progress'; 
                                }));
                                echo $in_progress;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-spinner text-3xl text-blue-500 opacity-50 fa-spin"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                            <p class="text-2xl font-bold text-green-600">
                                <?php 
                                $completed = count(array_filter($reports, function($r) { 
                                    return $r['status'] === 'done'; 
                                }));
                                echo $completed;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <!-- Reports Cards -->
            <div class="space-y-6">
                <?php if (count($reports) === 0): ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fa fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Reports Submitted</p>
                        <p class="text-gray-400 text-sm mb-6">You haven't submitted any reports yet.</p>
                        <a href="submit_report.php" class="inline-block bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-6 py-3 rounded-lg font-semibold transition">
                            <i class="fa fa-plus mr-2"></i>Submit Your First Report
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): 
                        $status_class = 'status-' . $report['status'];
                        $is_assigned = !empty($report['inspector_id']);
                        $is_completed = $report['status'] === 'done';
                        $has_maintenance = !empty($report['maintenance_id']);
                        
                        // Calculate progress
                        $progress = 0;
                        if ($report['validation_status'] === 'validated') $progress = 25;
                        if ($report['status'] === 'in_progress') $progress = 50;
                        if ($has_maintenance) $progress = 75;
                        if ($report['status'] === 'done') $progress = 100;
                        if ($report['validation_status'] === 'rejected') $progress = 0;
                        
                        // Team badge class
                        $team_badge_class = '';
                        if (!empty($report['team_type'])) {
                            switch($report['team_type']) {
                                case 'road_maintenance':
                                    $team_badge_class = 'team-badge-road';
                                    break;
                                case 'traffic_management':
                                    $team_badge_class = 'team-badge-traffic';
                                    break;
                                case 'bridge_maintenance':
                                    $team_badge_class = 'team-badge-bridge';
                                    break;
                            }
                        }
                    ?>
                        <div class="report-card bg-white rounded-lg shadow-md card-hover <?php echo $status_class; ?>" data-hazard-type="<?php echo htmlspecialchars($report['hazard_type']); ?>">
                            <div class="p-6">
                                <!-- Header Section -->
                                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h3 class="text-lg font-bold text-lgu-headline">
                                                Report #<?php echo htmlspecialchars($report['report_id']); ?>
                                            </h3>
                                            <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                            </span>
                                            <?php 
                                            $ai_data = !empty($report['ai_analysis_result']) ? json_decode($report['ai_analysis_result'], true) : null;
                                            if ($ai_data && isset($ai_data['hazardLevel'])): 
                                              $hazard_level = strtolower($ai_data['hazardLevel']);
                                              $level_colors = [
                                                'low' => 'bg-green-100 text-green-800 border-green-300',
                                                'medium' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                                'high' => 'bg-red-100 text-red-800 border-red-300'
                                              ];
                                              $color_class = $level_colors[$hazard_level] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                                            ?>
                                              <span class="inline-block px-3 py-1 rounded-full text-xs font-bold border <?= $color_class ?>">
                                                <?= htmlspecialchars($ai_data['hazardLevel']) ?>
                                              </span>
                                            <?php endif; ?>
                                            <?php if (!empty($report['team_type'])): ?>
                                                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold text-white <?php echo $team_badge_class; ?>">
                                                    <?php 
                                                    $team_name = str_replace('_', ' ', $report['team_type']);
                                                    echo ucwords($team_name);
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-lgu-paragraph">
                                            <i class="fa fa-calendar mr-1"></i>
                                            Submitted on <?php echo date('F j, Y g:i A', strtotime($report['created_at'])); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-2">
                                        <!-- Validation Status Badge -->
                                        <span class="text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center
                                            <?php echo $report['validation_status'] === 'validated' ? 'validation-validated' : 
                                                   ($report['validation_status'] === 'rejected' ? 'validation-rejected' : 'validation-pending'); ?>">
                                            <i class="fa <?php echo $report['validation_status'] === 'validated' ? 'fa-check' : 
                                                                  ($report['validation_status'] === 'rejected' ? 'fa-times' : 'fa-clock'); ?> mr-1"></i>
                                            <?php echo ucfirst($report['validation_status']); ?>
                                        </span>
                                        
                                        <!-- Report Status Badge -->
                                        <?php if ($report['status'] === 'pending'): ?>
                                            <span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                <span class="w-2 h-2 bg-orange-500 rounded-full mr-2"></span>
                                                Pending
                                            </span>
                                        <?php elseif ($report['status'] === 'in_progress'): ?>
                                            <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                <i class="fa fa-spinner mr-1 fa-spin"></i>
                                                In Progress
                                            </span>
                                        <?php elseif ($report['status'] === 'done'): ?>
                                            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                <i class="fa fa-check-circle mr-1"></i>
                                                Completed
                                            </span>
                                        <?php elseif ($report['status'] === 'escalated'): ?>
                                            <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                                Escalated
                                            </span>
                                        <?php endif; ?>
                                        
                                        <!-- Maintenance Status Badge -->
                                        <?php if ($has_maintenance): ?>
                                            <span class="text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center
                                                <?php echo $report['maintenance_status'] === 'assigned' ? 'maintenance-assigned' : 
                                                       ($report['maintenance_status'] === 'in_progress' ? 'maintenance-in_progress' : 
                                                       ($report['maintenance_status'] === 'completed' ? 'maintenance-completed' : 'maintenance-cancelled')); ?>">
                                                <i class="fa <?php echo $report['maintenance_status'] === 'completed' ? 'fa-check-circle' : 
                                                                      ($report['maintenance_status'] === 'in_progress' ? 'fa-tools' : 'fa-user-cog'); ?> mr-1"></i>
                                                Maintenance: <?php echo ucfirst(str_replace('_', ' ', $report['maintenance_status'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="mb-4">
                                    <div class="flex justify-between text-xs text-lgu-paragraph mb-1">
                                        <span>Report Progress</span>
                                        <span><?php echo $progress; ?>%</span>
                                    </div>
                                    <div class="progress-bar bg-gray-200">
                                        <div class="progress-fill bg-lgu-button" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                </div>

                                <!-- Details Section -->
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-4">
                                    <!-- Report Details -->
                                    <div>
                                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                            <i class="fa fa-info-circle mr-2 text-lgu-button"></i>
                                            Report Details
                                        </h4>
                                        <div class="space-y-2 text-sm">
                                            <div>
                                                <span class="font-medium text-lgu-paragraph">Hazard Type:</span>
                                                <p class="text-gray-700"><?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?></p>
                                            </div>
                                            <div>
                                                <span class="font-medium text-lgu-paragraph">Location:</span>
                                                <p class="text-gray-700"><?php echo htmlspecialchars($report['address']); ?></p>
                                                <?php if (!empty($report['landmark'])): ?>
                                                <p class="text-gray-600 text-xs mt-1">
                                                    <i class="fa fa-landmark mr-1"></i> <?php echo htmlspecialchars($report['landmark']); ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <span class="font-medium text-lgu-paragraph">Description:</span>
                                                <p class="text-gray-700"><?php echo htmlspecialchars($report['description']); ?></p>
                                            </div>
                                            <?php if (!empty($report['contact_number'])): ?>
                                                <div>
                                                    <span class="font-medium text-lgu-paragraph">Contact:</span>
                                                    <p class="text-gray-700"><?php echo htmlspecialchars($report['contact_number']); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Assignment Details -->
                                    <div>
                                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                            <i class="fa fa-user-shield mr-2 text-lgu-button"></i>
                                            Inspection Details
                                        </h4>
                                        <div class="space-y-2 text-sm">
                                            <?php if ($is_assigned && !empty($report['assigned_inspector_name'])): ?>
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold">
                                                        <?php echo strtoupper(substr($report['assigned_inspector_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($report['assigned_inspector_name']); ?></p>
                                                        <p class="text-gray-600 text-xs">
                                                            Assigned on <?php echo date('M j, Y', strtotime($report['assigned_at'])); ?>
                                                        </p>
                                                        <?php if (!empty($report['inspector_contact'])): ?>
                                                            <p class="text-gray-600 text-xs">
                                                                <i class="fa fa-phone mr-1"></i><?php echo htmlspecialchars($report['inspector_contact']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($report['inspector_notes'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Inspector Notes:</span>
                                                        <p class="text-gray-700 bg-yellow-50 p-2 rounded text-xs mt-1">
                                                            <?php echo htmlspecialchars($report['inspector_notes']); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($is_completed && !empty($report['completed_at'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Inspection Completed:</span>
                                                        <p class="text-gray-700">
                                                            <?php echo date('F j, Y g:i A', strtotime($report['completed_at'])); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="text-gray-500 text-sm italic">
                                                    <?php if ($report['validation_status'] === 'pending'): ?>
                                                        Waiting for validation before assignment
                                                    <?php elseif ($report['validation_status'] === 'rejected'): ?>
                                                        Report was rejected and cannot be assigned
                                                    <?php else: ?>
                                                        Awaiting inspector assignment
                                                    <?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Maintenance Details -->
                                    <div>
                                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                            <i class="fa fa-tools mr-2 text-lgu-button"></i>
                                            Maintenance Details
                                        </h4>
                                        <div class="space-y-2 text-sm">
                                            <?php if ($has_maintenance && !empty($report['maintenance_assignee_name'])): ?>
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center text-white font-bold">
                                                        <?php echo strtoupper(substr($report['maintenance_assignee_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($report['maintenance_assignee_name']); ?></p>
                                                        <p class="text-gray-600 text-xs">Maintenance Personnel</p>
                                                        <?php if (!empty($report['maintenance_contact'])): ?>
                                                            <p class="text-gray-600 text-xs">
                                                                <i class="fa fa-phone mr-1"></i><?php echo htmlspecialchars($report['maintenance_contact']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($report['completion_deadline'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Deadline:</span>
                                                        <p class="text-gray-700">
                                                            <?php echo date('F j, Y g:i A', strtotime($report['completion_deadline'])); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($report['maintenance_notes'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Maintenance Notes:</span>
                                                        <p class="text-gray-700 bg-blue-50 p-2 rounded text-xs mt-1">
                                                            <?php echo htmlspecialchars($report['maintenance_notes']); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($report['maintenance_started'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Work Started:</span>
                                                        <p class="text-gray-700">
                                                            <?php echo date('F j, Y g:i A', strtotime($report['maintenance_started'])); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($report['maintenance_completed'])): ?>
                                                    <div class="mt-2">
                                                        <span class="font-medium text-lgu-paragraph">Work Completed:</span>
                                                        <p class="text-gray-700">
                                                            <?php echo date('F j, Y g:i A', strtotime($report['maintenance_completed'])); ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="text-gray-500 text-sm italic">
                                                    <?php if ($report['validation_status'] === 'pending' || $report['validation_status'] === 'rejected'): ?>
                                                        Maintenance assignment pending validation
                                                    <?php elseif (empty($report['forwarded_team_type'])): ?>
                                                        Awaiting team assignment
                                                    <?php else: ?>
                                                        Maintenance team not yet assigned
                                                    <?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Location Map -->
                                <div class="mt-4">
                                    <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                        <i class="fa fa-map mr-2 text-lgu-button"></i>
                                        Location Map
                                    </h4>
                                    <div id="reportMap_<?php echo $report['report_id']; ?>" class="w-full h-48 rounded-lg border border-gray-300"></div>
                                    <p class="text-xs text-lgu-paragraph mt-1">Red marker shows hazard location</p>
                                </div>
                                
                                <!-- Image Preview -->
                                <?php if (!empty($report['image_path'])): ?>
                                    <div class="mt-4">
                                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                            <i class="fa fa-image mr-2 text-lgu-button"></i>
                                            Attached Media
                                        </h4>
                                        <div class="max-w-xs">
                                            <?php
                                            $image_src = $report['image_path'];
                                            if (substr($image_src, 0, 4) !== 'http' && substr($image_src, 0, 1) !== '/') {
                                                if (substr($image_src, 0, 8) !== 'uploads/') {
                                                    $image_src = 'uploads/hazard_reports/' . $image_src;
                                                }
                                                $image_src = '../' . $image_src;
                                            }
                                            $file_ext = strtolower(pathinfo($report['image_path'], PATHINFO_EXTENSION));
                                            $is_video = in_array($file_ext, ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv']);
                                            ?>
                                            <?php if ($is_video): ?>
                                                <video controls class="w-full rounded-lg shadow-sm border border-gray-300" style="max-height: 300px;">
                                                    <source src="<?php echo htmlspecialchars($image_src); ?>" type="video/<?php echo $file_ext; ?>">
                                                    Your browser does not support the video tag.
                                                </video>
                                                <p class="text-xs text-lgu-paragraph mt-2">Submitted Video</p>
                                            <?php else: ?>
                                                <img src="<?php echo htmlspecialchars($image_src); ?>" 
                                                     alt="Report Image" 
                                                     class="rounded-lg shadow-sm cursor-pointer max-w-full h-auto"
                                                     onclick="openImageModal('<?php echo htmlspecialchars($image_src); ?>')">
                                                <p class="text-xs text-lgu-paragraph mt-2">Submitted Photo</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Maintenance Completion Image -->
                                <?php if (!empty($report['completion_image_path'])): ?>
                                    <div class="mt-4">
                                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                            <i class="fa fa-check-circle mr-2 text-green-600"></i>
                                            Completion Photo
                                        </h4>
                                        <div class="max-w-xs">
                                            <?php
                                            $completion_src = $report['completion_image_path'];
                                            if (substr($completion_src, 0, 4) !== 'http' && substr($completion_src, 0, 1) !== '/') {
                                                $completion_src = '../uploads/' . $completion_src;
                                            }
                                            ?>
                                            <img src="<?php echo htmlspecialchars($completion_src); ?>" 
                                                 alt="Completion Photo" 
                                                 class="rounded-lg shadow-sm cursor-pointer max-w-full h-auto border-2 border-green-500"
                                                 onclick="openImageModal('<?php echo htmlspecialchars($completion_src); ?>')">
                                            <p class="text-xs text-green-600 font-semibold mt-2">Work Completion Photo</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- AI Analysis Results -->
                                <?php if (!empty($report['ai_analysis_result'])): ?>
                                <div class="mt-4">
                                    <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                                        <i class="fa fa-robot mr-2 text-lgu-button"></i>
                                        Beripikado AI Analysis
                                    </h4>
                                    
                                    <?php 
                                    // Try to decode JSON if it's structured data
                                    $ai_data = json_decode($report['ai_analysis_result'], true);
                                    if ($ai_data && isset($ai_data['predictions'])): 
                                    ?>
                                        <div class="bg-blue-50 rounded-lg p-4">
                                            <!-- Primary Detection Only -->
                                            <?php if (isset($ai_data['topPrediction'])): ?>
                                            <div class="mb-3">
                                                <div class="bg-blue-100 p-3 rounded-lg border-l-4 border-blue-500">
                                                    <div class="flex justify-between items-center">
                                                        <span class="font-semibold text-blue-800"><?php echo htmlspecialchars($ai_data['topPrediction']['className']); ?></span>
                                                        <span class="text-sm font-bold text-blue-700"><?php echo number_format($ai_data['topPrediction']['probability'] * 100, 1); ?>%</span>
                                                    </div>
                                                    <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- AI Recommendation -->
                                            <?php 
                                            $topPrediction = $ai_data['topPrediction'];
                                            if ($topPrediction['probability'] > 0.6): ?>
                                            <div class="mt-3 p-2 bg-green-50 border border-green-200 rounded-lg">
                                                <div class="flex items-center text-green-800">
                                                    <i class="fa fa-check-circle mr-2 text-xs"></i>
                                                    <span class="font-medium text-xs">AI detected: <?php echo htmlspecialchars($topPrediction['className']); ?></span>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div class="mt-3 p-2 bg-yellow-50 border border-yellow-200 rounded-lg">
                                                <div class="flex items-center text-yellow-800">
                                                    <i class="fa fa-exclamation-triangle mr-2 text-xs"></i>
                                                    <span class="font-medium text-xs">Classification requires verification</span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- Fallback for plain text AI analysis -->
                                        <div class="bg-gray-50 p-3 rounded-lg">
                                            <p class="text-lgu-paragraph leading-relaxed text-sm"><?php echo nl2br(htmlspecialchars($report['ai_analysis_result'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 lg:mt-12">
            <div class="container mx-auto px-4 lg:px-6 text-center">
                <p class="text-sm lg:text-base">&copy; <?php echo date('Y'); ?> Road and Traffic Infrastructure Management System</p>
                <p class="mt-2 text-xs lg:text-sm">LGU - Local Government Unit</p>
            </div>
        </footer>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
        <div class="max-w-4xl max-h-full p-4">
            <div class="bg-white rounded-lg p-4 relative">
                <button onclick="closeImageModal()" class="absolute -top-3 -right-3 bg-red-500 text-white rounded-full w-8 h-8 flex items-center justify-center hover:bg-red-600 transition">
                    <i class="fa fa-times"></i>
                </button>
                <img id="modalImage" src="" alt="Full size image" class="max-w-full max-h-[80vh] rounded">
            </div>
        </div>
    </div>

    <!-- Status Guide Modal -->
    <div id="statusModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
        <div class="max-w-md w-full mx-4">
            <div class="bg-white rounded-lg p-6 relative">
                <button onclick="closeStatusModal()" class="absolute -top-3 -right-3 bg-red-500 text-white rounded-full w-8 h-8 flex items-center justify-center hover:bg-red-600 transition">
                    <i class="fa fa-times"></i>
                </button>
                <h3 class="text-lg font-bold text-lgu-headline mb-4">Report Status Guide</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-2 bg-gray-400 rounded"></div>
                        <span><strong>0% - Pending:</strong> Report submitted, awaiting validation</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-2 bg-orange-500 rounded"></div>
                        <span><strong>25% - Validated:</strong> Report approved and in progress</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-2 bg-yellow-500 rounded"></div>
                        <span><strong>50% - Inspector Assigned:</strong> Inspector reviewing the issue</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-2 bg-blue-500 rounded"></div>
                        <span><strong>75% - Maintenance Assigned:</strong> Repair team assigned</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-2 bg-green-500 rounded"></div>
                        <span><strong>100% - Completed:</strong> Issue fully resolved</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const sidebarToggle = document.getElementById('mobile-sidebar-toggle');
            const sidebarClose = document.getElementById('sidebar-close');
            const sidebarOverlay = document.getElementById('sidebar-overlay');

            function toggleSidebar() {
                if (sidebar) {
                    sidebar.classList.toggle('-translate-x-full');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.toggle('hidden');
                }
            }

            function closeSidebar() {
                if (sidebar) {
                    sidebar.classList.add('-translate-x-full');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.add('hidden');
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarClose) {
                sidebarClose.addEventListener('click', closeSidebar);
            }
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', closeSidebar);
            }
        });

        // Image modal functions
        function openImageModal(imagePath) {
            const modal = document.getElementById('imageModal');
            const modalImage = document.getElementById('modalImage');
            modalImage.src = imagePath;
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modal on background click
        document.getElementById('imageModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
                closeStatusModal();
            }
        });
        
        // Status guide modal functions
        function showStatusGuide() {
            document.getElementById('statusModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        document.getElementById('statusModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
        
        // Filter functionality
        function filterReports(type) {
            const reportCards = document.querySelectorAll('.report-card');
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterBtns.forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('data-type') === type) {
                    btn.classList.add('active');
                }
            });
            
            // Show/hide reports
            reportCards.forEach(card => {
                const hazardType = card.getAttribute('data-hazard-type');
                if (type === 'all' || hazardType === type) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }
        
        // Initialize maps for all reports
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        const reportMaps = {};
        
        // Initialize all maps after page load
        window.addEventListener('load', function() {
            <?php foreach ($reports as $report): ?>
            initReportMap(<?php echo $report['report_id']; ?>, <?php echo json_encode($report['address']); ?>, <?php echo json_encode($report['hazard_type']); ?>, <?php echo json_encode($report['landmark'] ?? ''); ?>);
            <?php endforeach; ?>
        });
        
        function initReportMap(reportId, address, hazardType, landmark) {
            const mapId = 'reportMap_' + reportId;
            const mapElement = document.getElementById(mapId);
            
            if (!mapElement || !address) return;
            
            // Initialize map
            const map = L.map(mapId).setView([14.5995, 120.9842], 12);
            
            // Add TomTom tile layer
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            reportMaps[reportId] = map;
            
            // Geocode and add marker
            geocodeAndMarkLocation(map, address, hazardType, landmark);
        }
        
        async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                const data = await response.json();
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-marker',
                            html: '<div style="background: #ef4444; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
                            iconSize: [20, 20],
                            iconAnchor: [10, 10]
                        })
                    }).addTo(map);
                    
                    let popupContent = `
                        <div class="p-2 text-xs">
                            <h4 class="font-bold text-red-600 mb-1">My Report</h4>
                            <p><strong>Type:</strong> ${hazardType}</p>
                            <p><strong>Address:</strong> ${result.address.freeformAddress}</p>
                    `;
                    
                    if (landmark) {
                        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `</div>`;
                    
                    marker.bindPopup(popupContent);
                    map.setView([lat, lng], 15);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
    </script>

</body>
</html>