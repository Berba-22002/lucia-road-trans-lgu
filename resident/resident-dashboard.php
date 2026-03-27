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

// Initialize variables
$submitted_reports = $pending_reports = $in_progress_reports = $done_reports = $escalated_reports = 0;
$pending_validation = $validated_reports = $rejected_reports = 0;
$reports = [];
$reports_by_type = [];

try {
    // Get report counts by status
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) AS escalated
        FROM reports 
        WHERE user_id = ? AND status != 'archived'
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $submitted_reports = (int)($row['total'] ?? 0);
    $pending_reports = (int)($row['pending'] ?? 0);
    $in_progress_reports = (int)($row['in_progress'] ?? 0);
    $done_reports = (int)($row['done'] ?? 0);
    $escalated_reports = (int)($row['escalated'] ?? 0);

    // Get validation status counts
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN validation_status = 'pending' THEN 1 ELSE 0 END) AS pending_validation,
            SUM(CASE WHEN validation_status = 'validated' THEN 1 ELSE 0 END) AS validated,
            SUM(CASE WHEN validation_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
        FROM reports 
        WHERE user_id = ? AND status != 'archived'
    ");
    $stmt->execute([$user_id]);
    $val = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_validation = (int)($val['pending_validation'] ?? 0);
    $validated_reports = (int)($val['validated'] ?? 0);
    $rejected_reports = (int)($val['rejected'] ?? 0);

    // Get reports by type
    $stmt = $pdo->prepare("SELECT hazard_type, COUNT(*) AS count FROM reports WHERE user_id = ? GROUP BY hazard_type");
    $stmt->execute([$user_id]);
    $reports_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent reports
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE user_id = ? AND status != 'archived' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get monthly trend (last 6 months)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%b %Y') AS month_label, COUNT(*) AS count
        FROM reports
        WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m') ASC
    ");
    $stmt->execute([$user_id]);
    $monthly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user email for notification filtering
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_email = $stmt->fetch(PDO::FETCH_ASSOC)['email'];

    // Get notifications for the registered email
    $stmt = $pdo->prepare("SELECT n.* FROM notifications n INNER JOIN users u ON n.user_id = u.id WHERE u.email = ? ORDER BY n.created_at DESC LIMIT 10");
    $stmt->execute([$user_email]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread notification count for the registered email
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications n INNER JOIN users u ON n.user_id = u.id WHERE u.email = ? AND n.is_read = 0");
    $stmt->execute([$user_email]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // For debugging - remove this in production
    die("Database Error: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - LGU Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        
        /* Animations */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-slide-in {
            animation: slideInUp 0.5s ease-out forwards;
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .quick-action {
            transition: all 0.3s ease;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
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
                        <h1 class="text-xl font-bold text-lgu-headline">Dashboard</h1>
                        <p class="text-sm text-lgu-paragraph">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    </div>
                </div>

                <div class="flex items-center space-x-4">
                    <!-- Live Clock -->
                    <div class="text-right hidden md:block">
                        <div id="currentDate" class="text-xs font-semibold text-lgu-headline"></div>
                        <div id="currentTime" class="text-sm font-bold text-lgu-button"></div>
                    </div>
                    
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
                                    <div class="flex space-x-2">
                                        <?php if ($unread_count > 0): ?>
                                        <button id="mark-all-read-btn" class="text-sm text-lgu-button hover:text-lgu-stroke">Mark all read</button>
                                        <?php endif; ?>
                                        <?php if (count($notifications) > 0): ?>
                                        <button id="clear-all-btn" class="text-sm text-red-500 hover:text-red-700">Clear all</button>
                                        <?php endif; ?>
                                    </div>
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
                            <p class="text-sm font-medium text-lgu-headline"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                            <p class="text-xs text-lgu-paragraph">Resident User</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <div class="mb-6">
                <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold mb-2">Good Morning, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
                            <p class="text-gray-200">Here's what's happening with your infrastructure reports today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="stat-card bg-white p-4 lg:p-6 rounded-lg shadow-md border-l-4 border-lgu-button animate-slide-in">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-lgu-paragraph text-xs font-semibold uppercase">Total Reports</p>
                            <p class="text-2xl lg:text-3xl font-bold text-lgu-headline mt-2"><?php echo $submitted_reports; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-list text-lgu-button text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-white p-4 lg:p-6 rounded-lg shadow-md border-l-4 border-orange-500 animate-slide-in" style="animation-delay: 0.1s;">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-lgu-paragraph text-xs font-semibold uppercase">Pending</p>
                            <p class="text-2xl lg:text-3xl font-bold text-orange-600 mt-2"><?php echo $pending_reports; ?></p>
                            <p class="text-xs text-orange-500 mt-1 font-medium"><?php echo $pending_validation; ?> awaiting validation</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-orange-500 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-white p-4 lg:p-6 rounded-lg shadow-md border-l-4 border-blue-500 animate-slide-in" style="animation-delay: 0.2s;">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-lgu-paragraph text-xs font-semibold uppercase">In Progress</p>
                            <p class="text-2xl lg:text-3xl font-bold text-blue-600 mt-2"><?php echo $in_progress_reports; ?></p>
                            <p class="text-xs text-blue-500 mt-1 font-medium">Being worked on</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-spinner text-blue-500 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white p-4 lg:p-6 rounded-lg shadow-md border-l-4 border-green-500 animate-slide-in" style="animation-delay: 0.3s;">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-lgu-paragraph text-xs font-semibold uppercase">Completed</p>
                            <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-2"><?php echo $done_reports; ?></p>
                            <p class="text-xs text-green-500 mt-1 font-medium"><?php echo $escalated_reports; ?> escalated</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-500 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold text-lgu-headline mb-4">My Reports by Type</h3>
                    <div class="chart-container" style="position: relative; height: 280px; width: 100%;">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>

                <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold text-lgu-headline mb-4">Report Status Overview</h3>
                    <div class="chart-container" style="position: relative; height: 280px; width: 100%;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <?php if (count($monthly_trend) > 0): ?>
            <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md mb-6 lg:mb-8">
                <h3 class="text-lg font-semibold text-lgu-headline mb-4">My Reporting Activity (Last 6 Months)</h3>
                <div class="chart-container" style="position: relative; height: 280px; width: 100%;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Reports -->
            <div class="bg-white p-4 lg:p-6 rounded-lg shadow-md mb-6 lg:mb-8">
                <h3 class="text-lg lg:text-xl font-bold text-lgu-headline mb-4">My Recent Reports</h3>
                <div class="responsive-table">
                    <table class="min-w-full">
                        <thead>
                            <tr class="bg-lgu-bg">
                                <th class="px-3 py-2 lg:px-4 lg:py-2 text-left text-sm">Issue Type</th>
                                <th class="px-3 py-2 lg:px-4 lg:py-2 text-left text-sm">Location</th>
                                <th class="px-3 py-2 lg:px-4 lg:py-2 text-left text-sm">Date Reported</th>
                                <th class="px-3 py-2 lg:px-4 lg:py-2 text-left text-sm">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($reports) > 0): ?>
                                <?php foreach ($reports as $report): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="border px-3 py-2 lg:px-4 lg:py-2 text-sm">
                                        <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-semibold">
                                            <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="border px-3 py-2 lg:px-4 lg:py-2 text-sm"><?php echo htmlspecialchars(substr($report['address'], 0, 40)) . (strlen($report['address']) > 40 ? '...' : ''); ?></td>
                                    <td class="border px-3 py-2 lg:px-4 lg:py-2 text-sm"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                                    <td class="border px-3 py-2 lg:px-4 lg:py-2 text-sm">
                                        <?php
                                        $status_classes = [
                                            'pending' => 'bg-orange-100 text-orange-700',
                                            'in_progress' => 'bg-blue-100 text-blue-700',
                                            'done' => 'bg-green-100 text-green-700',
                                            'escalated' => 'bg-red-100 text-red-700'
                                        ];
                                        $class = $status_classes[$report['status']] ?? 'bg-gray-100 text-gray-700';
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="border px-3 py-2 lg:px-4 lg:py-2 text-center text-sm text-lgu-paragraph">No reports found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <a href="reports_history.php" class="block text-center mt-4 text-lgu-button hover:text-lgu-stroke font-semibold text-sm lg:text-base">View All Reports →</a>
            </div>
            
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6 mb-8">
                <a href="report_hazard.php" class="quick-action bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-4 px-6 rounded-lg flex items-center justify-between shadow-md">
                    <div><i class="fa fa-plus-circle mr-2"></i> Report Issue</div>
                    <i class="fa fa-arrow-right"></i>
                </a>
                <a href="reports_history.php" class="quick-action bg-lgu-headline hover:bg-lgu-stroke text-white font-bold py-4 px-6 rounded-lg flex items-center justify-between shadow-md">
                    <div><i class="fa fa-history mr-2"></i> View History</div>
                    <i class="fa fa-arrow-right"></i>
                </a>
                <a href="view_status.php" class="quick-action bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-6 rounded-lg flex items-center justify-between shadow-md">
                    <div><i class="fa fa-eye mr-2"></i> Check Status</div>
                    <i class="fa fa-arrow-right"></i>
                </a>
            </div>

            <!-- Emergency Contact -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-6 mb-8">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-phone-alt text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-red-800">Emergency Contact</h3>
                        <p class="text-red-600 text-sm">For urgent matters requiring immediate attention</p>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-red-800 font-semibold">0919-075-5101</span>
                    <span class="text-xs text-red-600">Available 24/7</span>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 lg:mt-12">
            <div class="container mx-auto px-4 lg:px-6 text-center">
                <p class="text-sm lg:text-base">&copy; <?php echo date('Y'); ?> Road and Traffic Infrastructure Management System</p>
                <p class="mt-2 text-xs lg:text-sm">LGU - Local Government Unit</p>
            </div>
        </footer>
    </div>

    <script>
        // Live Clock
        document.addEventListener('DOMContentLoaded', function() {
            const updateClock = () => {
                const dateEl = document.getElementById('currentDate');
                const timeEl = document.getElementById('currentTime');
                const now = new Date();
                
                if (dateEl) {
                    dateEl.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
                if (timeEl) {
                    timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
                }
            };
            updateClock();
            setInterval(updateClock, 1000);

            // Mobile sidebar toggle functionality
            const sidebar = document.getElementById('residents-sidebar');
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
                Swal.fire({
                    title: 'Marking as read...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                fetch('mark_notifications_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_all_read' })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Done!',
                            text: 'All notifications marked as read',
                            confirmButtonColor: '#faae2b'
                        }).then(() => location.reload());
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Failed to mark notifications', 'error');
                    console.error('Error:', err);
                });
            });
        }

        // Clear all notifications
        const clearAllBtn = document.getElementById('clear-all-btn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', () => {
                Swal.fire({
                    title: 'Clear all notifications?',
                    text: 'This action cannot be undone',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#faae2b',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, clear all'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Clearing...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });
                        fetch('mark_notifications_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'clear_all' })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Cleared!',
                                    text: 'All notifications have been cleared',
                                    confirmButtonColor: '#faae2b'
                                }).then(() => location.reload());
                            }
                        })
                        .catch(err => {
                            Swal.fire('Error', 'Failed to clear notifications', 'error');
                            console.error('Error:', err);
                        });
                    }
                });
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
                        item.style.opacity = '0';
                        item.style.transition = 'opacity 0.3s ease';
                        setTimeout(() => item.remove(), 300);
                        
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

        // Charts data
        const reportsByType = <?php echo json_encode($reports_by_type); ?> || [];
        const statusData = {
            pending: <?php echo $pending_reports; ?>,
            in_progress: <?php echo $in_progress_reports; ?>,
            done: <?php echo $done_reports; ?>,
            escalated: <?php echo $escalated_reports; ?>
        };
        const monthlyTrend = <?php echo json_encode($monthly_trend); ?> || [];

        // Chart.js default configuration
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#475d5b';

        // Reports by type chart (Doughnut)
        const typeCtx = document.getElementById('typeChart');
        if (typeCtx && reportsByType.length > 0) {
            new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: reportsByType.map(i => {
                        const type = i.hazard_type || 'Other';
                        return type.charAt(0).toUpperCase() + type.slice(1);
                    }),
                    datasets: [{
                        data: reportsByType.map(i => i.count),
                        backgroundColor: [
                            '#faae2b',
                            '#00473e',
                            '#4f46e5',
                            '#ef4444',
                            '#10b981',
                            '#f59e0b'
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                font: { size: 12 }
                            }
                        }
                    }
                }
            });
        } else if (typeCtx) {
            typeCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No reports yet</p>';
        }

        // Status chart (Doughnut)
        const statusCtx = document.getElementById('statusChart');
        const hasStatusData = Object.values(statusData).some(val => val > 0);
        if (statusCtx && hasStatusData) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'In Progress', 'Done', 'Escalated'],
                    datasets: [{
                        data: [statusData.pending, statusData.in_progress, statusData.done, statusData.escalated],
                        backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#ef4444'],
                        borderColor: '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                font: { size: 12 }
                            }
                        }
                    }
                }
            });
        } else if (statusCtx) {
            statusCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No reports yet</p>';
        }

        // Monthly trend chart (Line)
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx && monthlyTrend.length > 0) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: monthlyTrend.map(i => i.month_label),
                    datasets: [{
                        label: 'Reports Submitted',
                        data: monthlyTrend.map(i => i.count),
                        borderColor: '#faae2b',
                        backgroundColor: 'rgba(250,174,43,0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3,
                        pointBackgroundColor: '#faae2b',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });
        }    </script>
</body>
</html>
</body>