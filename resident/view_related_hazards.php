<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
}
$root = dirname(dirname(__FILE__));
require_once __DIR__ . '/../includes/bootstrap.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/hazard_helper.php';
require_once $root . '/resident/sidebar.php';

$hazard_type = isset($_GET['type']) ? $_GET['type'] : 'all';

$query = "
    SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, r.description, r.image_path, u.fullname as reporter_name, mu.fullname as assigned_staff, 
           CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END as has_project
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN maintenance_assignments ma ON r.id = ma.report_id
    LEFT JOIN users mu ON ma.assigned_to = mu.id
    LEFT JOIN projects p ON r.id = p.report_id
    WHERE r.status IN ('in_progress', 'escalated')
";

$params = [];

if ($hazard_type !== 'all') {
    $query .= " AND r.hazard_type = ?";
    $params[] = $hazard_type;
}

$query .= " ORDER BY r.status = 'escalated' DESC, p.id IS NOT NULL DESC, r.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$hazards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate escalated hazards (those with projects or escalated status, but not done)
$escalated_hazards = array_filter($hazards, fn($h) => ($h['status'] === 'escalated' || $h['has_project'] == 1) && $h['status'] !== 'done');
$normal_hazards = array_filter($hazards, fn($h) => $h['status'] !== 'escalated' && $h['has_project'] == 0);

$grouped = [];
foreach ($hazards as $hazard) {
    $type = $hazard['hazard_type'];
    if (!isset($grouped[$type])) {
        $grouped[$type] = [];
    }
    $grouped[$type][] = $hazard;
}

$hazardsJson = json_encode($hazards);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Hazards</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script>
        tailwind.config = {
          theme: {
            fontFamily: {
              sans: ['Poppins', 'sans-serif']
            },
            extend: {
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
        #map { height: 500px; border-radius: 8px; width: 100%; }
        #hazard-details { height: 500px; overflow-y: auto; border-radius: 8px; }
        #nearby-hazards { max-height: 400px; overflow-y: auto; }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="bg-lgu-bg">
    <?php include 'sidebar.php'; ?>
    <button id="sidebar-toggle" class="fixed top-4 left-4 lg:hidden z-50 bg-lgu-button text-lgu-button-text p-2 rounded-lg shadow-lg">
        <i class="fas fa-bars text-xl"></i>
    </button>
    <div class="lg:ml-64 p-6">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h1 class="text-3xl font-bold text-lgu-headline mb-4">
                <i class="fas fa-exclamation-triangle mr-2 text-orange-500"></i>In-Progress Hazards
            </h1>
            
            <div class="flex flex-col lg:flex-row gap-4 items-center">
                <form method="GET" class="flex gap-4">
                    <select name="type" class="px-4 py-2 border border-gray-300 rounded">
                        <option value="all" <?= $hazard_type === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="road" <?= $hazard_type === 'road' ? 'selected' : '' ?>>Road</option>
                        <option value="bridge" <?= $hazard_type === 'bridge' ? 'selected' : '' ?>>Bridge</option>
                        <option value="traffic" <?= $hazard_type === 'traffic' ? 'selected' : '' ?>>Traffic</option>
                    </select>
                    <button type="submit" class="px-6 py-2 bg-lgu-button text-lgu-button-text rounded font-bold hover:bg-yellow-500">Filter</button>
                </form>
                <button id="locationBtn" onclick="getMyLocation()" class="px-6 py-2 bg-blue-500 text-white rounded font-bold hover:bg-blue-600 flex items-center gap-2">
                    <i class="fas fa-location-arrow"></i>Get My Location
                </button>
            </div>
        </div>



        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-md p-4 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-lgu-paragraph text-sm font-medium">Total In Progress</p>
                        <p class="text-3xl font-bold text-lgu-headline"><?= count($hazards) ?></p>
                    </div>
                    <div class="p-4 bg-gradient-to-br from-orange-100 to-orange-50 rounded-xl">
                        <i class="fas fa-spinner text-orange-600 text-2xl"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-md p-4 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-lgu-paragraph text-sm font-medium">Escalated</p>
                        <p class="text-3xl font-bold text-red-600"><?= count($escalated_hazards) ?></p>
                    </div>
                    <div class="p-4 bg-gradient-to-br from-red-100 to-red-50 rounded-xl">
                        <i class="fas fa-fire text-red-600 text-2xl"></i>
                    </div>
                </div>
            </div>
            <?php foreach ($grouped as $type => $items): ?>
                <div class="bg-white rounded-lg shadow-md p-4 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-medium"><?= ucfirst($type) ?></p>
                            <p class="text-3xl font-bold text-lgu-headline"><?= count($items) ?></p>
                        </div>
                        <div class="p-4 bg-gradient-to-br from-blue-100 to-blue-50 rounded-xl">
                            <?php if ($type === 'road'): ?>
                                <i class="fas fa-road text-blue-600 text-2xl"></i>
                            <?php elseif ($type === 'bridge'): ?>
                                <i class="fas fa-bridge text-blue-600 text-2xl"></i>
                            <?php else: ?>
                                <i class="fas fa-traffic-light text-blue-600 text-2xl"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="your-location-section" class="hidden">
            <div class="bg-blue-50 rounded-lg shadow-md p-4 mb-6 border-l-4 border-blue-500">
                <h3 class="text-lg font-bold text-blue-900 mb-3 flex items-center">
                    <i class="fas fa-map-pin mr-2 text-blue-600"></i><span id="location-title">Your Location</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-blue-700 font-semibold">Coordinates</p>
                        <p class="text-sm text-blue-900"><span id="location-lat">--</span>, <span id="location-lng">--</span></p>
                    </div>
                    <div>
                        <p class="text-sm text-blue-700 font-semibold">Address</p>
                        <p class="text-sm text-blue-900" id="location-address">Loading...</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                    <i class="fas fa-map text-blue-600 mr-2"></i>Map View
                </h3>
                    <div id="map"></div>
                </div>
            </div>

            <div>
                <div class="bg-white rounded-lg shadow-md p-6" id="hazard-details">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fas fa-info-circle text-lgu-headline mr-2"></i>Hazard Details
                    </h3>
                    <div id="details-content" class="text-center text-lgu-paragraph text-sm">
                        <i class="fas fa-map-marker-alt text-4xl mb-3 text-gray-300"></i>
                        <p>Click on a pin to view details</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mt-6">
            <h2 class="text-xl font-bold text-lgu-headline mb-4 flex items-center">
                <i class="fas fa-location-dot text-orange-500 mr-2"></i>Nearby Hazards (15km radius)
            </h2>
            <div id="nearby-hazards" class="space-y-2">
                <p class="text-lgu-paragraph text-sm text-center py-4">Click "Get My Location" to see nearby hazards</p>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle for mobile
        document.getElementById('sidebar-toggle').addEventListener('click', () => {
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        });
        const hazards = <?= $hazardsJson ?>;
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        let map;
        let markers = [];
        let userLocation = null;
        let userMarker = null;
        let radiusCircle = null;
        const RADIUS_KM = 15;

        function showLoadingSpinner() {
            document.getElementById('nearby-hazards').innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="text-center">
                        <i class="fas fa-spinner text-4xl text-blue-500 animate-spin mb-3 block"></i>
                        <p class="text-lgu-paragraph text-sm">Loading nearby hazards...</p>
                    </div>
                </div>
            `;
        }

        function getHazardCoordinates(address) {
            fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`)
                .then(res => res.json())
                .then(data => {
                    if (data.results && data.results.length > 0) {
                        const pos = data.results[0].position;
                        document.getElementById('location-lat').textContent = pos.lat.toFixed(6);
                        document.getElementById('location-lng').textContent = pos.lon.toFixed(6);
                        document.getElementById('location-address').textContent = data.results[0].address.freeformAddress;
                        document.getElementById('location-title').textContent = 'Hazard Location';
                    }
                })
                .catch(() => {
                    document.getElementById('location-address').textContent = address;
                    document.getElementById('location-title').textContent = 'Hazard Location';
                });
        }

        function showHazardDetails(hazard) {
            let mediaHtml = '';
            
            if (hazard.image_path) {
                let imageSrc = hazard.image_path;
                if (imageSrc.substring(0, 4) !== 'http' && imageSrc.substring(0, 1) !== '/') {
                    if (imageSrc.substring(0, 8) !== 'uploads/') {
                        imageSrc = 'uploads/hazard_reports/' + imageSrc;
                    }
                    imageSrc = '../' + imageSrc;
                }
                
                const fileExt = imageSrc.split('.').pop().toLowerCase();
                const videoExts = ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv'];
                const isVideo = videoExts.includes(fileExt);
                
                if (isVideo) {
                    mediaHtml += '<div class="border-b pb-3 mb-3"><p class="text-xs text-lgu-paragraph font-bold mb-2">Video</p>';
                    mediaHtml += `<video src="${imageSrc}" class="w-full rounded object-cover" controls style="max-height: 150px;"></video>`;
                    mediaHtml += '</div>';
                } else {
                    mediaHtml += '<div class="border-b pb-3 mb-3"><p class="text-xs text-lgu-paragraph font-bold mb-2">Photo</p>';
                    mediaHtml += `<img src="${imageSrc}" class="w-full rounded object-cover cursor-pointer hover:opacity-80" onclick="window.open('${imageSrc}')" style="max-height: 150px;" />`;
                    mediaHtml += '</div>';
                }
            }
            
            const html = `
                <div class="space-y-3 text-xs">
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Report ID</p>
                        <p class="font-bold text-lgu-headline">#${hazard.id}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Type</p>
                        <p class="font-bold text-lgu-headline capitalize">${hazard.hazard_type}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Address</p>
                        <p class="font-bold text-lgu-headline text-xs">${hazard.address}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Description</p>
                        <p class="font-bold text-lgu-headline text-xs">${hazard.description || 'N/A'}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Reporter</p>
                        <p class="font-bold text-lgu-headline">${hazard.reporter_name || 'Unknown'}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Date Reported</p>
                        <p class="font-bold text-lgu-headline">${new Date(hazard.created_at).toLocaleDateString()}</p>
                    </div>
                    <div class="border-b pb-2">
                        <p class="text-lgu-paragraph text-xs">Assigned Staff</p>
                        <p class="font-bold text-lgu-headline">${hazard.assigned_staff || 'Pending'}</p>
                    </div>
                    ${(hazard.status === 'escalated' || hazard.has_project) && hazard.status !== 'done' ? `<div class="border-b pb-2 bg-red-50 p-2 rounded"><p class="text-xs text-red-700 font-bold">⚠️ ESCALATED / PROJECT ASSIGNED</p></div>` : ''}
                    ${mediaHtml}
                </div>
            `;
            document.getElementById('details-content').innerHTML = html;
            document.getElementById('your-location-section').classList.remove('hidden');
            getHazardCoordinates(hazard.address);
        }

        function getDistance(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }

        function displayNearbyHazards() {
            if (!userLocation) return;

            const nearby = [];
            markers.forEach(marker => {
                if (marker.hazard) {
                    const hazard = marker.hazard;
                    const distance = getDistance(userLocation.lat, userLocation.lng, marker.getLatLng().lat, marker.getLatLng().lng);
                    
                    if (distance <= RADIUS_KM) {
                        nearby.push({ hazard, distance, marker });
                    }
                }
            });

            nearby.sort((a, b) => a.distance - b.distance);

            let html = '';
            if (nearby.length === 0) {
                html = '<p class="text-lgu-paragraph text-sm text-center py-4">No hazards within 15km</p>';
            } else {
                html = nearby.map((item, index) => {
                    const isEscalated = (item.hazard.status === 'escalated' || item.hazard.has_project) && item.hazard.status !== 'done';
                    const borderColor = isEscalated ? 'border-red-500' : 'border-orange-500';
                    const bgColor = isEscalated ? 'bg-red-50' : 'bg-gray-50';
                    const escalationBadge = isEscalated ? `<span class="bg-red-600 text-white text-xs px-2 py-1 rounded font-bold ml-2">ESCALATED</span>` : '';
                    return `
                    <div class="${bgColor} p-3 rounded border-l-4 ${borderColor} cursor-pointer hover:shadow-md transition-all" onclick="showHazardDetails(${JSON.stringify(item.hazard).replace(/"/g, '&quot;')})">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-bold text-lgu-headline text-sm">#${item.hazard.id} - ${item.hazard.hazard_type.toUpperCase()}</p>
                                    ${escalationBadge}
                                </div>
                                <p class="text-xs text-lgu-paragraph">${item.hazard.address}</p>
                                <p class="text-xs text-blue-600 font-semibold mt-1"><i class="fas fa-map-marker-alt mr-1"></i>${item.distance.toFixed(2)} km away</p>
                            </div>
                        </div>
                    </div>
                `;
                }).join('');
            }

            document.getElementById('nearby-hazards').innerHTML = html;
        }

        function getMyLocation() {
            const btn = document.getElementById('locationBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner animate-spin"></i>Getting Location...';
            
            showLoadingSpinner();

            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-location-arrow"></i>Get My Location';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                position => {
                    userLocation = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };

                    document.getElementById('location-lat').textContent = userLocation.lat.toFixed(6);
                    document.getElementById('location-lng').textContent = userLocation.lng.toFixed(6);
                    document.getElementById('location-title').textContent = 'Your Location';
                    document.getElementById('your-location-section').classList.remove('hidden');
                    
                    fetch(`https://api.tomtom.com/search/2/reverseGeocode/${userLocation.lat},${userLocation.lng}.json?key=${TOMTOM_API_KEY}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.addresses && data.addresses.length > 0) {
                                document.getElementById('location-address').textContent = data.addresses[0].address.freeformAddress;
                            }
                        })
                        .catch(() => {
                            document.getElementById('location-address').textContent = userLocation.lat.toFixed(4) + ', ' + userLocation.lng.toFixed(4);
                        });

                    if (userMarker) map.removeLayer(userMarker);
                    if (radiusCircle) map.removeLayer(radiusCircle);

                    userMarker = L.marker([userLocation.lat, userLocation.lng], {
                        icon: L.icon({
                            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
                            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                            iconSize: [25, 41],
                            iconAnchor: [12, 41],
                            shadowSize: [41, 41]
                        })
                    }).addTo(map).bindPopup('Your Location');
                    userMarker.isUserLocation = true;

                    radiusCircle = L.circle([userLocation.lat, userLocation.lng], {
                        radius: RADIUS_KM * 1000,
                        color: '#3b82f6',
                        fillColor: '#3b82f6',
                        fillOpacity: 0.1,
                        weight: 2
                    }).addTo(map);

                    map.setView([userLocation.lat, userLocation.lng], 13);

                    markers.forEach(marker => {
                        if (marker.hazard) {
                            const distance = getDistance(userLocation.lat, userLocation.lng, marker.getLatLng().lat, marker.getLatLng().lng);
                            marker.setOpacity(distance <= RADIUS_KM ? 1 : 0.3);
                        }
                    });

                    setTimeout(() => {
                        displayNearbyHazards();
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-location-arrow"></i>Get My Location';
                    }, 500);
                },
                error => {
                    alert('Error getting location: ' + error.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-location-arrow"></i>Get My Location';
                }
            );
        }

        function initMap() {
            map = L.map('map').setView([14.5995, 120.9842], 11);
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);

            if (hazards && hazards.length > 0) {
                hazards.forEach(hazard => {
                    fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(hazard.address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.results && data.results.length > 0) {
                                const pos = data.results[0].position;
                                addMarker(pos.lat, pos.lon, hazard);
                            }
                        })
                        .catch(() => addMarker(14.5995, 120.9842, hazard));
                });
            }
        }

        function addMarker(lat, lng, hazard) {
            const marker = L.marker([lat, lng], {
                icon: L.icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    shadowSize: [41, 41]
                })
            }).addTo(map);
            marker.hazard = hazard;
            markers.push(marker);
            marker.on('click', () => {
                showHazardDetails(hazard);
                document.getElementById('your-location-section').classList.remove('hidden');
            });
        }

        document.addEventListener('DOMContentLoaded', initMap);
    </script>
</body>
</html>
