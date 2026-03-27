<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$report_id = (int)($_GET['id'] ?? 0);
if (!$report_id) {
    header("Location: view_status.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $stmt = $pdo->prepare("
        SELECT r.*, u.fullname AS reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.user_id = ?
    ");
    $stmt->execute([$report_id, $_SESSION['user_id']]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        header("Location: view_status.php");
        exit();
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report #<?php echo $report_id; ?> - RTIM Resident</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'lgu-bg': '#f2f7f5',
                        'lgu-headline': '#00473e',
                        'lgu-paragraph': '#475d5b',
                        'lgu-button': '#faae2b',
                        'lgu-button-text': '#00473e',
                        'lgu-stroke': '#00332c'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-lgu-bg">
    <?php include 'sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="bg-white shadow-sm border-b border-lgu-stroke">
            <div class="flex items-center justify-between p-4">
                <h1 class="text-xl font-semibold text-lgu-headline">Report #<?php echo $report_id; ?></h1>
                <a href="view_status.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                    <i class="fa fa-arrow-left mr-2"></i>Back
                </a>
            </div>
        </header>

        <main class="flex-1 p-6">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <h3 class="text-lg font-semibold mb-4">Report Details</h3>
                            <div class="space-y-3">
                                <div><strong>Type:</strong> <?php echo htmlspecialchars($report['hazard_type']); ?></div>
                                <div><strong>Status:</strong> <?php echo htmlspecialchars($report['status']); ?></div>
                                <div><strong>Address:</strong> <?php echo htmlspecialchars($report['address']); ?></div>
                                <?php if (!empty($report['landmark'])): ?>
                                <div><strong>Landmark:</strong> <?php echo htmlspecialchars($report['landmark']); ?></div>
                                <?php endif; ?>
                                <div><strong>Date:</strong> <?php echo date('M d, Y h:i A', strtotime($report['created_at'])); ?></div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-lg font-semibold mb-4">Contact Information</h3>
                            <div class="space-y-3">
                                <div><strong>Reporter:</strong> <?php echo htmlspecialchars($report['reporter_name'] ?? 'You'); ?></div>
                                <div><strong>Phone:</strong> <?php echo htmlspecialchars($report['contact_number'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold mb-4">Description</h3>
                        <p class="bg-gray-50 p-4 rounded"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                    </div>
                    
                    <!-- Location Map -->
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <i class="fa fa-map-marker-alt mr-2 text-lgu-button"></i>
                            Location Map
                        </h3>
                        <div id="reportMap" class="w-full h-64 rounded-lg border border-gray-300"></div>
                        <p class="text-xs text-lgu-paragraph mt-2">Red marker shows the reported hazard location</p>
                    </div>
                    
                    <!-- AI Analysis Results -->
                    <?php if (!empty($report['ai_analysis_result'])): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <i class="fa fa-robot mr-2 text-lgu-button"></i>
                            Beripikado AI Analysis
                        </h3>
                        
                        <?php 
                        // Try to decode JSON if it's structured data
                        $ai_data = json_decode($report['ai_analysis_result'], true);
                        if ($ai_data && isset($ai_data['predictions'])): 
                        ?>
                            <div class="bg-blue-50 rounded-lg p-4">
                                <!-- Primary Detection Only -->
                                <?php if (isset($ai_data['topPrediction'])): ?>
                                <div class="mb-3">
                                    <div class="bg-blue-100 p-3 rounded-lg border-l-4 border-blue-500">
                                        <div class="flex justify-between items-center">
                                            <span class="font-semibold text-blue-800"><?php echo htmlspecialchars($ai_data['topPrediction']['className']); ?></span>
                                            <span class="text-sm font-bold text-blue-700"><?php echo number_format($ai_data['topPrediction']['probability'] * 100, 1); ?>%</span>
                                        </div>
                                        <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- AI Recommendation -->
                                <?php 
                                $topPrediction = $ai_data['topPrediction'];
                                if ($topPrediction['probability'] > 0.6): ?>
                                <div class="mt-3 p-2 bg-green-50 border border-green-200 rounded-lg">
                                    <div class="flex items-center text-green-800">
                                        <i class="fa fa-check-circle mr-2 text-xs"></i>
                                        <span class="font-medium text-xs">AI detected: <?php echo htmlspecialchars($topPrediction['className']); ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="mt-3 p-2 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <div class="flex items-center text-yellow-800">
                                        <i class="fa fa-exclamation-triangle mr-2 text-xs"></i>
                                        <span class="font-medium text-xs">Classification requires verification</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Fallback for plain text AI analysis -->
                            <div class="bg-gray-50 p-3 rounded-lg">
                                <p class="text-lgu-paragraph leading-relaxed text-sm"><?php echo nl2br(htmlspecialchars($report['ai_analysis_result'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report['image_path']): ?>
                    <div>
                        <h3 class="text-lg font-semibold mb-4">Image</h3>
                        <?php
                        $image_src = $report['image_path'];
                        if (substr($image_src, 0, 4) !== 'http' && substr($image_src, 0, 1) !== '/') {
                            if (substr($image_src, 0, 8) !== 'uploads/') {
                                $image_src = 'uploads/hazard_reports/' . $image_src;
                            }
                            $image_src = '../' . $image_src;
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Hazard Image" class="max-w-full h-auto rounded cursor-pointer hover:opacity-90 transition" onclick="window.open(this.src, '_blank')">
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        // Map functionality
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
        
        function initMap() {
            // Initialize map centered on Philippines
            const map = L.map('reportMap').setView([14.5995, 120.9842], 12);
            
            // Add TomTom tile layer
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            // Geocode the address and add marker
            const address = <?php echo json_encode($report['address'] ?? ''); ?>;
            const hazardType = <?php echo json_encode($report['hazard_type'] ?? ''); ?>;
            const landmark = <?php echo json_encode($report['landmark'] ?? ''); ?>;
            
            if (address) {
                geocodeAndMarkLocation(map, address, hazardType, landmark);
            }
        }
        
        async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                const data = await response.json();
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    // Add hazard marker
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-marker',
                            html: '<div style="background: #ef4444; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
                            iconSize: [25, 25],
                            iconAnchor: [12, 12]
                        })
                    }).addTo(map);
                    
                    // Create popup content
                    let popupContent = `
                        <div class="p-2">
                            <h4 class="font-bold text-red-600 text-sm">My Report</h4>
                            <p class="text-xs mt-1"><strong>Type:</strong> ${hazardType}</p>
                            <p class="text-xs"><strong>Address:</strong> ${result.address.freeformAddress}</p>
                    `;
                    
                    if (landmark) {
                        popupContent += `<p class="text-xs"><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `</div>`;
                    
                    marker.bindPopup(popupContent);
                    
                    // Center map on location
                    map.setView([lat, lng], 16);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
    </script>
</body>
</html>