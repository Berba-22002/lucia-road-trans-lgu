<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$treasurer_id = $_SESSION['user_id'];
$data_error = false;

$total_budget = $allocated_budget = $spent_budget = $remaining_budget = 0;
$budget_items = [];
$recent_transactions = [];
$budget_requests = [];
$fund_requests = [];
$unread_notifications = 0;

try {
    // Get total collected payments from OVR tickets (paid penalties)
    $stmt = $pdo->prepare("
        SELECT SUM(penalty_amount) as total_collected
        FROM ovr_tickets
        WHERE payment_status = 'paid'
    ");
    $stmt->execute();
    $collected_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_collected = (float)($collected_row['total_collected'] ?? 0);
    
    // Get approved fund requests (budget allocated to maintenance)
    $stmt = $pdo->prepare("
        SELECT SUM(approved_amount) as allocated
        FROM fund_requests
        WHERE status = 'approved'
    ");
    $stmt->execute();
    $allocated_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $allocated_budget = (float)($allocated_row['allocated'] ?? 0);
    
    // Total Budget = Collected - Allocated
    $total_budget = $total_collected - $allocated_budget;
    
    // Get spent budget (completed maintenance work)
    $stmt = $pdo->prepare("
        SELECT SUM(fr.approved_amount) as spent
        FROM fund_requests fr
        INNER JOIN maintenance_assignments ma ON fr.report_id = ma.report_id
        WHERE fr.status = 'approved' AND ma.status = 'completed'
    ");
    $stmt->execute();
    $spent_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $spent_budget = (float)($spent_row['spent'] ?? 0);
    
    // Net Budget = Total Budget - Spent
    $net_budget = $total_budget - $spent_budget;

    // Get fund requests (approved budget allocations)
    $stmt = $pdo->prepare("
        SELECT fr.*, r.hazard_type, r.description as report_description
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status = 'approved'
        ORDER BY fr.approved_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $budget_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get transactions
    $stmt = $pdo->prepare("
        SELECT fr.id, fr.report_id, fr.approved_amount as amount, fr.approved_at as transaction_date, r.description
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status = 'approved'
        ORDER BY fr.approved_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get budget requests (endorsed fund requests)
    $stmt = $pdo->prepare("
        SELECT fr.*, r.hazard_type, r.description
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status IN ('endorsed', 'approved')
        ORDER BY fr.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $budget_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get fund requests (all pending and approved)
    $stmt = $pdo->prepare("
        SELECT fr.*, r.hazard_type, r.description, r.address
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status IN ('endorsed', 'approved')
        ORDER BY fr.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $fund_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread notifications count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$treasurer_id]);
    $notif_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unread_notifications = (int)($notif_row['count'] ?? 0);
} catch (PDOException $e) {
    error_log("Treasurer dashboard error: " . $e->getMessage());
    $data_error = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Treasurer Dashboard - RTIM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {'poppins': ['Poppins', 'sans-serif']},
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
    .stat-card { transition: transform .15s ease; }
    .stat-card:hover { transform: translateY(-4px); }
    .chart-container { position: relative; height: 280px; width: 100%; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; }
    .modal.show { display: flex; }
    @media (max-width: 640px) { .chart-container { height: 240px; } }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="lg:ml-64 flex flex-col min-h-screen">
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Treasurer Dashboard</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Welcome, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Treasurer', 0, 20)); ?></p>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
          <!-- Live Clock -->
          <div class="text-right hidden sm:block">
            <div id="currentDate" class="text-xs font-semibold text-lgu-headline"></div>
            <div id="currentTime" class="text-sm font-bold text-lgu-button"></div>
          </div>
          
          <!-- Notification Bell -->
          <button id="notificationBell" class="relative text-lgu-paragraph hover:text-lgu-headline transition flex-shrink-0 p-2 rounded-lg hover:bg-gray-100">
            <i class="fa fa-bell text-lg sm:text-xl"></i>
            <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center <?php echo $unread_notifications > 0 ? '' : 'hidden'; ?>"><?php echo $unread_notifications; ?></span>
          </button>
          
          <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
            <i class="fa fa-money-bill text-lgu-button-text font-semibold text-sm sm:text-base"></i>
          </div>
          <div class="hidden md:block">
            <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Treasurer', 0, 15)); ?></p>
            <p class="text-xs text-lgu-paragraph">Treasurer</p>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <?php if ($data_error): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
          <i class="fas fa-exclamation-triangle text-red-600"></i>
          <p class="text-red-700 font-semibold">Unable to load data. Please try again later.</p>
        </div>
      <?php endif; ?>

      <div class="mb-6">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-4 sm:p-6 text-white flex flex-col sm:flex-row items-start sm:items-center justify-between shadow-lg gap-4">
          <div>
            <h2 class="text-2xl sm:text-3xl font-bold">Hello, <?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Treasurer', 0, 15)); ?> 👋</h2>
            <p class="text-xs sm:text-sm text-gray-200 mt-2">Budget allocation and financial overview.</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
          <p class="text-xs font-semibold text-lgu-paragraph uppercase">Collected Payments</p>
          <p class="text-2xl sm:text-3xl font-bold text-lgu-headline mt-2">₱<?php echo number_format($total_collected, 2); ?></p>
        </div>
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <p class="text-xs font-semibold text-lgu-paragraph uppercase">Budget Request</p>
          <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2">₱<?php echo number_format($allocated_budget, 2); ?></p>
        </div>
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Budget</p>
          <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2">₱<?php echo number_format($total_budget, 2); ?></p>
        </div>
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
          <p class="text-xs font-semibold text-lgu-paragraph uppercase">Spent</p>
          <p class="text-2xl sm:text-3xl font-bold text-red-600 mt-2">₱<?php echo number_format($spent_budget, 2); ?></p>
        </div>
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
          <p class="text-xs font-semibold text-lgu-paragraph uppercase">Net Budget</p>
          <p class="text-2xl sm:text-3xl font-bold text-purple-600 mt-2">₱<?php echo number_format($net_budget, 2); ?></p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Budget Distribution</h3>
          <div class="chart-container">
            <canvas id="budgetPieChart"></canvas>
          </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Budget Overview</h3>
          <div class="chart-container">
            <canvas id="budgetBarChart"></canvas>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Budget Items</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
              <thead>
                <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50">
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Item</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Amount</th>
                  <th class="py-2 px-2 sm:py-3 sm:px-3">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($budget_items)): ?>
                  <tr><td colspan="3" class="py-6 text-center text-lgu-paragraph">No budget items</td></tr>
                <?php else: foreach ($budget_items as $item): ?>
                  <tr class="border-b border-gray-200 hover:bg-gray-50">
                    <td class="py-2 px-2 sm:py-3 sm:px-3 font-semibold"><?php echo htmlspecialchars(substr($item['report_description'] ?? 'N/A', 0, 20)); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3">₱<?php echo number_format($item['approved_amount'] ?? 0, 2); ?></td>
                    <td class="py-2 px-2 sm:py-3 sm:px-3">
                      <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-semibold"><?php echo ucfirst($item['status']); ?></span>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
          <h3 class="text-base sm:text-lg font-semibold text-lgu-headline mb-4">Recent Transactions</h3>
          <div class="space-y-3 max-h-96 overflow-y-auto">
            <?php if (empty($recent_transactions)): ?>
              <p class="text-lgu-paragraph py-6 text-center text-sm">No transactions yet</p>
            <?php else: foreach($recent_transactions as $transaction): ?>
              <div class="border border-gray-200 rounded-lg p-3 sm:p-4 hover:shadow-md transition">
                <h4 class="font-semibold text-lgu-headline text-sm truncate"><?php echo htmlspecialchars($transaction['description'] ?? 'Transaction'); ?></h4>
                <p class="text-xs text-lgu-paragraph mt-1">₱<?php echo number_format($transaction['amount'], 2); ?></p>
                <p class="text-xs text-gray-500 mt-1"><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></p>
              </div>
            <?php endforeach; endif; ?>
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

  <!-- Activities Modal -->
  <div id="activitiesModal" class="modal">
    <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full max-h-96 flex flex-col">
      <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
        <h3 class="text-lg font-bold"><i class="fa fa-history mr-2"></i>Recent Activities</h3>
        <div class="flex gap-2">
          <button onclick="markAllAsRead()" class="text-xs bg-white/20 hover:bg-white/30 px-2 py-1 rounded transition">Mark all read</button>
          <button onclick="closeActivitiesModal()" class="text-white hover:text-gray-200">
            <i class="fa fa-times text-xl"></i>
          </button>
        </div>
      </div>
      <div id="activitiesList" class="overflow-y-auto flex-1 p-4 space-y-3"></div>
    </div>
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

  // Notification Bell
  let unreadCount = <?php echo $unread_notifications; ?>;
  const notificationBell = document.getElementById('notificationBell');
  const notificationBadge = document.getElementById('notificationBadge');
  
  function updateBadge() {
    if (unreadCount > 0) {
      notificationBadge.textContent = unreadCount;
      notificationBadge.classList.remove('hidden');
    } else {
      notificationBadge.classList.add('hidden');
    }
  }
  
  updateBadge();
  
  if (notificationBell) {
    notificationBell.addEventListener('click', () => {
      fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
          const list = document.getElementById('activitiesList');
          if (data.notifications && data.notifications.length > 0) {
            list.innerHTML = data.notifications.map(notif => `
              <div class="border border-gray-200 rounded-lg p-3 hover:bg-gray-50">
                <p class="font-semibold text-lgu-headline text-sm">${notif.message || 'Notification'}</p>
                <p class="text-xs text-gray-500 mt-1">${new Date(notif.created_at).toLocaleDateString()}</p>
              </div>
            `).join('');
          } else {
            list.innerHTML = '<p class="text-center text-lgu-paragraph py-8">No notifications</p>';
          }
          document.getElementById('activitiesModal').classList.add('show');
        })
        .catch(error => console.error('Error:', error));
    });
  }

  window.markAllAsRead = () => {
    fetch('mark_notifications_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read' })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        unreadCount = 0;
        updateBadge();
        document.getElementById('activitiesList').innerHTML = '<p class="text-center text-lgu-paragraph py-8">No notifications</p>';
      }
    })
    .catch(error => console.error('Error:', error));
  };

  window.closeActivitiesModal = () => {
    document.getElementById('activitiesModal').classList.remove('show');
  };

  document.getElementById('activitiesModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'activitiesModal') closeActivitiesModal();
  });

  const budgetData = {
    total: <?php echo json_encode($total_budget); ?>,
    allocated: <?php echo json_encode($allocated_budget); ?>,
    spent: <?php echo json_encode($spent_budget); ?>,
    net: <?php echo json_encode($net_budget); ?>
  };

  const pieCtx = document.getElementById('budgetPieChart');
  if (pieCtx) {
    new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: ['Spent', 'Net Budget'],
        datasets: [{
          data: [budgetData.spent, budgetData.net],
          backgroundColor: ['#ef4444', '#10b981'],
          borderColor: ['#dc2626', '#059669'],
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 12, family: "'Poppins', sans-serif" }, padding: 15 } }
        }
      }
    });
  }

  const barCtx = document.getElementById('budgetBarChart');
  if (barCtx) {
    new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: ['Collected', 'Budget Request', 'Total Budget', 'Spent', 'Net Budget'],
        datasets: [{
          label: 'Amount (₱)',
          data: [<?php echo $total_collected; ?>, budgetData.allocated, budgetData.total, budgetData.spent, budgetData.net],
          backgroundColor: ['#faae2b', '#3b82f6', '#10b981', '#ef4444', '#a855f7'],
          borderColor: ['#f59e0b', '#1d4ed8', '#059669', '#dc2626', '#9333ea'],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, ticks: { font: { size: 11, family: "'Poppins', sans-serif" } } },
          y: { ticks: { font: { size: 11, family: "'Poppins', sans-serif" } } }
        }
      }
    });
  }
});
</script>

</body>
</html>
