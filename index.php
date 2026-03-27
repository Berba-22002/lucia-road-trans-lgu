<?php
require_once 'config/database.php';

// Get public data only
function getPublicData($pdo) {
    try {
        return [
            'total_reports' => getCount($pdo, 'reports'),
            'active_projects' => getCount($pdo, 'projects', "status IN ('approved', 'under_construction')"),
            'completed_projects' => getCount($pdo, 'projects', "status = 'completed'"),
            'recent_reports' => getRecentPublicReports($pdo)
        ];
    } catch (Exception $e) {
        return [
            'total_reports' => 0,
            'active_projects' => 0,
            'completed_projects' => 0,
            'recent_reports' => []
        ];
    }
}

function getCount($pdo, $table, $condition = '1=1') {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$condition}");
        return $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) { return 0; }
}

function getRecentPublicReports($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, hazard_type as report_type, address as location, status, created_at as reported_at FROM reports WHERE status != 'archived' ORDER BY created_at DESC LIMIT 20");
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add coordinates by geocoding addresses
        foreach ($reports as &$report) {
            $coords = geocodeAddress($report['location']);
            $report['latitude'] = $coords['lat'];
            $report['longitude'] = $coords['lng'];
        }
        
        return array_filter($reports, function($r) { return $r['latitude'] && $r['longitude']; });
    } catch (Exception $e) { return []; }
}

function geocodeAddress($address) {
    $address = urlencode($address);
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'LGU-RTIM/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data[0])) {
            return ['lat' => $data[0]['lat'], 'lng' => $data[0]['lon']];
        }
    }
    
    return ['lat' => null, 'lng' => null];
}

try {
    $public_data = getPublicData($pdo);
} catch (Exception $e) {
    $public_data = [
        'total_reports' => 0,
        'active_projects' => 0,
        'completed_projects' => 0,
        'recent_reports' => []
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Transportation Management - LGU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body { 
            font-family: 'Inter', system-ui, sans-serif; 
        }
        
        .hero-section {
            background: linear-gradient(135deg, rgba(0, 71, 62, 0.8) 0%, rgba(71, 93, 91, 0.8) 100%), url('https://images.unsplash.com/photo-1449824913935-59a10b8d2000?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: white;
            padding: 4rem 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .navbar {
            background: rgba(0, 71, 62, 0.95) !important;
            backdrop-filter: blur(10px);
        }
        
        .navbar-brand {
            color: #faae2b !important;
            font-weight: bold;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.9) !important;
        }
        
        .nav-link:hover {
            color: #faae2b !important;
        }
        
        .btn-primary {
            background: #faae2b;
            border-color: #faae2b;
            color: #00473e;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: #e09900;
            border-color: #e09900;
            color: #00473e;
        }
        
        .btn-outline-light:hover {
            background: #faae2b;
            border-color: #faae2b;
            color: #00473e;
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .process-card {
            background: white;
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .process-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(0,0,0,0.12);
        }
        
        .process-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
        }
        
        .section-bg {
            background: linear-gradient(rgba(242, 247, 245, 0.95), rgba(242, 247, 245, 0.95)), url('https://images.unsplash.com/photo-1558618666-fcd25c85cd64?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        
        .text-primary-custom {
            color: #00473e !important;
        }
        
        .bg-primary-custom {
            background: #00473e !important;
        }
        
        .text-accent {
            color: #faae2b !important;
        }
        
        .bg-accent {
            background: #faae2b !important;
        }
        
        #map {
            height: 400px;
            border-radius: 12px;
        }
        
        .legend {
            background: white;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#home">
                <img src="admin/logo.jpg" alt="LGU Logo" class="me-2" style="height: 32px; width: auto;">
                Road Transport Management
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#services">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#process">Process</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#reports">Reports</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="https://lucia-road-trans.local-government-unit-1-ph.com/login.php">Staff Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4">Road Transportation Management System</h1>
                    <p class="lead mb-4">Report road issues, track maintenance progress, and stay informed about transportation infrastructure in our community.</p>
                    <div class="d-flex flex-column flex-sm-row gap-3">
                        <a href="#services" class="btn btn-primary btn-lg">
                            <i class="fas fa-exclamation-triangle me-2"></i>Report an Issue
                        </a>
                        <a href="#process" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-info-circle me-2"></i>Learn More
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="row g-3 mt-4 mt-lg-0">
                        <div class="col-6">
                            <div class="metric-card p-4">
                                <div class="text-primary-custom mb-2">
                                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                                </div>
                                <h3 class="fw-bold text-primary-custom"><?= $public_data['total_reports'] ?></h3>
                                <p class="text-muted mb-0">Total Reports</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="metric-card p-4">
                                <div class="text-warning mb-2">
                                    <i class="fas fa-road fa-2x"></i>
                                </div>
                                <h3 class="fw-bold text-primary-custom"><?= $public_data['active_projects'] ?></h3>
                                <p class="text-muted mb-0">Active Projects</p>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="metric-card p-4">
                                <div class="text-success mb-2">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                                <h3 class="fw-bold text-primary-custom"><?= $public_data['completed_projects'] ?></h3>
                                <p class="text-muted mb-0">Completed Projects</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="py-5 section-bg">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto text-center mb-5">
                    <h2 class="display-5 fw-bold text-primary-custom mb-3">Our Services</h2>
                    <p class="lead text-muted">We provide comprehensive road transportation management services to ensure safe and efficient infrastructure for our community.</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="process-card p-4 text-center">
                        <div class="process-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h5 class="fw-semibold text-primary-custom">Damage Reporting</h5>
                        <p class="text-muted">Report road damage, potholes, and hazards for immediate attention.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="process-card p-4 text-center">
                        <div class="process-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-wrench"></i>
                        </div>
                        <h5 class="fw-semibold text-primary-custom">Maintenance Scheduling</h5>
                        <p class="text-muted">Track scheduled maintenance and repair activities.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="process-card p-4 text-center">
                        <div class="process-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-bridge"></i>
                        </div>
                        <h5 class="fw-semibold text-primary-custom">Bridge Inspection</h5>
                        <p class="text-muted">Regular inspection and monitoring of bridges and overpasses.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="process-card p-4 text-center">
                        <div class="process-icon bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-traffic-light"></i>
                        </div>
                        <h5 class="fw-semibold text-primary-custom">Traffic Monitoring</h5>
                        <p class="text-muted">Monitor traffic flow and manage transportation issues.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Process Section -->
    <section id="process" class="py-5" style="background: linear-gradient(rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.95)), url('https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80'); background-size: cover; background-position: center; background-attachment: fixed;">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto text-center mb-5">
                    <h2 class="display-5 fw-bold text-primary-custom mb-3">Resident Process Flow</h2>
                    <p class="lead text-muted">Follow these simple steps to report issues and track progress.</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="process-card p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="process-icon bg-accent text-white me-3" style="width: 50px; height: 50px; font-size: 1.2rem;">
                                1
                            </div>
                            <h5 class="fw-semibold text-primary-custom mb-0">Report Issue</h5>
                        </div>
                        <p class="text-muted mb-3">Submit a report about road damage, hazards, or transportation issues in your area.</p>
                        <div class="small text-muted">
                            <i class="fas fa-arrow-right text-accent me-1"></i>
                            Goes to Damage & Hazard Reporting
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="process-card p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="process-icon bg-accent text-white me-3" style="width: 50px; height: 50px; font-size: 1.2rem;">
                                2
                            </div>
                            <h5 class="fw-semibold text-primary-custom mb-0">Receive Confirmation</h5>
                        </div>
                        <p class="text-muted mb-3">Get confirmation that your report has been received and assigned for processing.</p>
                        <div class="small text-muted">
                            <i class="fas fa-arrow-left text-success me-1"></i>
                            Confirmation from system
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="process-card p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="process-icon bg-accent text-white me-3" style="width: 50px; height: 50px; font-size: 1.2rem;">
                                3
                            </div>
                            <h5 class="fw-semibold text-primary-custom mb-0">Track Progress</h5>
                        </div>
                        <p class="text-muted mb-3">Monitor the status and progress of your reported issue through regular updates.</p>
                        <div class="small text-muted">
                            <i class="fas fa-arrow-left text-info me-1"></i>
                            Status updates & completion reports
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Process Flow Details -->
            <div class="row mt-5">
                <div class="col-12">
                    <div class="process-card p-4">
                        <h5 class="fw-semibold text-primary-custom mb-3">What Happens After You Report?</h5>
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-3">
                                <div class="d-flex align-items-start">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded p-2 me-3">
                                        <i class="fas fa-road"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold">Road Issues</h6>
                                        <small class="text-muted">Sent to Road Maintenance Scheduling</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="d-flex align-items-start">
                                    <div class="bg-warning bg-opacity-10 text-warning rounded p-2 me-3">
                                        <i class="fas fa-bridge"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold">Bridge Issues</h6>
                                        <small class="text-muted">Sent to Bridge & Overpass Inspection</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="d-flex align-items-start">
                                    <div class="bg-danger bg-opacity-10 text-danger rounded p-2 me-3">
                                        <i class="fas fa-traffic-light"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold">Traffic Issues</h6>
                                        <small class="text-muted">Sent to Transportation Flow Monitoring</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="d-flex align-items-start">
                                    <div class="bg-success bg-opacity-10 text-success rounded p-2 me-3">
                                        <i class="fas fa-project-diagram"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold">Major Issues</h6>
                                        <small class="text-muted">Escalated to Road Project Tracking</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Recent Reports Section -->
    <section id="reports" class="py-5 section-bg">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto text-center mb-5">
                    <h2 class="display-5 fw-bold text-primary-custom mb-3">Recent Reports</h2>
                    <p class="lead text-muted">View reported issues on the map and their current status.</p>
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                    <div class="process-card p-4">
                        <div id="map"></div>
                        <div class="mt-3">
                            <div class="legend">
                                <h6 class="fw-semibold mb-2">Legend</h6>
                                <div class="legend-item">
                                    <div class="legend-color" style="background: #0d6efd;"></div>
                                    <span>Road Issues</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background: #ffc107;"></div>
                                    <span>Bridge Issues</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color" style="background: #dc3545;"></div>
                                    <span>Traffic Issues</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-primary-custom text-white py-4" style="background: linear-gradient(rgba(0, 71, 62, 0.9), rgba(0, 71, 62, 0.9)), url('https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') !important; background-size: cover; background-position: center;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2024 Road Transportation Management System. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">Local Government Unit - Transportation Department</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Navbar background on scroll
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(0, 71, 62, 0.98)';
            } else {
                navbar.style.background = 'rgba(0, 71, 62, 0.95)';
            }
        });

        // Initialize map
        const reports = <?= json_encode($public_data['recent_reports']) ?>;
        
        if (reports.length > 0) {
            const map = L.map('map').setView([reports[0].latitude, reports[0].longitude], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            const getMarkerColor = (type) => {
                if (type === 'road') return '#0d6efd';
                if (type === 'bridge') return '#ffc107';
                return '#dc3545';
            };

            const getStatusBadge = (status) => {
                if (status === 'completed') return '<span class="badge bg-success">Completed</span>';
                if (status === 'in_progress') return '<span class="badge bg-warning">In Progress</span>';
                return '<span class="badge bg-secondary">' + status.replace('_', ' ') + '</span>';
            };

            reports.forEach(report => {
                const marker = L.circleMarker([report.latitude, report.longitude], {
                    radius: 8,
                    fillColor: getMarkerColor(report.report_type),
                    color: '#fff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.8
                }).addTo(map);

                marker.bindPopup(`
                    <div style="min-width: 200px;">
                        <h6 class="fw-bold mb-2">${report.report_type.charAt(0).toUpperCase() + report.report_type.slice(1)} Issue</h6>
                        <p class="mb-1"><strong>Location:</strong> ${report.location}</p>
                        <p class="mb-1"><strong>Status:</strong> ${getStatusBadge(report.status)}</p>
                        <p class="mb-0"><strong>Reported:</strong> ${new Date(report.reported_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</p>
                    </div>
                `);
            });
        } else {
            document.getElementById('map').innerHTML = '<div class="text-center text-muted py-5">No reports with location data available</div>';
        }
    </script>
</body>
</html>