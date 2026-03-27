<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspectors
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: assigned_reports.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$report = null;
$error_message = '';

try {
    // Get report details - only if assigned to this inspector
    $stmt = $pdo->prepare("
        SELECT 
            r.id AS report_id,
            r.user_id,
            r.hazard_type,
            r.address,
            r.landmark,
            r.description,
            r.status,
            r.validation_status,
            r.created_at,
            r.image_path,
            r.ai_analysis_result,
            r.contact_number AS reporter_phone,
            u.fullname AS reporter_name,
            u.contact_number AS reporter_contact,
            ri.assigned_at,
            ri.completed_at,
            ri.notes AS assignment_notes,
            ui.fullname AS assigned_inspector_name
        FROM reports r
        INNER JOIN report_inspectors ri ON r.id = ri.report_id
        INNER JOIN users u ON r.user_id = u.id
        LEFT JOIN users ui ON ri.inspector_id = ui.id
        WHERE r.id = ? 
        AND ri.inspector_id = ?
        AND ri.status = 'assigned'
        AND r.status != 'archived'
    ");
    $stmt->execute([$_GET['id'], $_SESSION['user_id']]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        $error_message = "Report not found or you don't have permission to view it.";
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching report details: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
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

    <style>
        * { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Include Inspector Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Report Details</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">View detailed hazard report information</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="assigned_reports.php" 
                       class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm font-bold transition flex items-center gap-2">
                        <i class="fa fa-arrow-left"></i>
                        Back to Reports
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-exclamation-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Error</p>
                        <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
                <div class="text-center mt-8">
                    <a href="assigned_reports.php" class="bg-lgu-button text-lgu-button-text px-6 py-3 rounded-lg font-semibold hover:bg-yellow-500 transition inline-flex items-center gap-2">
                        <i class="fa fa-arrow-left"></i>
                        Return to Assigned Reports
                    </a>
                </div>
            <?php elseif ($report): ?>
                
                <!-- Report Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <h2 class="text-2xl font-bold text-lgu-headline">
                                    Report #<?php echo htmlspecialchars($report['report_id']); ?>
                                </h2>
                                <?php if ($report['status'] === 'pending'): ?>
                                    <span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-sm font-bold inline-flex items-center">
                                        <span class="w-2 h-2 bg-orange-500 rounded-full mr-2"></span>
                                        Pending
                                    </span>
                                <?php elseif ($report['status'] === 'in_progress'): ?>
                                    <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-sm font-bold inline-flex items-center">
                                        <i class="fa fa-spinner mr-1 fa-spin"></i>
                                        In Progress
                                    </span>
                                <?php elseif ($report['status'] === 'done'): ?>
                                    <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-bold inline-flex items-center">
                                        <i class="fa fa-check-circle mr-1"></i>
                                        Completed
                                    </span>
                                <?php elseif ($report['status'] === 'escalated'): ?>
                                    <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-bold inline-flex items-center">
                                        <i class="fa fa-exclamation-triangle mr-1"></i>
                                        Escalated
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-lgu-paragraph">
                                <i class="fa fa-calendar mr-2"></i>
                                Reported on <?php echo date('F j, Y \a\t g:i A', strtotime($report['created_at'])); ?>
                            </p>
                        </div>
                        <div class="bg-lgu-headline text-white px-4 py-2 rounded-lg text-center">
                            <p class="text-sm font-semibold">Hazard Type</p>
                            <p class="text-lg font-bold"><?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?></p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column - Report Details -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Hazard Information -->
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-exclamation-triangle text-lgu-tertiary"></i>
                                Hazard Information
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Hazard Type</label>
                                    <p class="text-lg font-semibold text-lgu-headline bg-blue-50 px-4 py-2 rounded border">
                                        <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Location</label>
                                    <p class="text-lg text-lgu-headline bg-gray-50 px-4 py-2 rounded border flex items-start gap-2">
                                        <i class="fa fa-map-marker-alt text-lgu-tertiary mt-1"></i>
                                        <?php echo htmlspecialchars($report['address']); ?>
                                    </p>
                                    <?php if (!empty($report['landmark'])): ?>
                                    <p class="text-sm text-gray-600 bg-gray-50 px-4 py-2 rounded border mt-2 flex items-start gap-2">
                                        <i class="fa fa-landmark text-gray-500 mt-0.5"></i>
                                        <span><strong>Landmark:</strong> <?php echo htmlspecialchars($report['landmark']); ?></span>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Description</label>
                                    <p class="text-lgu-headline bg-gray-50 px-4 py-3 rounded border min-h-[100px]">
                                        <?php echo nl2br(htmlspecialchars($report['description'])); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Location Map -->
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-map text-lgu-button"></i>
                                Hazard Location Map
                            </h3>
                            <div id="reportMap" class="w-full h-64 rounded-lg border-2 border-gray-300"></div>
                            <p class="text-xs text-lgu-paragraph mt-2">Red marker shows the reported hazard location</p>
                        </div>

                        <!-- AI Analysis Section -->
                        <?php if (!empty($report['ai_analysis_result'])): ?>
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-robot text-lgu-button"></i>
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
                                    <div class="mb-4">
                                        <div class="bg-blue-100 p-3 rounded-lg border-l-4 border-blue-500">
                                            <div class="flex justify-between items-center">
                                                <span class="font-semibold text-blue-800"><?php echo htmlspecialchars($ai_data['topPrediction']['className']); ?></span>
                                                <span class="text-sm font-bold text-blue-700"><?php echo number_format($ai_data['topPrediction']['probability'] * 100, 1); ?>%</span>
                                            </div>
                                            <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Hazard Level -->
                                    <?php if (isset($ai_data['hazardLevel'])): ?>
                                    <div class="mb-4 p-3 rounded-lg border-l-4 border-purple-500 bg-purple-50">
                                        <div class="font-semibold text-purple-800">Hazard Level: <?php echo ucfirst($ai_data['hazardLevel']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- AI Recommendation -->
                                    <?php 
                                    $topPrediction = $ai_data['topPrediction'];
                                    if ($topPrediction['probability'] > 0.6): ?>
                                    <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <div class="flex items-center text-green-800">
                                            <i class="fa fa-check-circle mr-2"></i>
                                            <span class="font-medium text-sm">AI Recommendation: Image appears to show <?php echo htmlspecialchars($topPrediction['className']); ?></span>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                        <div class="flex items-center text-yellow-800">
                                            <i class="fa fa-exclamation-triangle mr-2"></i>
                                            <span class="font-medium text-sm">AI Recommendation: Hazard classification requires manual verification</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Fallback for plain text AI analysis -->
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <p class="text-lgu-paragraph leading-relaxed"><?php echo nl2br(htmlspecialchars($report['ai_analysis_result'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Reporter Information -->
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-user text-lgu-button"></i>
                                Reporter Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Full Name</label>
                                    <p class="text-lg font-semibold text-lgu-headline bg-gray-50 px-4 py-2 rounded border">
                                        <?php echo htmlspecialchars($report['reporter_name']); ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Contact Number</label>
                                    <p class="text-lg text-lgu-headline bg-gray-50 px-4 py-2 rounded border">
                                        <?php echo htmlspecialchars($report['reporter_phone'] ?? $report['reporter_contact'] ?? 'N/A'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Assignment & Status -->
                    <div class="space-y-6">
                        <!-- Assignment Information -->
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-user-check text-green-500"></i>
                                Assignment Details
                            </h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Assigned To</label>
                                    <p class="text-lg font-semibold text-lgu-headline">
                                        <?php echo htmlspecialchars($report['assigned_inspector_name']); ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Assigned Date</label>
                                    <p class="text-lg text-lgu-headline">
                                        <?php echo date('F j, Y \a\t g:i A', strtotime($report['assigned_at'])); ?>
                                    </p>
                                </div>
                                <?php if ($report['completed_at']): ?>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Completed Date</label>
                                    <p class="text-lg text-green-600 font-semibold">
                                        <?php echo date('F j, Y \a\t g:i A', strtotime($report['completed_at'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($report['assignment_notes'])): ?>
                                <div>
                                    <label class="block text-sm font-medium text-lgu-paragraph mb-1">Assignment Notes</label>
                                    <p class="text-lgu-headline bg-gray-50 px-4 py-2 rounded border">
                                        <?php echo nl2br(htmlspecialchars($report['assignment_notes'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Media (Image/Video) -->
                        <?php if ($report['image_path']): ?>
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <?php 
                            $file_path = $report['image_path'];
                            $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                            $is_video = in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'ogv']);
                            ?>
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-<?php echo $is_video ? 'video' : 'camera'; ?> text-purple-500"></i>
                                Hazard <?php echo $is_video ? 'Video' : 'Image'; ?>
                            </h3>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 bg-gray-50 text-center">
                                <?php if ($is_video): ?>
                                    <video controls class="max-w-full h-auto rounded-lg shadow-sm mx-auto" style="max-height: 400px;">
                                        <source src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>">
                                        Your browser does not support the video tag.
                                    </video>
                                    <p class="text-xs text-gray-500 mt-2"><i class="fa fa-video mr-1 text-red-500"></i>Video File</p>
                                <?php else: ?>
                                    <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>" 
                                         alt="Hazard Report Image" 
                                         class="w-full h-auto rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition"
                                         onclick="openImageModal('../uploads/hazard_reports/<?php echo htmlspecialchars($file_path); ?>')">
                                    <p class="text-xs text-gray-500 mt-2"><i class="fa fa-image mr-1 text-blue-500"></i>Image File - Click to enlarge</p>
                                <?php endif; ?>
                                <div class="mt-4 p-3 bg-white rounded-lg text-left text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="text-lgu-paragraph"><i class="fa fa-file mr-2"></i>File Type:</span>
                                        <span class="font-semibold text-lgu-headline"><?php echo strtoupper($file_ext); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Validation Status -->
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                                <i class="fa fa-check-circle text-blue-500"></i>
                                Validation Status
                            </h3>
                            <div class="text-center">
                                <?php if ($report['validation_status'] === 'validated'): ?>
                                    <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-green-100 text-green-800">
                                        <i class="fa fa-check-circle mr-2"></i>
                                        Validated
                                    </span>
                                <?php elseif ($report['validation_status'] === 'rejected'): ?>
                                    <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-red-100 text-red-800">
                                        <i class="fa fa-times-circle mr-2"></i>
                                        Rejected
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-yellow-100 text-yellow-800">
                                        <i class="fa fa-clock mr-2"></i>
                                        Pending Validation
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
                
            </div>
        </footer>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 hidden">
        <div class="max-w-4xl max-h-full p-4">
            <div class="relative">
                <button onclick="closeImageModal()" class="absolute -top-12 right-0 text-white text-2xl hover:text-gray-300">
                    <i class="fa fa-times"></i>
                </button>
                <img id="modalImage" src="" alt="Enlarged view" class="max-w-full max-h-screen rounded-lg">
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebar = document.getElementById('inspector-sidebar');
            const toggle = document.getElementById('mobile-sidebar-toggle');
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
                });
            }
        });

        // Image modal functions
        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
        }

        // Close modal when clicking outside image
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });
        
        // Map functionality
        <?php if ($report): ?>
        let map = null;
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        
        // Initialize map when page loads
        initMap();
        
        function initMap() {
            // Initialize map centered on Philippines
            map = L.map('reportMap').setView([14.5995, 120.9842], 12);
            
            // Add TomTom tile layer
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            // Geocode the address and add marker
            const address = <?php echo json_encode($report['address'] ?? ''); ?>;
            if (address) {
                geocodeAndMarkLocation(address);
            }
        }
        
        async function geocodeAndMarkLocation(address) {
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
                    const popupContent = `
                        <div class="p-2">
                            <h4 class="font-bold text-red-600 text-sm">Hazard Location</h4>
                            <p class="text-xs mt-1"><strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $report['hazard_type'] ?? '')); ?></p>
                            <p class="text-xs"><strong>Address:</strong> ${result.address.freeformAddress}</p>
                            <?php if (!empty($report['landmark'])): ?>
                            <p class="text-xs"><strong>Landmark:</strong> <?php echo htmlspecialchars($report['landmark']); ?></p>
                            <?php endif; ?>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent);
                    
                    // Center map on location
                    map.setView([lat, lng], 16);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }
        <?php endif; ?>
    </script>

</body>
</html>