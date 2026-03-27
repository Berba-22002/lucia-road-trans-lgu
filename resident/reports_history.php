<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is a resident
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    die("Database connection failed");
}

$user_id = $_SESSION['user_id'];

// Get notifications
try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread notification count
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
} catch (PDOException $e) {
    $notifications = [];
    $unread_count = 0;
}

// Pagination setup
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$hazard_filter = isset($_GET['hazard_type']) ? $_GET['hazard_type'] : '';

try {
    // Build query for fetching reports
    $query = "SELECT r.*, 
                     GROUP_CONCAT(DISTINCT ri.status) as inspection_status,
                     rf.rating, rf.feedback_text
              FROM reports r 
              LEFT JOIN report_inspectors ri ON r.id = ri.report_id 
              LEFT JOIN report_feedback rf ON r.id = rf.report_id 
              WHERE r.user_id = :user_id";

    $params = [':user_id' => $user_id];

    // Add search condition
    if (!empty($search)) {
        $query .= " AND (r.description LIKE :search OR r.address LIKE :search2 OR r.hazard_type LIKE :search3)";
        $params[':search'] = "%$search%";
        $params[':search2'] = "%$search%";
        $params[':search3'] = "%$search%";
    }

    // Add status filter
    if (!empty($status_filter)) {
        $query .= " AND r.status = :status";
        $params[':status'] = $status_filter;
    }

    // Add hazard type filter
    if (!empty($hazard_filter)) {
        $query .= " AND r.hazard_type = :hazard_type";
        $params[':hazard_type'] = $hazard_filter;
    }

    // Complete query with grouping and ordering
    $query .= " GROUP BY r.id ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    // Prepare and execute query
    $stmt = $pdo->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $count_query = "SELECT COUNT(DISTINCT r.id) as total 
                    FROM reports r 
                    WHERE r.user_id = :user_id";
    $count_params = [':user_id' => $user_id];

    if (!empty($search)) {
        $count_query .= " AND (r.description LIKE :search OR r.address LIKE :search2 OR r.hazard_type LIKE :search3)";
        $count_params[':search'] = "%$search%";
        $count_params[':search2'] = "%$search%";
        $count_params[':search3'] = "%$search%";
    }

    if (!empty($status_filter)) {
        $count_query .= " AND r.status = :status";
        $count_params[':status'] = $status_filter;
    }

    if (!empty($hazard_filter)) {
        $count_query .= " AND r.hazard_type = :hazard_type";
        $count_params[':hazard_type'] = $hazard_filter;
    }

    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($count_params);
    $total_rows = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_rows / $limit);

    // Get unique hazard types for filter dropdown
    $hazard_types_query = "SELECT DISTINCT hazard_type FROM reports WHERE user_id = :user_id ORDER BY hazard_type";
    $hazard_stmt = $pdo->prepare($hazard_types_query);
    $hazard_stmt->execute([':user_id' => $user_id]);
    $hazard_types = $hazard_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Reports history error: " . $e->getMessage());
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports History - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .sidebar-link {
            color: #9CA3AF;
        }
        
        .sidebar-link:hover {
            color: #FFFFFF;
            background-color: #00332c;
        }
        
        .sidebar-link.active {
            color: #faae2b;
            background-color: #00332c;
            border-left: 3px solid #faae2b;
        }
        
        .sidebar-submenu {
            transition: all 0.3s ease-in-out;
        }
        
        .rotate-180 {
            transform: rotate(180deg);
        }
        
        /* Custom scrollbar for sidebar */
        #residents-sidebar nav::-webkit-scrollbar {
            width: 4px;
        }
        
        #residents-sidebar nav::-webkit-scrollbar-track {
            background: #00332c;
        }
        
        #residents-sidebar nav::-webkit-scrollbar-thumb {
            background: #faae2b;
            border-radius: 2px;
        }
        
        #residents-sidebar nav::-webkit-scrollbar-thumb:hover {
            background: #e09900;
        }
        
        .group:hover .group-hover\:block {
            display: block;
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
        
        /* Table responsiveness */
        @media (max-width: 640px) {
            .responsive-table {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
        
        .rating-stars {
            color: #ffc107;
        }
        .report-image {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 0.375rem;
        }
        
        /* Notification dropdown styles */
        #notification-menu {
            transform: translateY(-10px);
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }
        
        #notification-menu.show {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
            display: block !important;
        }
        
        .notification-item.unread {
            border-left: 3px solid #faae2b;
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="lg:ml-64 min-h-screen">
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center space-x-3 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline hover:text-lgu-highlight flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-lg lg:text-xl font-bold text-lgu-headline truncate">My Reports History</h1>
                        <p class="text-xs lg:text-sm text-lgu-paragraph truncate">View and manage your submitted reports</p>
                    </div>
                </div>

                <div class="flex items-center space-x-4 flex-shrink-0">

                    <!-- Notifications Dropdown -->
                    <div class="relative">
                        <button id="notification-btn" class="p-2 text-lgu-paragraph hover:text-lgu-headline relative">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5-5V9a6 6 0 10-12 0v3l-5 5h5m7 0v1a3 3 0 01-6 0v-1m6 0H9"></path>
                            </svg>
                            <?php if ($unread_count > 0): ?>
                            <span id="notification-badge" class="absolute -top-1 -right-1 bg-lgu-tertiary text-white text-xs rounded-full h-5 w-5 flex items-center justify-center"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div id="notification-menu" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 z-50 hidden">
                            <div class="p-4 border-b border-gray-200">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-lgu-headline">Notifications</h3>
                                    <?php if ($unread_count > 0): ?>
                                    <button id="mark-all-read-btn" class="text-sm text-lgu-button hover:text-lgu-stroke">Mark all read</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer <?php echo $notification['is_read'] ? '' : 'unread bg-blue-50'; ?>" data-notification-id="<?php echo $notification['id']; ?>">
                                        <div class="flex items-start space-x-3">
                                            <div class="flex-shrink-0">
                                                <?php
                                                $icon_classes = [
                                                    'info' => 'text-blue-500 fas fa-info-circle',
                                                    'success' => 'text-green-500 fas fa-check-circle',
                                                    'warning' => 'text-yellow-500 fas fa-exclamation-triangle',
                                                    'error' => 'text-red-500 fas fa-times-circle'
                                                ];
                                                $icon_class = $icon_classes[$notification['type']] ?? 'text-gray-500 fas fa-bell';
                                                ?>
                                                <i class="<?php echo $icon_class; ?>"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-lgu-headline"><?php echo htmlspecialchars($notification['title']); ?></p>
                                                <p class="text-sm text-lgu-paragraph mt-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                <p class="text-xs text-gray-400 mt-2"><?php echo date('M d, Y g:i A', strtotime($notification['created_at'])); ?></p>
                                            </div>
                                            <?php if (!$notification['is_read']): ?>
                                            <div class="flex-shrink-0">
                                                <div class="w-2 h-2 bg-lgu-button rounded-full"></div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-8 text-center">
                                        <i class="fas fa-bell-slash text-gray-300 text-3xl mb-3"></i>
                                        <p class="text-gray-500">No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-lgu-highlight rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-lgu-button-text" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-sm font-medium text-lgu-headline"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></p>
                            <p class="text-xs text-lgu-paragraph">Resident User</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <!-- Header Section -->
            <div class="mb-6">
                <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold mb-2">My Reports History</h2>
                            <p class="text-gray-200">Track the status of your submitted infrastructure reports</p>
                        </div>
                        <div class="hidden md:block">
                            <a href="report_hazard.php" class="bg-lgu-button text-lgu-button-text font-bold py-3 px-6 rounded-lg hover:bg-yellow-400 transition inline-flex items-center">
                                <i class="fas fa-plus mr-2"></i> Report New Incident
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md mb-6">
                <h3 class="text-lg font-bold text-lgu-headline mb-4">Filter Reports</h3>
                <form method="GET" action="">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                        <div>
                            <label for="status" class="block text-sm font-medium text-lgu-paragraph mb-1">Status</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-highlight focus:border-transparent" 
                                    id="status" name="status">
                                <option value="">All Status</option>
                                <option value="done" <?php echo $status_filter === 'done' ? 'selected' : ''; ?>>Done</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="inspection_ended" <?php echo $status_filter === 'inspection_ended' ? 'selected' : ''; ?>>Inspection Ended</option>
                                <option value="escalated" <?php echo $status_filter === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                            </select>
                        </div>
                        <div>
                            <label for="hazard_type" class="block text-sm font-medium text-lgu-paragraph mb-1">Hazard Type</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-highlight focus:border-transparent" 
                                    id="hazard_type" name="hazard_type">
                                <option value="">All Hazard Types</option>
                                <?php foreach ($hazard_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['hazard_type']); ?>" 
                                            <?php echo $hazard_filter === $type['hazard_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['hazard_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-lgu-button text-lgu-button-text font-bold py-2 px-4 rounded-lg hover:bg-yellow-400 transition inline-flex items-center justify-center">
                                <i class="fas fa-filter mr-2"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Reports List -->
            <?php if (empty($reports)): ?>
                <div class="bg-white p-8 lg:p-12 rounded-lg shadow-md text-center">
                    <i class="fas fa-clipboard-list fa-3x text-gray-400 mb-4"></i>
                    <h3 class="text-xl font-bold text-lgu-headline mb-2">No reports found</h3>
                    <p class="text-lgu-paragraph mb-6">
                        <?php echo (!empty($status_filter) || !empty($hazard_filter)) ? 
                            'Try adjusting your filters.' : 
                            'You haven\'t submitted any reports yet.'; ?>
                    </p>
                    <?php if (empty($status_filter) && empty($hazard_filter)): ?>
                        <a href="report_hazard.php" class="bg-lgu-button text-lgu-button-text font-bold py-3 px-6 rounded-lg hover:bg-yellow-400 transition inline-flex items-center">
                            <i class="fas fa-plus mr-2"></i> Report Your First Incident
                        </a>
                    <?php else: ?>
                        <a href="reports_history.php" class="bg-lgu-button text-lgu-button-text font-bold py-3 px-6 rounded-lg hover:bg-yellow-400 transition inline-flex items-center">
                            <i class="fas fa-times mr-2"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($reports as $report): ?>
                        <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md">
                            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between">
                                <div class="flex-1">
                                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between mb-3">
                                        <h4 class="text-lg font-bold text-lgu-headline mb-2 lg:mb-0">
                                            <?php echo htmlspecialchars($report['hazard_type']); ?>
                                        </h4>
                                        <div class="flex flex-wrap gap-2">
                                            <span class="px-3 py-1 rounded-full text-xs font-medium 
                                                <?php switch($report['status']) {
                                                    case 'done': echo 'bg-green-100 text-green-800'; break;
                                                    case 'in_progress': echo 'bg-blue-100 text-blue-800'; break;
                                                    case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                    case 'inspection_ended': echo 'bg-indigo-100 text-indigo-800'; break;
                                                    case 'escalated': echo 'bg-red-100 text-red-800'; break;
                                                    default: echo 'bg-gray-100 text-gray-800';
                                                } ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                            </span>
                                            <?php if (isset($report['validation_status']) && $report['validation_status'] === 'validated'): ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Validated</span>
                                            <?php elseif (isset($report['validation_status']) && $report['validation_status'] === 'rejected'): ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">Rejected</span>
                                            <?php elseif (isset($report['validation_status'])): ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending Validation</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <p class="text-lgu-paragraph mb-3">
                                        <?php echo htmlspecialchars($report['description']); ?>
                                    </p>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-lgu-paragraph mb-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-map-marker-alt mr-2 text-lgu-highlight"></i>
                                            <span><?php echo htmlspecialchars($report['address']); ?></span>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-clock mr-2 text-lgu-highlight"></i>
                                            <span><?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?></span>
                                        </div>
                                    </div>

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
                                                $hazardLevel = $ai_data['hazardLevel'] ?? 'low';
                                                $levelColors = [
                                                    'high' => ['bg' => 'bg-red-100', 'text' => 'text-red-700', 'label' => 'HIGH'],
                                                    'medium' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'label' => 'MEDIUM'],
                                                    'low' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'label' => 'LOW']
                                                ];
                                                $colors = $levelColors[$hazardLevel] ?? $levelColors['low'];
                                                ?>
                                                <div class="mt-3 p-2 <?php echo $colors['bg']; ?> border border-gray-300 rounded-lg">
                                                    <div class="flex items-center <?php echo $colors['text']; ?>">
                                                        <i class="fa fa-exclamation-circle mr-2 text-xs"></i>
                                                        <span class="font-bold text-xs"><?php echo $colors['label']; ?> (<?php echo number_format($topPrediction['probability'] * 100, 1); ?>%)</span>
                                                    </div>
                                                </div>
                                                <?php if ($topPrediction['probability'] > 0.6): ?>
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

                                    <?php if (isset($report['rating']) && $report['rating']): ?>
                                        <div class="mt-3 p-3 bg-lgu-bg rounded-lg">
                                            <p class="font-medium text-lgu-headline mb-1">Your Feedback</p>
                                            <div class="rating-stars mb-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $report['rating'] ? '' : '-empty'; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <?php if (!empty($report['feedback_text'])): ?>
                                                <p class="text-lgu-paragraph text-sm">"<?php echo htmlspecialchars($report['feedback_text']); ?>"</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-4 lg:mt-0 lg:ml-6 lg:text-right">
                                    <?php if (isset($report['image_path']) && $report['image_path']): ?>
                                        <?php
                                        // Handle different image path formats
                                        $media_src = $report['image_path'];
                                        if (substr($media_src, 0, 4) !== 'http' && substr($media_src, 0, 1) !== '/') {
                                            if (substr($media_src, 0, 8) !== 'uploads/') {
                                                $media_src = 'uploads/hazard_reports/' . $media_src;
                                            }
                                            $media_src = '../' . $media_src;
                                        }
                                        $ext = strtolower(pathinfo($report['image_path'], PATHINFO_EXTENSION));
                                        $is_video = in_array($ext, ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv']);
                                        ?>
                                        <?php if ($is_video): ?>
                                            <video controls class="report-image mb-3 mx-auto lg:mx-0" style="max-height: 200px;">
                                                <source src="<?php echo htmlspecialchars($media_src); ?>" type="video/mp4">
                                                Your browser does not support the video tag.
                                            </video>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($media_src); ?>" 
                                                 alt="Report Image" class="report-image mb-3 mx-auto lg:mx-0">
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="flex flex-col sm:flex-row lg:flex-col gap-2">
                                        <a href="view_report.php?id=<?php echo $report['id']; ?>" 
                                           class="bg-white border border-lgu-headline text-lgu-headline font-medium py-2 px-4 rounded-lg hover:bg-lgu-headline hover:text-white transition inline-flex items-center justify-center">
                                            <i class="fas fa-eye mr-2"></i> View Details
                                        </a>
                                        <?php if ($report['status'] === 'done' && empty($report['rating'])): ?>
                                            <a href="give_feedback.php?report_id=<?php echo $report['id']; ?>" 
                                               class="bg-lgu-highlight text-lgu-button-text font-medium py-2 px-4 rounded-lg hover:bg-yellow-400 transition inline-flex items-center justify-center">
                                                <i class="fas fa-star mr-2"></i> Give Feedback
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="bg-white p-4 rounded-lg shadow-md">
                            <nav class="flex items-center justify-between">
                                <div class="text-sm text-lgu-paragraph">
                                    Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> results
                                </div>
                                <div class="flex space-x-1">
                                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status_filter); ?>&hazard_type=<?php echo urlencode($hazard_filter); ?>" 
                                       class="px-3 py-2 rounded-lg border border-gray-300 text-lgu-paragraph hover:bg-gray-50 <?php echo $page <= 1 ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                        Previous
                                    </a>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&hazard_type=<?php echo urlencode($hazard_filter); ?>" 
                                           class="px-3 py-2 rounded-lg border <?php echo $i === $page ? 'bg-lgu-headline text-white border-lgu-headline' : 'border-gray-300 text-lgu-paragraph hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status_filter); ?>&hazard_type=<?php echo urlencode($hazard_filter); ?>" 
                                       class="px-3 py-2 rounded-lg border border-gray-300 text-lgu-paragraph hover:bg-gray-50 <?php echo $page >= $total_pages ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                        Next
                                    </a>
                                </div>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8">
            <div class="container mx-auto px-4 lg:px-6 text-center">
                <p class="text-sm lg:text-base">&copy; <?php echo date('Y'); ?> Road and Traffic Infrastructure Management System</p>
                <p class="mt-2 text-xs lg:text-sm">LGU - Local Government Unit</p>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle functionality
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

            // Event listeners for mobile sidebar
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarClose) {
                sidebarClose.addEventListener('click', closeSidebar);
            }
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', closeSidebar);
            }

            // Confirm logout
            document.querySelectorAll('a[href*="logout.php"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to logout?')) {
                        e.preventDefault();
                    }
                });
            });
            
            // Notification functionality
            const notificationBtn = document.getElementById('notification-btn');
            const notificationMenu = document.getElementById('notification-menu');
            const markAllReadBtn = document.getElementById('mark-all-read-btn');
            
            if (notificationBtn && notificationMenu) {
                notificationBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notificationMenu.classList.toggle('show');
                });

                document.addEventListener('click', (e) => {
                    if (!notificationMenu.contains(e.target) && !notificationBtn.contains(e.target)) {
                        notificationMenu.classList.remove('show');
                    }
                });
            }

            // Mark all notifications as read
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', () => {
                    fetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'mark_all_read' })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        }
                    })
                    .catch(err => console.error('Error:', err));
                });
            }

            // Mark individual notification as read
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', () => {
                    const notifId = item.getAttribute('data-notification-id');
                    fetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'mark_single_read', notification_id: notifId })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            item.classList.remove('unread');
                            item.style.opacity = '0.6';
                            
                            // Update badge count
                            const badge = document.getElementById('notification-badge');
                            if (badge) {
                                let currentCount = parseInt(badge.textContent);
                                currentCount--;
                                if (currentCount <= 0) {
                                    badge.remove();
                                } else {
                                    badge.textContent = currentCount;
                                }
                            }
                        }
                    })
                    .catch(err => console.error('Error:', err));
                });
            });
        });
    </script>
</body>
</html>