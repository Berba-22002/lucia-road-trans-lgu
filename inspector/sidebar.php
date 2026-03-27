<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inspector Dashboard - LGU Infrastructure</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
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
            'lgu-main': '#f2f7f5',
            'lgu-highlight': '#faae2b',
            'lgu-secondary': '#ffa8ba',
            'lgu-tertiary': '#fa5246'
          }
        }
      }
    }
  </script>
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
            <a href="inspector-dashboard.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200 hover:bg-lgu-stroke" data-page="inspector-dashboard.php">
              <i class="fas fa-tachometer-alt mr-3 text-lgu-highlight"></i> Dashboard
            </a>
          </li>
         
        </ul>
      </div>

      <!-- ASSIGNED TASKS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Assigned Tasks</h4>
        <ul class="space-y-1">
          <li><a href="assigned_reports.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="assigned_reports.php"><i class="fas fa-inbox mr-3 text-lgu-highlight"></i> Incoming Assignments</a></li>
          <li><a href="conduct_inspection.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="conduct_inspection.php"><i class="fas fa-clipboard-check mr-3 text-lgu-highlight"></i> Conduct Inspection</a></li>
          <li><a href="submit_hazard_report.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="submit_hazard_report.php"><i class="fas fa-exclamation-circle mr-3 text-lgu-highlight"></i> Submit Hazard Report</a></li>
          <li><a href="issue_ovr.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="issue_ovr.php"><i class="fas fa-exclamation mr-3 text-lgu-highlight"></i> Issue Ticket</a></li>
         
        </ul>
      </div>

      <!-- CLASSIFY REPORTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Report Classification</h4>
        <ul class="space-y-1">
          <li><a href="minor_reports.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="minor_reports.php"><i class="fas fa-wrench mr-3 text-lgu-highlight"></i> Minor (For Maintenance)</a></li>
          <li><a href="major_reports.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="major_reports.php"><i class="fas fa-project-diagram mr-3 text-lgu-highlight"></i> Major (For Project Tracking)</a></li>
         
        </ul>
      </div>

     

      <!-- REPORTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Reports & Analytics</h4>
        <ul class="space-y-1">
          <li><a href="inspection_history.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="inspection_history.php"><i class="fas fa-history mr-3 text-lgu-highlight"></i> Inspection History</a></li>
          <li><a href="performance_report.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="performance_report.php"><i class="fas fa-chart-line mr-3 text-lgu-highlight"></i> Performance Report</a></li>
        
        </ul>
      </div>

      <!-- HAZARD MONITORING -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Hazard Monitoring</h4>
        <ul class="space-y-1">
          <li><a href="view_related_hazards.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition" data-page="view_related_hazards.php"><i class="fas fa-link mr-3 text-lgu-highlight"></i> View Related Hazards</a></li>
          
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
          <p class="text-sm font-medium">Inspector User</p>
          <p class="text-xs text-gray-400">Field Inspector</p>
        </div>
      </div>
      <button id="logout-btn" class="w-full flex items-center justify-center px-3 py-2 text-sm font-medium text-white bg-lgu-tertiary hover:bg-red-600 rounded-lg transition-colors duration-200">
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
      const sidebar = document.getElementById('inspector-sidebar');
      const toggle = document.getElementById('sidebar-toggle');
      const close = document.getElementById('sidebar-close');
      const overlay = document.getElementById('sidebar-overlay');
      const logout = document.getElementById('logout-btn');
      
      // Function to get current page from URL
      function getCurrentPage() {
        const path = window.location.pathname;
        const page = path.split('/').pop();
        return page || 'inspector-dashboard.php';
      }

      // Function to set active menu item
      function setActiveMenuItem() {
        const currentPage = getCurrentPage();
        const sidebarLinks = document.querySelectorAll('.sidebar-link');
        
        // Remove active class from all links
        sidebarLinks.forEach(link => {
          link.classList.remove('active');
        });
        
        // Add active class to current page link
        const currentLink = document.querySelector(`[data-page="${currentPage}"]`);
        if (currentLink) {
          currentLink.classList.add('active');
        }
        
        // Save to localStorage
        localStorage.setItem('currentPage', currentPage);
      }

      // Initialize sidebar state
      setActiveMenuItem();

      // Toggle sidebar
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

      // Handle menu item clicks
      document.querySelectorAll('.sidebar-link').forEach(link => {
        link.addEventListener('click', function() {
          // Update active state
          document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
          this.classList.add('active');
          
          // Save to localStorage
          localStorage.setItem('currentPage', this.dataset.page);
        });
      });

      logout.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
          // Clear localStorage on logout
          localStorage.clear();
          window.location.href = '/logout.php';
        }
      });
    });
  </script>

  <style>
    * { font-family: 'Poppins', sans-serif; }
    .sidebar-link.active { 
      color: #faae2b; 
      background-color: #00332c; 
      border-left: 3px solid #faae2b; 
    }
    #inspector-sidebar nav::-webkit-scrollbar { 
      width: 6px; 
    }
    #inspector-sidebar nav::-webkit-scrollbar-track { 
      background: transparent; 
    }
    #inspector-sidebar nav::-webkit-scrollbar-thumb { 
      background: #faae2b; 
      border-radius: 3px; 
    }
    #inspector-sidebar nav::-webkit-scrollbar-thumb:hover { 
      background: #f5a217; 
    }
  </style>

</body>
</html>