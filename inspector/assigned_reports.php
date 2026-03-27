<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspectors
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$assigned_reports = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'done' => 0,
    'escalated' => 0
];
$error_message = '';

try {
    // Get all reports assigned to this inspector
    $stmt = $pdo->prepare("
        SELECT 
            r.id AS report_id,
            r.user_id,
            r.hazard_type,
            r.address,
            r.description,
            r.status,
            r.validation_status,
            r.created_at,
            r.image_path,
            r.contact_number AS phone,
            u.fullname AS reporter_name,
            u.contact_number AS reporter_phone,
            ri.assigned_at,
            ri.completed_at,
            ri.notes AS assignment_notes
        FROM reports r
        INNER JOIN report_inspectors ri ON r.id = ri.report_id
        INNER JOIN users u ON r.user_id = u.id
        WHERE ri.inspector_id = ? 
        AND ri.status = 'assigned'
        AND r.status != 'archived'
        ORDER BY 
            CASE 
                WHEN r.status = 'pending' THEN 1
                WHEN r.status = 'in_progress' THEN 2
                WHEN r.status = 'escalated' THEN 3
                ELSE 4
            END,
            ri.assigned_at DESC,
            r.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $assigned_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $stats['total'] = count($assigned_reports);
    foreach ($assigned_reports as $report) {
        if (isset($stats[$report['status']])) {
            $stats[$report['status']]++;
        }
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching assigned reports: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Reports - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        .table-row-hover:hover { 
            background-color: #f9fafb; 
            transform: translateY(-1px);
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-in_progress { border-left: 4px solid #3b82f6; }
        .status-done { border-left: 4px solid #10b981; }
        .status-escalated { border-left: 4px solid #ef4444; }
        
        .badge-pending { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .badge-in_progress { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .badge-done { background: linear-gradient(135deg, #10b981, #059669); }
        .badge-escalated { background: linear-gradient(135deg, #ef4444, #dc2626); }
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">My Assigned Reports</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">View your assigned hazard reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $stats['total']; ?></div>
                    <div class="text-xs">Assigned Reports</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-check-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Success</p>
                        <p class="text-sm"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-exclamation-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Error</p>
                        <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Section -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Assigned</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo $stats['total']; ?></p>
                        </div>
                        <i class="fa fa-clipboard-list text-3xl text-lgu-button opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Pending</p>
                            <p class="text-2xl font-bold text-orange-600"><?php echo $stats['pending']; ?></p>
                        </div>
                        <i class="fa fa-clock text-3xl text-orange-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $stats['in_progress']; ?></p>
                        </div>
                        <i class="fa fa-spinner text-3xl text-blue-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $stats['done']; ?></p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Escalated</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo $stats['escalated']; ?></p>
                        </div>
                        <i class="fa fa-exclamation-triangle text-3xl text-red-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <!-- Reports Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if (count($assigned_reports) === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Assigned Reports</p>
                        <p class="text-gray-400 text-sm">You don't have any assigned reports at the moment.</p>
                        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4 max-w-md mx-auto">
                            <i class="fa fa-info-circle text-blue-500 text-lg mb-2 block"></i>
                            <p class="text-blue-700 text-sm">Reports will appear here once they are assigned to you by an administrator.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Report ID</th>
                                    <th class="px-4 py-3 text-left font-semibold">Hazard Type</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Location</th>
                                    <th class="px-4 py-3 text-left font-semibold">Reporter</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Assigned Date</th>
                                    <th class="px-4 py-3 text-center font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($assigned_reports as $report): 
                                    $status_class = 'status-' . $report['status'];
                                ?>
                                    <tr class="table-row-hover transition <?php echo $status_class; ?>">
                                        <td class="px-4 py-3 font-bold text-lgu-headline">
                                            #<?php echo htmlspecialchars($report['report_id']); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 hidden lg:table-cell">
                                            <span class="text-xs text-lgu-paragraph">
                                                <i class="fa fa-map-marker-alt mr-1 text-lgu-tertiary"></i>
                                                <?php echo htmlspecialchars(substr($report['address'], 0, 30)) . (strlen($report['address']) > 30 ? '...' : ''); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-sm mr-2">
                                                    <?php echo strtoupper(substr($report['reporter_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($report['reporter_name']); ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($report['reporter_phone'] ?? 'N/A'); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($report['status'] === 'pending'): ?>
                                                <span class="badge-pending text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <span class="w-2 h-2 bg-white rounded-full mr-2"></span>
                                                    Pending
                                                </span>
                                            <?php elseif ($report['status'] === 'in_progress'): ?>
                                                <span class="badge-in_progress text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-spinner mr-1 fa-spin"></i>
                                                    In Progress
                                                </span>
                                            <?php elseif ($report['status'] === 'done'): ?>
                                                <span class="badge-done text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-check-circle mr-1"></i>
                                                    Completed
                                                </span>
                                            <?php elseif ($report['status'] === 'escalated'): ?>
                                                <span class="badge-escalated text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-exclamation-triangle mr-1"></i>
                                                    Escalated
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 hidden md:table-cell">
                                            <span class="text-xs text-gray-500">
                                                <?php echo date('M d, Y', strtotime($report['assigned_at'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-2 justify-center">
                                                <!-- View Button -->
                                                <a href="view_report.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                   class="bg-lgu-headline hover:bg-lgu-stroke text-white px-4 py-2 rounded text-xs font-bold transition flex items-center gap-2"
                                                   title="View Details">
                                                    <i class="fa fa-eye"></i>
                                                    <span class="hidden sm:inline">View Report</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Summary Section -->
                    <div class="bg-gray-50 px-4 py-3 border-t border-gray-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                        <div class="text-sm text-lgu-paragraph">
                            <span class="font-semibold text-lgu-headline"><?php echo $stats['total']; ?></span> assigned report(s) | 
                            <span class="font-semibold text-orange-600"><?php echo $stats['pending']; ?></span> pending |
                            <span class="font-semibold text-blue-600"><?php echo $stats['in_progress']; ?></span> in progress |
                            <span class="font-semibold text-green-600"><?php echo $stats['done']; ?></span> completed |
                            <span class="font-semibold text-red-600"><?php echo $stats['escalated']; ?></span> escalated
                        </div>
                        <div class="text-xs text-gray-500 flex items-center gap-2">
                            <i class="fa fa-info-circle"></i>
                            <span>Click "View Report" to see detailed information</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
                
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

            // Show success message with SweetAlert if there's a success message
            <?php if (isset($_SESSION['success_message'])): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: '<?php echo addslashes($_SESSION['success_message']); ?>',
                    confirmButtonColor: '#10b981',
                    timer: 3000,
                    timerProgressBar: true
                });
            <?php endif; ?>
        });
    </script>

</body>
</html>