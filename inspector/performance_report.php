<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector role
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$pdo = (new Database())->getConnection();
$inspector_id = $_SESSION['user_id'];

// Handle export requests
if (isset($_GET['export'])) {
    $period = $_GET['period'] ?? 'month';
    $format = $_GET['format'] ?? 'excel';
    
    // Get data based on period
    $where_clause = "";
    $period_label = "";
    
    switch($period) {
        case 'day':
            $where_clause = "AND DATE(i.created_at) = CURDATE()";
            $period_label = "Today (" . date('Y-m-d') . ")";
            break;
        case 'week':
            $where_clause = "AND YEARWEEK(i.created_at) = YEARWEEK(NOW())";
            $period_label = "This Week (" . date('Y-m-d', strtotime('monday this week')) . " to " . date('Y-m-d', strtotime('sunday this week')) . ")";
            break;
        case 'month':
        default:
            $where_clause = "AND YEAR(i.created_at) = YEAR(NOW()) AND MONTH(i.created_at) = MONTH(NOW())";
            $period_label = "This Month (" . date('F Y') . ")";
            break;
    }
    
    $query = "
        SELECT 
            i.id as finding_id,
            i.report_id,
            i.severity,
            i.notes as findings,
            i.created_at as inspection_date,
            r.hazard_type,
            r.description,
            r.address,
            r.status as report_status,
            u.fullname as reporter_name,
            ri.assigned_at
        FROM inspection_findings i
        INNER JOIN reports r ON i.report_id = r.id
        INNER JOIN users u ON r.user_id = u.id
        INNER JOIN report_inspectors ri ON r.id = ri.report_id AND ri.inspector_id = i.inspector_id
        WHERE i.inspector_id = ? 
          $where_clause
        ORDER BY i.created_at DESC
    ";
    
    $export_data = $pdo->prepare($query);
    $export_data->execute([$inspector_id]);
    $export_data = $export_data->fetchAll();
    
    if ($format === 'excel') {
        // Excel export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="inspector_report_' . $period . '_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th colspan='9' style='text-align:center; font-weight:bold;'>Inspector Performance Report - $period_label</th></tr>";
        echo "<tr><th>Finding ID</th><th>Report ID</th><th>Hazard Type</th><th>Severity</th><th>Findings</th><th>Address</th><th>Assigned Date</th><th>Inspection Date</th></tr>";
        
        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['finding_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['report_id']) . "</td>";
            echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['hazard_type']))) . "</td>";
            echo "<td>" . htmlspecialchars(ucfirst($row['severity'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['findings']) . "</td>";

            echo "<td>" . htmlspecialchars($row['address']) . "</td>";
            echo "<td>" . date('Y-m-d', strtotime($row['assigned_at'])) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['inspection_date'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit();
    } else {
        // CSV export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="inspector_report_' . $period . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Inspector Performance Report - ' . $period_label]);
        fputcsv($output, ['Finding ID', 'Report ID', 'Hazard Type', 'Severity', 'Findings', 'Address', 'Assigned Date', 'Inspection Date']);
        
        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['finding_id'],
                $row['report_id'],
                ucfirst(str_replace('_', ' ', $row['hazard_type'])),
                ucfirst($row['severity']),
                $row['findings'],

                $row['address'],
                date('Y-m-d', strtotime($row['assigned_at'])),
                date('Y-m-d H:i', strtotime($row['inspection_date']))
            ]);
        }
        fclose($output);
        exit();
    }
}

// Get performance data
$today_count = $pdo->prepare("SELECT COUNT(*) FROM inspection_findings WHERE inspector_id = ? AND DATE(created_at) = CURDATE()");
$today_count->execute([$inspector_id]);
$today_count = $today_count->fetchColumn();

$week_count = $pdo->prepare("SELECT COUNT(*) FROM inspection_findings WHERE inspector_id = ? AND YEARWEEK(created_at) = YEARWEEK(NOW())");
$week_count->execute([$inspector_id]);
$week_count = $week_count->fetchColumn();

$month_count = $pdo->prepare("SELECT COUNT(*) FROM inspection_findings WHERE inspector_id = ? AND YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())");
$month_count->execute([$inspector_id]);
$month_count = $month_count->fetchColumn();

// Get severity breakdown for current month
$severity_data = $pdo->prepare("SELECT severity, COUNT(*) as count FROM inspection_findings WHERE inspector_id = ? AND YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW()) GROUP BY severity");
$severity_data->execute([$inspector_id]);
$severity_data = $severity_data->fetchAll();

$minor_count = 0;
$major_count = 0;
foreach ($severity_data as $data) {
    if ($data['severity'] === 'minor') {
        $minor_count = $data['count'];
    } elseif ($data['severity'] === 'major') {
        $major_count = $data['count'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Report - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            <div class="flex items-center justify-between px-4 py-4 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold text-lgu-headline truncate">Performance Report</h1>
                        <p class="text-sm text-lgu-paragraph truncate">Generate inspection performance reports</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 flex-shrink-0">
                    <div class="w-12 h-12 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-lg shadow-md">
                        <i class="fa fa-user"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-6 overflow-y-auto">
            
            <!-- Performance Statistics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="bg-white rounded-lg shadow-md p-5 border-l-4 border-green-500">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Today</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $today_count; ?></p>
                        </div>
                        <div class="text-4xl text-green-500 opacity-30">
                            <i class="fa fa-calendar-day"></i>
                        </div>
                    </div>
                    <p class="text-xs text-lgu-paragraph">Inspections completed</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-5 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">This Week</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $week_count; ?></p>
                        </div>
                        <div class="text-4xl text-blue-500 opacity-30">
                            <i class="fa fa-calendar-week"></i>
                        </div>
                    </div>
                    <p class="text-xs text-lgu-paragraph">Inspections completed</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-5 border-l-4 border-purple-500">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">This Month</p>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $month_count; ?></p>
                        </div>
                        <div class="text-4xl text-purple-500 opacity-30">
                            <i class="fa fa-calendar-alt"></i>
                        </div>
                    </div>
                    <p class="text-xs text-lgu-paragraph">Inspections completed</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-5 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Minor Severity</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $minor_count; ?></p>
                        </div>
                        <div class="text-4xl text-orange-500 opacity-30">
                            <i class="fa fa-exclamation-circle"></i>
                        </div>
                    </div>
                    <p class="text-xs text-lgu-paragraph">This month</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-5 border-l-4 border-red-500">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Major Severity</p>
                            <p class="text-3xl font-bold text-red-600"><?php echo $major_count; ?></p>
                        </div>
                        <div class="text-4xl text-red-500 opacity-30">
                            <i class="fa fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <p class="text-xs text-lgu-paragraph">This month</p>
                </div>
            </div>

            <!-- Export Section -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-6 py-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fa fa-download mr-3"></i>
                        Export Performance Report
                    </h2>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Period Selection -->
                        <div>
                            <h3 class="text-lg font-semibold text-lgu-headline mb-4">Select Time Period</h3>
                            <div class="space-y-3">
                                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="day">
                                    <input type="radio" name="period" value="day" class="mr-3" id="period-day">
                                    <label for="period-day" class="flex-1 cursor-pointer">
                                        <div class="font-medium text-lgu-headline">Today</div>
                                        <div class="text-sm text-gray-500"><?php echo date('F j, Y'); ?> (<?php echo $today_count; ?> inspections)</div>
                                    </label>
                                </div>
                                
                                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="week">
                                    <input type="radio" name="period" value="week" class="mr-3" id="period-week" checked>
                                    <label for="period-week" class="flex-1 cursor-pointer">
                                        <div class="font-medium text-lgu-headline">This Week</div>
                                        <div class="text-sm text-gray-500"><?php echo date('M j', strtotime('monday this week')); ?> - <?php echo date('M j, Y', strtotime('sunday this week')); ?> (<?php echo $week_count; ?> inspections)</div>
                                    </label>
                                </div>
                                
                                <div class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer period-option" data-period="month">
                                    <input type="radio" name="period" value="month" class="mr-3" id="period-month">
                                    <label for="period-month" class="flex-1 cursor-pointer">
                                        <div class="font-medium text-lgu-headline">This Month</div>
                                        <div class="text-sm text-gray-500"><?php echo date('F Y'); ?> (<?php echo $month_count; ?> inspections)</div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Export Options -->
                        <div>
                            <h3 class="text-lg font-semibold text-lgu-headline mb-4">Export Format</h3>
                            <div class="space-y-4">
                                <button onclick="exportReport('csv')" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
                                    <i class="fa fa-file-csv mr-2"></i>
                                    Export as CSV
                                </button>
                                
                                <button onclick="exportReport('excel')" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center transition">
                                    <i class="fa fa-file-excel mr-2"></i>
                                    Export as Excel
                                </button>
                            </div>
                            
                            <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                <h4 class="font-medium text-gray-700 mb-2">Report includes:</h4>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li>• Finding and Report IDs</li>
                                    <li>• Hazard Types and Severity</li>
                                    <li>• Inspection Findings</li>
                
                                    <li>• Locations and Addresses</li>
                                    <li>• Assignment and Inspection Dates</li>
                                </ul>
                                <p class="text-xs text-gray-500 mt-2">CSV files can be opened in Excel or Google Sheets</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-6 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM - Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
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

            // Period selection
            document.querySelectorAll('.period-option').forEach(option => {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                });
            });
        });

        function exportReport(format) {
            const selectedPeriod = document.querySelector('input[name="period"]:checked').value;
            const periodText = document.querySelector(`input[name="period"][value="${selectedPeriod}"]`).nextElementSibling.querySelector('div').textContent;
            
            Swal.fire({
                title: 'Generating Report...',
                text: `Preparing ${format.toUpperCase()} report for ${periodText}`,
                icon: 'info',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true
            }).then(() => {
                window.open(`performance_report.php?export=1&period=${selectedPeriod}&format=${format}`, '_blank');
                
                Swal.fire({
                    title: 'Success!',
                    text: 'Report has been generated and downloaded.',
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false
                });
            });
        }
    </script>

</body>
</html>