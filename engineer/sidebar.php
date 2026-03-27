<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Engineer';
?>

<!-- Sidebar -->
<div id="engineer-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-50 flex flex-col">

  <!-- Header -->
  <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
        <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
      </div>
      <div>
        <h2 class="text-white font-semibold text-sm">Engineer Panel</h2>
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
          <a href="engineer-dashboard.php" class="sidebar-link <?php echo ($current_page === 'engineer-dashboard.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke">
            <i class="fas fa-tachometer-alt mr-3 text-lgu-highlight"></i> Dashboard
          </a>
        </li>
      </ul>
    </div>

    <!-- PROJECTS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Projects</h4>
      <ul class="space-y-1">
        <li>
          <a href="project_requests.php" class="sidebar-link <?php echo ($current_page === 'project_requests.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-folder-open mr-3 text-lgu-highlight"></i> Project Requests
          </a>
        </li>
        <li>
          <a href="active_projects.php" class="sidebar-link <?php echo ($current_page === 'active_projects.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-hard-hat mr-3 text-lgu-highlight"></i> Active Projects
          </a>
        </li>
        <li>
          <a href="project_history.php" class="sidebar-link <?php echo ($current_page === 'project_history.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-history mr-3 text-lgu-highlight"></i> Project History
          </a>
        </li>
      </ul>
    </div>

    <!-- INSPECTIONS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Inspections</h4>
      <ul class="space-y-1">
        <li>
          <a href="site_inspections.php" class="sidebar-link <?php echo ($current_page === 'site_inspections.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-clipboard-check mr-3 text-lgu-highlight"></i> Site Inspections
          </a>
        </li>
        <li>
          <a href="inspection_findings.php" class="sidebar-link <?php echo ($current_page === 'inspection_findings.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-search mr-3 text-lgu-highlight"></i> Inspection Findings
          </a>
        </li>
      </ul>
    </div>

    <!-- REPORTS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Reports</h4>
      <ul class="space-y-1">
        <li>
          <a href="engineering_reports.php" class="sidebar-link <?php echo ($current_page === 'engineering_reports.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition">
            <i class="fas fa-file-alt mr-3 text-lgu-highlight"></i> Engineering Reports
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
        <i class="fas fa-user-cog text-lgu-headline text-sm"></i>
      </div>
      <div>
        <p class="text-sm font-medium"><?php echo htmlspecialchars($user_name); ?></p>
        <p class="text-xs text-gray-400">Engineer</p>
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

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('engineer-sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const close = document.getElementById('sidebar-close');
    const overlay = document.getElementById('sidebar-overlay');
    const logout = document.getElementById('logout-btn');

    if (toggle) toggle.addEventListener('click', () => { sidebar.classList.toggle('-translate-x-full'); overlay.classList.toggle('hidden'); });
    if (close) close.addEventListener('click', () => { sidebar.classList.add('-translate-x-full'); overlay.classList.add('hidden'); });
    if (overlay) overlay.addEventListener('click', () => { sidebar.classList.add('-translate-x-full'); overlay.classList.add('hidden'); });
    if (logout) logout.addEventListener('click', () => { if (confirm('Are you sure you want to logout?')) { localStorage.clear(); window.location.href = '/logout.php'; } });
  });
</script>

<style>
  * { font-family: 'Poppins', sans-serif; }
  .sidebar-link.active { color: #faae2b; background-color: #00332c; border-left: 3px solid #faae2b; }
  .sidebar-link { color: #d1d5db; transition: all 0.3s ease-in-out; }
  .sidebar-link:hover { color: #ffffff; background-color: #00332c; }
  #engineer-sidebar nav::-webkit-scrollbar { width: 6px; }
  #engineer-sidebar nav::-webkit-scrollbar-thumb { background: #faae2b; border-radius: 3px; }
  #engineer-sidebar nav::-webkit-scrollbar-thumb:hover { background: #f5a217; }
</style>
