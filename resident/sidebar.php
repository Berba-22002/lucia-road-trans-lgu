<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - LGU Infrastructure</title>
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
    
    <!-- Toggle Button (Added for mobile) -->
    <button id="sidebar-toggle" class="fixed top-4 left-4 z-50 lg:hidden bg-lgu-headline text-white p-2 rounded-lg shadow-lg hover:bg-lgu-stroke transition-colors">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Resident Sidebar -->
    <div id="admin-sidebar" class="fixed left-0 top-0 h-full w-64 bg-lgu-headline text-white shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-40 flex flex-col">
        <!-- Sidebar Header -->
        <div class="flex items-center justify-between p-4 border-b border-lgu-stroke">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-lgu-highlight">
                    <img src="logo.jpg" alt="LGU Logo" class="w-full h-full object-cover">
                </div>
                <div>
                    <h2 class="text-white font-semibold text-sm">Resident Panel</h2>
                    <p class="text-gray-300 text-xs font-light">LGU Infrastructure</p>
                </div>
            </div>
            <!-- Close button for mobile -->
            <button id="sidebar-close" class="lg:hidden text-white hover:text-lgu-highlight transition">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 overflow-y-auto py-4">
            <!-- MAIN -->
            <div class="px-4 mb-6">
                <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">
                    <i class="fas fa-home mr-1"></i> Main
                </h4>
                <ul class="space-y-1">
                    <li>
                        <a href="resident-dashboard.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200" data-page="dashboard">
                            <i class="fas fa-tachometer-alt mr-3 text-lgu-highlight"></i>
                            Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="public_advisories.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="public_advisory">
                            <i class="fas fa-bullhorn mr-3 text-lgu-highlight"></i>
                            Public Advisory
                        </a>
                    </li>
                   
                </ul>
            </div>

            <!-- INFRASTRUCTURE SERVICES -->
            <div class="px-4 mb-6">
                <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">
                    <i class="fas fa-road mr-1"></i> Infrastructure Services
                </h4>
                <ul class="space-y-1">
                    <!-- Submit Hazard Report -->
                    <li>
                        <a href="report_hazard.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="report_hazard">
                            <i class="fas fa-exclamation-triangle mr-3 text-lgu-highlight"></i>
                            Submit Hazard Report
                        </a>
                    </li>
                    
                    <!-- Status and Confirmation -->
                    <li>
                        <a href="view_status.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="view_status">
                            <i class="fas fa-clipboard-check mr-3 text-lgu-highlight"></i>
                            Report Status
                        </a>
                    </li>
                    
                    <!-- Receive Alerts -->
                    <li>
                        <a href="receive_traffic_alerts.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="receive_traffic_alerts">
                            <i class="fas fa-bell mr-3 text-lgu-highlight"></i>
                            Traffic Alerts
                        </a>
                    </li>

                    <!-- View Related Hazards -->
                    <li>
                        <a href="view_related_hazards.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="view_related_hazards">
                            <i class="fas fa-link mr-3 text-lgu-highlight"></i>
                            Related Hazards
                        </a>
                    </li>

                    <!-- Submit Community Feedback -->
                    <li>
                        <a href="submit_feedback.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="submit_feedback">
                            <i class="fas fa-comment-dots mr-3 text-lgu-highlight"></i>
                            Community Feedback
                        </a>
                    </li>
                    
                    <!-- Emergency Contacts -->
                    <li>
                        <a href="emergency_contacts.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="emergency_contacts">
                            <i class="fas fa-phone-alt mr-3 text-lgu-highlight"></i>
                            Emergency Contacts
                        </a>
                    </li>
                </ul>
            </div>

            <!-- MY ACTIVITIES -->
            <div class="px-4 mb-6">
                <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">
                    <i class="fas fa-history mr-1"></i> My Activities
                </h4>
                <ul class="space-y-1">
                    <li>
                        <a href="reports_history.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="reports_history">
                            <i class="fas fa-history mr-3 text-lgu-highlight"></i>
                            Reports History
                        </a>
                    </li>
                    <li>
                        <a href="view_tickets.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="view_tickets">
                            <i class="fas fa-ticket-alt mr-3 text-lgu-highlight"></i>
                            My Violation Tickets
                        </a>
                    </li>
                   
                   
                </ul>
            </div>

            <!-- SETTINGS & PRIVACY -->
            <div class="px-4 mb-6">
                <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">
                    <i class="fas fa-cog mr-1"></i> Settings & Privacy
                </h4>
                <ul class="space-y-1">
                    <li>
                        <a href="privacy_settings.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="privacy_settings">
                            <i class="fas fa-shield-alt mr-3 text-lgu-highlight"></i>
                            Privacy Settings
                        </a>
                    </li>
                  
                    <li>
                        <a href="#" onclick="showPrivacyModal()" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200">
                            <i class="fas fa-file-contract mr-3 text-lgu-highlight"></i>
                            Privacy Policy
                        </a>
                    </li>
                    <li>
                        <a href="terms_of_service.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="terms_of_service">
                            <i class="fas fa-file-alt mr-3 text-lgu-highlight"></i>
                            Terms of Service
                        </a>
                    </li>
                </ul>
            </div>

            <!-- HELP & SUPPORT -->
            <div class="px-4 mb-6">
                <h4 class="text-gray-400 text-xs font-semibold uppercase tracking-widest mb-3">
                    <i class="fas fa-question-circle mr-1"></i> Help & Support
                </h4>
                <ul class="space-y-1">
                    <li>
                        <a href="faq.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="faq">
                            <i class="fas fa-question mr-3 text-lgu-highlight"></i>
                            FAQ
                        </a>
                    </li>
                    <li>
                        <a href="contact_support.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="contact_support">
                            <i class="fas fa-headset mr-3 text-lgu-highlight"></i>
                            Contact Support
                        </a>
                    </li>
                    <li>
                        <a href="report_guide.php" class="sidebar-link flex items-center px-3 py-2 text-sm font-medium rounded-lg hover:bg-lgu-stroke transition-colors duration-200" data-page="report_guide">
                            <i class="fas fa-book mr-3 text-lgu-highlight"></i>
                            Reporting Guide
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- USER ACTIONS -->
        <div class="p-4 border-t border-lgu-stroke flex-shrink-0">
            <div class="flex items-center mb-3">
                <div class="w-8 h-8 rounded-full bg-lgu-highlight flex items-center justify-center mr-2">
                    <i class="fas fa-user text-lgu-headline text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium">Resident User</p>
                    <p class="text-xs text-gray-400">Verified Resident</p>
                </div>
            </div>
            <button id="logout-btn" class="w-full flex items-center justify-center px-3 py-2 text-sm font-medium text-white bg-lgu-tertiary hover:bg-red-600 rounded-lg transition-colors duration-200">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </button>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 lg:hidden hidden"></div>

    <!-- Privacy Policy Modal -->
    <div id="privacyModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden">
            <div class="bg-lgu-headline text-white p-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold">
                    <i class="fas fa-shield-alt mr-2"></i>Privacy Policy
                </h3>
                <button onclick="hidePrivacyModal()" class="text-white hover:text-lgu-highlight">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[70vh]">
                <p class="text-sm text-gray-500 mb-6">Last updated: <?php echo date('F d, Y'); ?></p>

                <div class="space-y-6">
                    <section>
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-info-circle mr-2"></i>1. Information We Collect
                        </h4>
                        <p class="text-lgu-paragraph mb-2">We collect the following personal information:</p>
                        <ul class="list-disc pl-6 text-lgu-paragraph space-y-1">
                            <li><i class="fas fa-user mr-2 text-lgu-highlight"></i>Full name and contact information</li>
                            <li><i class="fas fa-map-marker-alt mr-2 text-lgu-highlight"></i>Address and location data for service delivery</li>
                            <li><i class="fas fa-camera mr-2 text-lgu-highlight"></i>Photos and descriptions of infrastructure issues</li>
                            <li><i class="fas fa-comments mr-2 text-lgu-highlight"></i>Communication records and feedback</li>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-tasks mr-2"></i>2. How We Use Your Information
                        </h4>
                        <ul class="list-disc pl-6 text-lgu-paragraph space-y-1">
                            <li><i class="fas fa-wrench mr-2 text-lgu-highlight"></i>Process and respond to infrastructure reports</li>
                            <li><i class="fas fa-tools mr-2 text-lgu-highlight"></i>Coordinate maintenance and repair activities</li>
                            <li><i class="fas fa-bell mr-2 text-lgu-highlight"></i>Send notifications about service updates</li>
                            <li><i class="fas fa-chart-line mr-2 text-lgu-highlight"></i>Improve our services and system functionality</li>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-database mr-2"></i>3. Data Retention
                        </h4>
                        <p class="text-lgu-paragraph mb-2">We retain your data as follows:</p>
                        <ul class="list-disc pl-6 text-lgu-paragraph space-y-1">
                            <li><i class="fas fa-file-alt mr-2 text-lgu-highlight"></i>Active reports: Until resolution + 2 years</li>
                            <li><i class="fas fa-user-times mr-2 text-lgu-highlight"></i>User accounts: Until account deletion requested</li>
                            <li><i class="fas fa-shield-alt mr-2 text-lgu-highlight"></i>System logs: 1 year for security purposes</li>
                            <li><i class="fas fa-archive mr-2 text-lgu-highlight"></i>Archived reports: 5 years for historical records</li>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-gavel mr-2"></i>4. Your Rights
                        </h4>
                        <p class="text-lgu-paragraph mb-2">Under the Data Privacy Act of 2012, you have the right to:</p>
                        <ul class="list-disc pl-6 text-lgu-paragraph space-y-1">
                            <li><i class="fas fa-eye mr-2 text-lgu-highlight"></i>Access your personal data</li>
                            <li><i class="fas fa-edit mr-2 text-lgu-highlight"></i>Correct inaccurate information</li>
                            <li><i class="fas fa-trash-alt mr-2 text-lgu-highlight"></i>Request deletion of your data</li>
                            <li><i class="fas fa-ban mr-2 text-lgu-highlight"></i>Withdraw consent (where applicable)</li>
                            <li><i class="fas fa-exclamation-triangle mr-2 text-lgu-highlight"></i>File complaints with the National Privacy Commission</li>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-lock mr-2"></i>5. Data Security
                        </h4>
                        <p class="text-lgu-paragraph">We implement appropriate security measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction.</p>
                    </section>

                    <section class="bg-lgu-bg p-4 rounded-lg">
                        <h4 class="text-lg font-semibold text-lgu-headline mb-3">
                            <i class="fas fa-address-card mr-2"></i>6. Contact Information
                        </h4>
                        <p class="text-lgu-paragraph mb-2">For privacy concerns, contact our Data Protection Officer at:</p>
                        <p class="text-lgu-paragraph font-semibold"><i class="fas fa-envelope mr-2 text-lgu-highlight"></i>Email: dpo@lgu-infrastructure.gov.ph</p>
                        <p class="text-lgu-paragraph font-semibold"><i class="fas fa-phone mr-2 text-lgu-highlight"></i>Phone: 0919-075-5101</p>
                        <p class="text-lgu-paragraph font-semibold"><i class="fas fa-building mr-2 text-lgu-highlight"></i>Address: LGU Infrastructure Office, City Hall Complex</p>
                    </section>
                </div>
            </div>
            <div class="p-4 border-t bg-gray-50 flex justify-between">
                <button onclick="printPrivacyPolicy()" class="bg-lgu-headline text-white px-4 py-2 rounded hover:bg-lgu-stroke font-semibold">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <button onclick="hidePrivacyModal()" class="bg-lgu-button text-lgu-button-text px-6 py-2 rounded hover:bg-yellow-500 font-semibold">
                    <i class="fas fa-times mr-2"></i>Close
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            const close = document.getElementById('sidebar-close');
            const overlay = document.getElementById('sidebar-overlay');
            const logout = document.getElementById('logout-btn');
            const dropdowns = document.querySelectorAll('.sidebar-dropdown');
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
                    
                    // Close sidebar on mobile
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                        overlay.classList.add('hidden');
                    }
                });
            });

            submenuLinks.forEach(link => {
                link.addEventListener('click', function() {
                    // Update active state
                    submenuLinks.forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Save to localStorage
                    localStorage.setItem('currentPage', this.dataset.page);
                    
                    // Close sidebar on mobile
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                        overlay.classList.add('hidden');
                    }
                });
            });

            logout.addEventListener('click', () => {
                if (confirm('Are you sure you want to logout?')) {
                    // Clear localStorage on logout
                    localStorage.clear();
                    window.location.href = '/logout.php';
                }
            });

            // Responsive sidebar behavior
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.remove('-translate-x-full');
                    overlay.classList.add('hidden');
                } else {
                    sidebar.classList.add('-translate-x-full');
                }
            });
        });

        // Privacy modal functions
        function showPrivacyModal() {
            document.getElementById('privacyModal').classList.remove('hidden');
        }

        function hidePrivacyModal() {
            document.getElementById('privacyModal').classList.add('hidden');
        }

        function printPrivacyPolicy() {
            const printContent = document.getElementById('privacyModal').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = printContent;
            window.print();
            document.body.innerHTML = originalContent;
            
            // Reinitialize the sidebar functionality
            document.addEventListener('DOMContentLoaded', function() {
                // Reattach event listeners if needed
            });
        }

        // Close modal when clicking outside
        document.getElementById('privacyModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hidePrivacyModal();
            }
        });
    </script>

    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }

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
