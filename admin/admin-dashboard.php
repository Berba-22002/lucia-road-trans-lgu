<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

// Initialize variables
$total_reports = $pending_reports = $in_progress_reports = $done_reports = $escalated_reports = 0;
$pending_validation = $validated_reports = $rejected_reports = 0;
$approved_projects = $under_construction_projects = $completed_projects = 0;
$recent_reports = $recent_projects = [];
$reports_by_type = $monthly_trend = [];
$forwarded_reports = $assigned_maintenance = 0;

try {
    // Total reports and status breakdown (using actual status values)
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) AS escalated
        FROM reports
        WHERE status != 'archived'
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_reports = (int)($row['total'] ?? 0);
    $pending_reports = (int)($row['pending'] ?? 0);
    $in_progress_reports = (int)($row['in_progress'] ?? 0);
    $done_reports = (int)($row['done'] ?? 0);
    $escalated_reports = (int)($row['escalated'] ?? 0);

    // Validation status breakdown
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN validation_status = 'pending' THEN 1 ELSE 0 END) AS pending_validation,
            SUM(CASE WHEN validation_status = 'validated' THEN 1 ELSE 0 END) AS validated,
            SUM(CASE WHEN validation_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
        FROM reports
        WHERE status != 'archived'
    ");
    $val = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_validation = (int)($val['pending_validation'] ?? 0);
    $validated_reports = (int)($val['validated'] ?? 0);
    $rejected_reports = (int)($val['rejected'] ?? 0);

    // Forwarded reports count
    $stmt = $pdo->query("SELECT COUNT(*) FROM report_forwards WHERE status = 'pending'");
    $forwarded_reports = (int)($stmt->fetchColumn() ?? 0);

    // Assigned maintenance count
    $stmt = $pdo->query("SELECT COUNT(*) FROM maintenance_assignments WHERE status = 'assigned'");
    $assigned_maintenance = (int)($stmt->fetchColumn() ?? 0);

    // Projects counts
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'under_construction' THEN 1 ELSE 0 END) AS under_construction,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
        FROM projects
    ");
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);
    $approved_projects = (int)($proj['approved'] ?? 0);
    $under_construction_projects = (int)($proj['under_construction'] ?? 0);
    $completed_projects = (int)($proj['completed'] ?? 0);

    // Reports by type
    $stmt = $pdo->query("SELECT hazard_type, COUNT(*) AS count FROM reports GROUP BY hazard_type");
    $reports_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly trend (last 6 months)
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%b %Y') AS month_label, COUNT(*) AS count
        FROM reports
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m') ASC
    ");
    $monthly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent reports (last 6) - using correct user table structure
    $stmt = $pdo->prepare("
        SELECT r.*, u.fullname AS reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status != 'archived'
        ORDER BY r.created_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $recent_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent projects under construction (last 6)
    $stmt = $pdo->prepare("
        SELECT p.*, u.fullname AS created_by_name
        FROM projects p
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.status = 'under_construction'
        ORDER BY p.updated_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $recent_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Admin dashboard query error: " . $e->getMessage());
}

// Get notifications
try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread notification count
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
} catch (PDOException $e) {
    $notifications = [];
    $unread_count = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard - RTIM</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <!-- Poppins Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
    * { font-family: 'Poppins', sans-serif; }
    html, body { width: 100%; height: 100%; overflow-x: hidden; }
    .sidebar-link { color:#9CA3AF; }
    .sidebar-link:hover { color:#FFF; background:#00332c; }
    .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
    .sidebar-submenu { transition: all .25s ease; }
    .rotate-180 { transform: rotate(180deg); }
    .stat-card { transition: transform .15s ease; }
    .stat-card:hover { transform: translateY(-4px); }
    
    /* Notification Dropdown Styles */
    .notification-dropdown {
      position: fixed;
      right: 1rem;
      top: 4rem;
      width: calc(100vw - 2rem);
      max-width: 420px;
      max-height: 500px;
      overflow: hidden;
      z-index: 1000;
      opacity: 0;
      transform: translateY(-10px);
      transition: opacity 0.2s ease, transform 0.2s ease;
      pointer-events: none;
    }
    
    .notification-dropdown.show {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }
    
    .notification-list-container {
      max-height: 420px;
      overflow-y: auto;
      overflow-x: hidden;
    }
    
    .notification-list-container::-webkit-scrollbar {
      width: 6px;
    }
    
    .notification-list-container::-webkit-scrollbar-track {
      background: #f1f1f1;
    }
    
    .notification-list-container::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 3px;
    }
    
    .notification-list-container::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }
    
    .notification-item {
      border-bottom: 1px solid #e5e7eb;
      padding: 14px 16px;
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
    }
    
    .notification-item:hover {
      background-color: #f9fafb;
      padding-left: 20px;
    }
    
    .notification-item.unread {
      background-color: #fef9e7;
      border-left: 3px solid #faae2b;
    }
    
    .notification-item:hover {
      background-color: #f9fafb;
    }
    
    .notification-item.unread:hover {
      background-color: #fef3c7;
    }
    
    .notification-item.unread:hover {
      background-color: #fef3c7;
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }
    
    /* Badge positioning */
    .notification-badge {
      position: absolute;
      top: -4px;
      right: -4px;
      min-width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
    }
    
    /* Chart container styling */
    .chart-container {
      position: relative;
      height: 280px;
      width: 100%;
    }
    
    @media (max-width: 640px) {
      .notification-dropdown {
        right: 0.5rem;
        width: calc(100vw - 1rem);
        top: 3.5rem;
      }
      
      .notification-badge {
        top: -2px;
        right: -2px;
        min-width: 18px;
        height: 18px;
        font-size: 9px;
      }
      
      .chart-container {
        height: 240px;
      }
    }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <!-- include sidebar -->
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- header - STICKY -->
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <!-- Left Section -->
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Dashboard</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Welcome back, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 20)); ?></p>
          </div>
        </div>

        <!-- Right Section -->
        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
          <!-- Live Clock -->
          <div class="text-right hidden sm:block">
            <div id="currentDate" class="text-xs font-semibold text-lgu-headline"></div>
            <div id="currentTime" class="text-sm font-bold text-lgu-button"></div>
          </div>

          <!-- Notification Bell -->
          <div class="relative flex items-center gap-2">
            <button id="notification-btn" class="relative text-lgu-paragraph hover:text-lgu-headline transition flex-shrink-0 p-2 rounded-lg hover:bg-gray-100">
              <i class="fa fa-bell text-lg sm:text-xl"></i>
              <?php if ($unread_count > 0): ?>
              <span id="notification-badge" class="notification-badge bg-lgu-tertiary text-white rounded-full"><?php echo $unread_count; ?></span>
              <?php endif; ?>
            </button>
          </div>

          <!-- Profile -->
          <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
              <i class="fa fa-user text-lgu-button-text font-semibold text-sm sm:text-base"></i>
            </div>
            <div class="hidden md:block">
              <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 15)); ?></p>
              <p class="text-xs text-lgu-paragraph">Admin</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- Notification Dropdown -->
    <div id="notification-menu" class="notification-dropdown bg-white rounded-lg shadow-2xl border border-gray-200">
      <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 rounded-t-lg flex items-center justify-between">
        <div class="flex items-center gap-2">
          <i class="fa fa-bell text-sm"></i>
          <h3 class="font-semibold text-sm">Notifications</h3>
        </div>
        <?php if ($unread_count > 0): ?>
        <button id="mark-all-read-btn" class="text-xs text-gray-200 hover:text-white">Mark all read</button>
        <?php endif; ?>
      </div>

      <div id="notification-list" class="notification-list-container">
        <?php if (count($notifications) > 0): ?>
          <?php foreach ($notifications as $notification): ?>
          <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" data-notification-id="<?php echo $notification['id']; ?>">
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
          <div class="px-4 py-12 text-center text-lgu-paragraph">
            <i class="fa fa-check-circle text-4xl text-green-500 mb-3 block"></i>
            <p class="text-sm font-medium">All caught up!</p>
            <p class="text-xs text-gray-500 mt-1">No new notifications</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <!-- Welcome Section -->
      <div class="mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-4 sm:p-6 text-white flex flex-col sm:flex-row items-start sm:items-center justify-between shadow-lg gap-4">
          <div>
            <h2 class="text-2xl sm:text-3xl font-bold">Hello, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 15)); ?> 👋</h2>
            <p class="text-xs sm:text-sm text-gray-200 mt-2">Overview of your infrastructure reports and projects.</p>
          </div>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Reports</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-headline mt-2"><?php echo $total_reports; ?></p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-file-alt text-lg sm:text-xl text-lgu-button"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Pending Validation</p>
              <p class="text-2xl sm:text-3xl font-bold text-orange-600 mt-2"><?php echo $pending_validation; ?></p>
              <p class="text-xs text-orange-500 mt-1 font-medium">Need review</p>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-hourglass-half text-lg sm:text-xl text-orange-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Escalated</p>
              <p class="text-2xl sm:text-3xl font-bold text-red-600 mt-2"><?php echo $escalated_reports; ?></p>
              <p class="text-xs text-red-500 mt-1 font-medium">Major issues</p>
            </div>
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-exclamation-triangle text-lg sm:text-xl text-red-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Under Construction</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2"><?php echo $under_construction_projects; ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-hard-hat text-lg sm:text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Completed</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?php echo $done_reports; ?></p>
              <p class="text-xs text-green-500 mt-1 font-medium">Done reports</p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Reports by Type</h3>
          <div class="chart-container">
            <canvas id="typeChart"></canvas>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Status Distribution</h3>
          <div class="chart-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Monthly Trend</h3>
          <div class="chart-container">
            <canvas id="trendChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Recent Reports & Projects -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5 overflow-hidden">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
            <h3 class="text-base sm:text-lg font-semibold text-lgu-headline truncate">Recent Reports</h3>
            <a href="/BENG/ADMIN/incoming_reports.php" class="text-lgu-button hover:text-lgu-stroke font-semibold text-xs sm:text-sm whitespace-nowrap">View All →</a>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
              <thead>
                <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50">
                  <th class="py-2 px-2 sm:py-3 sm:px-3">ID</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3 hidden sm:table-cell">Reporter</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Type</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Validation</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recent_reports)): ?>
                  <tr><td colspan="5" class="py-6 text-center text-lgu-paragraph">No recent reports</td></tr>
                <?php else: foreach ($recent_reports as $r): ?>
                  <tr class="border-b border-gray-200 hover:bg-gray-50">
                    <td class="py-2 px-2 sm:py-3 sm:px-3 font-semibold text-lgu-headline">#<?php echo htmlspecialchars($r['id']); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3 hidden sm:table-cell"><?php echo htmlspecialchars(substr($r['reporter_name'] ?? 'Unknown', 0, 12)); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3"><span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-semibold"><?php echo htmlspecialchars(ucfirst(substr($r['hazard_type'] ?? '-', 0, 8))); ?></span></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3">
                      <?php
                        $val_status = $r['validation_status'] ?? 'pending';
                        $val_class = $val_status === 'validated' ? 'bg-green-100 text-green-700' : ($val_status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700');
                        echo '<span class="' . $val_class . ' px-2 py-1 rounded text-xs font-semibold">' . htmlspecialchars(ucfirst($val_status)) . '</span>';
                      ?>
                    </td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3 text-xs"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$r['status'] ?? '-'))); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5 overflow-hidden">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
            <h3 class="text-base sm:text-lg font-semibold text-lgu-headline truncate">Active Projects</h3>
            <a href="/BENG/ADMIN/under_construction.php" class="text-lgu-button hover:text-lgu-stroke font-semibold text-xs sm:text-sm whitespace-nowrap">View All →</a>
          </div>

          <div class="space-y-3 max-h-96 overflow-y-auto">
            <?php if (empty($recent_projects)): ?>
              <p class="text-lgu-paragraph py-6 text-center text-sm">No active projects</p>
            <?php else: foreach($recent_projects as $p): ?>
              <div class="border border-gray-200 rounded-lg p-3 sm:p-4 hover:shadow-md transition">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 min-w-0">
                    <h4 class="font-semibold text-lgu-headline text-sm truncate"><?php echo htmlspecialchars(substr($p['project_title'] ?? 'Untitled', 0, 30)); ?></h4>
                    <p class="text-xs text-lgu-paragraph mt-1 truncate"><?php echo htmlspecialchars(substr($p['location'] ?? '-', 0, 40)); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Updated: <?php echo date('M d', strtotime($p['updated_at'] ?? $p['created_at'] ?? 'now')); ?></p>
                  </div>
                  <div class="text-right ml-2 flex-shrink-0">
                    <div class="text-sm font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded text-xs"><?php echo (int)($p['progress'] ?? 0); ?>%</div>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-8">
        <a href="incoming_reports.php" class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-inbox mr-2"></i> View Reports</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
        <a href="forward_to_team.php" class="bg-lgu-headline hover:bg-lgu-stroke text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-arrow-right mr-2"></i> Forward to Team</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
        <a href="under_construction.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-hard-hat mr-2"></i> Active Projects</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
      </div>

    </main>

    <footer class="bg-lgu-headline text-white py-6 sm:py-8 mt-8 sm:mt-12 flex-shrink-0">
      <div class="container mx-auto px-4 text-center">
        <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
      </div>
    </footer>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Live Clock
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

  // Notification Dropdown Toggle
  const notificationBtn = document.getElementById('notification-btn');
  const notificationMenu = document.getElementById('notification-menu');
  const markAllReadBtn = document.getElementById('mark-all-read-btn');
  
  if (notificationBtn && notificationMenu) {
    notificationBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notificationMenu.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      if (!notificationMenu.contains(e.target) && e.target !== notificationBtn) {
        notificationMenu.classList.remove('show');
      }
    });

    notificationMenu.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }

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
          fetchNotifications();
        }
      })
      .catch(err => console.error('Error:', err));
    });
  }
  
  const fetchNotifications = () => {
    fetch('fetch_notifications.php')
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const badge = document.getElementById('notification-badge');
          const notifList = document.getElementById('notification-list');
          
          if (data.unread_count > 0) {
            if (!badge) {
              const btn = document.getElementById('notification-btn');
              const newBadge = document.createElement('span');
              newBadge.id = 'notification-badge';
              newBadge.className = 'notification-badge bg-lgu-tertiary text-white rounded-full';
              newBadge.textContent = data.unread_count;
              btn.appendChild(newBadge);
            } else {
              badge.textContent = data.unread_count;
            }
          } else if (badge) {
            badge.remove();
          }
          
          if (notifList && data.notifications.length > 0) {
            notifList.innerHTML = data.notifications.map(n => `
              <div class="notification-item ${n.is_read ? '' : 'unread'}" data-notification-id="${n.id}">
                <div class="flex items-start space-x-3">
                  <div class="flex-shrink-0">
                    <i class="${{'info': 'text-blue-500 fas fa-info-circle', 'success': 'text-green-500 fas fa-check-circle', 'warning': 'text-yellow-500 fas fa-exclamation-triangle', 'error': 'text-red-500 fas fa-times-circle'}[n.type] || 'text-gray-500 fas fa-bell'}"></i>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-lgu-headline">${n.title}</p>
                    <p class="text-sm text-lgu-paragraph mt-1">${n.message}</p>
                    <p class="text-xs text-gray-400 mt-2">${new Date(n.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</p>
                  </div>
                  ${!n.is_read ? '<div class="flex-shrink-0"><div class="w-2 h-2 bg-lgu-button rounded-full"></div></div>' : ''}
                </div>
              </div>
            `).join('');
            attachNotificationListeners();
          }
        }
      })
      .catch(err => console.error('Error fetching notifications:', err));
  };
  
  const attachNotificationListeners = () => {
    document.querySelectorAll('.notification-item').forEach(item => {
      item.removeEventListener('click', handleNotificationClick);
      item.addEventListener('click', handleNotificationClick);
    });
  };
  
  const handleNotificationClick = function() {
    const notifId = this.getAttribute('data-notification-id');
    fetch('mark_notifications_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_single_read', notification_id: notifId })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        this.classList.remove('unread');
        this.style.opacity = '0.6';
        fetchNotifications();
      }
    })
    .catch(err => console.error('Error:', err));
  };
  
  fetchNotifications();
  setInterval(fetchNotifications, 5000);
  attachNotificationListeners();



  // Mobile sidebar toggle
  const sidebar = document.getElementById('admin-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  // Charts data
  const reportsByType = <?php echo json_encode($reports_by_type); ?> || [];
  const statusData = {
    pending: <?php echo $pending_reports; ?>,
    in_progress: <?php echo $in_progress_reports; ?>,
    done: <?php echo $done_reports; ?>,
    escalated: <?php echo $escalated_reports; ?>
  };
  const monthly = <?php echo json_encode($monthly_trend); ?> || [];

  // Chart.js default configuration
  Chart.defaults.font.family = "'Poppins', sans-serif";
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
          borderWidth: 3,
          hoverBorderWidth: 4,
          hoverOffset: 8
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
              pointStyle: 'circle',
              font: {
                size: 12,
                weight: '500'
              }
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0,0,0,0.8)',
            padding: 12,
            cornerRadius: 8,
            titleFont: {
              size: 13,
              weight: '600'
            },
            bodyFont: {
              size: 12
            },
            callbacks: {
              label: function(context) {
                const label = context.label || '';
                const value = context.parsed || 0;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = ((value / total) * 100).toFixed(1);
                return `${label}: ${value} (${percentage}%)`;
              }
            }
          }
        },
        animation: {
          animateRotate: true,
          animateScale: true
        }
      }
    });
  } else if (typeCtx) {
    typeCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No data available</p>';
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
          borderWidth: 3,
          hoverBorderWidth: 4,
          hoverOffset: 8
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
              pointStyle: 'circle',
              font: {
                size: 12,
                weight: '500'
              }
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0,0,0,0.8)',
            padding: 12,
            cornerRadius: 8,
            titleFont: {
              size: 13,
              weight: '600'
            },
            bodyFont: {
              size: 12
            },
            callbacks: {
              label: function(context) {
                const label = context.label || '';
                const value = context.parsed || 0;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = ((value / total) * 100).toFixed(1);
                return `${label}: ${value} (${percentage}%)`;
              }
            }
          }
        },
        animation: {
          animateRotate: true,
          animateScale: true
        }
      }
    });
  } else if (statusCtx) {
    statusCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No data available</p>';
  }

  // Monthly trend chart (Line)
  const trendCtx = document.getElementById('trendChart');
  if (trendCtx && monthly.length > 0) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: monthly.map(i => i.month_label),
        datasets: [{
          label: 'Reports',
          data: monthly.map(i => i.count),
          borderColor: '#faae2b',
          backgroundColor: 'rgba(250,174,43,0.1)',
          fill: true,
          tension: 0.4,
          borderWidth: 3,
          pointBackgroundColor: '#faae2b',
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2,
          pointRadius: 5,
          pointHoverRadius: 7,
          pointHoverBackgroundColor: '#f5a217',
          pointHoverBorderWidth: 3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: { 
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: 'rgba(0,0,0,0.8)',
            padding: 12,
            cornerRadius: 8,
            titleFont: {
              size: 13,
              weight: '600'
            },
            bodyFont: {
              size: 12
            },
            callbacks: {
              label: function(context) {
                return `Reports: ${context.parsed.y}`;
              }
            }
          }
        },
        scales: { 
          y: { 
            beginAtZero: true,
            ticks: {
              stepSize: 5,
              font: {
                size: 11
              }
            },
            grid: {
              color: 'rgba(0,0,0,0.05)',
              drawBorder: false
            }
          },
          x: {
            ticks: {
              font: {
                size: 11
              }
            },
            grid: {
              display: false,
              drawBorder: false
            }
          }
        }
      }
    });
  } else if (trendCtx) {
    trendCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No data available</p>';
  }


});
</script>
</body>
</html>