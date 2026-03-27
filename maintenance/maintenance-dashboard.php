<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow maintenance users
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'maintenance') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

// Initialize variables
$assigned_tasks = $pending_tasks = $in_progress_tasks = $completed_tasks = 0;
$completed_today = 0;
$my_tasks = $recent_completed = [];
$task_types = $weekly_completion = [];
$unread_notifications = [];
$unread_count = 0;

try {
    $maintenance_id = $_SESSION['user_id'];

    // Get task counts from maintenance_assignments table (all team types)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
        FROM maintenance_assignments
        WHERE assigned_to = ?
    ");
    $stmt->execute([$maintenance_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $assigned_tasks = (int)($row['total'] ?? 0);
    $pending_tasks = (int)($row['pending'] ?? 0);
    $in_progress_tasks = (int)($row['in_progress'] ?? 0);
    $completed_tasks = (int)($row['completed'] ?? 0);

    // Get traffic management specific counts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS traffic_tasks
        FROM maintenance_assignments
        WHERE assigned_to = ? AND team_type = 'traffic_management'
    ");
    $stmt->execute([$maintenance_id]);
    $traffic_tasks = (int)($stmt->fetchColumn() ?? 0);

    // Tasks completed today
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM maintenance_assignments 
        WHERE assigned_to = ? 
          AND status = 'completed' 
          AND DATE(updated_at) = CURDATE()
    ");
    $stmt->execute([$maintenance_id]);
    $completed_today = (int)($stmt->fetchColumn() ?? 0);

    // Get my active tasks (assigned + in_progress)
    $stmt = $pdo->prepare("
        SELECT ma.*, r.hazard_type, r.address, r.description, u.fullname AS reporter_name
        FROM maintenance_assignments ma
        INNER JOIN reports r ON ma.report_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE ma.assigned_to = ? 
          AND ma.status IN ('assigned', 'in_progress')
        ORDER BY 
            CASE ma.status 
                WHEN 'in_progress' THEN 1 
                WHEN 'assigned' THEN 2 
            END,
            ma.created_at ASC
        LIMIT 6
    ");
    $stmt->execute([$maintenance_id]);
    $my_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recently completed tasks (last 6)
    $stmt = $pdo->prepare("
        SELECT ma.*, r.hazard_type, r.address, r.description, u.fullname AS reporter_name
        FROM maintenance_assignments ma
        INNER JOIN reports r ON ma.report_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE ma.assigned_to = ? 
          AND ma.status = 'completed'
        ORDER BY ma.updated_at DESC
        LIMIT 6
    ");
    $stmt->execute([$maintenance_id]);
    $recent_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tasks by team type
    $stmt = $pdo->prepare("
        SELECT 
            ma.team_type,
            COUNT(*) AS count
        FROM maintenance_assignments ma
        WHERE ma.assigned_to = ?
        GROUP BY ma.team_type
    ");
    $stmt->execute([$maintenance_id]);
    $task_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Weekly completion trend (last 7 days)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(updated_at, '%a') AS day_label, COUNT(*) AS count
        FROM maintenance_assignments
        WHERE assigned_to = ? 
          AND status = 'completed'
          AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(updated_at)
        ORDER BY DATE(updated_at) ASC
    ");
    $stmt->execute([$maintenance_id]);
    $weekly_completion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize notification variables (simplified)
    $unread_notifications = [];
    $unread_count = 0;

} catch (PDOException $e) {
    error_log("Maintenance dashboard query error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Maintenance Dashboard - RTIM</title>

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

  <!-- Include sidebar -->
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- Header - STICKY -->
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <!-- Left Section -->
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Dashboard</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Welcome back, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Maintenance', 0, 20)); ?></p>
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
          <div class="relative">
            <button id="notification-btn" class="relative text-lgu-paragraph hover:text-lgu-headline transition flex-shrink-0 p-2 rounded-lg hover:bg-gray-100">
              <i class="fa fa-bell text-lg sm:text-xl"></i>
            </button>
          </div>

          <!-- Profile -->
          <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
              <i class="fa fa-tools text-lgu-button-text font-semibold text-sm sm:text-base"></i>
            </div>
            <div class="hidden md:block">
              <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Maintenance', 0, 15)); ?></p>
              <p class="text-xs text-lgu-paragraph">Maintenance</p>
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
      </div>

      <div id="notification-list" class="notification-list-container">
        <div class="px-4 py-12 text-center text-lgu-paragraph">
          <i class="fa fa-check-circle text-4xl text-green-500 mb-3 block"></i>
          <p class="text-sm font-medium">All caught up!</p>
          <p class="text-xs text-gray-500 mt-1">No new notifications</p>
        </div>
      </div>
    </div>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <!-- Welcome Section -->
      <div class="mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-4 sm:p-6 text-white flex flex-col sm:flex-row items-start sm:items-center justify-between shadow-lg gap-4">
          <div>
            <h2 class="text-2xl sm:text-3xl font-bold">Hello, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Maintenance', 0, 15)); ?> 👋</h2>
            <p class="text-xs sm:text-sm text-gray-200 mt-2">Your assigned maintenance tasks and progress overview.</p>
          </div>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Tasks</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-headline mt-2"><?php echo $assigned_tasks; ?></p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-tasks text-lg sm:text-xl text-lgu-button"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Pending</p>
              <p class="text-2xl sm:text-3xl font-bold text-orange-600 mt-2"><?php echo $pending_tasks; ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-clock text-lg sm:text-xl text-orange-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">In Progress</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2"><?php echo $in_progress_tasks; ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-spinner text-lg sm:text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Completed Today</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?php echo $completed_today; ?></p>
              <p class="text-xs text-green-600 mt-1 font-medium">Total: <?php echo $completed_tasks; ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Team Type Stats -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Road Maintenance</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2">
                <?php 
                $road_count = 0;
                foreach($task_types as $type) {
                  if($type['team_type'] === 'road_maintenance') $road_count = $type['count'];
                }
                echo $road_count;
                ?>
              </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-road text-lg sm:text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Bridge Maintenance</p>
              <p class="text-2xl sm:text-3xl font-bold text-purple-600 mt-2">
                <?php 
                $bridge_count = 0;
                foreach($task_types as $type) {
                  if($type['team_type'] === 'bridge_maintenance') $bridge_count = $type['count'];
                }
                echo $bridge_count;
                ?>
              </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-archway text-lg sm:text-xl text-purple-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Traffic Management</p>
              <p class="text-2xl sm:text-3xl font-bold text-red-600 mt-2">
                <?php 
                $traffic_count = 0;
                foreach($task_types as $type) {
                  if($type['team_type'] === 'traffic_management') $traffic_count = $type['count'];
                }
                echo $traffic_count;
                ?>
              </p>
            </div>
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-traffic-light text-lg sm:text-xl text-red-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Tasks by Team Type</h3>
          <div class="chart-container">
            <canvas id="typeChart"></canvas>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Weekly Completion</h3>
          <div class="chart-container">
            <canvas id="weeklyChart"></canvas>
          </div>
        </div>
      </div>

      <!-- My Tasks & Recently Completed -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <!-- My Active Tasks -->
        <div class="bg-white rounded-lg shadow p-4 sm:p-5 overflow-hidden">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
            <h3 class="text-base sm:text-lg font-semibold text-lgu-headline truncate">My Active Tasks</h3>
            <a href="assigned_minor_issues.php" class="text-lgu-button hover:text-lgu-stroke font-semibold text-xs sm:text-sm whitespace-nowrap">View All →</a>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
              <thead>
                <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50">
                  <th class="py-2 px-2 sm:py-3 sm:px-3">ID</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3 hidden sm:table-cell">Reporter</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Type</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Status</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($my_tasks)): ?>
                  <tr><td colspan="5" class="py-6 text-center text-lgu-paragraph">No active tasks</td></tr>
                <?php else: foreach ($my_tasks as $task): ?>
                  <tr class="border-b border-gray-200 hover:bg-gray-50">
                    <td class="py-2 px-2 sm:py-3 sm:px-3 font-semibold text-lgu-headline">#<?php echo htmlspecialchars($task['report_id']); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3 hidden sm:table-cell"><?php echo htmlspecialchars(substr($task['reporter_name'] ?? 'Unknown', 0, 12)); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3"><span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-semibold"><?php echo htmlspecialchars(ucfirst(substr($task['hazard_type'] ?? '-', 0, 8))); ?></span></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3">
                      <?php
                        $status_class = $task['status'] === 'assigned' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700';
                        $status_text = $task['status'] === 'assigned' ? 'Pending' : ucfirst(str_replace('_',' ',$task['status'] ?? '-'));
                        echo '<span class="' . $status_class . ' px-2 py-1 rounded text-xs font-semibold">' . htmlspecialchars($status_text) . '</span>';
                      ?>
                    </td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3">
                      <a href="assigned_minor_issues.php" class="text-lgu-button hover:text-lgu-stroke font-semibold text-xs">View</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Recently Completed -->
        <div class="bg-white rounded-lg shadow p-4 sm:p-5 overflow-hidden">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
            <h3 class="text-base sm:text-lg font-semibold text-lgu-headline truncate">Recently Completed</h3>
          </div>

          <div class="space-y-3 max-h-96 overflow-y-auto">
            <?php if (empty($recent_completed)): ?>
              <p class="text-lgu-paragraph py-6 text-center text-sm">No completed tasks yet</p>
            <?php else: foreach($recent_completed as $task): ?>
              <div class="border border-gray-200 rounded-lg p-3 sm:p-4 hover:shadow-md transition">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span class="text-xs font-bold text-lgu-headline">#<?php echo $task['report_id']; ?></span>
                      <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 font-semibold">Completed</span>
                    </div>
                    <h4 class="font-semibold text-lgu-headline text-sm truncate"><?php echo htmlspecialchars(ucfirst($task['hazard_type'])); ?></h4>
                    <p class="text-xs text-lgu-paragraph mt-1 truncate"><?php echo htmlspecialchars(substr($task['address'] ?? '-', 0, 40)); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Completed: <?php echo date('M d, Y', strtotime($task['updated_at'])); ?></p>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-8">
        <a href="assigned_minor_issues.php" class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-tasks mr-2"></i> All Tasks</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
        <a href="assigned_road_maintenance.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-road mr-2"></i> Road</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
        <a href="assigned_bridge_maintenance.php" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-archway mr-2"></i> Bridge</div>
          <i class="fa fa-arrow-right text-xs sm:text-sm"></i>
        </a>
        <a href="assigned_traffic_management.php" class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition text-sm sm:text-base">
          <div><i class="fa fa-traffic-light mr-2"></i> Traffic</div>
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

  // Mark all notifications as read
  const markAllReadBtn = document.getElementById('mark-all-read-btn');
  if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', () => {
      fetch('/BENG/MAINTENANCE/api/mark_all_notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: <?php echo $_SESSION['user_id']; ?> })
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

  // Mark individual notification as read on click
  document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', () => {
      const notifId = item.getAttribute('data-notification-id');
      fetch('/BENG/MAINTENANCE/api/mark_notification_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ notification_id: notifId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          item.classList.remove('unread');
          item.style.opacity = '0.6';
          
          // Update notification count
          const badge = document.querySelector('.notification-badge');
          if (badge) {
            let currentCount = parseInt(badge.textContent);
            currentCount--;
            if (currentCount <= 0) {
              badge.remove();
            } else {
              badge.textContent = currentCount > 99 ? '99+' : currentCount;
            }
          }
        }
      })
      .catch(err => console.error('Error:', err));
    });
  });

  // Mobile sidebar toggle
  const sidebar = document.getElementById('maintenance-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  // Charts data
  const taskTypes = <?php echo json_encode($task_types); ?> || [];
  const weeklyCompletion = <?php echo json_encode($weekly_completion); ?> || [];

  // Chart.js default configuration
  Chart.defaults.font.family = "'Poppins', sans-serif";
  Chart.defaults.color = '#475d5b';

  // Tasks by type chart (Doughnut)
  const typeCtx = document.getElementById('typeChart');
  if (typeCtx && taskTypes.length > 0) {
    new Chart(typeCtx, {
      type: 'doughnut',
      data: {
        labels: taskTypes.map(i => {
          const type = i.team_type || 'Other';
          return type.replace('_', ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }),
        datasets: [{
          data: taskTypes.map(i => i.count),
          backgroundColor: [
            '#3b82f6', // Road - Blue
            '#8b5cf6', // Bridge - Purple  
            '#ef4444', // Traffic - Red
            '#faae2b', // Other - Yellow
            '#10b981', // Green
            '#f59e0b'  // Orange
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

  // Weekly completion chart (Bar)
  const weeklyCtx = document.getElementById('weeklyChart');
  if (weeklyCtx && weeklyCompletion.length > 0) {
    new Chart(weeklyCtx, {
      type: 'bar',
      data: {
        labels: weeklyCompletion.map(i => i.day_label),
        datasets: [{
          label: 'Completed Tasks',
          data: weeklyCompletion.map(i => i.count),
          backgroundColor: '#10b981',
          borderColor: '#059669',
          borderWidth: 2,
          borderRadius: 8,
          hoverBackgroundColor: '#059669'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
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
                return `Completed: ${context.parsed.y} tasks`;
              }
            }
          }
        },
        scales: { 
          y: { 
            beginAtZero: true,
            ticks: {
              stepSize: 1,
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
  } else if (weeklyCtx) {
    weeklyCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No data available</p>';
  }

  // Refresh notifications every 30 seconds
  setInterval(() => {
    fetch('/BENG/MAINTENANCE/api/get_unread_count.php')
    .then(res => res.json())
    .then(data => {
      const badge = document.querySelector('.notification-badge');
      if (data.unread_count > 0) {
        if (!badge) {
          const newBadge = document.createElement('span');
          newBadge.className = 'notification-badge bg-red-600 text-white rounded-full';
          newBadge.textContent = Math.min(data.unread_count, 99) + (data.unread_count > 99 ? '+' : '');
          document.getElementById('notification-btn').appendChild(newBadge);
        } else {
          badge.textContent = Math.min(data.unread_count, 99) + (data.unread_count > 99 ? '+' : '');
        }
      } else if (badge) {
        badge.remove();
      }
    })
    .catch(err => console.error('Error fetching notifications:', err));
  }, 30000);
});
</script>
</body>
</html>