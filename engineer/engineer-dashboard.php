<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'engineer') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$total_projects = $active_projects = $completed_projects = $pending_projects = 0;
$recent_projects = [];
$weekly_completion = [];

try {
    $engineer_id = $_SESSION['user_id'];

    // Project counts
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
        FROM project_requests
        WHERE assigned_engineer = ?
    ");
    $stmt->execute([$engineer_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_projects  = (int)($row['total']     ?? 0);
    $active_projects = (int)($row['active']    ?? 0);
    $completed_projects = (int)($row['completed'] ?? 0);
    $pending_projects   = (int)($row['pending']   ?? 0);

    // Recent projects
    $stmt = $pdo->prepare("
        SELECT pr.*, u.fullname AS requester_name
        FROM project_requests pr
        LEFT JOIN users u ON pr.user_id = u.id
        WHERE pr.assigned_engineer = ?
        ORDER BY pr.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$engineer_id]);
    $recent_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Weekly completion trend
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(updated_at, '%a') AS day_label, COUNT(*) AS count
        FROM project_requests
        WHERE assigned_engineer = ?
          AND status = 'completed'
          AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(updated_at)
        ORDER BY DATE(updated_at) ASC
    ");
    $stmt->execute([$engineer_id]);
    $weekly_completion = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Engineer dashboard error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Engineer Dashboard - RTIM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { 'poppins': ['Poppins', 'sans-serif'] },
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
    .sidebar-link { color: #9CA3AF; }
    .sidebar-link:hover { color: #FFF; background: #00332c; }
    .sidebar-link.active { color: #faae2b; background: #00332c; border-left: 3px solid #faae2b; }
    .stat-card { transition: transform .15s ease; }
    .stat-card:hover { transform: translateY(-4px); }
    .chart-container { position: relative; height: 280px; width: 100%; }
    @media (max-width: 640px) { .chart-container { height: 240px; } }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Dashboard</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Welcome back, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Engineer', 0, 20)); ?></p>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
          <div class="text-right hidden sm:block">
            <div id="currentDate" class="text-xs font-semibold text-lgu-headline"></div>
            <div id="currentTime" class="text-sm font-bold text-lgu-button"></div>
          </div>
          <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
              <i class="fa fa-user-cog text-lgu-button-text font-semibold text-sm sm:text-base"></i>
            </div>
            <div class="hidden md:block">
              <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Engineer', 0, 15)); ?></p>
              <p class="text-xs text-lgu-paragraph">Engineer</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">

      <!-- Welcome Banner -->
      <div class="mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-4 sm:p-6 text-white shadow-lg">
          <h2 class="text-2xl sm:text-3xl font-bold">Hello, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Engineer', 0, 15)); ?> 👋</h2>
          <p class="text-xs sm:text-sm text-gray-200 mt-2">Your engineering projects and inspection overview.</p>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Projects</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-headline mt-2"><?php echo $total_projects; ?></p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-project-diagram text-xl text-lgu-button"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Pending</p>
              <p class="text-2xl sm:text-3xl font-bold text-orange-600 mt-2"><?php echo $pending_projects; ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-clock text-xl text-orange-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Active</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2"><?php echo $active_projects; ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-hard-hat text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Completed</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?php echo $completed_projects; ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-xl text-green-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Project Status Overview</h3>
          <div class="chart-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Weekly Completions</h3>
          <div class="chart-container">
            <canvas id="weeklyChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Recent Projects -->
      <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6 overflow-hidden">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline">Recent Projects</h3>
          <a href="active_projects.php" class="text-lgu-button hover:text-lgu-stroke font-semibold text-xs sm:text-sm">View All →</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead>
              <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50">
                <th class="py-2 px-3">ID</th>
                <th class="py-2 px-3 hidden sm:table-cell">Requester</th>
                <th class="py-2 px-3">Title</th>
                <th class="py-2 px-3">Status</th>
                <th class="py-2 px-3">Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent_projects)): ?>
                <tr><td colspan="5" class="py-6 text-center text-lgu-paragraph">No projects assigned yet</td></tr>
              <?php else: foreach ($recent_projects as $project): ?>
                <?php
                  $sc = match($project['status'] ?? '') {
                    'active'    => 'bg-blue-100 text-blue-700',
                    'completed' => 'bg-green-100 text-green-700',
                    'pending'   => 'bg-orange-100 text-orange-700',
                    default     => 'bg-gray-100 text-gray-700'
                  };
                ?>
                <tr class="border-b border-gray-200 hover:bg-gray-50">
                  <td class="py-2 px-3 font-semibold text-lgu-headline">#<?php echo htmlspecialchars($project['id']); ?></td>
                  <td class="py-2 px-3 hidden sm:table-cell"><?php echo htmlspecialchars(substr($project['requester_name'] ?? 'N/A', 0, 15)); ?></td>
                  <td class="py-2 px-3"><?php echo htmlspecialchars(substr($project['title'] ?? $project['project_type'] ?? '-', 0, 20)); ?></td>
                  <td class="py-2 px-3"><span class="<?php echo $sc; ?> px-2 py-1 rounded text-xs font-semibold"><?php echo ucfirst($project['status'] ?? '-'); ?></span></td>
                  <td class="py-2 px-3 text-gray-500"><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Sprint Planning Section -->
      <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-4">
        <h3 class="text-base sm:text-lg font-bold text-lgu-headline mb-2">9.2 Sprint Planning and Backlog Management</h3>
        <p class="text-xs sm:text-sm text-lgu-paragraph leading-relaxed">
          The Road and Transportation Infrastructure Monitoring (RTIM) system was developed using an Agile Scrum framework, where the project was broken down into manageable sprints. Sprint planning involved identifying and prioritizing user stories from the product backlog based on business value and technical feasibility. Each user story was assigned story points to estimate effort and complexity. The backlog was continuously refined throughout the development lifecycle to accommodate new requirements and feedback from stakeholders — including administrators, inspectors, residents, maintenance workers, treasurers, and the system itself. This iterative approach ensured that all core features such as report management, inspection workflows, AI-based road defect detection, real-time traffic alerts, and payment processing were delivered incrementally and on schedule.
        </p>
      </div>

      <!-- Product Backlog -->
      <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6 overflow-hidden">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline">Product Backlog</h3>
          <span class="text-xs text-lgu-paragraph">Engineer User Stories</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs sm:text-sm border-collapse">
            <thead>
              <tr class="text-xs font-semibold text-white bg-lgu-headline">
                <th class="py-2 px-3">ID</th>
                <th class="py-2 px-3">User Story</th>
                <th class="py-2 px-3 text-center">Status</th>
                <th class="py-2 px-3 text-center">Story Points</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $backlog = [
                ['id'=>'AD-001','story'=>'As an admin, I want to view, validate, and manage incoming reports.','status'=>'Done','points'=>8],
                ['id'=>'AD-002','story'=>'As an admin, I want to assign reports to appropriate teams.','status'=>'Done','points'=>13],
                ['id'=>'IN-003','story'=>'As an Inspector, I want digital forms to conduct and record inspections.','status'=>'Done','points'=>5],
                ['id'=>'IN-004','story'=>'As an inspector, I want to update report status after inspection.','status'=>'Done','points'=>5],
                ['id'=>'RE-005','story'=>'As a resident, I want to report hazards with photos and location details.','status'=>'Done','points'=>8],
                ['id'=>'RE-006','story'=>'As a resident, I want to track the status of my submitted reports.','status'=>'Done','points'=>5],
                ['id'=>'MA-007','story'=>'As a maintenance worker, I want a dashboard to view assigned tasks.','status'=>'Done','points'=>3],
                ['id'=>'MA-008','story'=>'As a maintenance worker I want to receive and update work orders.','status'=>'Done','points'=>8],
                ['id'=>'TR-009','story'=>'As a treasurer, I want to receive payments from residents for traffic violations.','status'=>'Done','points'=>8],
                ['id'=>'TR-010','story'=>"As a treasurer, I want to check a resident's pending violations.",'status'=>'Done','points'=>5],
                ['id'=>'SYS-011','story'=>'As a system, I want to automatically detect road surface defects using TensorFlow.js.','status'=>'Done','points'=>13],
                ['id'=>'SYS-012','story'=>'As a system, I want to send real-time traffic alerts to residents.','status'=>'Done','points'=>5],
              ];
              $total_points = 0;
              foreach ($backlog as $i => $item):
                $total_points += $item['points'];
                $sc = $item['status'] === 'Done' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700';
              ?>
              <tr class="border-b border-gray-100 <?php echo $i % 2 === 0 ? 'bg-white' : 'bg-gray-50'; ?> hover:bg-yellow-50">
                <td class="py-2 px-3 font-semibold text-lgu-headline whitespace-nowrap"><?php echo $item['id']; ?></td>
                <td class="py-2 px-3 text-lgu-paragraph"><?php echo htmlspecialchars($item['story']); ?></td>
                <td class="py-2 px-3 text-center"><span class="<?php echo $sc; ?> px-2 py-1 rounded text-xs font-semibold"><?php echo $item['status']; ?></span></td>
                <td class="py-2 px-3 text-center font-bold text-lgu-headline"><?php echo $item['points']; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="bg-lgu-bg font-bold">
                <td colspan="3" class="py-2 px-3 text-right text-lgu-headline">Total Story Points</td>
                <td class="py-2 px-3 text-center text-lgu-headline"><?php echo $total_points; ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-8">
        <a href="project_requests.php" class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition">
          <div><i class="fa fa-folder-open mr-2"></i> Requests</div>
          <i class="fa fa-arrow-right text-xs"></i>
        </a>
        <a href="active_projects.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition">
          <div><i class="fa fa-hard-hat mr-2"></i> Active</div>
          <i class="fa fa-arrow-right text-xs"></i>
        </a>
        <a href="site_inspections.php" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition">
          <div><i class="fa fa-clipboard-check mr-2"></i> Inspections</div>
          <i class="fa fa-arrow-right text-xs"></i>
        </a>
        <a href="engineering_reports.php" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 sm:py-4 px-4 sm:px-6 rounded-lg flex items-center justify-between shadow-md hover:shadow-lg transition">
          <div><i class="fa fa-file-alt mr-2"></i> Reports</div>
          <i class="fa fa-arrow-right text-xs"></i>
        </a>
      </div>

    </main>

    <footer class="bg-lgu-headline text-white py-6 sm:py-8 mt-auto flex-shrink-0">
      <div class="container mx-auto px-4 text-center">
        <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM - Road and Transportation Infrastructure Monitoring</p>
      </div>
    </footer>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Live Clock
  const updateClock = () => {
    const dateEl = document.getElementById('currentDate');
    const timeEl = document.getElementById('currentTime');
    const now = new Date();
    if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
  };
  updateClock();
  setInterval(updateClock, 1000);

  // Mobile sidebar toggle
  const sidebar = document.getElementById('engineer-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  Chart.defaults.font.family = "'Poppins', sans-serif";
  Chart.defaults.color = '#475d5b';

  // Status doughnut chart
  const statusCtx = document.getElementById('statusChart');
  if (statusCtx) {
    new Chart(statusCtx, {
      type: 'doughnut',
      data: {
        labels: ['Pending', 'Active', 'Completed'],
        datasets: [{
          data: [<?php echo $pending_projects; ?>, <?php echo $active_projects; ?>, <?php echo $completed_projects; ?>],
          backgroundColor: ['#f97316', '#3b82f6', '#10b981'],
          borderColor: '#ffffff',
          borderWidth: 3,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { padding: 15, usePointStyle: true, pointStyle: 'circle' } }
        }
      }
    });
  }

  // Weekly bar chart
  const weeklyData = <?php echo json_encode($weekly_completion); ?>;
  const weeklyCtx = document.getElementById('weeklyChart');
  if (weeklyCtx && weeklyData.length > 0) {
    new Chart(weeklyCtx, {
      type: 'bar',
      data: {
        labels: weeklyData.map(i => i.day_label),
        datasets: [{
          label: 'Completed Projects',
          data: weeklyData.map(i => i.count),
          backgroundColor: '#10b981',
          borderColor: '#059669',
          borderWidth: 2,
          borderRadius: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,0.05)' } },
          x: { grid: { display: false } }
        }
      }
    });
  } else if (weeklyCtx) {
    weeklyCtx.parentElement.innerHTML = '<p class="text-center text-gray-400 py-8">No data available</p>';
  }
});
</script>
</body>
</html>
