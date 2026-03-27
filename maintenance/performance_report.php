<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow maintenance users
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'maintenance') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize filter variables
$selected_period = $_GET['period'] ?? 'month';
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$selected_day = $_GET['day'] ?? date('Y-m-d');

// Handle export requests
if (isset($_GET['export'])) {
    $period = $_GET['period'] ?? 'month';
    $format = $_GET['format'] ?? 'pdf';
    $month = $_GET['month'] ?? date('m');
    $year = $_GET['year'] ?? date('Y');
    $day = $_GET['day'] ?? date('Y-m-d');
    
    // Get data based on period
    $where_clause = "";
    $period_label = "";
    
    switch($period) {
        case 'day':
            $where_clause = "AND DATE(ma.completed_at) = :day_date";
            $period_label = date('F j, Y', strtotime($day));
            break;
        case 'week':
            $week_start = date('Y-m-d', strtotime('monday this week', strtotime($day)));
            $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($day)));
            $where_clause = "AND DATE(ma.completed_at) BETWEEN :week_start AND :week_end";
            $period_label = date('M j', strtotime($week_start)) . " - " . date('M j, Y', strtotime($week_end));
            break;
        case 'month':
            $where_clause = "AND YEAR(ma.completed_at) = :year AND MONTH(ma.completed_at) = :month";
            $period_label = date('F Y', strtotime($year . '-' . $month . '-01'));
            break;
        case 'year':
            $where_clause = "AND YEAR(ma.completed_at) = :year";
            $period_label = "Year " . $year;
            break;
        default:
            $where_clause = "AND YEAR(ma.completed_at) = YEAR(NOW()) AND MONTH(ma.completed_at) = MONTH(NOW())";
            $period_label = date('F Y');
            break;
    }
    
    $query = "
        SELECT 
            ma.id as assignment_id,
            ma.report_id,
            ma.completion_deadline,
            ma.started_at,
            ma.completed_at,
            ma.created_at as assigned_date,
            ma.team_type,
            r.hazard_type,
            r.description,
            r.address,
            u_assigned_by.fullname as assigned_by_name
        FROM maintenance_assignments ma
        INNER JOIN reports r ON ma.report_id = r.id
        INNER JOIN users u_assigned_by ON ma.assigned_by = u_assigned_by.id
        WHERE ma.assigned_to = ? 
          AND ma.status = 'completed'
          $where_clause
        ORDER BY ma.completed_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    
    // Bind parameters based on period
    switch($period) {
        case 'day':
            $stmt->bindValue(':day_date', $day);
            break;
        case 'week':
            $stmt->bindValue(':week_start', $week_start);
            $stmt->bindValue(':week_end', $week_end);
            break;
        case 'month':
            $stmt->bindValue(':month', $month, PDO::PARAM_INT);
            $stmt->bindValue(':year', $year, PDO::PARAM_INT);
            break;
        case 'year':
            $stmt->bindValue(':year', $year, PDO::PARAM_INT);
            break;
    }
    
    $stmt->execute();
    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'excel') {
        // Excel export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="maintenance_report_' . $period . '_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th colspan='8' style='text-align:center; font-weight:bold;'>Maintenance Performance Report - $period_label</th></tr>";
        echo "<tr><th>Task ID</th><th>Report ID</th><th>Team Type</th><th>Hazard Type</th><th>Description</th><th>Address</th><th>Assigned Date</th><th>Completed Date</th></tr>";
        
        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['assignment_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['report_id']) . "</td>";
            echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['team_type']))) . "</td>";
            echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['hazard_type']))) . "</td>";
            echo "<td>" . htmlspecialchars($row['description']) . "</td>";
            echo "<td>" . htmlspecialchars($row['address']) . "</td>";
            echo "<td>" . date('Y-m-d', strtotime($row['assigned_date'])) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['completed_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit();
    } elseif ($format === 'csv') {
        // CSV export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="maintenance_report_' . $period . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add header
        fputcsv($output, array('Maintenance Performance Report - ' . $period_label));
        fputcsv($output, array(''));
        fputcsv($output, array('Task ID', 'Report ID', 'Team Type', 'Hazard Type', 'Description', 'Address', 'Assigned Date', 'Completed Date'));
        
        foreach ($export_data as $row) {
            fputcsv($output, array(
                $row['assignment_id'],
                $row['report_id'],
                ucfirst(str_replace('_', ' ', $row['team_type'])),
                ucfirst(str_replace('_', ' ', $row['hazard_type'])),
                $row['description'],
                $row['address'],
                date('Y-m-d', strtotime($row['assigned_date'])),
                date('Y-m-d H:i', strtotime($row['completed_at']))
            ));
        }
        
        fclose($output);
        exit();
    } elseif ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="maintenance_report_' . $period . '_' . date('Y-m-d') . '.pdf"');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report</title><style>body{font-family:Arial,sans-serif;font-size:12px}.header{text-align:center;margin-bottom:20px}.header h1{color:#00473e;margin:0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background-color:#00473e;color:white}</style></head><body><div class="header"><h1>Maintenance Performance Report</h1><p>' . $period_label . '</p><p>Generated: ' . date('Y-m-d H:i:s') . '</p><p>Staff: ' . htmlspecialchars($_SESSION['user_name'] ?? 'Unknown') . '</p></div><table><thead><tr><th>Task ID</th><th>Report ID</th><th>Team Type</th><th>Hazard Type</th><th>Description</th><th>Address</th><th>Assigned</th><th>Completed</th></tr></thead><tbody>';
        foreach ($export_data as $row) {
            echo '<tr><td>' . htmlspecialchars($row['assignment_id']) . '</td><td>' . htmlspecialchars($row['report_id']) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['team_type']))) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['hazard_type']))) . '</td><td>' . htmlspecialchars(substr($row['description'], 0, 50)) . '</td><td>' . htmlspecialchars(substr($row['address'], 0, 30)) . '</td><td>' . date('Y-m-d', strtotime($row['assigned_date'])) . '</td><td>' . date('Y-m-d H:i', strtotime($row['completed_at'])) . '</td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit();
    }
}

// Get performance data for display based on current filter
$where_clause_display = "";
$params = [$user_id];

switch($selected_period) {
    case 'day':
        $where_clause_display = "AND DATE(ma.completed_at) = ?";
        $params[] = $selected_day;
        break;
    case 'week':
        $week_start = date('Y-m-d', strtotime('monday this week', strtotime($selected_day)));
        $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($selected_day)));
        $where_clause_display = "AND DATE(ma.completed_at) BETWEEN ? AND ?";
        $params[] = $week_start;
        $params[] = $week_end;
        break;
    case 'month':
        $where_clause_display = "AND YEAR(ma.completed_at) = ? AND MONTH(ma.completed_at) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        break;
    case 'year':
        $where_clause_display = "AND YEAR(ma.completed_at) = ?";
        $params[] = $selected_year;
        break;
    default:
        $where_clause_display = "AND YEAR(ma.completed_at) = YEAR(NOW()) AND MONTH(ma.completed_at) = MONTH(NOW())";
        break;
}

// Get count for current filter
$count_query = "SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.assigned_to = ? AND ma.status = 'completed' $where_clause_display";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$current_count = $stmt->fetchColumn();

// Get additional stats for quick view
$today_query = "SELECT COUNT(*) FROM maintenance_assignments WHERE assigned_to = ? AND status = 'completed' AND DATE(completed_at) = CURDATE()";
$stmt = $pdo->prepare($today_query);
$stmt->execute([$user_id]);
$today_count = $stmt->fetchColumn();

$week_query = "SELECT COUNT(*) FROM maintenance_assignments WHERE assigned_to = ? AND status = 'completed' AND YEARWEEK(completed_at) = YEARWEEK(NOW())";
$stmt = $pdo->prepare($week_query);
$stmt->execute([$user_id]);
$week_count = $stmt->fetchColumn();

$month_query = "SELECT COUNT(*) FROM maintenance_assignments WHERE assigned_to = ? AND status = 'completed' AND YEAR(completed_at) = YEAR(NOW()) AND MONTH(completed_at) = MONTH(NOW())";
$stmt = $pdo->prepare($month_query);
$stmt->execute([$user_id]);
$month_count = $stmt->fetchColumn();

$year_query = "SELECT COUNT(*) FROM maintenance_assignments WHERE assigned_to = ? AND status = 'completed' AND YEAR(completed_at) = YEAR(NOW())";
$stmt = $pdo->prepare($year_query);
$stmt->execute([$user_id]);
$year_count = $stmt->fetchColumn();

// Get data for display table
$display_query = "
    SELECT 
        ma.id as assignment_id,
        ma.report_id,
        ma.completed_at,
        ma.team_type,
        r.hazard_type,
        r.description,
        r.address
    FROM maintenance_assignments ma
    INNER JOIN reports r ON ma.report_id = r.id
    WHERE ma.assigned_to = ? 
      AND ma.status = 'completed'
      $where_clause_display
    ORDER BY ma.completed_at DESC
    LIMIT 10
";

$stmt = $pdo->prepare($display_query);
$stmt->execute($params);
$display_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get months and years for dropdowns
$months = [];
for ($i = 1; $i <= 12; $i++) {
    $months[$i] = date('F', mktime(0, 0, 0, $i, 1));
}

// Get years from when the user started (or last 5 years as default)
$years_query = "SELECT DISTINCT YEAR(completed_at) as year FROM maintenance_assignments WHERE assigned_to = ? AND status = 'completed' ORDER BY year DESC";
$stmt = $pdo->prepare($years_query);
$stmt->execute([$user_id]);
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no years found, use last 5 years
if (empty($available_years)) {
    $current_year = date('Y');
    for ($i = 0; $i < 5; $i++) {
        $available_years[] = $current_year - $i;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Performance Report - RTIM</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    * { font-family: 'Poppins', sans-serif; }
    .sidebar-link { color:#9CA3AF; }
    .sidebar-link:hover { color:#FFF; background:#00332c; }
    .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <?php include 'sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Performance Report</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Generate maintenance performance reports</p>
          </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
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

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <!-- Performance Statistics -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Today</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?= $today_count ?></p>
              <p class="text-xs text-gray-500 mt-1">Completed Tasks</p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-calendar-day text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">This Week</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2"><?= $week_count ?></p>
              <p class="text-xs text-gray-500 mt-1">Completed Tasks</p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-calendar-week text-lg sm:text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">This Month</p>
              <p class="text-2xl sm:text-3xl font-bold text-purple-600 mt-2"><?= $month_count ?></p>
              <p class="text-xs text-gray-500 mt-1">Completed Tasks</p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-calendar-alt text-lg sm:text-xl text-purple-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">This Year</p>
              <p class="text-2xl sm:text-3xl font-bold text-orange-600 mt-2"><?= $year_count ?></p>
              <p class="text-xs text-gray-500 mt-1">Completed Tasks</p>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-calendar text-lg sm:text-xl text-orange-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-filter mr-2"></i>
            Filter Reports
          </h2>
        </div>

        <div class="p-6">
          <form id="filterForm" method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- Period Type -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Period Type</label>
                <div class="grid grid-cols-4 gap-2">
                  <button type="button" class="period-btn py-2 px-3 rounded-lg text-sm font-medium transition <?= $selected_period == 'day' ? 'bg-lgu-headline text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-period="day">
                    Day
                  </button>
                  <button type="button" class="period-btn py-2 px-3 rounded-lg text-sm font-medium transition <?= $selected_period == 'week' ? 'bg-lgu-headline text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-period="week">
                    Week
                  </button>
                  <button type="button" class="period-btn py-2 px-3 rounded-lg text-sm font-medium transition <?= $selected_period == 'month' ? 'bg-lgu-headline text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-period="month">
                    Month
                  </button>
                  <button type="button" class="period-btn py-2 px-3 rounded-lg text-sm font-medium transition <?= $selected_period == 'year' ? 'bg-lgu-headline text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-period="year">
                    Year
                  </button>
                </div>
                <input type="hidden" name="period" id="periodInput" value="<?= $selected_period ?>">
              </div>

              <!-- Dynamic Filters -->
              <div id="filterControls">
                <?php if ($selected_period == 'day'): ?>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Date</label>
                    <input type="date" name="day" value="<?= $selected_day ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-headline focus:border-transparent">
                  </div>
                <?php elseif ($selected_period == 'week'): ?>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Week (Any day in the week)</label>
                    <input type="date" name="day" value="<?= $selected_day ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-headline focus:border-transparent">
                    <p class="text-xs text-gray-500 mt-1">Week: <?= date('M j', strtotime('monday this week', strtotime($selected_day))) ?> - <?= date('M j, Y', strtotime('sunday this week', strtotime($selected_day))) ?></p>
                  </div>
                <?php elseif ($selected_period == 'month'): ?>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-2">Month</label>
                      <select name="month" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-headline focus:border-transparent">
                        <?php foreach ($months as $num => $name): ?>
                          <option value="<?= sprintf('%02d', $num) ?>" <?= $selected_month == $num ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-2">Year</label>
                      <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-headline focus:border-transparent">
                        <?php foreach ($available_years as $year): ?>
                          <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                <?php elseif ($selected_period == 'year'): ?>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Year</label>
                    <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-headline focus:border-transparent">
                      <?php foreach ($available_years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
              <button type="button" onclick="resetFilters()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Reset
              </button>
              <button type="submit" class="px-4 py-2 bg-lgu-headline text-white rounded-lg hover:bg-lgu-stroke transition flex items-center">
                <i class="fas fa-search mr-2"></i>
                Apply Filters
              </button>
            </div>
          </form>

          <!-- Current Filter Info -->
          <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-blue-800">Current Filter: 
                  <span class="font-bold">
                    <?php
                    switch($selected_period) {
                      case 'day':
                        echo 'Day - ' . date('F j, Y', strtotime($selected_day));
                        break;
                      case 'week':
                        $week_start = date('M j', strtotime('monday this week', strtotime($selected_day)));
                        $week_end = date('M j, Y', strtotime('sunday this week', strtotime($selected_day)));
                        echo 'Week - ' . $week_start . ' to ' . $week_end;
                        break;
                      case 'month':
                        echo 'Month - ' . date('F Y', strtotime($selected_year . '-' . $selected_month . '-01'));
                        break;
                      case 'year':
                        echo 'Year - ' . $selected_year;
                        break;
                    }
                    ?>
                  </span>
                </p>
                <p class="text-xs text-blue-600">Showing <?= $current_count ?> completed tasks</p>
              </div>
              <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm font-medium rounded-full">
                <?= strtoupper($selected_period) ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Data Preview -->
      <?php if (!empty($display_data)): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
          <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6">
            <h2 class="text-lg sm:text-xl font-bold flex items-center justify-between">
              <span><i class="fas fa-list mr-2"></i> Recent Tasks (Preview)</span>
              <span class="text-sm font-normal">Last 10 tasks</span>
            </h2>
          </div>
          
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task ID</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Report ID</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team Type</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hazard Type</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed At</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($display_data as $task): ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($task['assignment_id']) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($task['report_id']) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $task['team_type']))) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $task['hazard_type']))) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= date('Y-m-d H:i', strtotime($task['completed_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <!-- Export Section -->
      <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-download mr-2"></i>
            Export Performance Report
          </h2>
        </div>

        <div class="p-6">
          <div class="mb-4">
            <p class="text-gray-700">Export the filtered report in your preferred format. The export will include all tasks matching your current filter criteria.</p>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <button onclick="exportReport('csv')" class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
              <i class="fas fa-file-csv mr-2"></i>
              Export as CSV
            </button>
            
            <button onclick="exportReport('excel')" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
              <i class="fas fa-file-excel mr-2"></i>
              Export as Excel
            </button>
            
            <button onclick="exportReport('pdf')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
              <i class="fas fa-file-pdf mr-2"></i>
              Export as PDF
            </button>
          </div>
          
          <div class="mt-4 p-3 bg-gray-50 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-2">Report includes:</h4>
            <ul class="text-sm text-gray-600 space-y-1">
              <li>• Task and Report IDs</li>
              <li>• Team and Hazard Types</li>
              <li>• Task Descriptions</li>
              <li>• Locations and Addresses</li>
              <li>• Assignment and Completion Dates</li>
            </ul>
          </div>
        </div>
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
  // Mobile sidebar toggle
  const sidebar = document.getElementById('maintenance-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  // Period buttons
  document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const period = this.dataset.period;
      document.getElementById('periodInput').value = period;
      
      // Update button styles
      document.querySelectorAll('.period-btn').forEach(b => {
        b.classList.remove('bg-lgu-headline', 'text-white');
        b.classList.add('bg-gray-100', 'text-gray-700', 'hover:bg-gray-200');
      });
      this.classList.remove('bg-gray-100', 'text-gray-700', 'hover:bg-gray-200');
      this.classList.add('bg-lgu-headline', 'text-white');
      
      // Update filter controls via AJAX
      updateFilterControls(period);
    });
  });
});

function updateFilterControls(period) {
  const form = document.getElementById('filterForm');
  const formData = new FormData(form);
  formData.append('period', period);
  formData.append('ajax', '1');
  
  fetch(window.location.pathname, {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(html => {
    // This would need server-side support for AJAX
    // For now, we'll just reload with the new period
    const url = new URL(window.location);
    url.searchParams.set('period', period);
    window.location.href = url.toString();
  })
  .catch(error => {
    console.error('Error:', error);
    // Fallback to page reload
    const url = new URL(window.location);
    url.searchParams.set('period', period);
    window.location.href = url.toString();
  });
}

function resetFilters() {
  const url = new URL(window.location);
  url.searchParams.delete('period');
  url.searchParams.delete('month');
  url.searchParams.delete('year');
  url.searchParams.delete('day');
  window.location.href = url.toString();
}

function exportReport(format) {
  const form = document.getElementById('filterForm');
  const formData = new FormData(form);
  const params = new URLSearchParams();
  
  // Add current filter parameters
  for (let [key, value] of formData.entries()) {
    params.append(key, value);
  }
  
  // Add export parameters
  params.append('export', '1');
  params.append('format', format);
  
  const periodText = document.querySelector('.period-btn.bg-lgu-headline')?.textContent || 'Month';
  
  Swal.fire({
    title: 'Generating Report...',
    text: `Preparing ${format.toUpperCase()} report for ${periodText}`,
    icon: 'info',
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true
  }).then(() => {
    window.open(`performance_report.php?${params.toString()}`, '_blank');
    
    Swal.fire({
      title: 'Success!',
      text: 'Report has been generated and downloaded.',
      icon: 'success',
      timer: 3000,
      showConfirmButton: false
    });
  });
}
</script>
</body>
</html>