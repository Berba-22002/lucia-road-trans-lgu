<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/maps_config.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
                'lgu-stroke': '#00332c',
                'lgu-highlight': '#faae2b'
              }
            }
          }
        }
    </script>
    <style>
        .font-poppins { font-family: 'Poppins', sans-serif; }
        #map { height: 500px; }
        .traffic-heavy { background: linear-gradient(45deg, #ff0000, #cc0000); }
        .traffic-moderate { background: linear-gradient(45deg, #ff8800, #cc6600); }
        .traffic-light { background: linear-gradient(45deg, #00cc00, #008800); }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main content -->
    <div class="lg:ml-64 flex flex-col h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-lgu-stroke">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-lgu-highlight rounded-lg">
                        <i class="fas fa-route text-lgu-button-text text-lg"></i>
                    </div>
                    <h1 class="text-xl font-semibold text-lgu-headline">GPS Traffic Dashboard</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-3">
                        <button onclick="sendAllTrafficAlerts()" class="bg-lgu-headline hover:bg-lgu-stroke text-white px-4 py-2 rounded-lg font-medium flex items-center gap-2 transition-colors duration-200">
                            <i class="fas fa-broadcast-tower"></i>
                            Send All Routes
                        </button>
                        <button onclick="openAlertModal()" class="bg-lgu-button hover:bg-lgu-highlight text-lgu-button-text px-4 py-2 rounded-lg font-medium flex items-center gap-2 transition-colors duration-200">
                            <i class="fas fa-bullhorn"></i>
                            Custom Alert
                        </button>
                    </div>
                    <div class="text-sm text-lgu-paragraph">
                        <i class="fas fa-clock mr-1"></i>
                        <span id="current-time"></span>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6">
            <!-- Live GPS Map -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke mb-6">
                <div class="bg-lgu-headline px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-white text-lg font-semibold flex items-center">
                            <i class="fas fa-satellite-dish mr-2"></i>
                            Live GPS Traffic Map - Metro Manila
                        </h2>
                        <div class="flex items-center space-x-4">
                            <button onclick="getCurrentLocation()" class="bg-lgu-button hover:bg-lgu-highlight text-lgu-button-text px-3 py-1 rounded-lg text-sm font-medium flex items-center gap-1">
                                <i class="fas fa-crosshairs"></i> My Location
                            </button>
                            <div class="text-white text-sm">
                                <i class="fas fa-signal mr-1"></i>
                                <span id="gps-status">GPS Ready</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div id="map" class="rounded-lg border border-lgu-stroke"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Traffic Status Cards -->
                <div class="bg-white rounded-xl p-6 border border-lgu-stroke">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg">
                            <i class="fas fa-road text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-lgu-paragraph">Light Traffic</p>
                            <p class="text-2xl font-bold text-lgu-headline" id="light-count">2</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 border border-lgu-stroke">
                    <div class="flex items-center">
                        <div class="p-3 bg-orange-100 rounded-lg">
                            <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-lgu-paragraph">Moderate Traffic</p>
                            <p class="text-2xl font-bold text-lgu-headline" id="moderate-count">3</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 border border-lgu-stroke">
                    <div class="flex items-center">
                        <div class="p-3 bg-red-100 rounded-lg">
                            <i class="fas fa-ban text-red-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-lgu-paragraph">Heavy Traffic</p>
                            <p class="text-2xl font-bold text-lgu-headline" id="heavy-count">2</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 border border-lgu-stroke">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg">
                            <i class="fas fa-map-pin text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-lgu-paragraph">Active Routes</p>
                            <p class="text-2xl font-bold text-lgu-headline">7</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traffic Routes List -->
            <div class="mt-6 bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke">
                <div class="bg-lgu-headline px-6 py-4">
                    <h2 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-list-ul mr-2"></i>
                        Live Traffic Routes
                    </h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="traffic-routes">
                        <!-- Routes will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Send Alert Modal -->
    <div id="alertModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-[9999] hidden">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full border border-lgu-stroke">
            <div class="bg-lgu-headline px-6 py-4 rounded-t-xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-white text-lg font-semibold flex items-center">
                        <i class="fas fa-bullhorn mr-2"></i>
                        Send Traffic Alert
                    </h3>
                    <button type="button" onclick="closeAlertModal()" class="text-white hover:text-lgu-highlight">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
            </div>
            
            <form method="POST" action="send_traffic_alert.php" class="p-6 space-y-4">
                <div>
                    <label for="alert_title" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-heading mr-2 text-lgu-headline"></i>Alert Title
                    </label>
                    <input type="text" id="alert_title" name="alert_title" required
                           placeholder="Traffic Alert Title..."
                           class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white">
                </div>
                
                <div>
                    <label for="alert_message" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-comment mr-2 text-lgu-headline"></i>Alert Message
                    </label>
                    <textarea id="alert_message" name="alert_message" rows="3" required
                              placeholder="Describe the traffic situation..."
                              class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white"></textarea>
                </div>
                
                <div>
                    <label for="alert_location" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-map-marker-alt mr-2 text-lgu-headline"></i>Location (Optional)
                    </label>
                    <input type="text" id="alert_location" name="alert_location"
                           placeholder="Specific location..."
                           class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white">
                </div>
                
                <div>
                    <label for="alert_priority" class="block text-sm font-medium text-lgu-paragraph mb-2">
                        <i class="fas fa-exclamation-circle mr-2 text-lgu-headline"></i>Priority Level
                    </label>
                    <select id="alert_priority" name="alert_priority" required
                            class="w-full px-3 py-2 border border-lgu-stroke rounded-lg focus:ring-2 focus:ring-lgu-headline focus:border-lgu-headline transition-colors duration-200 bg-white">
                        <option value="">Select priority...</option>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeAlertModal()"
                            class="flex-1 bg-lgu-paragraph hover:bg-lgu-stroke text-white py-2 px-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-lgu-headline hover:bg-lgu-stroke text-white py-2 px-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane"></i> Send Alert
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=geometry&callback=initMap"></script>
    <script>
        let map;
        let trafficLayer;
        let directionsService;
        let trafficRoutes = [];
        
        const routeDefinitions = [
            { name: 'EDSA Northbound', start: [14.5378, 121.0014], end: [14.6507, 121.0014] },
            { name: 'EDSA Southbound', start: [14.6507, 121.0014], end: [14.5378, 121.0014] },
            { name: 'C5 Road', start: [14.5547, 121.0244], end: [14.6547, 121.0244] },
            { name: 'Commonwealth Ave', start: [14.6507, 121.0494], end: [14.6707, 121.0694] },
            { name: 'Katipunan Ave', start: [14.6417, 121.0685], end: [14.6617, 121.0885] },
            { name: 'Ortigas Ave', start: [14.5833, 121.0639], end: [14.6033, 121.0839] },
            { name: 'Quezon Ave', start: [14.6298, 121.0348], end: [14.6498, 121.0548] }
        ];

        function initMap() {
            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 12,
                center: { lat: 14.5995, lng: 120.9842 },
                mapTypeId: 'roadmap'
            });

            trafficLayer = new google.maps.TrafficLayer();
            trafficLayer.setMap(map);
            
            directionsService = new google.maps.DirectionsService();
            
            fetchRealTrafficData();
            setInterval(fetchRealTrafficData, 300000); // Update every 5 minutes
        }

        function fetchRealTrafficData() {
            trafficRoutes = [];
            let completedRequests = 0;
            
            routeDefinitions.forEach((routeDef, index) => {
                const request = {
                    origin: new google.maps.LatLng(routeDef.start[0], routeDef.start[1]),
                    destination: new google.maps.LatLng(routeDef.end[0], routeDef.end[1]),
                    travelMode: google.maps.TravelMode.DRIVING,
                    drivingOptions: {
                        departureTime: new Date(),
                        trafficModel: google.maps.TrafficModel.BEST_GUESS
                    }
                };
                
                directionsService.route(request, (result, status) => {
                    if (status === 'OK') {
                        const route = result.routes[0].legs[0];
                        const duration = route.duration.value;
                        const durationInTraffic = route.duration_in_traffic ? route.duration_in_traffic.value : duration;
                        const distance = route.distance.value;
                        
                        const speed = Math.round((distance / 1000) / (durationInTraffic / 3600));
                        const trafficRatio = durationInTraffic / duration;
                        
                        let status = 'light';
                        if (trafficRatio > 1.5) status = 'heavy';
                        else if (trafficRatio > 1.2) status = 'moderate';
                        
                        trafficRoutes.push({
                            name: routeDef.name,
                            status: status,
                            coords: routeDef.start,
                            speed: speed + ' km/h',
                            eta: Math.round(durationInTraffic / 60) + ' min',
                            distance: (distance / 1000).toFixed(1) + ' km'
                        });
                    }
                    
                    completedRequests++;
                    if (completedRequests === routeDefinitions.length) {
                        updateTrafficRoutes();
                        updateTrafficCounts();
                    }
                });
            });
        }

        function updateTrafficRoutes() {
            const routesContainer = document.getElementById('traffic-routes');
            routesContainer.innerHTML = '';

            trafficRoutes.forEach(route => {
                const statusClass = route.status === 'heavy' ? 'traffic-heavy' : 
                                  route.status === 'moderate' ? 'traffic-moderate' : 'traffic-light';
                
                const routeCard = document.createElement('div');
                routeCard.className = `p-4 rounded-lg border border-lgu-stroke hover:shadow-md transition-shadow cursor-pointer ${statusClass}`;
                routeCard.style.color = 'white';
                
                routeCard.innerHTML = `
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold">${route.name}</h3>
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Speed:</span>
                            <span class="font-medium">${route.speed}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>ETA:</span>
                            <span class="font-medium">${route.eta}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Status:</span>
                            <span class="font-medium">${route.status.toUpperCase()}</span>
                        </div>
                    </div>
                `;
                
                routeCard.onclick = () => {
                    map.setCenter({ lat: route.coords[0], lng: route.coords[1] });
                    map.setZoom(15);
                };
                
                routesContainer.appendChild(routeCard);
            });
        }

        function getCurrentLocation() {
            document.getElementById('gps-status').textContent = 'Locating...';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        new google.maps.Marker({
                            position: { lat: lat, lng: lng },
                            map: map,
                            title: 'Your Location',
                            icon: {
                                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="%23007bff"><circle cx="12" cy="12" r="10"/></svg>'),
                                scaledSize: new google.maps.Size(24, 24)
                            }
                        });
                        
                        map.setCenter({ lat: lat, lng: lng });
                        map.setZoom(15);
                        document.getElementById('gps-status').textContent = 'GPS Active';
                    },
                    (error) => {
                        console.error('Geolocation error:', error);
                        document.getElementById('gps-status').textContent = 'GPS Error';
                    }
                );
            } else {
                document.getElementById('gps-status').textContent = 'GPS Not Supported';
            }
        }

        function updateTime() {
            const now = new Date();
            document.getElementById('current-time').textContent = now.toLocaleTimeString();
        }

        function updateTrafficCounts() {
            const counts = { light: 0, moderate: 0, heavy: 0 };
            trafficRoutes.forEach(route => counts[route.status]++);
            
            document.getElementById('light-count').textContent = counts.light;
            document.getElementById('moderate-count').textContent = counts.moderate;
            document.getElementById('heavy-count').textContent = counts.heavy;
        }

        // Send all traffic routes as alerts
        async function sendAllTrafficAlerts() {
            try {
                const response = await fetch('auto_send_traffic_alerts.php', {
                    method: 'POST'
                });
                const result = await response.text();
                
                if (response.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'All traffic route alerts sent to residents!',
                        confirmButtonColor: '#faae2b'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Error sending alerts',
                        confirmButtonColor: '#faae2b'
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error sending alerts: ' + error.message,
                    confirmButtonColor: '#faae2b'
                });
            }
        }

        // Modal functions
        function openAlertModal() {
            document.getElementById('alertModal').classList.remove('hidden');
        }

        function closeAlertModal() {
            document.getElementById('alertModal').classList.add('hidden');
            document.querySelector('#alertModal form').reset();
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.id === 'alertModal') {
                closeAlertModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAlertModal();
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateTime();
            setInterval(updateTime, 1000);
        });
    </script>
</body>
</html>