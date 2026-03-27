<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'maintenance') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if (isset($_GET['export'])) {
    $period = $_GET['period'] ?? 'month';
    $format = $_GET['format'] ?? 'pdf';
    
    $where_clause = "";
    $period_label = "";
    
    switch($period) {
        case 'day':
            $where_clause = "AND DATE(ma.completed_at) = CURDATE()";
            $period_label = "Today (" . date('Y-m-d') . ")";
            break;
        case 'week':
            $where_clause = "AND YEARWEEK(ma.completed_at) = YEARWEEK(NOW())";
            $period_label = "This Week (" . date('Y-m-d', strtotime('monday this week')) . " to " . date('Y-m-d', strtotime('sunday this week')) . ")";
            break;
        case 'month':
        default:
            $where_clause = "AND YEAR(ma.completed_at) = YEAR(NOW()) AND MONTH(ma.completed_at) = MONTH(NOW())";
            $period_label = "This Month (" . date('F Y') . ")";
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
    $stmt->execute([$user_id]);
    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'excel') {
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
    } elseif ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="maintenance_report_' . $period . '_' . date('Y-m-d') . '.pdf"');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report</title><style>body{font-family:Arial,sans-serif;font-size:12px}.header{text-align:center;margin-bottom:20px}.header h1{color:#00473e;margin:0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background-color:#00473e;color:white}</style></head><body><div class="header"><h1>Maintenance Performance Report</h1><p>' . $period_label . '</p><p>Generated: ' . date('Y-m-d H:i:s') . '</p><p>Staff: ' . htmlspecialchars($_SESSION['user_name'] ?? 'Unknown') . '</p></div><table><thead><tr><th>Task ID</th><th>Report ID</th><th>Team Type</th><th>Hazard Type</th><th>Description</th><th>Address</th><th>Assigned</th><th>Completed</th></tr></thead><tbody>';
        foreach ($export_data as $row) {
            echo '<tr><td>' . htmlspecialchars($row['assignment_id']) . '</td><td>' . htmlspecialchars($row['report_id']) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['team_type']))) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['hazard_type']))) . '</td><td>' . htmlspecialchars(substr($row['description'], 0, 50)) . '</td><td>' . htmlspecialchars(substr($row['address'], 0, 30)) . '</td><td>' . date('Y-m-d', strtotime($row['assigned_date'])) . '</td><td>' . date('Y-m-d H:i', strtotime($row['completed_at'])) . '</td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit();
    } else {
        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="maintenance_report_' . $period . '_' . date('Y-m-d') . '.doc"');
        echo '<html><head><meta charset="utf-8"></head><body><h1 style="text-align:center;color:#00473e;">Maintenance Performance Report</h1><p style="text-align:center;">' . $period_label . '</p><p style="text-align:center;">Generated: ' . date('Y-m-d H:i:s') . '</p><p style="text-align:center;">Staff: ' . htmlspecialchars($_SESSION['user_name'] ?? 'Unknown') . '</p><table border="1" style="width:100%;border-collapse:collapse;"><tr style="background-color:#00473e;color:white;"><th>Task ID</th><th>Report ID</th><th>Team Type</th><th>Hazard Type</th><th>Description</th><th>Address</th><th>Assigned</th><th>Completed</th></tr>';
        foreach ($export_data as $row) {
            echo '<tr><td>' . htmlspecialchars($row['assignment_id']) . '</td><td>' . htmlspecialchars($row['report_id']) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['team_type']))) . '</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['hazard_type']))) . '</td><td>' . htmlspecialchars($row['description']) . '</td><td>' . htmlspecialchars($row['address']) . '</td><td>' . date('Y-m-d', strtotime($row['assigned_date'])) . '</td><td>' . date('Y-m-d H:i', strtotime($row['completed_at'])) . '</td></tr>';
        }
        echo '</table></body></html>';
        exit();
    }
}

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
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
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
      </div>

      <!-- Export Section -->
      <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-download mr-2"></i>
            Export Performance Report
          </h2>
        </div>

        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Period Selection -->
            <div>
              <h3 class="text-lg font-semibold text-lgu-headline mb-4">Select Time Period</h3>
              <div class="space-y-3">
                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="day">
                  <input type="radio" name="period" value="day" class="mr-3" id="period-day">
                  <label for="period-day" class="flex-1 cursor-pointer">
                    <div class="font-medium text-lgu-headline">Today</div>
                    <div class="text-sm text-gray-500"><?= date('F j, Y') ?> (<?= $today_count ?> tasks)</div>
                  </label>
                </div>
                
                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="week">
                  <input type="radio" name="period" value="week" class="mr-3" id="period-week" checked>
                  <label for="period-week" class="flex-1 cursor-pointer">
                    <div class="font-medium text-lgu-headline">This Week</div>
                    <div class="text-sm text-gray-500"><?= date('M j', strtotime('monday this week')) ?> - <?= date('M j, Y', strtotime('sunday this week')) ?> (<?= $week_count ?> tasks)</div>
                  </label>
                </div>
                
                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="month">
                  <input type="radio" name="period" value="month" class="mr-3" id="period-month">
                  <label for="period-month" class="flex-1 cursor-pointer">
                    <div class="font-medium text-lgu-headline">This Month</div>
                    <div class="text-sm text-gray-500"><?= date('F Y') ?> (<?= $month_count ?> tasks)</div>
                  </label>
                </div>
              </div>
            </div>

            <!-- Export Options -->
            <div>
              <h3 class="text-lg font-semibold text-lgu-headline mb-4">Export Format</h3>
              <div class="space-y-4">
                <button onclick="exportReport('pdf')" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
                  <i class="fas fa-file-pdf mr-2"></i>
                  Export as PDF
                </button>
                
                <button onclick="exportReport('excel')" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
                  <i class="fas fa-file-excel mr-2"></i>
                  Export as Excel
                </button>
                
                <button onclick="exportReport('word')" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
                  <i class="fas fa-file-word mr-2"></i>
                  Export as Word
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
  const sidebar = document.getElementById('maintenance-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  document.querySelectorAll('.period-option').forEach(option => {
    option.addEventListener('click', function() {
      const radio = this.querySelector('input[type="radio"]');
      radio.checked = true;
    });
  });
});

function exportReport(format) {
  const selectedPeriod = document.querySelector('input[name="period"]:checked').value;
  const periodText = document.querySelector(`input[name="period"][value="${selectedPeriod}"]`).nextElementSibling.querySelector('div').textContent;
  
  Swal.fire({
    title: 'Generating Report...',
    text: `Preparing ${format.toUpperCase()} report for ${periodText}`,
    icon: 'info',
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true
  }).then(() => {
    window.open(`performance_report_complete.php?export=1&period=${selectedPeriod}&format=${format}`, '_blank');
    
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