<?php
// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Maintenance Team';
?>

<!-- Sidebar -->
<div id="maintenance-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-50 flex flex-col">

  <!-- Header -->
  <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
        <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
      </div>
      <div>
        <h2 class="text-white font-semibold text-sm">Maintenance Panel</h2>
        <p class="text-gray-300 text-xs font-light">Infrastructure</p>
      </div>
    </div>
    <button id="sidebar-close" class="lg:hidden text-white hover:text-lgu-highlight transition">
      <i class="fas fa-times"></i>
    </button>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 overflow-y-auto py-4">

    <!-- MAIN -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Main</h4>
      <ul class="space-y-1">
        <li>
          <a href="maintenance-dashboard.php" class="sidebar-link <?php echo ($current_page === 'maintenance-dashboard.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke">
            <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
          </a>
        </li>
      </ul>
    </div>

    <!-- ASSIGNED TASKS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Assigned Tasks</h4>
      <ul class="space-y-1">
        <li>
          <a href="assigned_minor_issues.php" class="sidebar-link <?php echo ($current_page === 'assigned_minor_issues.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-inbox mr-3 text-lgu-highlight"></i> Received Tasks
            <?php
            // Show pending task count badge
            try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE assigned_team = ? AND status IN ('pending', 'in_progress')");
              $stmt->execute([$_SESSION['user_id']]);
              $pending_count = (int)$stmt->fetchColumn();
              if ($pending_count > 0) {
                echo '<span class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">' . $pending_count . '</span>';
              }
            } catch (PDOException $e) {
              // Silent fail
            }
            ?>
          </a>
        </li>
      
      </ul>
    </div>

    <!-- MODULES -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Maintenance Modules</h4>
      <ul class="space-y-1">
        <li>
          <a href="assigned_road_maintenance.php" class="sidebar-link <?php echo ($current_page === 'road_maintenance.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-road mr-3 text-lgu-highlight"></i> Road Repairs
          </a>
        </li>
        <li>
          <a href="assigned_bridge_maintenance.php" class="sidebar-link <?php echo ($current_page === 'bridge_maintenance.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-archway mr-3 text-lgu-highlight"></i> Bridge Maintenance
          </a>
        </li>
        <li>
          <a href="assigned_traffic_management.php" class="sidebar-link <?php echo ($current_page === 'traffic_maintenance.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-traffic-light mr-3 text-lgu-highlight"></i> Traffic Systems
          </a>
        </li>
      </ul>
    </div>

    <!-- FUND REQUESTS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Finance</h4>
      <ul class="space-y-1">
        <li>
          <a href="fund_requests.php" class="sidebar-link <?php echo ($current_page === 'fund_requests.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-money-bill-wave mr-3 text-lgu-highlight"></i> Fund Requests
            <?php
            try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM fund_requests WHERE maintenance_team_id = ? AND status = 'pending'");
              $stmt->execute([$_SESSION['user_id']]);
              $pending_requests = (int)$stmt->fetchColumn();
              if ($pending_requests > 0) {
                echo '<span class="ml-auto bg-yellow-500 text-white text-xs font-bold px-2 py-1 rounded-full">' . $pending_requests . '</span>';
              }
            } catch (PDOException $e) {}
            ?>
          </a>
        </li>
      </ul>
    </div>

    <!-- REPORTS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Reports</h4>
      <ul class="space-y-1">
        <li>
          <a href="maintenance_history.php" class="sidebar-link <?php echo ($current_page === 'maintenance_history.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-history mr-3 text-lgu-highlight"></i> Maintenance History
          </a>
        </li>
        <li>
          <a href="performance_report.php" class="sidebar-link <?php echo ($current_page === 'performance_report.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-chart-line mr-3 text-lgu-highlight"></i> Performance Report
          </a>
        </li>
      </ul>
    </div>
  </nav>

  <!-- Footer -->
  <div class="p-4 border-t border-lgu-stroke flex-shrink-0">
    <div class="flex items-center mb-3">
      <div class="w-8 h-8 rounded-full bg-lgu-highlight flex items-center justify-center mr-2">
        <i class="fas fa-user-hard-hat text-lgu-headline text-sm"></i>
      </div>
      <div>
        <p class="text-sm font-medium"><?php echo htmlspecialchars($user_name); ?></p>
        <p class="text-xs text-gray-400">Maintenance</p>
      </div>
    </div>
    <button id="logout-btn" class="w-full flex items-center justify-center px-3 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors duration-200">
      <i class="fas fa-sign-out-alt mr-2"></i> Logout
    </button>
  </div>
</div>

<!-- Overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

<!-- Toggle Button -->
<button id="sidebar-toggle" class="fixed top-4 left-4 z-50 lg:hidden bg-lgu-headline text-white p-2 rounded-lg shadow-lg hover:bg-lgu-stroke transition">
  <i class="fas fa-bars"></i>
</button>

<!-- JS Logic -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('maintenance-sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const close = document.getElementById('sidebar-close');
    const overlay = document.getElementById('sidebar-overlay');
    const logout = document.getElementById('logout-btn');

    if (toggle) {
      toggle.addEventListener('click', () => {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
      });
    }

    if (close) {
      close.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
      });
    }

    if (overlay) {
      overlay.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
      });
    }

    if (logout) {
      logout.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
          // Clear localStorage on logout
          localStorage.clear();
          window.location.href = '/logout.php';
        }
      });
    }

    // Set active menu item based on current page
    function setActiveMenuItem() {
      const currentPage = basename(window.location.pathname);
      const links = document.querySelectorAll('.sidebar-link');
      links.forEach(link => {
        link.classList.remove('active');
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'maintenance-dashboard.php')) {
          link.classList.add('active');
        }
      });
    }

    function basename(path) {
      return path.split('/').pop();
    }

    setActiveMenuItem();
  });
</script>

<style>
  * { font-family: 'Poppins', sans-serif; }
  .sidebar-link.active {
    color: #faae2b;
    background-color: #00332c;
    border-left: 3px solid #faae2b;
  }
  .sidebar-link {
    color: #d1d5db;
    transition: all 0.3s ease-in-out;
  }
  .sidebar-link:hover {
    color: #ffffff;
    background-color: #00332c;
  }
  #maintenance-sidebar nav::-webkit-scrollbar {
    width: 6px;
  }
  #maintenance-sidebar nav::-webkit-scrollbar-thumb {
    background: #faae2b;
    border-radius: 3px;
  }
  #maintenance-sidebar nav::-webkit-scrollbar-thumb:hover {
    background: #f5a217;
  }
</style>
