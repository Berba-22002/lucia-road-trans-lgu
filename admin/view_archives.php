<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$report_id = (int)($_GET['id'] ?? 0);
if (!$report_id) {
    header("Location: archives.php");
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.hazard_type,
            r.description,
            r.address,
            r.landmark,
            r.contact_number,
            r.image_path,
            r.status,
            r.validation_status,
            r.created_at,
            u.fullname as reporter_name,
            u.email as reporter_email
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.status = 'archived'
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        header("Location: archives.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching report: " . $e->getMessage());
    header("Location: archives.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Archived Report #<?php echo $report['id']; ?> - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
                        'lgu-stroke': '#00332c'
                    }
                }
            }
        }
    </script>
    <style>
        /* Map styles */
        .leaflet-container {
            height: 100%;
            width: 100%;
            border-radius: 0.5rem;
            z-index: 1 !important;
        }
        
        #reportMap {
            height: 300px !important;
            min-height: 300px;
            width: 100%;
        }
        
        .custom-hazard-marker {
            background: transparent !important;
            border: none !important;
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <div class="min-h-screen p-4">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <div class="bg-lgu-headline text-white p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">Archived Report #<?php echo $report['id']; ?></h1>
                            <p class="text-gray-200">Hazard Report Details</p>
                        </div>
                        <span class="bg-gray-500 px-4 py-2 rounded-full text-sm font-bold">ARCHIVED</span>
                    </div>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-lg font-semibold mb-4">Report Information</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Hazard Type</label>
                                    <p class="text-gray-900 capitalize"><?php echo htmlspecialchars($report['hazard_type']); ?></p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Location</label>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($report['address']); ?></p>
                                    <?php if (!empty($report['landmark'])): ?>
                                    <p class="text-sm text-gray-600 mt-1 flex items-center">
                                        <i class="fas fa-landmark mr-1"></i> <?php echo htmlspecialchars($report['landmark']); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Description</label>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($report['description']); ?></p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Submitted</label>
                                    <p class="text-gray-900"><?php echo date('F j, Y g:i A', strtotime($report['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold mb-4">Reporter Information</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Name</label>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($report['reporter_name'] ?? 'Unknown'); ?></p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Contact</label>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($report['contact_number'] ?? 'N/A'); ?></p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Email</label>
                                    <p class="text-gray-900"><?php echo htmlspecialchars($report['reporter_email'] ?? 'N/A'); ?></p>
                                </div>
                            </div>


                        </div>
                    </div>

                    <!-- Location Map -->
                    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-map mr-2 text-lgu-button"></i>
                            Location Map
                        </h3>
                        <div id="reportMap" class="w-full rounded-lg border border-gray-300"></div>
                        <p class="text-sm text-lgu-paragraph mt-2">Red marker shows the reported hazard location</p>
                    </div>

                    <!-- Image Section -->
                    <?php if ($report['image_path']): ?>
                    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
                        <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                            <i class="fa fa-image mr-2 text-lgu-button"></i>
                            Hazard Image
                        </h3>
                        <div class="text-center">
                            <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($report['image_path']); ?>" 
                                 alt="Hazard Image" 
                                 class="max-w-full h-auto rounded-lg cursor-pointer hover:opacity-90 transition shadow-lg" 
                                 onclick="window.open(this.src, '_blank')">
                            <p class="text-sm text-lgu-paragraph mt-2">Click image to view full size</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-8 flex gap-4 justify-center">
                        <button onclick="unarchiveReport(<?php echo $report['id']; ?>)" 
                                class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-6 py-3 rounded-lg font-semibold transition">
                            <i class="fa fa-undo mr-2"></i>Unarchive Report
                        </button>
                        <a href="archives.php" 
                           class="bg-lgu-headline hover:bg-lgu-stroke text-white px-6 py-3 rounded-lg font-semibold transition">
                            <i class="fa fa-arrow-left mr-2"></i>Back to Archives
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize map
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        document.addEventListener('DOMContentLoaded', function() {
            initReportMap();
        });
        
        function initReportMap() {
            const address = <?php echo json_encode($report['address']); ?>;
            const hazardType = <?php echo json_encode($report['hazard_type']); ?>;
            const landmark = <?php echo json_encode($report['landmark'] ?? ''); ?>;
            
            if (!address) return;
            
            try {
                const map = L.map('reportMap', {
                    zoomControl: true,
                    scrollWheelZoom: true
                }).setView([14.5995, 120.9842], 12);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(map);
                
                setTimeout(() => {
                    map.invalidateSize();
                    geocodeAndMarkLocation(map, address, hazardType, landmark);
                }, 200);
                
            } catch (error) {
                console.error('Error initializing map:', error);
                document.getElementById('reportMap').innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 text-gray-500"><i class="fa fa-map-marker-alt mr-2"></i>Map unavailable</div>';
            }
        }
        
        async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
            try {
                let response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                let data = await response.json();
                
                if (!data.results || data.results.length === 0) {
                    response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address + ', Philippines')}&limit=1`);
                    const nominatimData = await response.json();
                    
                    if (nominatimData && nominatimData.length > 0) {
                        data = {
                            results: [{
                                position: {
                                    lat: parseFloat(nominatimData[0].lat),
                                    lon: parseFloat(nominatimData[0].lon)
                                }
                            }]
                        };
                    }
                }
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    const marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'custom-hazard-marker',
                            html: '<div style="background: #ef4444; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-sm"></i></div>',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        })
                    }).addTo(map);
                    
                    let popupContent = `
                        <div class="p-3 min-w-[200px]">
                            <h4 class="font-bold text-red-600 mb-2 flex items-center">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Archived Hazard
                            </h4>
                            <div class="space-y-1 text-sm">
                                <p><strong>Type:</strong> <span class="text-red-600">${hazardType}</span></p>
                                <p><strong>Address:</strong> ${address}</p>
                    `;
                    
                    if (landmark && landmark.trim()) {
                        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `
                            </div>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent, {
                        maxWidth: 300,
                        className: 'custom-popup'
                    });
                    
                    map.setView([lat, lng], 16);
                    marker.openPopup();
                    
                } else {
                    const mapContainer = map.getContainer();
                    const overlay = document.createElement('div');
                    overlay.className = 'absolute inset-0 bg-gray-100 bg-opacity-90 flex items-center justify-center text-center p-4';
                    overlay.innerHTML = `
                        <div>
                            <i class="fa fa-map-marker-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm font-semibold text-gray-600">Location: ${address}</p>
                            <p class="text-xs text-gray-500 mt-1">Unable to show on map</p>
                        </div>
                    `;
                    mapContainer.style.position = 'relative';
                    mapContainer.appendChild(overlay);
                }
                
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
        
        async function unarchiveReport(reportId) {
            const result = await Swal.fire({
                title: 'Unarchive Report #' + reportId,
                text: 'This will restore the report to active status.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fa fa-undo"></i> Unarchive',
                cancelButtonText: '<i class="fa fa-times"></i> Cancel',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280'
            });

            if (result.isConfirmed) {
                try {
                    const response = await fetch('process_unarchive.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `report_id=${reportId}`
                    });

                    const result = await response.json();

                    if (result.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Report Unarchived!',
                            text: 'The report has been restored to active status.',
                            confirmButtonColor: '#10b981'
                        });
                        window.location.href = 'archives.php';
                    } else {
                        throw new Error(result.message || 'Failed to unarchive report');
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'An error occurred while unarchiving the report',
                        confirmButtonColor: '#ef4444'
                    });
                }
            }
        }
    </script>
</body>
</html>