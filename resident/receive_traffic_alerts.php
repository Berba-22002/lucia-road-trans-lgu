<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

// Create traffic_alerts table if it doesn't exist
$create_table = "
CREATE TABLE IF NOT EXISTS traffic_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    location VARCHAR(255),
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    resident_id INT NULL,
    sent_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resident_id (resident_id),
    INDEX idx_created_at (created_at)
)
";
$pdo->exec($create_table);

// Fetch traffic alerts for this resident (both targeted and broadcast alerts)
$query = "
    SELECT 
        ta.*,
        u.fullname as sent_by_name
    FROM traffic_alerts ta
    LEFT JOIN users u ON ta.sent_by = u.id
    WHERE ta.resident_id = ? OR ta.resident_id IS NULL
    ORDER BY ta.created_at DESC
    LIMIT 50
";

$stmt = $pdo->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get alert counts by priority
$priority_counts = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($alerts as $alert) {
    $priority_counts[$alert['priority']]++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic Notifications - Resident Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
        .font-poppins { font-family: 'Poppins', sans-serif; }
        #map { height: 350px; }
        .alert-card { transition: all 0.3s ease; }
        .alert-card:hover { transform: translateY(-2px); }
        
        .custom-traffic-marker {
            background: transparent !important;
            border: none !important;
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 0.3;
            }
            50% {
                transform: scale(1.2);
                opacity: 0.1;
            }
            100% {
                transform: scale(1);
                opacity: 0.3;
            }
        }
        
        .traffic-marker-container:hover .traffic-marker-label {
            opacity: 1 !important;
        }
        
        .traffic-marker-container:hover .traffic-marker-main {
            transform: scale(1.1);
            transition: transform 0.2s ease;
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col h-screen">
        <header class="bg-white shadow-sm border-b border-lgu-stroke sticky top-0 z-30">
            <div class="flex items-center justify-between p-4 gap-4">
                <div class="flex items-center space-x-3 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline hover:text-lgu-highlight flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <div class="p-2 bg-lgu-highlight rounded-lg flex-shrink-0">
                        <i class="fas fa-bell text-lgu-button-text text-lg"></i>
                    </div>
                    <h1 class="text-lg lg:text-xl font-semibold text-lgu-headline truncate">Traffic Notifications</h1>
                </div>
                <div class="flex items-center space-x-2 lg:space-x-4 flex-shrink-0">
                    <button onclick="getCurrentLocation()" class="bg-green-600 text-white px-2 lg:px-4 py-2 rounded-lg font-medium hover:bg-green-700 transition-colors flex items-center text-sm lg:text-base">
                        <i class="fas fa-location-arrow mr-1 lg:mr-2"></i>
                        <span class="hidden sm:inline">My Location</span>
                    </button>
                    <button onclick="refreshAlerts()" class="bg-lgu-button text-lgu-button-text px-2 lg:px-4 py-2 rounded-lg font-medium hover:bg-lgu-highlight transition-colors flex items-center text-sm lg:text-base">
                        <i class="fas fa-sync-alt mr-1 lg:mr-2"></i>
                        <span class="hidden sm:inline">Refresh</span>
                    </button>
                    <div class="text-xs lg:text-sm text-lgu-paragraph hidden md:block">
                        <span class="hidden lg:inline">Last updated: </span><span id="lastUpdate"><?= date('g:i A') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6">
            <!-- Alert Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-xl shadow-sm p-6 border border-lgu-stroke">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium">Total Notifications</p>
                            <p class="text-2xl font-bold text-lgu-headline"><?= count($alerts) ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-lg">
                            <i class="fas fa-bell text-blue-600"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6 border border-lgu-stroke">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium">High Priority</p>
                            <p class="text-2xl font-bold text-red-600"><?= $priority_counts['high'] ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-lg">
                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6 border border-lgu-stroke">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium">Medium Priority</p>
                            <p class="text-2xl font-bold text-orange-600"><?= $priority_counts['medium'] ?></p>
                        </div>
                        <div class="p-3 bg-orange-100 rounded-lg">
                            <i class="fas fa-exclamation-circle text-orange-600"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6 border border-lgu-stroke">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium">Low Priority</p>
                            <p class="text-2xl font-bold text-green-600"><?= $priority_counts['low'] ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-lg">
                            <i class="fas fa-info-circle text-green-600"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traffic Map -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke mb-6">
                <div class="bg-lgu-headline px-6 py-4">
                    <h2 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-map mr-2"></i>
                        Live Traffic Map
                    </h2>
                </div>
                <div class="p-6">
                    <!-- Traffic Status Legend -->
                    <div class="mb-4 p-3 bg-gray-50 rounded-lg border">
                        <h5 class="text-sm font-semibold text-lgu-headline mb-2">Traffic Status Legend:</h5>
                        <div class="flex flex-wrap gap-4 text-xs">
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
                                <span>Free Flow (80%+ normal speed)</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full bg-yellow-500 mr-2"></div>
                                <span>Moderate (50-80% normal speed)</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full bg-orange-500 mr-2"></div>
                                <span>Heavy (30-50% normal speed)</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full bg-red-500 mr-2"></div>
                                <span>Blocked (&lt;30% normal speed)</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div id="locationStatus" class="text-sm text-lgu-paragraph mb-2">Click "My Location" to see nearby traffic conditions</div>
                        <div id="nearbyTraffic" class="hidden bg-lgu-bg p-4 rounded-lg border border-lgu-stroke">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="font-semibold text-lgu-headline">Nearby Traffic Conditions</h4>
                                <div id="trafficPagination" class="hidden flex items-center space-x-2">
                                    <button id="prevPage" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <span id="pageInfo" class="text-xs text-lgu-paragraph"></span>
                                    <button id="nextPage" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="nearbyTrafficList" class="space-y-2"></div>
                        </div>
                    </div>
                    <div id="map" class="rounded-lg border border-lgu-stroke"></div>
                </div>
            </div>

            <!-- Traffic Alerts -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke">
                <div class="bg-lgu-headline px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-white text-lg font-semibold flex items-center">
                            <i class="fas fa-traffic-light mr-2"></i>
                            Traffic Notifications
                        </h2>
                        <div class="flex items-center space-x-3">
                            <span class="bg-lgu-highlight text-lgu-button-text px-3 py-1 rounded-full text-sm font-medium">
                                <?= count($alerts) ?> notifications
                            </span>
                            <div id="alertsPagination" class="<?= count($alerts) <= 5 ? 'hidden' : '' ?> flex items-center space-x-2">
                                <button id="alertsPrevPage" class="px-2 py-1 text-xs bg-white text-lgu-headline border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span id="alertsPageInfo" class="text-xs text-white"></span>
                                <button id="alertsNextPage" class="px-2 py-1 text-xs bg-white text-lgu-headline border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <?php if (empty($alerts)): ?>
                        <div class="text-center py-12">
                            <div class="bg-lgu-bg rounded-xl p-8 max-w-md mx-auto border border-lgu-stroke">
                                <i class="fas fa-bell-slash text-lgu-paragraph text-5xl mb-4"></i>
                                <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Traffic Notifications</h3>
                                <p class="text-lgu-paragraph">You haven't received any traffic notifications yet. Check back later for updates.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="alertsList" class="space-y-4">
                            <!-- Notifications will be populated by JavaScript -->
                        </div>
                        <div class="hidden">
                            <?php foreach ($alerts as $alert): ?>
                                <div class="alert-card border border-lgu-stroke rounded-lg p-6 hover:shadow-lg">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-start space-x-4 flex-1">
                                            <div class="p-3 rounded-lg flex-shrink-0
                                                <?= $alert['priority'] === 'high' ? 'bg-red-100' : 
                                                    ($alert['priority'] === 'medium' ? 'bg-orange-100' : 'bg-blue-100') ?>">
                                                <i class="fas <?= $alert['priority'] === 'high' ? 'fa-exclamation-triangle' : 
                                                    ($alert['priority'] === 'medium' ? 'fa-exclamation-circle' : 'fa-info-circle') ?>
                                                    <?= $alert['priority'] === 'high' ? 'text-red-600' : 
                                                        ($alert['priority'] === 'medium' ? 'text-orange-600' : 'text-blue-600') ?>"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between mb-2">
                                                    <h3 class="font-semibold text-lg text-lgu-headline">
                                                        <?= htmlspecialchars($alert['title']) ?>
                                                    </h3>
                                                    <span class="px-3 py-1 rounded-full text-xs font-medium ml-4 flex-shrink-0
                                                        <?= $alert['priority'] === 'high' ? 'bg-red-100 text-red-800' : 
                                                            ($alert['priority'] === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800') ?>">
                                                        <?= ucfirst($alert['priority']) ?> Priority
                                                    </span>
                                                </div>
                                                <p class="text-lgu-paragraph mb-3 leading-relaxed">
                                                    <?= htmlspecialchars($alert['message']) ?>
                                                </p>
                                                
                                                <div class="flex flex-wrap items-center gap-4 text-sm text-lgu-paragraph">
                                                    <?php if ($alert['location']): ?>
                                                        <div class="flex items-center">
                                                            <i class="fas fa-map-marker-alt mr-2 text-red-500"></i>
                                                            <span class="font-medium"><?= htmlspecialchars($alert['location']) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="flex items-center">
                                                        <i class="fas fa-clock mr-2 text-blue-500"></i>
                                                        <span><?= date('M j, Y g:i A', strtotime($alert['created_at'])) ?></span>
                                                    </div>
                                                    
                                                    <?php if ($alert['sent_by_name']): ?>
                                                        <div class="flex items-center">
                                                            <i class="fas fa-user mr-2 text-green-500"></i>
                                                            <span>From: <?= htmlspecialchars($alert['sent_by_name']) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        </div>
                        
                        <?php if (count($alerts) >= 50): ?>
                            <div class="text-center mt-6 p-4 bg-lgu-bg rounded-lg border border-lgu-stroke">
                                <p class="text-lgu-paragraph">Showing latest 50 notifications. Older notifications are automatically archived.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        const trafficRoutes = [
            // Major Expressways
            { name: 'EDSA', coords: [14.5547, 121.0244] }, { name: 'C5 Road', coords: [14.5764, 121.0851] }, { name: 'NLEX', coords: [14.6760, 120.9781] }, { name: 'SLEX', coords: [14.4208, 121.0244] }, { name: 'Skyway', coords: [14.5278, 121.0244] },
            // Caloocan City
            { name: 'A. Mabini St, Caloocan', coords: [14.6587, 120.9637] }, { name: 'Rizal Ave, Caloocan', coords: [14.6487, 120.9737] }, { name: 'Samson Rd, Caloocan', coords: [14.6687, 120.9837] }, { name: 'Gen Luis St, Caloocan', coords: [14.6387, 120.9537] }, { name: 'MacArthur Hwy, Caloocan', coords: [14.6787, 120.9937] }, { name: 'Monumento Circle', coords: [14.6587, 120.9837] }, { name: '10th Ave, Caloocan', coords: [14.6487, 120.9637] }, { name: 'Bagong Barrio Rd', coords: [14.6987, 120.9737] },
            // Las Piñas City
            { name: 'Alabang-Zapote Rd', coords: [14.4208, 121.0144] }, { name: 'Real St, Las Piñas', coords: [14.4308, 121.0244] }, { name: 'Naga Rd, Las Piñas', coords: [14.4108, 121.0344] }, { name: 'CAA Rd, Las Piñas', coords: [14.4408, 121.0144] }, { name: 'Daang Hari, Las Piñas', coords: [14.4508, 121.0044] },
            // Makati City
            { name: 'Ayala Ave', coords: [14.5547, 121.0244] }, { name: 'Makati Ave', coords: [14.5647, 121.0344] }, { name: 'Gil Puyat Ave', coords: [14.5447, 121.0144] }, { name: 'Paseo de Roxas', coords: [14.5747, 121.0244] }, { name: 'Salcedo St, Makati', coords: [14.5547, 121.0344] }, { name: 'Legaspi St, Makati', coords: [14.5647, 121.0244] }, { name: 'Dela Rosa St', coords: [14.5447, 121.0344] }, { name: 'Chino Roces Ave', coords: [14.5347, 121.0244] },
            // Malabon City
            { name: 'Gov Pascual Ave', coords: [14.6687, 120.9537] }, { name: 'Letre Rd, Malabon', coords: [14.6587, 120.9437] }, { name: 'McArthur Hwy, Malabon', coords: [14.6787, 120.9637] }, { name: 'Dagat-Dagatan Ave', coords: [14.6487, 120.9337] },
            // Mandaluyong City
            { name: 'Shaw Blvd', coords: [14.5833, 121.0564] }, { name: 'Boni Ave', coords: [14.5733, 121.0464] }, { name: 'Pioneer St, Mandaluyong', coords: [14.5933, 121.0664] }, { name: 'EDSA Central', coords: [14.5633, 121.0364] },
            // Manila City
            { name: 'Taft Ave', coords: [14.5547, 120.9934] }, { name: 'Roxas Blvd', coords: [14.5547, 120.9781] }, { name: 'España Blvd', coords: [14.6042, 120.9947] }, { name: 'Quezon Blvd, Manila', coords: [14.6142, 120.9847] }, { name: 'Recto Ave', coords: [14.6042, 120.9847] }, { name: 'Rizal Ave, Manila', coords: [14.5942, 120.9747] }, { name: 'UN Ave', coords: [14.5742, 120.9847] }, { name: 'Pedro Gil St', coords: [14.5642, 120.9947] }, { name: 'Quirino Ave', coords: [14.5442, 120.9847] },
            // Marikina City
            { name: 'Marcos Hwy', coords: [14.6387, 121.0931] }, { name: 'Sumulong Hwy', coords: [14.6487, 121.1031] }, { name: 'Gil Fernando Ave', coords: [14.6287, 121.0831] }, { name: 'Shoe Ave, Marikina', coords: [14.6187, 121.0731] },
            // Muntinlupa City
            { name: 'National Rd, Muntinlupa', coords: [14.3708, 121.0444] }, { name: 'Alabang-Zapote Rd, Muntinlupa', coords: [14.4008, 121.0344] }, { name: 'Commerce Ave', coords: [14.4108, 121.0244] }, { name: 'Corporate Ave', coords: [14.4208, 121.0144] },
            // Navotas City
            { name: 'Radial Rd 10', coords: [14.6687, 120.9237] }, { name: 'C3 Rd, Navotas', coords: [14.6587, 120.9137] }, { name: 'Navotas Fish Port Rd', coords: [14.6787, 120.9337] },
            // Parañaque City
            { name: 'Sucat Rd', coords: [14.4608, 121.0144] }, { name: 'Dr A Santos Ave', coords: [14.4708, 121.0244] }, { name: 'Ninoy Aquino Ave', coords: [14.4808, 121.0344] }, { name: 'Doña Soledad Ave', coords: [14.4508, 121.0044] },
            // Pasay City
            { name: 'NAIA Rd', coords: [14.5083, 121.0197] }, { name: 'Macapagal Ave', coords: [14.5183, 120.9797] }, { name: 'Roxas Blvd, Pasay', coords: [14.5283, 120.9897] }, { name: 'Taft Ave, Pasay', coords: [14.5383, 120.9997] },
            // Pasig City
            { name: 'Ortigas Ave', coords: [14.5833, 121.0564] }, { name: 'C5 Rd, Pasig', coords: [14.5933, 121.0664] }, { name: 'Caruncho Ave', coords: [14.6033, 121.0764] }, { name: 'Pasig Blvd', coords: [14.5733, 121.0464] },
            // Quezon City
            { name: 'Commonwealth Ave', coords: [14.6760, 121.0437] }, { name: 'Quezon Ave', coords: [14.6507, 121.0300] }, { name: 'Katipunan Ave', coords: [14.6387, 121.0731] }, { name: 'Timog Ave', coords: [14.6234, 121.0331] }, { name: 'West Ave', coords: [14.6507, 121.0200] }, { name: 'East Ave', coords: [14.6507, 121.0400] }, { name: 'North Ave', coords: [14.6607, 121.0300] }, { name: 'Mindanao Ave', coords: [14.6934, 121.0437] }, { name: 'Novaliches Rd', coords: [14.7234, 121.0348] }, { name: 'Fairview Ave', coords: [14.7634, 121.0548] },
            // San Juan City
            { name: 'N Domingo St', coords: [14.6034, 121.0331] }, { name: 'Nicanor Roxas St', coords: [14.6134, 121.0431] }, { name: 'Santolan Rd', coords: [14.6234, 121.0531] },
            // Taguig City
            { name: 'C6 Rd', coords: [14.5164, 121.0851] }, { name: 'McKinley Rd', coords: [14.5264, 121.0751] }, { name: 'Lawton Ave, Taguig', coords: [14.5364, 121.0651] }, { name: 'FTI Ave', coords: [14.5064, 121.0551] },
            // Valenzuela City
            { name: 'MacArthur Hwy, Valenzuela', coords: [14.6987, 120.9837] }, { name: 'Karuhatan Rd', coords: [14.6787, 120.9937] }, { name: 'Malinta Exit', coords: [14.6887, 120.9937] }, { name: 'Paso de Blas Rd', coords: [14.7287, 120.9837] },
            // Pateros Municipality
            { name: 'Pateros Main St', coords: [14.5434, 121.0731] }, { name: 'Martires del 96 St', coords: [14.5534, 121.0831] }, { name: 'Aguho St, Pateros', coords: [14.5334, 121.0631] }
        ];

        let map;
        let trafficLayers = [];
        let userLocationMarker = null;
        let userLocation = null;
        let nearbyRoutesData = [];
        let currentPage = 1;
        const itemsPerPage = 6;
        let alertsData = <?= json_encode($alerts) ?>;
        let currentAlertsPage = 1;
        const alertsPerPage = 5;

        function initMap() {
            map = L.map('map').setView([14.5995, 120.9842], 12);

            // Use TomTom base map
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);

            // Add TomTom traffic flow layer
            const trafficLayer = L.tileLayer(`https://api.tomtom.com/traffic/map/4/tile/flow/relative/{z}/{x}/{y}.png?key=${TOMTOM_API_KEY}`, {
                opacity: 0.7
            }).addTo(map);

            // Load real-time traffic data for routes
            loadTrafficData();
        }

        async function loadTrafficData() {
            for (const route of trafficRoutes) {
                try {
                    const trafficData = await fetchTrafficFlow(route.coords[0], route.coords[1]);
                    const trafficStatus = getTrafficStatus(trafficData);
                    
                    // Add enhanced marker with traffic info
                    const marker = L.marker(route.coords, {
                        icon: createTrafficIcon(trafficStatus, route.name)
                    }).addTo(map);
                    
                    marker.bindPopup(`
                        <div class="p-3">
                            <h4 class="font-semibold text-lg">${route.name}</h4>
                            <div class="mt-2 space-y-1">
                                <p class="text-sm">Traffic Status: <span class="font-medium" style="color: ${getTrafficColor(trafficStatus)}">${trafficStatus.toUpperCase()}</span></p>
                                <p class="text-sm">Current Speed: ${trafficData.currentSpeed || 'N/A'} km/h</p>
                                <p class="text-sm">Normal Speed: ${trafficData.freeFlowSpeed || 'N/A'} km/h</p>
                                <p class="text-sm">Confidence: ${trafficData.confidence || 'N/A'}</p>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                Last updated: ${new Date().toLocaleTimeString()}
                            </div>
                        </div>
                    `);
                    
                } catch (error) {
                    console.error(`Error loading traffic data for ${route.name}:`, error);
                    // Fallback to default marker
                    L.marker(route.coords, {
                        icon: createTrafficIcon('unknown', route.name)
                    }).addTo(map).bindPopup(`
                        <div class="p-2">
                            <h4 class="font-semibold">${route.name}</h4>
                            <p class="text-sm">Status: <span class="font-medium">DATA UNAVAILABLE</span></p>
                        </div>
                    `);
                }
            }
        }

        async function fetchTrafficFlow(lat, lon) {
            if (!TOMTOM_API_KEY || TOMTOM_API_KEY === 'YOUR_TOMTOM_API_KEY_HERE') {
                return generateMockTrafficData();
            }
            
            try {
                const response = await fetch(`https://api.tomtom.com/traffic/services/4/flowSegmentData/absolute/10/json?point=${lat},${lon}&unit=KMPH&key=${TOMTOM_API_KEY}`);
                
                if (!response.ok) {
                    console.warn(`TomTom API error: ${response.status}`);
                    return generateMockTrafficData();
                }
                
                const data = await response.json();
                return data.flowSegmentData || generateMockTrafficData();
            } catch (error) {
                console.warn('TomTom API unavailable, using mock data:', error);
                return generateMockTrafficData();
            }
        }
        
        function generateMockTrafficData() {
            const statuses = ['free', 'moderate', 'heavy', 'blocked'];
            const weights = [0.4, 0.3, 0.2, 0.1];
            
            let randomStatus = 'free';
            const rand = Math.random();
            let cumulative = 0;
            
            for (let i = 0; i < statuses.length; i++) {
                cumulative += weights[i];
                if (rand <= cumulative) {
                    randomStatus = statuses[i];
                    break;
                }
            }
            
            const baseSpeed = 60;
            let currentSpeed, freeFlowSpeed = baseSpeed;
            
            switch (randomStatus) {
                case 'free':
                    currentSpeed = baseSpeed * (0.8 + Math.random() * 0.2);
                    break;
                case 'moderate':
                    currentSpeed = baseSpeed * (0.5 + Math.random() * 0.3);
                    break;
                case 'heavy':
                    currentSpeed = baseSpeed * (0.3 + Math.random() * 0.2);
                    break;
                case 'blocked':
                    currentSpeed = baseSpeed * (0.0 + Math.random() * 0.3);
                    break;
            }
            
            return {
                currentSpeed: Math.round(currentSpeed),
                freeFlowSpeed: freeFlowSpeed,
                confidence: 0.8
            };
        }

        function getTrafficStatus(trafficData) {
            if (!trafficData.currentSpeed || !trafficData.freeFlowSpeed) {
                return 'unknown';
            }
            
            const ratio = trafficData.currentSpeed / trafficData.freeFlowSpeed;
            
            if (ratio >= 0.8) return 'free';
            if (ratio >= 0.5) return 'moderate';
            if (ratio >= 0.3) return 'heavy';
            return 'blocked';
        }

        function getTrafficColor(status) {
            switch (status) {
                case 'free': return '#22c55e';      // Green
                case 'moderate': return '#f59e0b';  // Yellow
                case 'heavy': return '#f97316';     // Orange
                case 'blocked': return '#ef4444';   // Red
                default: return '#6b7280';          // Gray
            }
        }

        function getTrafficIcon(status) {
            switch (status) {
                case 'free': return 'fa-road';
                case 'moderate': return 'fa-exclamation-triangle';
                case 'heavy': return 'fa-exclamation-circle';
                case 'blocked': return 'fa-times-circle';
                default: return 'fa-question-circle';
            }
        }

        function createTrafficIcon(status, routeName) {
            const color = getTrafficColor(status);
            const icon = getTrafficIcon(status);
            const isBlocked = status === 'blocked';
            const isHeavy = status === 'heavy';
            
            return L.divIcon({
                className: 'custom-traffic-marker',
                html: `
                    <div class="traffic-marker-container" style="position: relative;">
                        <div class="traffic-marker-pulse ${isBlocked || isHeavy ? 'pulse-animation' : ''}" 
                             style="
                                 position: absolute;
                                 top: -5px;
                                 left: -5px;
                                 width: 40px;
                                 height: 40px;
                                 background: ${color};
                                 border-radius: 50%;
                                 opacity: 0.3;
                             "></div>
                        <div class="traffic-marker-main" 
                             style="
                                 position: relative;
                                 width: 30px;
                                 height: 30px;
                                 background: ${color};
                                 border: 3px solid white;
                                 border-radius: 50%;
                                 display: flex;
                                 align-items: center;
                                 justify-content: center;
                                 box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                                 z-index: 1000;
                             ">
                            <i class="fas ${icon}" style="color: white; font-size: 12px;"></i>
                        </div>
                        <div class="traffic-marker-label" 
                             style="
                                 position: absolute;
                                 top: 35px;
                                 left: 50%;
                                 transform: translateX(-50%);
                                 background: rgba(0,0,0,0.8);
                                 color: white;
                                 padding: 2px 6px;
                                 border-radius: 4px;
                                 font-size: 10px;
                                 font-weight: bold;
                                 white-space: nowrap;
                                 pointer-events: none;
                                 opacity: 0;
                                 transition: opacity 0.3s ease;
                             ">
                            ${routeName.split(' ')[0]}
                        </div>
                    </div>
                `,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15]
            });
        }

        function refreshTrafficData() {
            // Clear existing traffic layers
            trafficLayers.forEach(layer => map.removeLayer(layer));
            trafficLayers = [];
            
            // Reload traffic data
            loadTrafficData();
        }

        function getCurrentLocation() {
            const locationBtn = document.querySelector('button[onclick="getCurrentLocation()"]');
            const originalText = locationBtn.innerHTML;
            
            // Check if geolocation is supported and page is secure
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by this browser');
                return;
            }
            
            if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                alert('Location access requires HTTPS. Please use https:// or localhost');
                return;
            }
            
            locationBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Getting Location...';
            locationBtn.disabled = true;
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    async function(position) {
                        userLocation = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        };
                        
                        // Get street address
                        const address = await getStreetAddress(userLocation.lat, userLocation.lng);
                        
                        // Update location status
                        document.getElementById('locationStatus').innerHTML = 
                            `<strong>Your Location:</strong> ${address}<br><small>Coordinates: ${userLocation.lat.toFixed(4)}, ${userLocation.lng.toFixed(4)}</small>`;
                        
                        // Add user location marker
                        if (userLocationMarker) {
                            map.removeLayer(userLocationMarker);
                        }
                        
                        userLocationMarker = L.marker([userLocation.lat, userLocation.lng], {
                            icon: L.divIcon({
                                className: 'user-location-marker',
                                html: '<div style="background: #3b82f6; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);"></div>',
                                iconSize: [20, 20],
                                iconAnchor: [10, 10]
                            })
                        }).addTo(map);
                        
                        userLocationMarker.bindPopup(`
                            <div class="p-3">
                                <h4 class="font-semibold text-blue-600">Your Location</h4>
                                <p class="text-sm font-medium">${address}</p>
                                <p class="text-xs text-gray-500">Lat: ${userLocation.lat.toFixed(6)}, Lng: ${userLocation.lng.toFixed(6)}</p>
                            </div>
                        `);
                        
                        // Center map on user location
                        map.setView([userLocation.lat, userLocation.lng], 15);
                        
                        // Get nearby traffic conditions
                        getNearbyTrafficConditions();
                        
                        locationBtn.innerHTML = originalText;
                        locationBtn.disabled = false;
                    },
                    function(error) {
                        let errorMessage = 'Unable to get your location';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage = 'Location access denied by user';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage = 'Location information unavailable';
                                break;
                            case error.TIMEOUT:
                                errorMessage = 'Location request timed out';
                                break;
                        }
                        
                        document.getElementById('locationStatus').textContent = errorMessage;
                        document.getElementById('locationStatus').className = 'text-sm text-red-600 mb-2';
                        
                        locationBtn.innerHTML = originalText;
                        locationBtn.disabled = false;
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 60000
                    }
                );
            } else {
                document.getElementById('locationStatus').textContent = 'Geolocation is not supported by this browser';
                document.getElementById('locationStatus').className = 'text-sm text-red-600 mb-2';
                
                locationBtn.innerHTML = originalText;
                locationBtn.disabled = false;
            }
        }

        async function getNearbyTrafficConditions() {
            if (!userLocation) return;
            
            const nearbyTrafficDiv = document.getElementById('nearbyTraffic');
            const nearbyTrafficList = document.getElementById('nearbyTrafficList');
            
            nearbyTrafficDiv.classList.remove('hidden');
            nearbyTrafficList.innerHTML = '<div class="text-sm text-lgu-paragraph">Loading nearby traffic conditions...</div>';
            
            try {
                // Get real traffic data within 5km radius using TomTom API
                const nearbyTrafficData = await fetchNearbyTrafficIncidents(userLocation.lat, userLocation.lng, 5);
                
                // Also check predefined routes within 5km
                const allNearbyRoutes = trafficRoutes.map(route => {
                    const distance = calculateDistance(
                        userLocation.lat, userLocation.lng,
                        route.coords[0], route.coords[1]
                    );
                    return { ...route, distance };
                }).filter(route => route.distance <= 5).sort((a, b) => a.distance - b.distance);
                
                // Load traffic data for nearby routes
                nearbyRoutesData = [];
                for (const route of allNearbyRoutes) {
                    try {
                        const trafficData = await fetchTrafficFlow(route.coords[0], route.coords[1]);
                        const trafficStatus = getTrafficStatus(trafficData);
                        const color = getTrafficColor(trafficStatus);
                        
                        nearbyRoutesData.push({
                            ...route,
                            trafficData,
                            trafficStatus,
                            color
                        });
                    } catch (error) {
                        nearbyRoutesData.push({
                            ...route,
                            trafficData: null,
                            trafficStatus: 'unknown',
                            color: '#6b7280'
                        });
                    }
                }
                
                // Add dynamic traffic incidents to the list
                if (nearbyTrafficData && nearbyTrafficData.length > 0) {
                    nearbyTrafficData.forEach(incident => {
                        const distance = calculateDistance(
                            userLocation.lat, userLocation.lng,
                            incident.geometry.coordinates[1], incident.geometry.coordinates[0]
                        );
                        
                        nearbyRoutesData.push({
                            name: incident.properties.description || 'Traffic Incident',
                            distance: distance,
                            trafficData: { currentSpeed: 0, freeFlowSpeed: 60 },
                            trafficStatus: 'blocked',
                            color: '#ef4444'
                        });
                    });
                }
                
                // Sort by distance and reset to first page
                nearbyRoutesData.sort((a, b) => a.distance - b.distance);
                currentPage = 1;
                displayNearbyTraffic();
                
            } catch (error) {
                console.error('Error getting nearby traffic conditions:', error);
                nearbyTrafficList.innerHTML = '<div class="text-sm text-red-600">Error loading nearby traffic conditions</div>';
            }
        }
        
        async function fetchNearbyTrafficIncidents(lat, lng, radiusKm) {
            try {
                const bbox = 0.018 * radiusKm; // Approximate 1km = 0.018 degrees
                const response = await fetch(`https://api.tomtom.com/traffic/services/5/incidentDetails?bbox=${lng-bbox},${lat-bbox},${lng+bbox},${lat+bbox}&fields=incidents&language=en-US&key=${TOMTOM_API_KEY}`);
                
                if (!response.ok) {
                    console.warn('TomTom traffic incidents API error:', response.status);
                    return [];
                }
                
                const data = await response.json();
                return data.incidents || [];
            } catch (error) {
                console.warn('Traffic incidents API unavailable:', error);
                return [];
            }
        }

        function displayNearbyTraffic() {
            const nearbyTrafficList = document.getElementById('nearbyTrafficList');
            const trafficPagination = document.getElementById('trafficPagination');
            const pageInfo = document.getElementById('pageInfo');
            const prevBtn = document.getElementById('prevPage');
            const nextBtn = document.getElementById('nextPage');
            
            if (nearbyRoutesData.length === 0) {
                nearbyTrafficList.innerHTML = '<div class="text-sm text-lgu-paragraph">No nearby traffic data available</div>';
                trafficPagination.classList.add('hidden');
                return;
            }
            
            const totalPages = Math.ceil(nearbyRoutesData.length / itemsPerPage);
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const currentRoutes = nearbyRoutesData.slice(startIndex, endIndex);
            
            let nearbyTrafficHTML = '';
            
            currentRoutes.forEach(route => {
                if (route.trafficData) {
                    nearbyTrafficHTML += `
                        <div class="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-4 h-4 rounded-full" style="background-color: ${route.color}"></div>
                                <div>
                                    <p class="font-medium text-sm">${route.name}</p>
                                    <p class="text-xs text-gray-500">${route.distance.toFixed(1)} km away</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium" style="color: ${route.color}">${route.trafficStatus.toUpperCase()}</p>
                                <p class="text-xs text-gray-500">${route.trafficData.currentSpeed || 'N/A'} km/h</p>
                            </div>
                        </div>
                    `;
                } else {
                    nearbyTrafficHTML += `
                        <div class="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-4 h-4 rounded-full bg-gray-400"></div>
                                <div>
                                    <p class="font-medium text-sm">${route.name}</p>
                                    <p class="text-xs text-gray-500">${route.distance.toFixed(1)} km away</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-500">NO DATA</p>
                            </div>
                        </div>
                    `;
                }
            });
            
            nearbyTrafficList.innerHTML = nearbyTrafficHTML;
            
            // Show/hide pagination based on total routes
            if (totalPages > 1) {
                trafficPagination.classList.remove('hidden');
                pageInfo.textContent = `${currentPage} of ${totalPages}`;
                prevBtn.disabled = currentPage === 1;
                nextBtn.disabled = currentPage === totalPages;
            } else {
                trafficPagination.classList.add('hidden');
            }
        }

        function changePage(direction) {
            const totalPages = Math.ceil(nearbyRoutesData.length / itemsPerPage);
            
            if (direction === 'prev' && currentPage > 1) {
                currentPage--;
            } else if (direction === 'next' && currentPage < totalPages) {
                currentPage++;
            }
            
            displayNearbyTraffic();
        }

        function displayAlerts() {
            const alertsList = document.getElementById('alertsList');
            const alertsPagination = document.getElementById('alertsPagination');
            const alertsPageInfo = document.getElementById('alertsPageInfo');
            const alertsPrevBtn = document.getElementById('alertsPrevPage');
            const alertsNextBtn = document.getElementById('alertsNextPage');
            
            if (alertsData.length === 0) {
                alertsList.innerHTML = `
                    <div class="text-center py-12">
                        <div class="bg-lgu-bg rounded-xl p-8 max-w-md mx-auto border border-lgu-stroke">
                            <i class="fas fa-bell-slash text-lgu-paragraph text-5xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Traffic Notifications</h3>
                            <p class="text-lgu-paragraph">You haven't received any traffic notifications yet. Check back later for updates.</p>
                        </div>
                    </div>
                `;
                return;
            }
            
            const totalPages = Math.ceil(alertsData.length / alertsPerPage);
            const startIndex = (currentAlertsPage - 1) * alertsPerPage;
            const endIndex = startIndex + alertsPerPage;
            const currentAlerts = alertsData.slice(startIndex, endIndex);
            
            let alertsHTML = '';
            
            currentAlerts.forEach(alert => {
                const priorityClass = alert.priority === 'high' ? 'bg-red-100 text-red-800' : 
                                    (alert.priority === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800');
                const iconClass = alert.priority === 'high' ? 'fa-exclamation-triangle text-red-600' : 
                                (alert.priority === 'medium' ? 'fa-exclamation-circle text-orange-600' : 'fa-info-circle text-blue-600');
                const bgClass = alert.priority === 'high' ? 'bg-red-100' : 
                              (alert.priority === 'medium' ? 'bg-orange-100' : 'bg-blue-100');
                
                alertsHTML += `
                    <div class="alert-card border border-lgu-stroke rounded-lg p-6 hover:shadow-lg">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4 flex-1">
                                <div class="p-3 rounded-lg flex-shrink-0 ${bgClass}">
                                    <i class="fas ${iconClass}"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between mb-2">
                                        <h3 class="font-semibold text-lg text-lgu-headline">
                                            ${alert.title}
                                        </h3>
                                        <span class="px-3 py-1 rounded-full text-xs font-medium ml-4 flex-shrink-0 ${priorityClass}">
                                            ${alert.priority.charAt(0).toUpperCase() + alert.priority.slice(1)} Priority
                                        </span>
                                    </div>
                                    <p class="text-lgu-paragraph mb-3 leading-relaxed">
                                        ${alert.message}
                                    </p>
                                    
                                    <div class="flex flex-wrap items-center gap-4 text-sm text-lgu-paragraph">
                                        ${alert.location ? `
                                            <div class="flex items-center">
                                                <i class="fas fa-map-marker-alt mr-2 text-red-500"></i>
                                                <span class="font-medium">${alert.location}</span>
                                            </div>
                                        ` : ''}
                                        
                                        <div class="flex items-center">
                                            <i class="fas fa-clock mr-2 text-blue-500"></i>
                                            <span>${new Date(alert.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                                        </div>
                                        
                                        ${alert.sent_by_name ? `
                                            <div class="flex items-center">
                                                <i class="fas fa-user mr-2 text-green-500"></i>
                                                <span>From: ${alert.sent_by_name}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            alertsList.innerHTML = alertsHTML;
            
            // Show/hide pagination based on total notifications
            if (totalPages > 1) {
                alertsPagination.classList.remove('hidden');
                alertsPageInfo.textContent = `${currentAlertsPage} of ${totalPages}`;
                alertsPrevBtn.disabled = currentAlertsPage === 1;
                alertsNextBtn.disabled = currentAlertsPage === totalPages;
            } else {
                alertsPagination.classList.add('hidden');
            }
        }

        function changeAlertsPage(direction) {
            const totalPages = Math.ceil(alertsData.length / alertsPerPage);
            
            if (direction === 'prev' && currentAlertsPage > 1) {
                currentAlertsPage--;
            } else if (direction === 'next' && currentAlertsPage < totalPages) {
                currentAlertsPage++;
            }
            
            displayAlerts();
        }

        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Radius of the Earth in kilometers
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                    Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c; // Distance in kilometers
        }

        function refreshAlerts() {
            // Refresh traffic data without full page reload
            refreshTrafficData();
            
            // Update notifications
            location.reload();
        }

        function updateLastUpdate() {
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        async function getStreetAddress(lat, lng) {
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/reverseGeocode/${lat},${lng}.json?key=${TOMTOM_API_KEY}`);
                const data = await response.json();
                
                if (data.addresses && data.addresses.length > 0) {
                    const addr = data.addresses[0].address;
                    return `${addr.streetNumber || ''} ${addr.streetName || ''}, ${addr.municipality || ''}, ${addr.country || ''}`.replace(/^\s+|\s+$/g, '').replace(/\s+/g, ' ');
                }
            } catch (error) {
                console.warn('Reverse geocoding failed:', error);
            }
            return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            // Setup pagination event listeners
            document.getElementById('prevPage').addEventListener('click', () => changePage('prev'));
            document.getElementById('nextPage').addEventListener('click', () => changePage('next'));
            
            // Setup notifications pagination
            document.getElementById('alertsPrevPage').addEventListener('click', () => changeAlertsPage('prev'));
            document.getElementById('alertsNextPage').addEventListener('click', () => changeAlertsPage('next'));
            
            // Initialize notifications display
            displayAlerts();
            
            // Auto-refresh traffic data every 30 seconds
            setInterval(function() {
                refreshTrafficData();
            }, 30000);
            
            // Auto-refresh notifications every 2 minutes
            setInterval(function() {
                location.reload();
            }, 120000);
            
            // Update timestamp every minute
            setInterval(updateLastUpdate, 60000);
        });
        // Mobile sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const sidebarToggle = document.getElementById('mobile-sidebar-toggle');
            const sidebarClose = document.getElementById('sidebar-close');
            const sidebarOverlay = document.getElementById('sidebar-overlay');

            function toggleSidebar() {
                if (sidebar) {
                    sidebar.classList.toggle('-translate-x-full');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.toggle('hidden');
                }
            }

            function closeSidebar() {
                if (sidebar) {
                    sidebar.classList.add('-translate-x-full');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.add('hidden');
                }
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarClose) {
                sidebarClose.addEventListener('click', closeSidebar);
            }
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', closeSidebar);
            }
        });
    </script>
</body>
</html>