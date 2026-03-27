<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - LGU Infrastructure</title>
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
  <div id="admin-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-50 flex flex-col">

    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
          <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
        </div>
        <div>
          <h2 class="text-white font-semibold text-sm">Admin Panel</h2>
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
            <a href="admin-dashboard.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200" data-page="dashboard">
              <i class="fas fa-tachometer-alt mr-3 text-lgu-highlight"></i>
              Dashboard
            </a>
          </li>
        </ul>
      </div>

      <!-- INFRASTRUCTURE MODULES -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Infrastructure</h4>
        <ul class="space-y-1">

          <!-- DAMAGE & HAZARD REPORTING -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="damage-hazard">
              <span class="flex items-center"><i class="fas fa-exclamation-triangle mr-3 text-lgu-highlight"></i> Damage & Hazard</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="damage-hazard">
              <li><a href="incoming_reports.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="incoming_reports"><i class="fas fa-inbox mr-2 text-sm"></i> View Incoming Reports</a></li>
              <li><a href="assign_inspector.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="assign_inspector"><i class="fas fa-user-check mr-2 text-sm"></i> Assign Inspector</a></li>
              <li><a href="inspection_findings.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="inspection_findings"><i class="fas fa-file-alt mr-2 text-sm"></i> Inspector Findings</a></li>
              <li><a href="forward_to_team.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="forward_to_team"><i class="fas fa-arrow-right mr-2 text-sm"></i> Forward to Maintenance</a></li>
              <li><a href="status_feedback.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="status_feedback"><i class="fas fa-comments mr-2 text-sm"></i> Status & Feedback</a></li>
            </ul>
          </li>

          <!-- ROAD MAINTENANCE SCHEDULING -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="road-maintenance">
              <span class="flex items-center"><i class="fas fa-tools mr-3 text-lgu-highlight"></i> Road Maintenance</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="road-maintenance">
              <li><a href="assign_maintenance.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="assign_maintenance"><i class="fas fa-user-hard-hat mr-2 text-sm"></i> Assign Maintenance Team</a></li>
              <li><a href="maintenance_planner.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="maintenance_planner"><i class="fas fa-calendar-alt mr-2 text-sm"></i> Maintenance Team</a></li>
              <li><a href="maintenance_progress.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="maintenance_progress"><i class="fas fa-chart-pie mr-2 text-sm"></i> Progress Tracking</a></li>
            </ul>
          </li>

          <!-- BRIDGE & OVERPASS INSPECTION -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="bridge-inspection">
              <span class="flex items-center"><i class="fas fa-archway mr-3 text-lgu-highlight"></i> Bridge Inspection</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="bridge-inspection">
              <li><a href="assign_bridge_team.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="assign_bridge_team"><i class="fas fa-user-hard-hat mr-2 text-sm"></i> Assign Maintenance Team</a></li>
              <li><a href="bridge_scheduler.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="bridge_scheduler"><i class="fas fa-clock mr-2 text-sm"></i> Inspection Maintenance Team</a></li>
              <li><a href="bridge_updates.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="bridge_updates"><i class="fas fa-sync-alt mr-2 text-sm"></i> Bridge Repair Updates</a></li>
            </ul>
          </li>

          <!-- TRANSPORTATION FLOW MONITORING -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="transportation-flow">
              <span class="flex items-center"><i class="fas fa-traffic-light mr-3 text-lgu-highlight"></i> Transportation Flow</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="transportation-flow">
              <li><a href="traffic_dashboard.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="traffic_dashboard"><i class="fas fa-th-large mr-2 text-sm"></i> Traffic Dashboard</a></li>
              <li><a href="assign_traffic_team.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="assign_traffic_team"><i class="fas fa-user-hard-hat mr-2 text-sm"></i> Assign Maintenance Team</a></li>
              <li><a href="minor_traffic_issues.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="minor_traffic_issues"><i class="fas fa-exclamation-triangle mr-2 text-sm"></i> Traffic Maintenance Team</a></li>
              <li><a href="monitoring_updates.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="monitoring_updates"><i class="fas fa-chart-line mr-2 text-sm"></i> Monitoring Updates</a></li>
            </ul>
          </li>

         

          <!-- ROAD PROJECT TRACKING -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="road-project">
              <span class="flex items-center"><i class="fas fa-road mr-3 text-lgu-highlight"></i> Road Project Tracking</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="road-project">
              <li><a href="major_projects.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="major_projects"><i class="fas fa-project-diagram mr-2 text-sm"></i> Major Projects</a></li>
              <li><a href="under_construction.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="under_construction"><i class="fas fa-hammer mr-2 text-sm"></i> Under Construction</a></li>
            
             
            </ul>
          </li>
 <!-- FUND REQUESTS -->
          <li>
            <a href="fund_requests.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="fund_requests">
              <i class="fas fa-file-invoice-dollar mr-3 text-lgu-highlight"></i> Fund Requests
            </a>
          </li>
          <!-- REPORTS & ARCHIVE -->
          <li>
            <button class="sidebar-dropdown flex items-center justify-between w-full px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-section="reports-archive">
              <span class="flex items-center"><i class="fas fa-file-alt mr-3 text-lgu-highlight"></i> Reports & Archive</span>
              <svg class="w-4 h-4 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
            <ul class="sidebar-submenu hidden pl-8 space-y-1 text-sm" data-section="reports-archive">
              <li><a href="report_analytics.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="report_analytics"><i class="fas fa-chart-line mr-2 text-sm"></i> Report Analytics</a></li>
              <li><a href="generate_report.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="generate_report"><i class="fas fa-file-pdf mr-2 text-sm"></i> Generate Report</a></li>
              <li><a href="archives.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="archives"><i class="fas fa-archive mr-2 text-sm"></i> Archives</a></li>
              <li><a href="view_ovr_tickets.php" class="submenu-link flex items-center py-2 hover:text-lgu-highlight transition-colors" data-page="view_ovr_tickets"><i class="fas fa-ticket-alt mr-2 text-sm"></i> OVR Tickets</a></li>
            </ul>
          </li>
        </ul>
      </div>

      <!-- EXTERNAL SYSTEMS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">External Systems</h4>
        <ul class="space-y-1">
          <li>
            <a href="assessing_request.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="assessing_request">
              <i class="fas fa-check-circle mr-3 text-lgu-highlight"></i> Assessing Request
            </a>
          </li>
      
          
        </ul>
      </div>

      <!-- PUBLIC ANNOUNCEMENTS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Communications</h4>
        <ul class="space-y-1">
          <li>
            <a href="public_advisories.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="public_advisories">
              <i class="fas fa-bullhorn mr-3 text-lgu-highlight"></i> Public Advisories
            </a>
          </li>
          
        </ul>
      </div>

      <!-- SETTINGS -->
      <div class="px-4 mb-6">
        <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">Settings</h4>
        <ul class="space-y-1">
          <li>
            <a href="user_management.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="user_management">
              <i class="fas fa-users-cog mr-3 text-lgu-highlight"></i> User Management
            </a>
          </li>
        
          <li>
            <a href="manage_fines.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="manage_fines">
              <i class="fas fa-money-bill-wave mr-3 text-lgu-highlight"></i> Manage Fines
            </a>
          </li>
        </ul>
      </div>
    </nav>

    <!-- Footer -->
    <div class="p-4 border-t border-lgu-stroke flex-shrink-0">
      <div class="flex items-center mb-3">
        <div class="w-8 h-8 rounded-full bg-lgu-highlight flex items-center justify-center mr-2">
          <i class="fas fa-user-tie text-lgu-headline text-sm"></i>
        </div>
        <div>
          <p class="text-sm font-medium">Admin User</p>
          <p class="text-xs text-gray-400">Administrator</p>
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

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.getElementById('admin-sidebar');
      const toggle = document.getElementById('sidebar-toggle');
      const close = document.getElementById('sidebar-close');
      const overlay = document.getElementById('sidebar-overlay');
      const dropdowns = document.querySelectorAll('.sidebar-dropdown');
      const logout = document.getElementById('logout-btn');
      const sidebarLinks = document.querySelectorAll('.sidebar-link');
      const submenuLinks = document.querySelectorAll('.submenu-link');

      // Function to get current page from URL
      function getCurrentPage() {
        const path = window.location.pathname;
        const page = path.split('/').pop();
        return page.replace('.php', '') || 'dashboard';
      }

      // Function to set active menu item
      function setActiveMenuItem() {
        const currentPage = getCurrentPage();
        
        // Remove active class from all links
        sidebarLinks.forEach(link => {
          link.classList.remove('active');
        });
        submenuLinks.forEach(link => {
          link.classList.remove('active');
        });
        
        // Add active class to current page link
        const currentLink = document.querySelector(`[data-page="${currentPage}"]`);
        if (currentLink) {
          currentLink.classList.add('active');
          
          // If it's a submenu item, open the parent dropdown
          if (currentLink.classList.contains('submenu-link')) {
            const parentSection = currentLink.closest('.sidebar-submenu').dataset.section;
            const dropdownButton = document.querySelector(`[data-section="${parentSection}"]`);
            const dropdownSubmenu = document.querySelector(`.sidebar-submenu[data-section="${parentSection}"]`);
            const arrow = dropdownButton.querySelector('svg:last-child');
            
            dropdownSubmenu.classList.remove('hidden');
            arrow.classList.add('rotate-180');
            
            // Save to localStorage
            localStorage.setItem('activeSection', parentSection);
          }
        }
        
        // Save current page to localStorage
        localStorage.setItem('currentPage', currentPage);
      }

      // Function to restore dropdown state from localStorage
      function restoreDropdownState() {
        const activeSection = localStorage.getItem('activeSection');
        if (activeSection) {
          const dropdownButton = document.querySelector(`[data-section="${activeSection}"]`);
          const dropdownSubmenu = document.querySelector(`.sidebar-submenu[data-section="${activeSection}"]`);
          const arrow = dropdownButton.querySelector('svg:last-child');
          
          if (dropdownSubmenu) {
            dropdownSubmenu.classList.remove('hidden');
            arrow.classList.add('rotate-180');
          }
        }
      }

      // Initialize sidebar state
      setActiveMenuItem();
      restoreDropdownState();

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

      // Handle dropdowns
      dropdowns.forEach(btn => {
        btn.addEventListener('click', () => {
          const submenu = btn.nextElementSibling;
          const arrow = btn.querySelector('svg:last-child');
          submenu.classList.toggle('hidden');
          arrow.classList.toggle('rotate-180');
          
          // Save to localStorage
          if (!submenu.classList.contains('hidden')) {
            localStorage.setItem('activeSection', btn.dataset.section);
          } else {
            localStorage.removeItem('activeSection');
          }
        });
      });

      // Handle menu item clicks
      sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
          // Update active state
          sidebarLinks.forEach(l => l.classList.remove('active'));
          this.classList.add('active');
          
          // Save to localStorage
          localStorage.setItem('currentPage', this.dataset.page);
        });
      });

      submenuLinks.forEach(link => {
        link.addEventListener('click', function() {
          // Update active state
          submenuLinks.forEach(l => l.classList.remove('active'));
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
    .sidebar-link.active, .submenu-link.active {
      color: #faae2b;
      background-color: #00332c;
      border-left: 3px solid #faae2b;
    }
    .sidebar-submenu {
      transition: all 0.3s ease-in-out;
    }
    .rotate-180 {
      transform: rotate(180deg);
    }
    #admin-sidebar nav::-webkit-scrollbar {
      width: 6px;
    }
    #admin-sidebar nav::-webkit-scrollbar-track {
      background: transparent;
    }
    #admin-sidebar nav::-webkit-scrollbar-thumb {
      background: #faae2b;
      border-radius: 3px;
    }
    #admin-sidebar nav::-webkit-scrollbar-thumb:hover {
      background: #f5a217;
    }
  </style>
</body>
</html>