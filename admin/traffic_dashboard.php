<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Get all traffic alerts count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM traffic_alerts");
$stmt->execute();
$alert_count = $stmt->fetch()['count'];

// Get recent alerts
$stmt = $pdo->prepare("SELECT ta.*, u.fullname as sent_by_name FROM traffic_alerts ta LEFT JOIN users u ON ta.sent_by = u.id ORDER BY ta.created_at DESC LIMIT 5");
$stmt->execute();
$recent_alerts = $stmt->fetchAll();

// Get priority breakdown
$stmt = $pdo->prepare("SELECT priority, COUNT(*) as count FROM traffic_alerts GROUP BY priority");
$stmt->execute();
$priority_stats = [];
while ($row = $stmt->fetch()) {
    $priority_stats[$row['priority']] = $row['count'];
}

// Get all resident users
$stmt = $pdo->prepare("SELECT id, fullname, email FROM users WHERE role = 'resident' AND status = 'active' ORDER BY fullname");
$stmt->execute();
$residents = $stmt->fetchAll();

// Handle alert sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_alert'])) {
    $title = $_POST['title'];
    $message = $_POST['message'];
    $location = $_POST['location'];
    $priority = $_POST['priority'];
    $recipients = $_POST['recipients'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        if (in_array('all', $recipients)) {
            // Send to all residents
            $stmt = $pdo->prepare("INSERT INTO traffic_alerts (title, message, location, priority, resident_id, sent_by, created_at) VALUES (?, ?, ?, ?, NULL, ?, NOW())");
            $stmt->execute([$title, $message, $location, $priority, $_SESSION['user_id']]);
            
            // Send notifications to all residents
            $all_residents_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'resident' AND status = 'active'");
            $all_residents_stmt->execute();
            $all_residents = $all_residents_stmt->fetchAll();
            
            $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
            foreach ($all_residents as $resident) {
                $notification_message = $message . ($location ? ' Location: ' . $location : '');
                $notification_stmt->execute([$resident['id'], 'Traffic Notification: ' . $title, $notification_message, 'warning']);
            }
        } else {
            // Send to specific residents
            $stmt = $pdo->prepare("INSERT INTO traffic_alerts (title, message, location, priority, resident_id, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
            
            foreach ($recipients as $resident_id) {
                $stmt->execute([$title, $message, $location, $priority, $resident_id, $_SESSION['user_id']]);
                
                // Send notification to specific resident
                $notification_message = $message . ($location ? ' Location: ' . $location : '');
                $notification_stmt->execute([$resident_id, 'Traffic Notification: ' . $title, $notification_message, 'warning']);
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Traffic notification sent successfully!';
        header('Location: traffic_dashboard.php');
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error sending notification: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Traffic Dashboard</title>
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
        #trafficMap { height: 350px; }
        
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
    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col h-screen">
        <header class="bg-white shadow-sm border-b border-lgu-stroke">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-lgu-highlight rounded-lg">
                        <i class="fas fa-road text-lgu-button-text text-lg"></i>
                    </div>
                    <h1 class="text-xl font-semibold text-lgu-headline">Admin Traffic Dashboard</h1>
                </div>
               
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center">
                    <i class="fas fa-check-circle text-green-500 text-lg mr-3"></i>
                    <span class="text-green-700"><?= htmlspecialchars($_SESSION['success']) ?></span>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 text-lg mr-3"></i>
                    <span class="text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-xl shadow-sm p-6 border border-lgu-stroke">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium">Total Alerts</p>
                            <p class="text-2xl font-bold text-lgu-headline"><?= $alert_count ?></p>
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
                            <p class="text-2xl font-bold text-red-600"><?= $priority_stats['high'] ?? 0 ?></p>
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
                            <p class="text-2xl font-bold text-orange-600"><?= $priority_stats['medium'] ?? 0 ?></p>
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
                            <p class="text-2xl font-bold text-green-600"><?= $priority_stats['low'] ?? 0 ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-lg">
                            <i class="fas fa-info-circle text-green-600"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traffic Alert Form -->
            <div class="bg-white rounded-xl shadow-sm border border-lgu-stroke mb-6">
                <div class="bg-lgu-headline px-6 py-4">
                    <h2 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-bullhorn mr-2"></i>
                        Send Traffic Notification
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" action="traffic_dashboard.php">
                        <input type="hidden" name="send_alert" value="1">
                        
                        <!-- First Row -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-lgu-headline mb-2">Select Road</label>
                                <select name="location" id="roadSelect" class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required>
                                    <option value="">Choose a road...</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-lgu-headline mb-2">Traffic Status</label>
                                <input type="text" id="trafficStatus" class="w-full px-3 py-2 border border-lgu-stroke rounded-lg bg-gray-50" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-lgu-headline mb-2">Priority</label>
                                <select name="priority" class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Second Row -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-lgu-headline mb-2">Notification Title</label>
                                <input type="text" name="title" id="alertTitle" class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-lgu-headline mb-2">Message</label>
                                <textarea name="message" id="alertMessage" rows="2" class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required></textarea>
                            </div>
                            <div class="flex flex-col justify-end">
                                <input type="hidden" name="recipients[]" value="all">
                                <button type="submit" class="bg-lgu-button text-lgu-button-text px-6 py-2 rounded-lg font-medium hover:bg-lgu-highlight transition-colors flex items-center justify-center">
                                    <i class="fas fa-paper-plane mr-2"></i>
                                    Send to All Residents
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Hidden traffic status list for JavaScript -->
            <div id="trafficStatusList" style="display: none;"></div>

            <!-- Traffic Map -->
            <div class="bg-white rounded-xl shadow-sm border border-lgu-stroke mb-6">
                <div class="bg-lgu-headline px-6 py-4">
                    <h2 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-map mr-2"></i>
                        Live Traffic Map
                    </h2>
                </div>
                <div class="p-6">

                    <div id="trafficMap" class="rounded-lg border border-lgu-stroke"></div>
                </div>
            </div>

            <!-- Recent Alerts -->
            <div class="bg-white rounded-xl shadow-sm border border-lgu-stroke">
                <div class="bg-lgu-headline px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-white text-lg font-semibold flex items-center">
                            <i class="fas fa-history mr-2"></i>
                            Recent Notifications
                        </h2>
                        <div class="text-white text-sm">
                            Auto-notifications: <span id="autoAlertStatus" class="font-medium">Active</span>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div id="alertsList">
                        <?php if (empty($recent_alerts)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-bell-slash text-lgu-paragraph text-4xl mb-4"></i>
                                <p class="text-lgu-paragraph">No notifications sent yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_alerts as $alert): ?>
                                    <div class="border border-lgu-stroke rounded-lg p-4">
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-start space-x-4">
                                                <div class="p-3 rounded-lg
                                                    <?= $alert['priority'] === 'high' ? 'bg-red-100' : 
                                                        ($alert['priority'] === 'medium' ? 'bg-orange-100' : 'bg-blue-100') ?>">
                                                    <i class="fas fa-exclamation-triangle 
                                                        <?= $alert['priority'] === 'high' ? 'text-red-600' : 
                                                            ($alert['priority'] === 'medium' ? 'text-orange-600' : 'text-blue-600') ?>"></i>
                                                </div>
                                                <div class="flex-1">
                                                    <h3 class="font-semibold text-lgu-headline">
                                                        <?= htmlspecialchars($alert['title']) ?>
                                                    </h3>
                                                    <p class="text-lgu-paragraph mt-1">
                                                        <?= htmlspecialchars($alert['message']) ?>
                                                    </p>
                                                    <?php if ($alert['location']): ?>
                                                        <div class="flex items-center mt-2 text-sm text-lgu-paragraph">
                                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                                            <?= htmlspecialchars($alert['location']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="flex items-center mt-2 text-xs text-lgu-paragraph">
                                                        <i class="fas fa-clock mr-1"></i>
                                                        <?= date('M j, Y g:i A', strtotime($alert['created_at'])) ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                                <?= $alert['priority'] === 'high' ? 'bg-red-100 text-red-800' : 
                                                    ($alert['priority'] === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800') ?>">
                                                <?= ucfirst($alert['priority']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>



    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        const majorRoads = [
            // Metro Manila Major Roads
            { name: 'EDSA', coords: [14.5547, 121.0244] },
            { name: 'C5 Road', coords: [14.5764, 121.0851] },
            { name: 'Katipunan Avenue', coords: [14.6387, 121.0731] },
            { name: 'Commonwealth Avenue', coords: [14.6760, 121.0437] },
            { name: 'Quezon Avenue', coords: [14.6507, 121.0300] },
            { name: 'España Boulevard', coords: [14.6042, 120.9947] },
            { name: 'Taft Avenue', coords: [14.5547, 120.9934] },
            { name: 'Roxas Boulevard', coords: [14.5547, 120.9781] },
            { name: 'Ortigas Avenue', coords: [14.5833, 121.0564] },
            { name: 'Shaw Boulevard', coords: [14.5833, 121.0564] },
            { name: 'Makati Avenue', coords: [14.5547, 121.0244] },
            { name: 'Ayala Avenue', coords: [14.5547, 121.0244] },
            { name: 'Gil Puyat Avenue', coords: [14.5547, 121.0244] },
            { name: 'Marcos Highway', coords: [14.6042, 121.0731] },
            { name: 'Magsaysay Boulevard', coords: [14.6042, 120.9947] },
            { name: 'Rizal Avenue', coords: [14.6042, 120.9947] },
            { name: 'Recto Avenue', coords: [14.6042, 120.9947] },
            { name: 'Quirino Avenue', coords: [14.5547, 120.9934] },
            { name: 'Aurora Boulevard', coords: [14.6042, 121.0564] },
            { name: 'Araneta Avenue', coords: [14.6387, 121.0300] },
            { name: 'Mindanao Avenue', coords: [14.6760, 121.0437] },
            { name: 'North Avenue', coords: [14.6507, 121.0300] },
            { name: 'West Avenue', coords: [14.6507, 121.0300] },
            { name: 'East Avenue', coords: [14.6507, 121.0300] },
            { name: 'Timog Avenue', coords: [14.6387, 121.0300] },
            { name: 'Scout Tuason', coords: [14.6387, 121.0300] },
            { name: 'Morato Avenue', coords: [14.6387, 121.0300] },
            { name: 'Maginhawa Street', coords: [14.6387, 121.0731] },
            { name: 'Banawe Street', coords: [14.6387, 121.0300] },
            { name: 'Roosevelt Avenue', coords: [14.6760, 121.0437] },
            { name: 'Tandang Sora Avenue', coords: [14.6760, 121.0437] },
            { name: 'Fairview Avenue', coords: [14.6760, 121.0437] },
            { name: 'Don Antonio Avenue', coords: [14.6760, 121.0437] },
            { name: 'Congressional Avenue', coords: [14.6760, 121.0437] },
            { name: 'Visayas Avenue', coords: [14.6760, 121.0437] },
            { name: 'Elliptical Road', coords: [14.6507, 121.0300] },
            { name: 'University Avenue', coords: [14.6387, 121.0731] },
            { name: 'Anonas Street', coords: [14.6387, 121.0731] },
            { name: 'Santolan Road', coords: [14.6042, 121.0731] },
            { name: 'Marikina-Infanta Highway', coords: [14.6387, 121.1000] },
            { name: 'Sumulong Highway', coords: [14.6387, 121.0731] },
            { name: 'Ortigas Extension', coords: [14.5833, 121.0851] },
            { name: 'Cainta Junction', coords: [14.5833, 121.1167] },
            { name: 'Felix Avenue', coords: [14.5833, 121.1167] },
            { name: 'Imelda Avenue', coords: [14.5833, 121.1167] },
            { name: 'Marcos Highway Extension', coords: [14.6042, 121.1167] },
            { name: 'Manila East Road', coords: [14.5833, 121.1167] },
            { name: 'Circumferential Road', coords: [14.5833, 121.1167] },
            { name: 'Pasig Boulevard', coords: [14.5833, 121.0564] },
            { name: 'C6 Road', coords: [14.5764, 121.1167] },
            { name: 'Libis', coords: [14.6042, 121.0731] },
            { name: 'Eastwood Avenue', coords: [14.6042, 121.0731] },
            { name: 'Greenfield District', coords: [14.5833, 121.0851] },
            { name: 'McKinley Road', coords: [14.5278, 121.0564] },
            { name: 'Lawton Avenue', coords: [14.5278, 121.0244] },
            { name: 'Kalayaan Avenue', coords: [14.5547, 121.0244] },
            { name: 'Chino Roces Avenue', coords: [14.5278, 121.0244] },
            { name: 'Pasay Road', coords: [14.5278, 120.9934] },
            { name: 'NAIA Road', coords: [14.5083, 121.0197] },
            { name: 'Ninoy Aquino Avenue', coords: [14.5083, 121.0197] },
            { name: 'Domestic Road', coords: [14.5083, 121.0197] },
            { name: 'Airport Road', coords: [14.5083, 121.0197] },
            { name: 'Sucat Road', coords: [14.4208, 121.0244] },
            { name: 'Alabang-Zapote Road', coords: [14.4208, 121.0244] },
            { name: 'Las Piñas-Parañaque Road', coords: [14.4208, 120.9781] },
            { name: 'Coastal Road', coords: [14.5278, 120.9781] },
            { name: 'Seaside Boulevard', coords: [14.5278, 120.9781] },
            { name: 'Macapagal Avenue', coords: [14.5278, 120.9781] },
            { name: 'Diosdado Macapagal Boulevard', coords: [14.5278, 120.9781] },
            { name: 'Mall of Asia Boulevard', coords: [14.5278, 120.9781] },
            { name: 'Seaside Boulevard Extension', coords: [14.5278, 120.9781] },
            { name: 'Radial Road 10', coords: [14.5278, 120.9781] },
            { name: 'South Luzon Expressway', coords: [14.4208, 121.0244] },
            { name: 'Skyway', coords: [14.5278, 121.0244] },
            { name: 'North Luzon Expressway', coords: [14.6760, 120.9781] },
            { name: 'C3 Road', coords: [14.6042, 120.9947] },
            { name: 'Navotas-Malabon Road', coords: [14.6760, 120.9781] },
            { name: 'Caloocan-Malabon Road', coords: [14.6760, 120.9781] },
            { name: 'Monumento Circle', coords: [14.6760, 120.9781] },
            { name: 'MacArthur Highway', coords: [14.6760, 120.9781] },
            { name: 'Samson Road', coords: [14.6760, 120.9781] },
            { name: 'Gen. Luis Street', coords: [14.6042, 120.9947] },
            { name: 'Bambang Street', coords: [14.6042, 120.9947] },
            { name: 'Blumentritt Road', coords: [14.6042, 120.9947] },
            { name: 'Lacson Avenue', coords: [14.6042, 120.9947] },
            { name: 'Dapitan Street', coords: [14.6042, 120.9947] },
            { name: 'Padre Faura Street', coords: [14.5833, 120.9934] },
            { name: 'United Nations Avenue', coords: [14.5833, 120.9934] },
            { name: 'Pedro Gil Street', coords: [14.5833, 120.9934] },
            { name: 'Pres. Quirino Avenue', coords: [14.5833, 120.9934] },
            { name: 'Vito Cruz Street', coords: [14.5833, 120.9934] },
            { name: 'Sen. Gil Puyat Avenue', coords: [14.5547, 121.0244] },
            { name: 'Dela Rosa Street', coords: [14.5547, 121.0244] },
            { name: 'Paseo de Roxas', coords: [14.5547, 121.0244] },
            { name: 'Legaspi Street', coords: [14.5547, 121.0244] },
            { name: 'Salcedo Street', coords: [14.5547, 121.0244] },
            { name: 'Rada Street', coords: [14.5547, 121.0244] },
            { name: 'Legazpi Street', coords: [14.5547, 121.0244] },
            { name: 'Herrera Street', coords: [14.5547, 121.0244] },
            { name: 'Arnaiz Avenue', coords: [14.5547, 121.0244] },
            { name: 'Epifanio de los Santos Avenue', coords: [14.5547, 121.0244] },
            { name: 'Jupiter Street', coords: [14.5547, 121.0244] },
            { name: 'Polaris Street', coords: [14.5547, 121.0244] },
            { name: 'Nicanor Garcia Street', coords: [14.5547, 121.0244] },
            { name: 'Rockwell Drive', coords: [14.5547, 121.0244] },
            { name: 'Estrella Street', coords: [14.5547, 121.0244] },
            { name: 'Yakal Street', coords: [14.5547, 121.0244] },
            { name: 'Kamagong Street', coords: [14.5547, 121.0244] },
            { name: 'Malugay Street', coords: [14.5547, 121.0244] },
            { name: 'Evangelista Street', coords: [14.5547, 121.0244] },
            { name: 'Zobel Roxas Street', coords: [14.5547, 121.0244] },
            { name: 'Amorsolo Street', coords: [14.5547, 121.0244] },
            { name: 'Rufino Street', coords: [14.5547, 121.0244] },
            { name: 'Tordesillas Street', coords: [14.5547, 121.0244] },
            { name: 'Valero Street', coords: [14.5547, 121.0244] },
            { name: 'Urdaneta Street', coords: [14.5547, 121.0244] },
            { name: 'Leviste Street', coords: [14.5547, 121.0244] },
            { name: 'Gamboa Street', coords: [14.5547, 121.0244] },
            { name: 'Sedeño Street', coords: [14.5547, 121.0244] },
            { name: 'Trasierra Street', coords: [14.5547, 121.0244] },
            { name: 'Durban Street', coords: [14.5547, 121.0244] },
            { name: 'Greenbelt Drive', coords: [14.5547, 121.0244] },
            { name: 'Glorietta Drive', coords: [14.5547, 121.0244] },
            { name: 'Park Avenue', coords: [14.5547, 121.0244] },
            { name: 'Buendia Avenue', coords: [14.5547, 121.0244] },
            { name: 'Osmeña Highway', coords: [14.5547, 121.0244] },
            { name: 'South Superhighway', coords: [14.5278, 121.0244] },
            { name: 'Magallanes Drive', coords: [14.5278, 121.0244] },
            { name: 'Nichols', coords: [14.5278, 121.0564] },
            { name: '32nd Street', coords: [14.5278, 121.0564] },
            { name: '5th Avenue', coords: [14.5278, 121.0564] },
            { name: '26th Street', coords: [14.5278, 121.0564] },
            { name: '3rd Avenue', coords: [14.5278, 121.0564] },
            { name: '7th Avenue', coords: [14.5278, 121.0564] },
            { name: 'Rizal Drive', coords: [14.5278, 121.0564] },
            { name: 'Lawton Avenue', coords: [14.5278, 121.0564] },
            { name: 'Bonifacio Drive', coords: [14.5278, 121.0564] },
            { name: 'McKinley Parkway', coords: [14.5278, 121.0564] },
            { name: 'Cayetano Boulevard', coords: [14.5278, 121.0564] },
            { name: 'C5 Extension', coords: [14.5278, 121.0851] },
            { name: 'Lanuza Avenue', coords: [14.5833, 121.0564] },
            { name: 'Pioneer Street', coords: [14.5833, 121.0564] },
            { name: 'Reliance Street', coords: [14.5833, 121.0564] },
            { name: 'Boni Avenue', coords: [14.5833, 121.0564] },
            { name: 'Kapitolyo', coords: [14.5833, 121.0564] },
            { name: 'Mandaluyong-Makati Bridge', coords: [14.5833, 121.0564] },
            { name: 'Guadalupe Bridge', coords: [14.5547, 121.0564] },
            { name: 'Lambingan Bridge', coords: [14.5833, 121.0564] },
            { name: 'Estrella-Pantaleon Bridge', coords: [14.5833, 121.0564] },
            { name: 'Makati-Mandaluyong Bridge', coords: [14.5833, 121.0564] },
            { name: 'Jones Bridge', coords: [14.5833, 120.9781] },
            { name: 'Quezon Bridge', coords: [14.5833, 120.9781] },
            { name: 'MacArthur Bridge', coords: [14.5833, 120.9781] },
            { name: 'Ayala Bridge', coords: [14.5833, 120.9781] },
            { name: 'Nagtahan Bridge', coords: [14.5833, 120.9781] },
            { name: 'Pandacan Bridge', coords: [14.5833, 120.9781] },
            { name: 'Lambingan Bridge', coords: [14.5833, 120.9781] },
            { name: 'Makati-Mandaluyong Bridge', coords: [14.5833, 120.9781] },
            { name: 'Guadalupe Bridge', coords: [14.5833, 120.9781] },
            { name: 'Magallanes Bridge', coords: [14.5278, 120.9781] },
            { name: 'Kalayaan Bridge', coords: [14.5278, 120.9781] },
            { name: 'Rockwell Bridge', coords: [14.5278, 120.9781] },
            { name: 'Pasig River Ferry', coords: [14.5833, 120.9781] }
        ];
        
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
        
        let sentAlerts = new Set(); // Track sent alerts to avoid duplicates
        let currentTrafficData = []; // Store current traffic status

        let map;
        let trafficLayers = [];

        function initMap() {
            map = L.map('trafficMap').setView([14.5995, 120.9842], 12);

            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);

            L.tileLayer(`https://api.tomtom.com/traffic/map/4/tile/flow/relative/{z}/{x}/{y}.png?key=${TOMTOM_API_KEY}`, {
                opacity: 0.7
            }).addTo(map);

            // Load traffic data
            loadTrafficData();
        }

        async function loadTrafficData() {
            currentTrafficData = []; // Reset traffic data
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
                                <p class="text-sm">Street Type: ${getStreetType(route.name)}</p>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                Last updated: ${new Date().toLocaleTimeString()}
                            </div>
                        </div>
                    `);
                    
                    // Store traffic data for manual sending
                    currentTrafficData.push({
                        name: route.name,
                        status: trafficStatus,
                        data: trafficData
                    });
                    
                    // Update traffic status display
                    updateTrafficStatusDisplay();
                    
                    // Store for form dropdown
                    updateRoadDropdown();
                    
                } catch (error) {
                    console.error(`Error loading traffic data for ${route.name}:`, error);
                    // Fallback to default marker
                    L.marker(route.coords, {
                        icon: createTrafficIcon('unknown', route.name)
                    }).addTo(map).bindPopup(`
                        <div class="p-2">
                            <h4 class="font-semibold">${route.name}</h4>
                            <p class="text-sm">Status: <span class="font-medium">DATA UNAVAILABLE</span></p>
                            <p class="text-sm">Street Type: ${getStreetType(route.name)}</p>
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

        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            fetchAlerts();
            
            // Setup road selection handler
            const roadSelect = document.getElementById('roadSelect');
            if (roadSelect) {
                roadSelect.addEventListener('change', handleRoadSelection);
            }
            
            // Auto-refresh alerts every 60 seconds
            setInterval(fetchAlerts, 60000);
            
            // Auto-refresh traffic data every 2 minutes
            setInterval(loadTrafficData, 120000);
        });
        
        function sendAllTrafficStatus() {
            if (currentTrafficData.length === 0) {
                Swal.fire({
                    title: 'No Traffic Data',
                    text: 'No traffic data available to send notification',
                    icon: 'warning',
                    confirmButtonColor: '#faae2b'
                });
                return;
            }
            
            // Create summary of all traffic conditions
            let message = 'Current Traffic Status:\n';
            currentTrafficData.forEach(route => {
                const statusText = route.status.toUpperCase();
                const speed = route.data.currentSpeed ? `${route.data.currentSpeed} km/h` : 'N/A';
                message += `• ${route.name}: ${statusText} (${speed})\n`;
            });
            
            const formData = new FormData();
            formData.append('send_alert', '1');
            formData.append('title', 'Traffic Status Notification');
            formData.append('message', message);
            formData.append('location', 'Multiple Locations');
            formData.append('priority', 'medium');
            formData.append('recipients[]', 'all');
            
            fetch('traffic_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                Swal.fire({
                    title: 'Traffic Status Sent!',
                    text: `Traffic notification for ${currentTrafficData.length} routes sent to all residents`,
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false,
                    position: 'top-end',
                    toast: true
                });
                fetchAlerts();
            })
            .catch(error => {
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to send traffic notification',
                    icon: 'error',
                    confirmButtonColor: '#faae2b'
                });
            });
        }
        
        function getStreetType(streetName) {
            if (streetName.includes('Avenue')) return 'Main Avenue';
            if (streetName.includes('Street')) return 'City Street';
            if (streetName.includes('Highway')) return 'Highway';
            return 'Local Road';
        }
        
        function updateTrafficStatusDisplay() {
            const statusList = document.getElementById('trafficStatusList');
            
            if (currentTrafficData.length === 0) {
                statusList.innerHTML = `
                    <div class="text-center py-8 text-lgu-paragraph">
                        <i class="fas fa-road text-2xl mb-2"></i>
                        <p>No traffic data available</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            currentTrafficData.forEach(route => {
                const statusColor = getTrafficColor(route.status);
                const statusIcon = getTrafficIcon(route.status);
                const speed = route.data.currentSpeed || 'N/A';
                
                html += `
                    <div class="flex items-center justify-between p-4 bg-lgu-bg rounded-lg border border-lgu-stroke">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background-color: ${statusColor}20; border: 2px solid ${statusColor}">
                                <i class="fas ${statusIcon} text-sm" style="color: ${statusColor}"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-lgu-headline">${route.name}</h4>
                                <p class="text-sm text-lgu-paragraph">${getStreetType(route.name)}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold" style="color: ${statusColor}">${route.status.toUpperCase()}</p>
                            <p class="text-sm text-lgu-paragraph">${speed} km/h</p>
                        </div>
                    </div>
                `;
            });
            
            statusList.innerHTML = html;
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
        
        function fetchAlerts() {
            fetch('fetch_alerts.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateAlertsDisplay(data.alerts);
                        updateLastUpdate();
                    }
                })
                .catch(error => console.error('Error fetching alerts:', error));
        }
        
        function updateAlertsDisplay(alerts) {
            const alertsList = document.getElementById('alertsList');
            if (alerts.length === 0) {
                alertsList.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-bell-slash text-lgu-paragraph text-4xl mb-4"></i>
                        <p class="text-lgu-paragraph">No notifications sent yet</p>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="space-y-4">';
            alerts.forEach(alert => {
                const priorityBg = alert.priority === 'high' ? 'bg-red-100' : 
                                 (alert.priority === 'medium' ? 'bg-orange-100' : 'bg-blue-100');
                const priorityText = alert.priority === 'high' ? 'text-red-600' : 
                                   (alert.priority === 'medium' ? 'text-orange-600' : 'text-blue-600');
                const priorityBadge = alert.priority === 'high' ? 'bg-red-100 text-red-800' : 
                                    (alert.priority === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800');
                
                html += `
                    <div class="border border-lgu-stroke rounded-lg p-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4">
                                <div class="p-3 rounded-lg ${priorityBg}">
                                    <i class="fas fa-exclamation-triangle ${priorityText}"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="font-semibold text-lgu-headline">${alert.title}</h3>
                                    <p class="text-lgu-paragraph mt-1">${alert.message}</p>
                                    ${alert.location ? `
                                        <div class="flex items-center mt-2 text-sm text-lgu-paragraph">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            ${alert.location}
                                        </div>
                                    ` : ''}
                                    <div class="flex items-center mt-2 text-xs text-lgu-paragraph">
                                        <i class="fas fa-clock mr-1"></i>
                                        ${new Date(alert.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true})}
                                    </div>
                                </div>
                            </div>
                            <span class="px-2 py-1 rounded-full text-xs font-medium ${priorityBadge}">
                                ${alert.priority.charAt(0).toUpperCase() + alert.priority.slice(1)}
                            </span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            alertsList.innerHTML = html;
        }
        
        function updateLastUpdate() {
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }
        

        
        function updateRoadDropdown() {
            const roadSelect = document.getElementById('roadSelect');
            if (!roadSelect) return;
            
            // Clear existing options except first
            roadSelect.innerHTML = '<option value="">Choose a road...</option>';
            
            // Add roads from traffic data
            currentTrafficData.forEach(route => {
                const option = document.createElement('option');
                option.value = route.name;
                option.textContent = route.name;
                option.dataset.status = route.status;
                option.dataset.speed = route.data.currentSpeed || 'N/A';
                roadSelect.appendChild(option);
            });
        }
        
        function handleRoadSelection() {
            const roadSelect = document.getElementById('roadSelect');
            const trafficStatus = document.getElementById('trafficStatus');
            const alertTitle = document.getElementById('alertTitle');
            const alertMessage = document.getElementById('alertMessage');
            
            if (!roadSelect || !trafficStatus) return;
            
            const selectedOption = roadSelect.options[roadSelect.selectedIndex];
            if (selectedOption.value) {
                const status = selectedOption.dataset.status || 'unknown';
                const speed = selectedOption.dataset.speed || 'N/A';
                
                trafficStatus.value = `${status.toUpperCase()} (${speed} km/h)`;
                alertTitle.value = `Traffic Notification: ${selectedOption.value}`;
                alertMessage.value = `Current traffic condition on ${selectedOption.value}: ${status.toUpperCase()}. Speed: ${speed} km/h`;
            } else {
                trafficStatus.value = '';
                alertTitle.value = '';
                alertMessage.value = '';
            }
        }
    </script>
</body>
</html>