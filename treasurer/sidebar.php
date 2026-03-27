<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar -->
<div id="treasurer-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-50 flex flex-col">

  <!-- Header -->
  <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
        <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
      </div>
      <div>
        <h2 class="text-white font-semibold text-sm">Treasurer Panel</h2>
        <p class="text-gray-300 text-xs font-light">LGU Finance</p>
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
          <a href="treasurer-dashboard.php" class="sidebar-link <?php echo ($current_page === 'treasurer-dashboard.php') ? 'active' : ''; ?> flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke">
            <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
          </a>
        </li>
      </ul>
    </div>

    <!-- BUDGET MANAGEMENT -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Budget Management</h4>
      <ul class="space-y-1">
        <li><a href="budget_allocations.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-wallet mr-3 text-lgu-highlight"></i> Budget Allocations</a></li>
        <li><a href="budget_tracking.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-chart-pie mr-3 text-lgu-highlight"></i> Budget Tracking</a></li>
      </ul>
    </div>

    <!-- TRANSACTIONS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Transactions</h4>
      <ul class="space-y-1">
        <li><a href="view_ovr_tickets.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-ticket-alt mr-3 text-lgu-highlight"></i> OVR Tickets</a></li>
        <li><a href="fund_requests.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-file-invoice-dollar mr-3 text-lgu-highlight"></i> Fund Requests</a></li>
        <li><a href="transactions.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-exchange-alt mr-3 text-lgu-highlight"></i> All Transactions</a></li>
        <li><a href="expense_reports.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-receipt mr-3 text-lgu-highlight"></i> Expense Reports</a></li>
      </ul>
    </div>

    <!-- REPORTS -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Reports</h4>
      <ul class="space-y-1">
        <li><a href="financial_reports.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-chart-bar mr-3 text-lgu-highlight"></i> Financial Reports</a></li>
        <li><a href="audit_trail.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-list mr-3 text-lgu-highlight"></i> Audit Trail</a></li>
      </ul>
    </div>

    <!-- ALERTS & TRACKING -->
    <div class="px-4 mb-6">
      <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Alerts & Tracking</h4>
      <ul class="space-y-1">
        <li><a href="budget_alerts.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke"><i class="fas fa-bell mr-3 text-lgu-highlight"></i> Budget Alerts</a></li>
      </ul>
    </div>
  </nav>

  <!-- Footer -->
  <div class="p-4 border-t border-lgu-stroke flex-shrink-0">
    <div class="flex items-center mb-3">
      <div class="w-8 h-8 rounded-full bg-lgu-highlight flex items-center justify-center mr-2">
        <i class="fas fa-user text-lgu-headline text-sm"></i>
      </div>
      <div>
        <p class="text-sm font-medium">Treasurer</p>
        <p class="text-xs text-gray-400">Finance Officer</p>
      </div>
    </div>
    <button id="logout-btn" class="w-full flex items-center justify-center px-3 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition">
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
    const sidebar = document.getElementById('treasurer-sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const close = document.getElementById('sidebar-close');
    const overlay = document.getElementById('sidebar-overlay');
    const logout = document.getElementById('logout-btn');
    const sidebarLinks = document.querySelectorAll('.sidebar-link');

    function getCurrentPage() {
      const path = window.location.pathname;
      const page = path.split('/').pop();
      return page.replace('.php', '') || 'dashboard';
    }

    function setActiveMenuItem() {
      const currentPage = getCurrentPage();
      sidebarLinks.forEach(link => link.classList.remove('active'));
      const currentLink = document.querySelector(`a[href="${currentPage}.php"]`);
      if (currentLink) {
        currentLink.classList.add('active');
      }
    }

    setActiveMenuItem();

    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      overlay.classList.toggle('hidden');
    });

    close.addEventListener('click', () => {
      sidebar.classList.add('-translate-x-full');
      overlay.classList.add('hidden');
    });

    overlay.addEventListener('click', () => {
      sidebar.classList.add('-translate-x-full');
      overlay.classList.add('hidden');
    });

    sidebarLinks.forEach(link => {
      link.addEventListener('click', function() {
        sidebarLinks.forEach(l => l.classList.remove('active'));
        this.classList.add('active');
      });
    });

    logout.addEventListener('click', () => {
      if (confirm('Are you sure you want to logout?')) {
        localStorage.clear();
        window.location.href = '/logout.php';
      }
    });
  });
</script>

<style>
  * { font-family: 'Poppins', sans-serif; }
  .sidebar-link { color: #d1d5db; }
  .sidebar-link:hover { color: #ffffff; }
  .sidebar-link.active { 
    color: #faae2b; 
    background-color: #00332c; 
    border-left: 3px solid #faae2b; 
  }
  #treasurer-sidebar nav::-webkit-scrollbar { width: 6px; }
  #treasurer-sidebar nav::-webkit-scrollbar-thumb { background: #faae2b; border-radius: 3px; }
  #treasurer-sidebar nav::-webkit-scrollbar-thumb:hover { background: #f5a217; }
</style>
