<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/hazard_helper.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    header("Location: ../login.php");
    exit();
}

// Fetch forwarded ROAD maintenance reports with detailed information
$query = "
    SELECT 
        rf.id as forward_id,
        r.id as report_id,
        r.hazard_type,
        r.description,
        r.address,
        r.landmark,
        r.contact_number,
        r.image_path,
        r.ai_analysis_result,
        r.status as report_status,
        r.validation_status,
        r.created_at as report_created_at,
        rf.notes as forward_notes,
        rf.forwarded_at,
        u.fullname as forwarded_by,
        u.email as forwarded_by_email,
        reporter.fullname as reporter_name,
        reporter.email as reporter_email,
        t.team_name
    FROM report_forwards rf
    INNER JOIN reports r ON rf.report_id = r.id
    INNER JOIN users u ON rf.forwarded_by = u.id
    LEFT JOIN users reporter ON r.user_id = reporter.id
    LEFT JOIN teams t ON rf.team_id = t.id
    WHERE rf.team_type = 'road_maintenance' 
    AND rf.status = 'pending'
    ORDER BY rf.forwarded_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all road reports for table with pagination
$reports_page = isset($_GET['reports_page']) ? max(1, (int)$_GET['reports_page']) : 1;
$reports_limit = 10;
$reports_offset = ($reports_page - 1) * $reports_limit;

// Get total road reports count
$count_query = "SELECT COUNT(*) FROM reports r WHERE r.hazard_type = 'road'";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute();
$total_reports = $count_stmt->fetchColumn();
$total_reports_pages = ceil($total_reports / $reports_limit);

// Fetch all road reports for table with pagination
$all_reports_query = "
    SELECT 
        r.id as report_id,
        r.hazard_type,
        r.address,
        r.status,
        r.validation_status,
        r.created_at,
        u.fullname as reporter_name,
        u.contact_number,
        ma.assigned_to,
        mu.fullname as assigned_staff,
        ipm.id as ipm_id,
        ipm.severity
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN maintenance_assignments ma ON r.id = ma.report_id
    LEFT JOIN users mu ON ma.assigned_to = mu.id
    LEFT JOIN inspection_findings ipm ON r.id = ipm.report_id AND ipm.severity = 'major'
    WHERE r.hazard_type = 'road'
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
";

$all_reports_stmt = $pdo->prepare($all_reports_query);
$all_reports_stmt->execute([$reports_limit, $reports_offset]);
$all_reports = $all_reports_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available maintenance staff with pagination
$staff_page = isset($_GET['staff_page']) ? (int)$_GET['staff_page'] : 1;
$staff_limit = 10;
$staff_offset = ($staff_page - 1) * $staff_limit;

// Get total maintenance staff count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'maintenance' AND status = 'active'");
$count_stmt->execute();
$total_staff = $count_stmt->fetchColumn();
$total_staff_pages = ceil($total_staff / $staff_limit);

// Get maintenance staff for current page
$staff_query = "SELECT id, fullname, email, contact_number FROM users WHERE role = 'maintenance' AND status = 'active' ORDER BY fullname LIMIT :limit OFFSET :offset";
$staff_stmt = $pdo->prepare($staff_query);
$staff_stmt->bindValue(':limit', $staff_limit, PDO::PARAM_INT);
$staff_stmt->bindValue(':offset', $staff_offset, PDO::PARAM_INT);
$staff_stmt->execute();
$maintenance_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all maintenance staff for dropdown (no pagination)
$all_staff_query = "SELECT id, fullname FROM users WHERE role = 'maintenance' AND status = 'active' ORDER BY fullname";
$all_staff_stmt = $pdo->prepare($all_staff_query);
$all_staff_stmt->execute();
$all_maintenance_staff = $all_staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle assignment to maintenance user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_maintenance'])) {
    $forward_id = $_POST['forward_id'];
    $assigned_to = $_POST['assigned_to'];
    $completion_deadline = $_POST['completion_deadline'];
    $assignment_notes = $_POST['assignment_notes'];
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get report_id from forward_id
        $report_query = "SELECT report_id FROM report_forwards WHERE id = ?";
        $stmt = $pdo->prepare($report_query);
        $stmt->execute([$forward_id]);
        $report_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$report_data) {
            throw new Exception("Report not found!");
        }
        
        $report_id = $report_data['report_id'];
        
        // Update report forward status to in_progress
        $update_forward = "UPDATE report_forwards SET status = 'in_progress' WHERE id = ?";
        $stmt = $pdo->prepare($update_forward);
        $stmt->execute([$forward_id]);
        
        // Update report status to in_progress
        $update_report = "UPDATE reports SET status = 'in_progress' WHERE id = ?";
        $stmt = $pdo->prepare($update_report);
        $stmt->execute([$report_id]);
        
        // Create maintenance assignment record
        $insert_assignment = "
            INSERT INTO maintenance_assignments 
            (report_id, forward_id, assigned_to, assigned_by, completion_deadline, notes, status, team_type, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'assigned', 'road_maintenance', NOW())
        ";
        $stmt = $pdo->prepare($insert_assignment);
        $stmt->execute([
            $report_id, 
            $forward_id, 
            $assigned_to, 
            $_SESSION['user_id'], 
            $completion_deadline, 
            $assignment_notes
        ]);
        
        // Send notification to assigned maintenance staff
        $report_stmt = $pdo->prepare("SELECT hazard_type, address FROM reports WHERE id = ?");
        $report_stmt->execute([$report_id]);
        $report_data = $report_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report_data) {
            $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
            $notification_stmt->execute([
                $assigned_to,
                'New Maintenance Assignment',
                'You have been assigned road maintenance work for report #' . $report_id . ' (' . ucfirst($report_data['hazard_type']) . ') at ' . $report_data['address'],
                'info'
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Road maintenance work assigned successfully!";
        header('Location: assign_maintenance.php');
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error assigning work: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Road Maintenance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
                'lgu-highlight': '#faae2b'
              }
            }
          }
        }
    </script>
    <style>
        .font-poppins { font-family: 'Poppins', sans-serif; }
        
        /* Status colors */
        .status-pending { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-assigned { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-in_progress { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-inspection_ongoing { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-inspection_ended { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-resolved { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-done { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-rejected { background-color: #fee2e2 !important; color: #991b1b !important; }
        .status-escalated { background-color: #fee2e2 !important; color: #991b1b !important; }
        
        /* Sidebar integration fixes */
        body {
            margin: 0;
            padding: 0;
        }
        
        .sidebar-container {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 40;
        }
        
        .main-wrapper {
            margin-left: var(--sidebar-width, 0);
            transition: margin-left 0.3s ease;
        }
        
        /* Responsive sidebar */
        @media (max-width: 768px) {
            .sidebar-container {
                transform: translateX(-100%);
                width: 100% !important;
                transition: transform 0.3s ease;
            }
            
            .sidebar-container.active {
                transform: translateX(0);
            }
            
            .main-wrapper {
                margin-left: 0 !important;
            }
        }
        
        /* Map styles */
        .leaflet-container {
            height: 100%;
            width: 100%;
            border-radius: 0.5rem;
            z-index: 1 !important;
        }
        
        [id^="reportMap_"] {
            height: 150px !important;
            min-height: 150px;
            width: 100%;
        }
        
        .custom-hazard-marker {
            background: transparent !important;
            border: none !important;
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 flex flex-col h-screen">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-lgu-stroke">
                <div class="flex items-center justify-between p-3 sm:p-4">
                    <div class="flex items-center space-x-2 sm:space-x-3 flex-1 min-w-0">
                        <button id="sidebarToggle" class="lg:hidden p-2 hover:bg-lgu-bg rounded-lg transition-colors flex-shrink-0">
                            <i class="fas fa-bars text-lgu-headline text-lg"></i>
                        </button>
                        <div class="p-2 bg-lgu-highlight rounded-lg flex-shrink-0">
                            <i class="fas fa-road text-lgu-button-text text-lg"></i>
                        </div>
                        <h1 class="text-lg sm:text-xl font-semibold text-lgu-headline truncate">Assign Road Maintenance</h1>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto p-3 sm:p-4 lg:p-6">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center">
                        <i class="fas fa-exclamation-circle text-red-500 text-lg mr-3"></i>
                        <span class="text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
                        <button type="button" class="ml-auto text-red-500 hover:text-red-700" onclick="this.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                    <!-- Pending Road Maintenance Reports -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke">
                            <div class="bg-lgu-headline px-6 py-4">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-white text-lg font-semibold flex items-center">
                                        <i class="fas fa-list mr-2"></i>
                                        Pending Road Maintenance Reports
                                    </h2>
                                    <span class="bg-lgu-highlight text-lgu-button-text px-3 py-1 rounded-full text-sm font-medium">
                                        <?= count($reports) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="p-6">
                                <?php if (empty($reports)): ?>
                                    <div class="text-center py-8">
                                        <div class="bg-lgu-bg rounded-xl p-6 max-w-md mx-auto border border-lgu-stroke">
                                            <i class="fas fa-info-circle text-lgu-paragraph text-4xl mb-4"></i>
                                            <h3 class="text-lg font-medium text-lgu-headline mb-2">No pending reports</h3>
                                            <p class="text-lgu-paragraph">All road maintenance reports have been assigned or there are no pending requests.</p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-4">
                                        <?php foreach ($reports as $report): ?>
                                            <div class="border border-lgu-stroke rounded-lg hover:shadow-md transition-shadow duration-200 bg-white">
                                                <div class="p-3 sm:p-4">
                                                    <div class="flex flex-col gap-4">
                                                        <div class="flex-1">
                                                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 mb-3">
                                                                <h3 class="font-semibold text-lgu-headline text-sm sm:text-base">
                                                                    Report #<?= htmlspecialchars($report['report_id']) ?>
                                                                </h3>
                                                                <span class="bg-lgu-highlight bg-opacity-20 text-lgu-button-text px-2 py-1 rounded-full text-xs font-medium border border-lgu-highlight self-start">
                                                                    Pending Assignment
                                                                </span>
                                                            </div>
                                                            
                                                            <div class="grid grid-cols-1 gap-3 text-xs sm:text-sm">
                                                                <div class="flex items-start gap-2">
                                                                    <i class="fas fa-exclamation-triangle text-lgu-headline mt-1 flex-shrink-0"></i>
                                                                    <div class="min-w-0">
                                                                        <span class="font-medium text-lgu-paragraph">Hazard Type:</span>
                                                                        <p class="text-lgu-headline break-words"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $report['hazard_type']))) ?></p>
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="flex items-start gap-2">
                                                                    <i class="fas fa-map-marker-alt text-lgu-headline mt-1 flex-shrink-0"></i>
                                                                    <div class="min-w-0">
                                                                        <span class="font-medium text-lgu-paragraph">Location:</span>
                                                                        <p class="text-lgu-headline break-words"><?= htmlspecialchars($report['address']) ?></p>
                                                                        <?php if (!empty($report['landmark'])): ?>
                                                                        <p class="text-xs text-lgu-paragraph mt-1 flex items-center">
                                                                            <i class="fas fa-landmark mr-1"></i> <?= htmlspecialchars($report['landmark']) ?>
                                                                        </p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mt-3 flex items-start gap-2">
                                                                <i class="fas fa-file-alt text-lgu-paragraph mt-1"></i>
                                                                <div>
                                                                    <span class="font-medium text-lgu-paragraph">Description:</span>
                                                                    <p class="text-lgu-headline"><?= htmlspecialchars($report['description']) ?></p>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mt-3 flex items-start gap-2">
                                                                <i class="fas fa-user text-lgu-paragraph mt-1"></i>
                                                                <div>
                                                                    <span class="font-medium text-lgu-paragraph">Reporter:</span>
                                                                    <p class="text-lgu-headline"><?= htmlspecialchars($report['reporter_name'] ?? 'Unknown') ?></p>
                                                                    <?php if (!empty($report['reporter_email'])): ?>
                                                                    <p class="text-xs text-lgu-paragraph mt-1"><?= htmlspecialchars($report['reporter_email']) ?></p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mt-3 flex items-start gap-2">
                                                                <i class="fas fa-calendar text-lgu-paragraph mt-1"></i>
                                                                <div>
                                                                    <span class="font-medium text-lgu-paragraph">Report Date:</span>
                                                                    <p class="text-lgu-headline"><?= date('M d, Y h:i A', strtotime($report['report_created_at'])) ?></p>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mt-3 flex items-start gap-2">
                                                                <i class="fas fa-check-circle text-lgu-paragraph mt-1"></i>
                                                                <div>
                                                                    <span class="font-medium text-lgu-paragraph">Validation Status:</span>
                                                                    <p class="text-lgu-headline"><?= ucfirst($report['validation_status'] ?? 'Pending') ?></p>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mt-3 flex items-start gap-2">
                                                                <i class="fas fa-user-clock text-lgu-paragraph mt-1"></i>
                                                                <div>
                                                                    <span class="font-medium text-lgu-paragraph">Forwarded by:</span>
                                                                    <p class="text-lgu-headline"><?= htmlspecialchars($report['forwarded_by']) ?></p>
                                                                    <?php if (!empty($report['forwarded_by_email'])): ?>
                                                                    <p class="text-xs text-lgu-paragraph mt-1"><?= htmlspecialchars($report['forwarded_by_email']) ?></p>
                                                                    <?php endif; ?>
                                                                    <p class="text-xs text-lgu-paragraph mt-1"><?= date('M d, Y g:i A', strtotime($report['forwarded_at'])) ?></p>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if (!empty($report['forward_notes'])): ?>
                                                            <div class="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                                                                <div class="flex items-start gap-2">
                                                                    <i class="fas fa-sticky-note text-blue-600 mt-1"></i>
                                                                    <div>
                                                                        <span class="font-medium text-blue-800 text-sm">Forward Notes:</span>
                                                                        <p class="text-blue-700 text-sm mt-1"><?= nl2br(htmlspecialchars($report['forward_notes'])) ?></p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <!-- AI Analysis -->
                                                            <?php if (!empty($report['ai_analysis_result'])): ?>
                                                            <div class="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                                                                <div class="flex items-center gap-2 mb-2">
                                                                    <i class="fas fa-robot text-blue-600"></i>
                                                                    <span class="font-medium text-blue-800 text-xs">Beripikado AI Analysis</span>
                                                                </div>
                                                                <?php 
                                                                $ai_data = json_decode($report['ai_analysis_result'], true);
                                                                if ($ai_data && isset($ai_data['predictions'])): 
                                                                ?>
                                                                    <?php if (isset($ai_data['topPrediction'])): ?>
                                                                    <div class="mb-2">
                                                                        <div class="bg-blue-100 p-2 rounded border-l-4 border-blue-500">
                                                                            <div class="flex justify-between items-center">
                                                                                <span class="font-semibold text-blue-800 text-xs"><?= htmlspecialchars($ai_data['topPrediction']['className']) ?></span>
                                                                                <span class="text-xs font-bold text-blue-700"><?= number_format($ai_data['topPrediction']['probability'] * 100, 1) ?>%</span>
                                                                            </div>
                                                                            <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                                                        </div>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    
                                                                    <?php if (isset($ai_data['hazardLevel'])): ?>
                                                                    <div class="mb-2">
                                                                        <?php 
                                                                        $hazard_level = strtolower($ai_data['hazardLevel']);
                                                                        $level_colors = [
                                                                            'low' => 'bg-green-100 border-green-500 text-green-800',
                                                                            'medium' => 'bg-yellow-100 border-yellow-500 text-yellow-800',
                                                                            'high' => 'bg-red-100 border-red-500 text-red-800'
                                                                        ];
                                                                        $color_class = $level_colors[$hazard_level] ?? 'bg-gray-100 border-gray-500 text-gray-800';
                                                                        ?>
                                                                        <div class="p-2 rounded border-l-4 <?= $color_class ?>">
                                                                            <div class="flex items-center justify-between">
                                                                                <span class="font-semibold text-xs">Hazard Level:</span>
                                                                                <span class="font-bold text-xs uppercase"><?= htmlspecialchars($ai_data['hazardLevel']) ?></span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    
                                                                    <?php 
                                                                    $topPrediction = $ai_data['topPrediction'];
                                                                    if ($topPrediction['probability'] > 0.6): ?>
                                                                    <div class="p-2 bg-green-50 border border-green-200 rounded">
                                                                        <div class="flex items-center text-green-800">
                                                                            <i class="fas fa-check-circle mr-1"></i>
                                                                            <span class="font-medium text-xs">AI: Image shows <?= htmlspecialchars($topPrediction['className']) ?></span>
                                                                        </div>
                                                                    </div>
                                                                    <?php else: ?>
                                                                    <div class="p-2 bg-yellow-50 border border-yellow-200 rounded">
                                                                        <div class="flex items-center text-yellow-800">
                                                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                                                            <span class="font-medium text-xs">AI: Manual verification needed</span>
                                                                        </div>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <p class="text-xs text-blue-700"><?= htmlspecialchars(substr($report['ai_analysis_result'], 0, 80)) ?>...</p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <!-- Location Map -->
                                                            <div class="mt-3 bg-gray-50 rounded-lg p-3 border border-gray-200">
                                                                <div class="flex items-center gap-2 mb-2">
                                                                    <i class="fas fa-map text-lgu-headline"></i>
                                                                    <span class="font-medium text-lgu-headline text-xs">Location Map</span>
                                                                </div>
                                                                <div id="reportMap_<?= $report['report_id'] ?>" class="w-full h-32 rounded border border-gray-300"></div>
                                                                <p class="text-xs text-lgu-paragraph mt-1">Red marker shows hazard location</p>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="flex flex-col items-end gap-3">
                                                            <?php if ($report['image_path']): ?>
                                                                <img src="../uploads/hazard_reports/<?= htmlspecialchars($report['image_path']) ?>" 
                                                                     alt="Hazard Image" 
                                                                     class="w-32 h-24 object-cover rounded-lg border border-lgu-stroke cursor-pointer hover:shadow-md transition-shadow"
                                                                     onclick="openImageModal(this.src)">
                                                            <?php endif; ?>
                                                            
                                                            <button class="bg-lgu-headline hover:bg-lgu-stroke text-white px-4 py-2 rounded-lg font-medium flex items-center gap-2 transition-colors duration-200 border border-lgu-stroke"
                                                                    data-forward-id="<?= $report['forward_id'] ?>"
                                                                    data-report-id="<?= $report['report_id'] ?>"
                                                                    onclick="openAssignModal(this)">
                                                                <i class="fas fa-user-plus"></i>
                                                                Assign to Maintenance
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Available Maintenance Staff -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke">
                            <div class="bg-lgu-headline px-3 sm:px-6 py-4">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-white text-sm sm:text-lg font-semibold flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        <span class="hidden sm:inline">Available Maintenance Staff</span>
                                        <span class="sm:hidden">Staff</span>
                                    </h2>
                                    <span class="bg-lgu-highlight text-lgu-button-text px-2 sm:px-3 py-1 rounded-full text-xs sm:text-sm font-medium">
                                        <?= $total_staff ?>
                                    </span>
                                </div>
                            </div>
                            <div class="p-3 sm:p-6">
                                <?php if (empty($maintenance_staff)): ?>
                                    <div class="bg-lgu-highlight bg-opacity-20 border border-lgu-highlight rounded-lg p-3 sm:p-4 text-center">
                                        <i class="fas fa-exclamation-triangle text-lgu-button-text text-lg sm:text-xl mb-2"></i>
                                        <p class="text-lgu-button-text font-medium text-xs sm:text-sm">No maintenance staff available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-2 sm:space-y-3">
                                        <?php foreach ($maintenance_staff as $staff): ?>
                                            <div class="border border-lgu-stroke rounded-lg p-3 sm:p-4 hover:shadow-sm transition-shadow duration-200 bg-white">
                                                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-2 gap-2">
                                                    <h4 class="font-semibold text-lgu-headline text-sm break-words min-w-0"><?= htmlspecialchars($staff['fullname']) ?></h4>
                                                    <span class="bg-lgu-highlight bg-opacity-20 text-lgu-button-text px-2 py-1 rounded-full text-xs font-medium border border-lgu-highlight self-start flex-shrink-0">
                                                        Available
                                                    </span>
                                                </div>
                                                <div class="space-y-1 text-xs sm:text-sm text-lgu-paragraph">
                                                    <div class="flex items-start gap-2">
                                                        <i class="fas fa-envelope w-3 sm:w-4 flex-shrink-0 mt-0.5"></i>
                                                        <span class="break-all min-w-0"><?= htmlspecialchars($staff['email']) ?></span>
                                                    </div>
                                                    <?php if ($staff['contact_number']): ?>
                                                        <div class="flex items-start gap-2">
                                                            <i class="fas fa-phone w-3 sm:w-4 flex-shrink-0 mt-0.5"></i>
                                                            <span class="break-all min-w-0"><?= htmlspecialchars($staff['contact_number']) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- All Reports Table -->
                <div class="mt-6">
                    <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <input type="text" id="searchInput" placeholder="Search by ID or Reporter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                            <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="all">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="escalated">Escalated</option>
                                <option value="resolved">Resolved</option>
                            </select>
                            <select id="sortFilter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="date_desc">Newest First</option>
                                <option value="date_asc">Oldest First</option>
                                <option value="reporter">By Reporter</option>
                            </select>
                            <button onclick="applyFilters()" class="px-4 py-2 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text rounded font-bold transition">Apply</button>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Report ID</th>
                                        <th class="px-4 py-3 text-left font-semibold">Hazard Type</th>
                                        <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Reporter</th>
                                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                                        <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Date</th>
                                        <th class="px-4 py-3 text-center font-semibold">Assigned / IPM</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (count($all_reports) > 0): ?>
                                        <?php foreach ($all_reports as $report): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-4 py-3 font-bold text-lgu-headline">#<?= htmlspecialchars($report['report_id']) ?></td>
                                            <td class="px-4 py-3">
                                                <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                    <i class="fa fa-exclamation-triangle mr-1"></i>
                                                    <?= ucfirst($report['hazard_type']) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 hidden md:table-cell">
                                                <span class="text-xs text-lgu-paragraph">
                                                    <i class="fa fa-user mr-1"></i>
                                                    <?= htmlspecialchars($report['reporter_name'] ?? 'Unknown') ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php 
                                                $status_map = [
                                                    'pending' => 'status-pending',
                                                    'in_progress' => 'status-in_progress',
                                                    'escalated' => 'status-escalated',
                                                    'resolved' => 'status-resolved',
                                                    'done' => 'status-done'
                                                ];
                                                $status_class = $status_map[$report['status']] ?? 'status-pending';
                                                ?>
                                                <span class="<?= $status_class ?> px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <?= ucfirst(str_replace('_', ' ', $report['status'])) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 hidden md:table-cell text-xs text-lgu-paragraph"><?= date('M d, Y', strtotime($report['created_at'])) ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($report['ipm_id']): ?>
                                                    <span class="inline-block bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                        <i class="fa fa-alert mr-1"></i>
                                                        IPM
                                                    </span>
                                                <?php elseif ($report['assigned_staff']): ?>
                                                    <span class="inline-block bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                        <i class="fa fa-user-check mr-1"></i>
                                                        <?= htmlspecialchars($report['assigned_staff']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-block bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                        <i class="fa fa-minus mr-1"></i>
                                                        Unassigned
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-8 text-center text-lgu-paragraph">
                                                <i class="fas fa-inbox text-3xl text-lgu-stroke mb-3 block"></i>
                                                No road reports found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="bg-gray-50 px-4 py-4 border-t border-gray-200">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div class="text-sm text-lgu-paragraph">
                                    <span class="font-semibold text-lgu-headline"><?= $total_reports ?></span> total report(s)
                                </div>
                                <div class="flex items-center gap-2 flex-wrap justify-center">
                                    <?php if ($total_reports_pages > 1): ?>
                                        <?php if ($reports_page > 1): ?>
                                            <a href="?reports_page=1" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">First</a>
                                            <a href="?reports_page=<?= $reports_page - 1 ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Prev</a>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $start_page = max(1, $reports_page - 2);
                                        $end_page = min($total_reports_pages, $reports_page + 2);
                                        
                                        if ($start_page > 1): ?>
                                            <span class="text-gray-500">...</span>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <?php if ($i === $reports_page): ?>
                                                <span class="px-3 py-1 rounded bg-lgu-button text-lgu-button-text font-bold text-sm"><?= $i ?></span>
                                            <?php else: ?>
                                                <a href="?reports_page=<?= $i ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($end_page < $total_reports_pages): ?>
                                            <span class="text-gray-500">...</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($reports_page < $total_reports_pages): ?>
                                            <a href="?reports_page=<?= $reports_page + 1 ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Next</a>
                                            <a href="?reports_page=<?= $total_reports_pages ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Last</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Assign Maintenance Modal -->
    <div id="assignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-3 sm:p-4 z-50 hidden">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto border border-lgu-stroke mx-3 sm:mx-0">
            <div class="bg-lgu-headline px-6 py-4 rounded-t-xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-user-plus mr-2"></i>
                        Assign Road Maintenance
                    </h3>
                    <button type="button" onclick="closeAssignModal()" class="text-white hover:text-lgu-highlight">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
            </div>
            
            <form method="POST" action="" class="p-6 space-y-4">
                <input type="hidden" name="forward_id" id="modal_forward_id">
                
                <div>
                    <label for="assigned_to" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-user mr-2 text-lgu-headline"></i>Select Maintenance Staff
                    </label>
                    <select id="assigned_to" name="assigned_to" required
                            class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white">
                        <option value="">Choose maintenance staff...</option>
                        <?php foreach ($all_maintenance_staff as $staff): ?>
                            <option value="<?= $staff['id'] ?>">
                                <?= htmlspecialchars($staff['fullname']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="completion_deadline" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-calendar-alt mr-2 text-lgu-headline"></i>Completion Deadline
                    </label>
                    <input type="datetime-local" id="completion_deadline" name="completion_deadline" required
                           class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white">
                </div>
                
                <div>
                    <label for="assignment_notes" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-sticky-note mr-2 text-lgu-headline"></i>Work Instructions
                    </label>
                    <textarea id="assignment_notes" name="assignment_notes" rows="4"
                              placeholder="Add specific instructions for road maintenance work..."
                              class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white"></textarea>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeAssignModal()"
                            class="flex-1 bg-lgu-paragraph hover:bg-lgu-stroke text-white py-2 px-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2 border border-lgu-stroke">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="assign_maintenance"
                            class="flex-1 bg-lgu-headline hover:bg-lgu-stroke text-white py-2 px-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2 border border-lgu-stroke">
                        <i class="fas fa-check"></i> Assign Road Work
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-xl max-w-4xl w-full max-h-[90vh] overflow-hidden border border-lgu-stroke">
            <div class="flex justify-between items-center p-4 border-b border-lgu-stroke">
                <h3 class="text-lg font-semibold text-lgu-headline">Hazard Image</h3>
                <button type="button" onclick="closeImageModal()" class="text-lgu-paragraph hover:text-lgu-headline">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-4 flex justify-center">
                <img id="modalImage" src="" alt="Hazard Image" class="max-w-full max-h-[70vh] object-contain">
            </div>
        </div>
    </div>

    <script>
        // Show success message if exists
        <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?= addslashes($_SESSION['success']) ?>',
            confirmButtonColor: '#faae2b',
            confirmButtonText: 'OK'
        });
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        // Mobile sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('admin-sidebar');
        
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('-translate-x-full');
                document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
            });
        }

        // Modal functions
        function openAssignModal(button) {
            const forwardId = button.getAttribute('data-forward-id');
            const reportId = button.getAttribute('data-report-id');
            
            document.getElementById('modal_forward_id').value = forwardId;
            document.getElementById('assignModal').classList.remove('hidden');
            
            // Set minimum datetime for deadline (current time)
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('completion_deadline').min = now.toISOString().slice(0, 16);
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
            document.querySelector('#assignModal form').reset();
        }

        function openImageModal(src) {
            document.getElementById('modalImage').src = src;
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.id === 'assignModal') {
                closeAssignModal();
            }
            if (event.target.id === 'imageModal') {
                closeImageModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAssignModal();
                closeImageModal();
            }
        });
        
        // Initialize maps
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const sort = document.getElementById('sortFilter').value;
            window.location.href = `?reports_page=1&search=${encodeURIComponent(search)}&status=${status}&sort=${sort}`;
        }
        
        setTimeout(() => {
            <?php foreach ($reports as $report): ?>
            initReportMap(<?= $report['report_id'] ?>, <?= json_encode($report['address']) ?>, <?= json_encode($report['hazard_type']) ?>, <?= json_encode($report['landmark'] ?? '') ?>);
            <?php endforeach; ?>
        }, 100);
        
        function initReportMap(reportId, address, hazardType, landmark) {
            const mapId = 'reportMap_' + reportId;
            const mapElement = document.getElementById(mapId);
            
            if (!mapElement || !address) return;
            
            try {
                const map = L.map(mapId, {
                    zoomControl: true,
                    scrollWheelZoom: false
                }).setView([14.5995, 120.9842], 12);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(map);
                
                setTimeout(() => {
                    map.invalidateSize();
                    geocodeAndMarkLocation(map, address, hazardType, landmark);
                }, 200);
                
            } catch (error) {
                console.error(`Error initializing map for report ${reportId}:`, error);
                mapElement.innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 text-gray-500 text-xs"><i class="fa fa-map-marker-alt mr-1"></i>Map unavailable</div>';
            }
        }
        
        async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
            try {
                let response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                let data = await response.json();
                
                if (!data.results || data.results.length === 0) {
                    response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address + ', Philippines')}&limit=1`);
                    const nominatimData = await response.json();
                    
                    if (nominatimData && nominatimData.length > 0) {
                        data = {
                            results: [{
                                position: {
                                    lat: parseFloat(nominatimData[0].lat),
                                    lon: parseFloat(nominatimData[0].lon)
                                }
                            }]
                        };
                    }
                }
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-hazard-marker',
                            html: '<div style="background: #ef4444; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
                            iconSize: [20, 20],
                            iconAnchor: [10, 10]
                        })
                    }).addTo(map);
                    
                    let popupContent = `
                        <div class="p-2 text-xs">
                            <h4 class="font-bold text-red-600 mb-1">Hazard Location</h4>
                            <p><strong>Type:</strong> ${hazardType}</p>
                            <p><strong>Address:</strong> ${address}</p>
                    `;
                    
                    if (landmark && landmark.trim()) {
                        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `</div>`;
                    
                    marker.bindPopup(popupContent);
                    map.setView([lat, lng], 15);
                    
                } else {
                    const mapContainer = map.getContainer();
                    const overlay = document.createElement('div');
                    overlay.className = 'absolute inset-0 bg-gray-100 bg-opacity-90 flex items-center justify-center text-center p-2';
                    overlay.innerHTML = `
                        <div>
                            <i class="fa fa-map-marker-alt text-lg text-gray-400 mb-1"></i>
                            <p class="text-xs font-semibold text-gray-600">${address}</p>
                            <p class="text-xs text-gray-500">Unable to show on map</p>
                        </div>
                    `;
                    mapContainer.style.position = 'relative';
                    mapContainer.appendChild(overlay);
                }
                
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
    </script>
</body>
</html>