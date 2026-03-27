<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$inspector_id = $_SESSION['user_id'];

// Fetch only MINOR severity reports for this inspector
$reports = [];

try {
    $query = "SELECT r.id, r.hazard_type, r.address, r.description, r.status, r.created_at,
              u.fullname as reporter_name, u.contact_number as reporter_contact,
              f.severity as last_severity, f.notes as findings_notes, f.created_at as findings_date,
              ri.assigned_at
              FROM reports r
              INNER JOIN report_inspectors ri ON r.id = ri.report_id
              INNER JOIN users u ON r.user_id = u.id
              LEFT JOIN inspection_findings f ON r.id = f.report_id 
                AND f.created_at = (SELECT MAX(created_at) FROM inspection_findings WHERE report_id = r.id)
              WHERE ri.inspector_id = ? 
              AND (f.severity = 'minor' OR f.severity IS NULL)
              ORDER BY r.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$inspector_id]);
    $reports = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch minor reports error: " . $e->getMessage());
    $reports = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minor Reports - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Minor Severity Reports</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">List of minor severity inspection reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-green-500 to-green-600 text-white px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo count($reports); ?></div>
                    <div class="text-xs">Minor Reports</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <!-- Info Banner -->
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <i class="fa fa-info-circle text-green-500 text-lg mt-0.5 mr-3"></i>
                    <div>
                        <h3 class="font-semibold text-green-800 mb-1">Minor Severity Reports</h3>
                        <p class="text-green-700 text-sm">
                            This page shows only reports with <span class="font-bold">minor severity</span>. 
                            These are routine maintenance issues that can be handled without escalation.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Reports Table -->
            <?php if (count($reports) > 0): ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-lgu-headline text-white">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Report ID</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Reporter</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Hazard Type</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Location</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Status</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Severity</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Reported Date</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold">Assigned Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($reports as $report): 
                                $severity = $report['last_severity'] ?? 'not_assessed';
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-sm font-semibold text-lgu-headline">
                                    #<?php echo $report['id']; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-lgu-paragraph">
                                    <div class="font-semibold"><?php echo htmlspecialchars($report['reporter_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($report['reporter_contact']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-sm text-lgu-paragraph">
                                    <?php echo ucfirst($report['hazard_type']); ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-lgu-paragraph">
                                    <?php echo htmlspecialchars($report['address']); ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($report['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            <span class="w-1.5 h-1.5 bg-yellow-500 rounded-full mr-1"></span>
                                            Pending
                                        </span>
                                    <?php elseif ($report['status'] === 'in_progress'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <i class="fa fa-spinner fa-spin mr-1 text-xs"></i>
                                            In Progress
                                        </span>
                                    <?php elseif ($report['status'] === 'done'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fa fa-check-circle mr-1 text-xs"></i>
                                            Completed
                                        </span>
                                    <?php elseif ($report['status'] === 'escalated'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <i class="fa fa-exclamation-triangle mr-1 text-xs"></i>
                                            Escalated
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($severity === 'minor'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fa fa-check-circle mr-1 text-xs"></i>
                                            Minor
                                        </span>
                                    <?php elseif ($severity === 'major'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <i class="fa fa-exclamation-triangle mr-1 text-xs"></i>
                                            Major
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <i class="fa fa-question-circle mr-1 text-xs"></i>
                                            Not Assessed
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-lgu-paragraph">
                                    <?php echo date('M d, Y', strtotime($report['created_at'])); ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-lgu-paragraph">
                                    <?php echo date('M d, Y', strtotime($report['assigned_at'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg shadow-md p-12 text-center">
                <i class="fa fa-clipboard-check text-6xl text-gray-300 mb-4 block"></i>
                <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Minor Reports Assigned</h3>
                <p class="text-lgu-paragraph mb-4">You don't have any minor severity reports assigned to you at the moment.</p>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 max-w-md mx-auto">
                    <i class="fa fa-info-circle text-green-500 text-lg mb-2 block"></i>
                    <p class="text-green-700 text-sm">Minor severity reports will appear here once they are assigned to you and assessed as minor.</p>
                </div>
            </div>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?>RTIM- Road and Transportation Infrastructure Monitoring</p>
                
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
        });
    </script>

</body>
</html>