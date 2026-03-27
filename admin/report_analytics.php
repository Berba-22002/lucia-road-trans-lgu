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

try {
    // Reports Analytics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_reports,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed_reports,
            SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated_reports,
            SUM(CASE WHEN hazard_type = 'road' THEN 1 ELSE 0 END) as road_reports,
            SUM(CASE WHEN hazard_type = 'bridge' THEN 1 ELSE 0 END) as bridge_reports,
            SUM(CASE WHEN hazard_type = 'traffic' THEN 1 ELSE 0 END) as traffic_reports,
            SUM(CASE WHEN status = 'done' AND hazard_type = 'road' THEN 1 ELSE 0 END) as completed_road,
            SUM(CASE WHEN status = 'done' AND hazard_type = 'bridge' THEN 1 ELSE 0 END) as completed_bridge,
            SUM(CASE WHEN status = 'done' AND hazard_type = 'traffic' THEN 1 ELSE 0 END) as completed_traffic
        FROM reports
    ");
    $report_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Projects Analytics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_projects,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_projects,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_projects,
            SUM(CASE WHEN status = 'under_construction' THEN 1 ELSE 0 END) as active_projects,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
            SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) as on_hold_projects,
            AVG(progress) as avg_progress,
            SUM(estimated_budget) as total_budget,
            SUM(actual_cost) as total_spent
        FROM projects
    ");
    $project_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Monthly Reports Trend
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM reports 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $monthly_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent Activity
    $stmt = $pdo->query("
        SELECT 
            r.id,
            r.hazard_type,
            r.status,
            r.created_at,
            u.fullname as reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $recent_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching analytics: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Report Analytics - RTIM</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Poppins Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
        * { font-family: 'Poppins', sans-serif; }
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        .sidebar-link { color:#9CA3AF; }
        .sidebar-link:hover { color:#FFF; background:#00332c; }
        .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
        
        .stat-card { transition: transform .15s ease; }
        .stat-card:hover { transform: translateY(-4px); }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

    <!-- Include sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Report Analytics</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">System overview and statistics</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
                            <i class="fa fa-user text-lgu-button-text font-semibold text-sm sm:text-base"></i>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 15)); ?></p>
                            <p class="text-xs text-lgu-paragraph">Admin</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
            <!-- Reports Overview -->
            <div class="mb-8">
                <h2 class="text-xl font-bold text-lgu-headline mb-4">Reports Overview</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Total Reports</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $report_stats['total_reports'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Pending</p>
                            <p class="text-2xl font-bold text-orange-600"><?php echo $report_stats['pending_reports'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">In Progress</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo $report_stats['in_progress_reports'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Completed</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $report_stats['completed_reports'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Escalated</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo $report_stats['escalated_reports'] ?? 0; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Projects Overview -->
            <div class="mb-8">
                <h2 class="text-xl font-bold text-lgu-headline mb-4">Projects Overview</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Total Projects</p>
                            <p class="text-2xl font-bold text-lgu-headline"><?php echo $project_stats['total_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Pending</p>
                            <p class="text-2xl font-bold text-orange-600"><?php echo $project_stats['pending_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Approved</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $project_stats['approved_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Active</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $project_stats['active_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Completed</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo $project_stats['completed_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-gray-500">
                        <div class="text-center">
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">On Hold</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo $project_stats['on_hold_projects'] ?? 0; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Hazard Types Chart -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Reports by Hazard Type</h3>
                    <div style="height: 300px;">
                        <canvas id="hazardTypeChart"></canvas>
                    </div>
                </div>

                <!-- Completed Reports Chart -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Completed Reports by Type</h3>
                    <div style="height: 300px;">
                        <canvas id="completedReportsChart"></canvas>
                    </div>
                </div>

                <!-- Monthly Trend Chart -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Monthly Reports Trend</h3>
                    <div style="height: 300px;">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>
            </div>



            <!-- Recent Activity -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-lgu-headline mb-4">Recent Reports</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="py-3 px-4 text-left font-semibold text-lgu-headline">ID</th>
                                <th class="py-3 px-4 text-left font-semibold text-lgu-headline">Type</th>
                                <th class="py-3 px-4 text-left font-semibold text-lgu-headline">Status</th>
                                <th class="py-3 px-4 text-left font-semibold text-lgu-headline">Reporter</th>
                                <th class="py-3 px-4 text-left font-semibold text-lgu-headline">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_reports as $report): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 font-bold text-lgu-headline">#<?php echo str_pad($report['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo ucfirst($report['hazard_type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php
                                            $status_colors = [
                                                'pending' => 'bg-orange-100 text-orange-800',
                                                'in_progress' => 'bg-blue-100 text-blue-800',
                                                'done' => 'bg-green-100 text-green-800',
                                                'escalated' => 'bg-red-100 text-red-800'
                                            ];
                                            $color = $status_colors[$report['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $color; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $report['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-lgu-paragraph"><?php echo htmlspecialchars($report['reporter_name'] ?? 'Unknown'); ?></td>
                                    <td class="py-3 px-4 text-lgu-paragraph"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 sm:py-8 mt-8 sm:mt-12 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebar = document.getElementById('admin-sidebar');
            const mobileToggle = document.getElementById('mobile-sidebar-toggle');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
                });
            }

            // Hazard Types Pie Chart
            const hazardCtx = document.getElementById('hazardTypeChart').getContext('2d');
            new Chart(hazardCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Road', 'Bridge', 'Traffic'],
                    datasets: [{
                        data: [
                            <?php echo $report_stats['road_reports'] ?? 0; ?>,
                            <?php echo $report_stats['bridge_reports'] ?? 0; ?>,
                            <?php echo $report_stats['traffic_reports'] ?? 0; ?>
                        ],
                        backgroundColor: ['#3B82F6', '#10B981', '#F59E0B'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Completed Reports Bar Chart
            const completedCtx = document.getElementById('completedReportsChart').getContext('2d');
            new Chart(completedCtx, {
                type: 'bar',
                data: {
                    labels: ['Road', 'Bridge', 'Traffic'],
                    datasets: [{
                        label: 'Completed Reports',
                        data: [
                            <?php echo $report_stats['completed_road'] ?? 0; ?>,
                            <?php echo $report_stats['completed_bridge'] ?? 0; ?>,
                            <?php echo $report_stats['completed_traffic'] ?? 0; ?>
                        ],
                        backgroundColor: ['#10B981', '#3B82F6', '#F59E0B'],
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Monthly Trend Line Chart
            const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php 
                            foreach ($monthly_reports as $month) {
                                echo "'" . date('M Y', strtotime($month['month'] . '-01')) . "',";
                            }
                        ?>
                    ],
                    datasets: [{
                        label: 'Reports',
                        data: [
                            <?php 
                                foreach ($monthly_reports as $month) {
                                    echo $month['count'] . ',';
                                }
                            ?>
                        ],
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 2,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>