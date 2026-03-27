<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inspector Dashboard - LGU Infrastructure</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="bg-lgu-bg font-poppins">

  <!-- Sidebar -->
  <div id="inspector-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-50 flex flex-col">

    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
          <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
        </div>
        <div>
          <h2 class="text-white font-semibold text-sm">Inspector Panel</h2>
          <p class="text-gray-300 text-xs font-light">LGU Infrastructure</p>
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
            <a href="inspector-dashboard.php" class="sidebar-link active flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200">
              <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
            </a>
          </li>
        </ul>
      </div>

      <!-- ASSIGNED TASKS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Assigned Tasks</h4>
        <ul class="space-y-1">
          <li><a href="assigned_reports.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-inbox mr-3 text-lgu-highlight"></i> Incoming Assignments</a></li>
          <li><a href="conduct_inspection.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-clipboard-check mr-3 text-lgu-highlight"></i> Conduct Inspection</a></li>
        </ul>
      </div>

      <!-- CLASSIFY REPORTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Report Classification</h4>
        <ul class="space-y-1">
          <li><a href="minor_reports.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-wrench mr-3 text-lgu-highlight"></i> Minor (For Maintenance)</a></li>
          <li><a href="major_reports.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-project-diagram mr-3 text-lgu-highlight"></i> Major (For Project Tracking)</a></li>
        </ul>
      </div>

      <!-- ONGOING PROJECTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Ongoing Monitoring</h4>
        <ul class="space-y-1">
          <li><a href="under_construction.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-hard-hat mr-3 text-lgu-highlight"></i> Under Construction</a></li>
          <li><a href="work_done_verification.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-tasks mr-3 text-lgu-highlight"></i> Work Done Verification</a></li>
        </ul>
      </div>

      <!-- ENFORCEMENT -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Enforcement</h4>
        <ul class="space-y-1">
          <li><a href="issue_ovr.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-file-invoice mr-3 text-lgu-highlight"></i> Issue OVR Ticket</a></li>
        </ul>
      </div>

      <!-- REPORTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Reports</h4>
        <ul class="space-y-1">
          <li><a href="inspection_history.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-history mr-3 text-lgu-highlight"></i> Inspection History</a></li>
          <li><a href="performance_report.php" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition"><i class="fas fa-chart-line mr-3 text-lgu-highlight"></i> Performance Report</a></li>
        </ul>
      </div>
    </nav>

    <!-- Footer -->
    <div class="p-4 border-t border-lgu-stroke flex-shrink-0">
      <button id="logout-btn" class="w-full flex items-center px-3 py-2 text-sm font-medium text-gray-300 hover:text-lgu-tertiary hover:bg-lgu-stroke rounded-lg transition">
        <i class="fas fa-sign-out-alt mr-3"></i> Logout
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
      const sidebar = document.getElementById('inspector-sidebar');
      const toggle = document.getElementById('sidebar-toggle');
      const close = document.getElementById('sidebar-close');
      const overlay = document.getElementById('sidebar-overlay');
      const logout = document.getElementById('logout-btn');

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

      logout.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
          window.location.href = '/logout.php';
        }
      });
    });
  </script>

  <style>
    * { font-family: 'Poppins', sans-serif; }
    .sidebar-link.active { color: #faae2b; background-color: #00332c; border-left: 3px solid #faae2b; }
    #inspector-sidebar nav::-webkit-scrollbar { width: 6px; }
    #inspector-sidebar nav::-webkit-scrollbar-thumb { background: #faae2b; border-radius: 3px; }
    #inspector-sidebar nav::-webkit-scrollbar-thumb:hover { background: #f5a217; }
  </style>

</body>
</html>
