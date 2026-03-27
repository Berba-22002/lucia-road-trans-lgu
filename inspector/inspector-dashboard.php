<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector role
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$inspector_id = $_SESSION['user_id'];
$inspector_name = $_SESSION['user_name'] ?? 'Inspector';

// Initialize all statistics
$stats = [
    'total_assigned' => 0,
    'pending_reports' => 0,
    'in_progress_reports' => 0,
    'completed_reports' => 0,
    'escalated_reports' => 0,
    'minor_severity' => 0,
    'major_severity' => 0,
    'pending_verification' => 0,
    'approved_verification' => 0,
    'rejected_verification' => 0,
    'under_construction' => 0,
    'inspection_findings_submitted' => 0
];

$recent_activities = [];
$quick_actions = [];
$notifications = [];
$unread_count = 0;

try {
    // Get report statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN r.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN r.status = 'done' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN r.status = 'escalated' THEN 1 ELSE 0 END) as escalated
        FROM reports r
        INNER JOIN report_inspectors ri ON r.id = ri.report_id
        WHERE ri.inspector_id = ? AND ri.status = 'assigned'
    ");
    $stmt->execute([$inspector_id]);
    $reportStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total_assigned'] = (int)($reportStats['total'] ?? 0);
    $stats['pending_reports'] = (int)($reportStats['pending'] ?? 0);
    $stats['in_progress_reports'] = (int)($reportStats['in_progress'] ?? 0);
    $stats['completed_reports'] = (int)($reportStats['completed'] ?? 0);
    $stats['escalated_reports'] = (int)($reportStats['escalated'] ?? 0);

    // Get severity statistics
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN f.severity = 'minor' OR f.severity IS NULL THEN 1 ELSE 0 END) as minor,
            SUM(CASE WHEN f.severity = 'major' THEN 1 ELSE 0 END) as major
        FROM reports r
        INNER JOIN report_inspectors ri ON r.id = ri.report_id
        LEFT JOIN inspection_findings f ON r.id = f.report_id 
            AND f.created_at = (SELECT MAX(created_at) FROM inspection_findings WHERE report_id = r.id)
        WHERE ri.inspector_id = ? AND ri.status = 'assigned'
    ");
    $stmt->execute([$inspector_id]);
    $severityStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['minor_severity'] = (int)($severityStats['minor'] ?? 0);
    $stats['major_severity'] = (int)($severityStats['major'] ?? 0);

    // Get inspection findings count
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.id) as submitted
        FROM inspection_findings f
        INNER JOIN reports r ON f.report_id = r.id
        WHERE f.inspector_id = ?
    ");
    $stmt->execute([$inspector_id]);
    $findingsCount = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['inspection_findings_submitted'] = (int)($findingsCount['submitted'] ?? 0);

    // Get project verification statistics
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN p.status = 'under_construction' THEN 1 ELSE 0 END) as under_construction,
            SUM(CASE WHEN p.verification_status = 'pending' THEN 1 ELSE 0 END) as pending_verification,
            SUM(CASE WHEN p.verification_status = 'approved' THEN 1 ELSE 0 END) as approved_verification,
            SUM(CASE WHEN p.verification_status = 'rejected' THEN 1 ELSE 0 END) as rejected_verification
        FROM projects p
        WHERE p.status IN ('approved', 'under_construction', 'completed')
    ");
    $stmt->execute([]);
    $projectStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['under_construction'] = (int)($projectStats['under_construction'] ?? 0);
    $stats['pending_verification'] = (int)($projectStats['pending_verification'] ?? 0);
    $stats['approved_verification'] = (int)($projectStats['approved_verification'] ?? 0);
    $stats['rejected_verification'] = (int)($projectStats['rejected_verification'] ?? 0);

    // Get recent assigned reports
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.hazard_type,
            r.address,
            r.status,
            r.created_at,
            u.fullname as reporter_name,
            ri.assigned_at
        FROM reports r
        INNER JOIN report_inspectors ri ON r.id = ri.report_id
        INNER JOIN users u ON r.user_id = u.id
        WHERE ri.inspector_id = ? AND ri.status = 'assigned'
        ORDER BY ri.assigned_at DESC
        LIMIT 5
    ");
    $stmt->execute([$inspector_id]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get notifications from notifications table
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, is_read, related_id, related_type, created_at
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$inspector_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$inspector_id]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
        
        .stat-card {
            transition: all 0.3s ease;
            background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.8));
            backdrop-filter: blur(10px);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 71, 62, 0.15);
        }
        
        .quick-action-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .quick-action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }
        
        .activity-item {
            transition: background-color 0.2s ease;
        }
        
        .activity-item:hover {
            background-color: #f9fafb;
        }
        
        .progress-circle {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(var(--progress-color) var(--progress), #e5e7eb var(--progress));
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .progress-circle-inner {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
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
            <div class="flex items-center justify-between px-4 py-4 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold text-lgu-headline truncate">Inspector Dashboard</h1>
                        <p class="text-sm text-lgu-paragraph truncate">Welcome, <?php echo htmlspecialchars($inspector_name); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-4 flex-shrink-0">
                    <!-- Live Clock -->
                    <div class="text-right hidden sm:block">
                        <div id="currentDate" class="text-xs font-semibold text-lgu-headline"></div>
                        <div id="currentTime" class="text-sm font-bold text-lgu-button"></div>
                    </div>
                    
                    <!-- Notification Bell -->
                    <div class="relative">
                        <button id="notificationBell" class="relative w-10 h-10 text-lgu-headline hover:text-lgu-button transition" title="Notifications">
                            <i class="fa fa-bell text-xl"></i>
                            <?php if ($unread_count > 0): ?>
                            <span id="notificationBadge" class="absolute top-0 right-0 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center">
                                <span id="notificationCount"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                            </span>
                            <?php else: ?>
                            <span id="notificationBadge" class="absolute top-0 right-0 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center hidden">
                                <span id="notificationCount">0</span>
                            </span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notification Dropdown -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 rounded-t-lg font-bold flex items-center justify-between">
                                <span><i class="fa fa-bell mr-2"></i> Notifications</span>
                                <div class="flex space-x-2">
                                    <?php if ($unread_count > 0): ?>
                                    <button id="markAllReadBtn" class="text-xs bg-white/20 hover:bg-white/30 px-2 py-1 rounded transition">Mark all read</button>
                                    <?php endif; ?>
                                    <?php if (count($notifications) > 0): ?>
                                    <button id="clearAllNotifications" class="text-xs bg-white/20 hover:bg-white/30 px-2 py-1 rounded transition">Clear All</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div id="notificationList" class="max-h-96 overflow-y-auto">
                                <?php if (empty($notifications)): ?>
                                <div class="p-8 text-center text-gray-500">
                                    <i class="fa fa-inbox text-3xl mb-2 block opacity-50"></i>
                                    <p class="text-sm">No notifications</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                    <?php 
                                        $bgClass = $notification['is_read'] ? 'bg-white' : 'bg-blue-50';
                                        $iconClass = match($notification['type']) {
                                            'info' => 'fa-info-circle text-blue-600',
                                            'success' => 'fa-check-circle text-green-600', 
                                            'warning' => 'fa-exclamation-triangle text-yellow-600',
                                            'error' => 'fa-times-circle text-red-600',
                                            default => 'fa-bell text-gray-600'
                                        };
                                        $colorClass = match($notification['type']) {
                                            'info' => 'blue',
                                            'success' => 'green',
                                            'warning' => 'yellow', 
                                            'error' => 'red',
                                            default => 'gray'
                                        };
                                    ?>
                                    <div class="notification-item <?php echo $bgClass; ?> border-b border-gray-100 p-4 hover:bg-gray-50 cursor-pointer transition" data-id="<?php echo $notification['id']; ?>" data-type="<?php echo $notification['related_type']; ?>">
                                        <div class="flex gap-3">
                                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-<?php echo $colorClass; ?>-100 flex items-center justify-center">
                                                <i class="fa <?php echo $iconClass; ?>"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <h4 class="font-semibold text-lgu-headline text-sm">
                                                    <?php echo htmlspecialchars($notification['title']); ?>
                                                    <?php if (!$notification['is_read']): ?>
                                                    <span class="inline-block w-2 h-2 bg-lgu-button rounded-full ml-2"></span>
                                                    <?php endif; ?>
                                                </h4>
                                                <p class="text-sm text-lgu-paragraph mt-0.5 line-clamp-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo date('M d, h:i A', strtotime($notification['created_at'])); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="border-t border-gray-200 px-4 py-2 text-center">
                                <a href="#" class="text-xs font-semibold text-lgu-button hover:text-lgu-stroke">View All Notifications</a>
                            </div>
                        </div>
                    </div>

                    <div class="w-12 h-12 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-lg shadow-md">
                        <i class="fa fa-user"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-6 overflow-y-auto">
            
            <!-- Welcome Section -->
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg shadow-lg p-6 mb-8 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold mb-2">Good Day, <?php echo htmlspecialchars($inspector_name); ?>!</h2>
                        <p class="text-yellow-100">You have <span class="font-bold"><?php echo $stats['pending_reports']; ?></span> pending reports awaiting your inspection</p>
                    </div>
                    <div class="hidden sm:block text-6xl opacity-20">
                        <i class="fa fa-clipboard-list"></i>
                    </div>
                </div>
            </div>

            <!-- Key Statistics Section -->
            <div class="mb-8">
                <h3 class="text-xl font-bold text-lgu-headline mb-4 flex items-center">
                    <i class="fa fa-chart-bar mr-3 text-lgu-button"></i> Report Statistics
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <!-- Total Assigned -->
                    <div class="stat-card rounded-lg shadow-md p-5 border-l-4 border-lgu-button">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Assigned</p>
                                <p class="text-3xl font-bold text-lgu-headline"><?php echo $stats['total_assigned']; ?></p>
                            </div>
                            <div class="text-4xl text-lgu-button opacity-30">
                                <i class="fa fa-inbox"></i>
                            </div>
                        </div>
                        <p class="text-xs text-lgu-paragraph">All assigned reports</p>
                    </div>

                    <!-- Pending -->
                    <div class="stat-card rounded-lg shadow-md p-5 border-l-4 border-orange-500">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-lgu-paragraph uppercase">Pending</p>
                                <p class="text-3xl font-bold text-orange-600"><?php echo $stats['pending_reports']; ?></p>
                            </div>
                            <div class="text-4xl text-orange-500 opacity-30">
                                <i class="fa fa-clock"></i>
                            </div>
                        </div>
                        <p class="text-xs text-lgu-paragraph">Waiting to start</p>
                    </div>

                    <!-- In Progress -->
                    <div class="stat-card rounded-lg shadow-md p-5 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                                <p class="text-3xl font-bold text-blue-600"><?php echo $stats['in_progress_reports']; ?></p>
                            </div>
                            <div class="text-4xl text-blue-500 opacity-30">
                                <i class="fa fa-spinner"></i>
                            </div>
                        </div>
                        <p class="text-xs text-lgu-paragraph">Being inspected</p>
                    </div>

                    <!-- Completed -->
                    <div class="stat-card rounded-lg shadow-md p-5 border-l-4 border-green-500">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                                <p class="text-3xl font-bold text-green-600"><?php echo $stats['completed_reports']; ?></p>
                            </div>
                            <div class="text-4xl text-green-500 opacity-30">
                                <i class="fa fa-check-circle"></i>
                            </div>
                        </div>
                        <p class="text-xs text-lgu-paragraph">Findings submitted</p>
                    </div>

                    <!-- Escalated -->
                    <div class="stat-card rounded-lg shadow-md p-5 border-l-4 border-red-500">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-lgu-paragraph uppercase">Escalated</p>
                                <p class="text-3xl font-bold text-red-600"><?php echo $stats['escalated_reports']; ?></p>
                            </div>
                            <div class="text-4xl text-red-500 opacity-30">
                                <i class="fa fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <p class="text-xs text-lgu-paragraph">High severity</p>
                    </div>
                </div>
            </div>

            <!-- Severity and Verification Statistics -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Severity Overview -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-balance-scale mr-2 text-lgu-button"></i> Severity Overview
                    </h3>
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-lgu-headline">Minor Severity</span>
                                <span class="text-2xl font-bold text-green-600"><?php echo $stats['minor_severity']; ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                <div class="bg-green-500 h-full rounded-full transition-all duration-300" 
                                     style="width: <?php echo $stats['total_assigned'] > 0 ? ($stats['minor_severity'] / $stats['total_assigned'] * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-lgu-headline">Major Severity</span>
                                <span class="text-2xl font-bold text-red-600"><?php echo $stats['major_severity']; ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                <div class="bg-red-500 h-full rounded-full transition-all duration-300" 
                                     style="width: <?php echo $stats['total_assigned'] > 0 ? ($stats['major_severity'] / $stats['total_assigned'] * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <a href="minor_severity_reports.php" class="inline-flex items-center text-sm font-semibold text-lgu-button hover:text-lgu-stroke mt-2">
                            <i class="fa fa-arrow-right mr-2"></i> View by Severity
                        </a>
                    </div>
                </div>

                <!-- Project Verification Status -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-clipboard-check mr-2 text-lgu-button"></i> Verification Status
                    </h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                            <span class="font-semibold text-orange-800">Pending Verification</span>
                            <span class="text-2xl font-bold text-orange-600"><?php echo $stats['pending_verification']; ?></span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                            <span class="font-semibold text-green-800">Approved</span>
                            <span class="text-2xl font-bold text-green-600"><?php echo $stats['approved_verification']; ?></span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                            <span class="font-semibold text-red-800">Rejected</span>
                            <span class="text-2xl font-bold text-red-600"><?php echo $stats['rejected_verification']; ?></span>
                        </div>
                        <a href="work_done_verification.php" class="inline-flex items-center text-sm font-semibold text-lgu-button hover:text-lgu-stroke mt-2">
                            <i class="fa fa-arrow-right mr-2"></i> View Verification Details
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mb-8">
                <h3 class="text-xl font-bold text-lgu-headline mb-4 flex items-center">
                    <i class="fa fa-bolt mr-3 text-yellow-500"></i> Quick Actions
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="my_assigned_reports.php" class="quick-action-card bg-white rounded-lg shadow-md p-6 border-t-4 border-lgu-button hover:shadow-lg">
                        <div class="text-4xl text-lgu-button mb-3">
                            <i class="fa fa-list-check"></i>
                        </div>
                        <h4 class="font-bold text-lgu-headline mb-1">My Assigned Reports</h4>
                        <p class="text-sm text-lgu-paragraph">View all assigned reports</p>
                        <p class="text-xs text-lgu-button font-semibold mt-2"><?php echo $stats['total_assigned']; ?> reports</p>
                    </a>

                    <a href="conduct_inspection.php" class="quick-action-card bg-white rounded-lg shadow-md p-6 border-t-4 border-blue-500 hover:shadow-lg">
                        <div class="text-4xl text-blue-500 mb-3">
                            <i class="fa fa-clipboard-check"></i>
                        </div>
                        <h4 class="font-bold text-lgu-headline mb-1">Conduct Inspection</h4>
                        <p class="text-sm text-lgu-paragraph">Submit inspection findings</p>
                        <p class="text-xs text-blue-500 font-semibold mt-2"><?php echo $stats['pending_reports'] + $stats['in_progress_reports']; ?> pending</p>
                    </a>

                    <a href="under_construction_projects.php" class="quick-action-card bg-white rounded-lg shadow-md p-6 border-t-4 border-purple-500 hover:shadow-lg">
                        <div class="text-4xl text-purple-500 mb-3">
                            <i class="fa fa-hard-hat"></i>
                        </div>
                        <h4 class="font-bold text-lgu-headline mb-1">Active Projects</h4>
                        <p class="text-sm text-lgu-paragraph">View construction progress</p>
                        <p class="text-xs text-purple-500 font-semibold mt-2"><?php echo $stats['under_construction']; ?> projects</p>
                    </a>

                    <a href="work_done_verification.php" class="quick-action-card bg-white rounded-lg shadow-md p-6 border-t-4 border-green-500 hover:shadow-lg">
                        <div class="text-4xl text-green-500 mb-3">
                            <i class="fa fa-check-double"></i>
                        </div>
                        <h4 class="font-bold text-lgu-headline mb-1">Verify Work Done</h4>
                        <p class="text-sm text-lgu-paragraph">Review project completion</p>
                        <p class="text-xs text-green-500 font-semibold mt-2"><?php echo $stats['pending_verification']; ?> pending</p>
                    </a>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-6 py-4">
                    <h3 class="text-lg font-bold flex items-center">
                        <i class="fa fa-history mr-3"></i> Recent Assigned Reports
                    </h3>
                </div>
                
                <?php if (!empty($recent_activities)): ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item p-4 hover:bg-gray-50">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex-1">
                                        <h4 class="font-bold text-lgu-headline">
                                            Report #<?php echo $activity['id']; ?> - <?php echo ucfirst($activity['hazard_type']); ?>
                                        </h4>
                                        <p class="text-sm text-lgu-paragraph mt-1">
                                            <i class="fa fa-map-marker-alt mr-2 text-lgu-tertiary"></i>
                                            <?php echo htmlspecialchars(substr($activity['address'], 0, 50)); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <i class="fa fa-user mr-1"></i> Reporter: <?php echo htmlspecialchars($activity['reporter_name']); ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <?php 
                                            $statusClass = match($activity['status']) {
                                                'pending' => 'bg-orange-100 text-orange-700',
                                                'in_progress' => 'bg-blue-100 text-blue-700',
                                                'done' => 'bg-green-100 text-green-700',
                                                'escalated' => 'bg-red-100 text-red-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>
                                        <span class="<?php echo $statusClass; ?> px-3 py-1 rounded-full text-xs font-semibold">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between text-xs text-gray-500">
                                    <span>
                                        <i class="fa fa-clock mr-1"></i>
                                        Assigned: <?php echo date('M d, Y h:i A', strtotime($activity['assigned_at'])); ?>
                                    </span>
                                    <a href="view_report.php?id=<?php echo $activity['id']; ?>" class="text-lgu-button font-semibold hover:underline">
                                        View Details →
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-4 bg-gray-50 text-center border-t border-gray-200">
                        <a href="my_assigned_reports.php" class="inline-flex items-center text-sm font-semibold text-lgu-button hover:text-lgu-stroke">
                            <i class="fa fa-list mr-2"></i> View All Assigned Reports
                        </a>
                    </div>
                <?php else: ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-inbox text-4xl text-gray-300 mb-3"></i>
                        <p class="text-lgu-paragraph font-semibold">No recent assigned reports</p>
                        <p class="text-sm text-gray-500">Reports will appear here once they are assigned to you</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-6 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM - Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
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

            // Live Clock
            updateClock();
            setInterval(updateClock, 1000);

            // Notification System
            initializeNotifications();
        });

        function updateClock() {
            const now = new Date();
            const dateElement = document.getElementById('currentDate');
            const timeElement = document.getElementById('currentTime');
            
            if (dateElement) {
                dateElement.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            if (timeElement) {
                timeElement.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            }
        }

        function initializeNotifications() {
            const notificationBell = document.getElementById('notificationBell');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const notificationList = document.getElementById('notificationList');
            const notificationBadge = document.getElementById('notificationBadge');
            const notificationCount = document.getElementById('notificationCount');
            const clearAllBtn = document.getElementById('clearAllNotifications');

            let notifications = JSON.parse(localStorage.getItem('inspectorNotifications')) || [];

            // Toggle dropdown
            notificationBell.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationDropdown.classList.toggle('hidden');
                if (!notificationDropdown.classList.contains('hidden')) {
                    markNotificationsAsRead();
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!notificationDropdown.contains(e.target) && e.target !== notificationBell && !notificationBell.contains(e.target)) {
                    notificationDropdown.classList.add('hidden');
                }
            });

            // Mark all notifications as read
            const markAllReadBtn = document.getElementById('markAllReadBtn');
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', () => {
                    fetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'mark_all_read' })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        }
                    });
                });
            }

            // Clear all notifications
            clearAllBtn.addEventListener('click', () => {
                fetch('mark_notifications_read.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'clear_all' })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            });

            // Fetch notifications from server (simulated - replace with actual API call)
            fetchNotifications();

            // Poll for new notifications every 30 seconds
            setInterval(fetchNotifications, 30000);

            function fetchNotifications() {
                // Fetch notifications from server
                fetch('get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Merge with existing notifications (avoid duplicates)
                            data.notifications.forEach(newNotif => {
                                if (!notifications.some(n => n.id === newNotif.id)) {
                                    notifications.unshift(newNotif);
                                }
                            });

                            // Keep only last 50 notifications
                            notifications = notifications.slice(0, 50);
                            localStorage.setItem('inspectorNotifications', JSON.stringify(notifications));
                            updateNotificationUI();
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching notifications:', error);
                    });
            }

            function updateNotificationUI() {
                const unreadCount = notifications.filter(n => !n.read).length;

                // Update badge
                if (unreadCount > 0) {
                    notificationBadge.classList.remove('hidden');
                    notificationCount.textContent = unreadCount > 9 ? '9+' : unreadCount;
                } else {
                    notificationBadge.classList.add('hidden');
                }

                // Update list
                if (notifications.length === 0) {
                    notificationList.innerHTML = `
                        <div class="p-8 text-center text-gray-500">
                            <i class="fa fa-inbox text-3xl mb-2 block opacity-50"></i>
                            <p class="text-sm">No notifications</p>
                        </div>
                    `;
                } else {
                    notificationList.innerHTML = notifications.slice(0, 10).map(notif => `
                        <div class="notification-item ${notif.read ? 'bg-white' : 'bg-blue-50'} border-b border-gray-100 p-4 hover:bg-gray-50 cursor-pointer transition" data-id="${notif.id}">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-${notif.color}-100 flex items-center justify-center text-${notif.color}-600">
                                    <i class="fa ${notif.icon}"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-semibold text-lgu-headline text-sm">
                                        ${notif.title}
                                        ${!notif.read ? '<span class="inline-block w-2 h-2 bg-lgu-button rounded-full ml-2"></span>' : ''}
                                    </h4>
                                    <p class="text-sm text-lgu-paragraph mt-0.5 line-clamp-2">${notif.message}</p>
                                    <p class="text-xs text-gray-500 mt-1">${getTimeAgo(notif.timestamp)}</p>
                                </div>
                                <button class="flex-shrink-0 text-gray-400 hover:text-red-500 delete-notif" data-id="${notif.id}">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        </div>
                    `).join('');

                    // Add click listeners
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const id = parseInt(this.dataset.id);
                            handleNotificationClick(id);
                        });
                    });

                    // Add delete listeners
                    document.querySelectorAll('.delete-notif').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const id = parseInt(this.dataset.id);
                            notifications = notifications.filter(n => n.id !== id);
                            localStorage.setItem('inspectorNotifications', JSON.stringify(notifications));
                            updateNotificationUI();
                        });
                    });
                }
            }

            function markNotificationsAsRead() {
                notifications.forEach(n => n.read = true);
                localStorage.setItem('inspectorNotifications', JSON.stringify(notifications));
                updateNotificationUI();
            }

            // Add click listeners for server-rendered notifications
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const type = this.dataset.type;
                    
                    // Mark as read
                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id})
                    });
                    
                    // Navigate based on type
                    switch(type) {
                        case 'report':
                            window.location.href = 'my_assigned_reports.php';
                            break;
                        case 'project':
                            window.location.href = 'under_construction_projects.php';
                            break;
                        case 'verification':
                            window.location.href = 'work_done_verification.php';
                            break;
                        default:
                            notificationDropdown.classList.add('hidden');
                    }
                });
            });

            function handleNotificationClick(id) {
                const notif = notifications.find(n => n.id === id);
                if (notif) {
                    switch(notif.type) {
                        case 'report':
                            window.location.href = 'my_assigned_reports.php';
                            break;
                        case 'project':
                            window.location.href = 'under_construction_projects.php';
                            break;
                        case 'verification':
                            window.location.href = 'work_done_verification.php';
                            break;
                        default:
                            notificationDropdown.classList.add('hidden');
                    }
                }
            }

            function getTimeAgo(date) {
                const seconds = Math.floor((new Date() - date) / 1000);
                let interval = seconds / 31536000;

                if (interval > 1) return Math.floor(interval) + 'y ago';
                interval = seconds / 2592000;
                if (interval > 1) return Math.floor(interval) + 'mo ago';
                interval = seconds / 86400;
                if (interval > 1) return Math.floor(interval) + 'd ago';
                interval = seconds / 3600;
                if (interval > 1) return Math.floor(interval) + 'h ago';
                interval = seconds / 60;
                if (interval > 1) return Math.floor(interval) + 'm ago';
                return Math.floor(seconds) + 's ago';
            }
        }
    </script>

</body>
</html>