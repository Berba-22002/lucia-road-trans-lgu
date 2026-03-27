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

$reports = [];
$total_pending = 0;
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;
$total_reports = 0;
$total_pages = 0;

try {
    // Get total count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM reports WHERE status != 'archived'");
    $count_stmt->execute();
    $total_reports = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_reports / $items_per_page);
    
    // Get paginated reports with reporter info
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
            r.ai_analysis_result,
            u.fullname AS reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status != 'archived'
        ORDER BY 
            CASE 
                WHEN r.status = 'pending' THEN 1
                WHEN r.status = 'in_progress' THEN 2
                WHEN r.status = 'escalated' THEN 3
                WHEN r.status = 'rejected' THEN 4
                WHEN r.status = 'done' THEN 5
                ELSE 6
            END,
            r.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all reports for pending count
    $all_stmt = $pdo->prepare("SELECT status, validation_status FROM reports WHERE status != 'archived'");
    $all_stmt->execute();
    $all_reports = $all_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pending = count(array_filter($all_reports, function($r) { 
        return $r['status'] === 'pending' && $r['validation_status'] === 'pending'; 
    }));

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching reports: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Management - RTIM Admin</title>
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
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        
        .table-row-hover:hover {
            background-color: #f9fafb;
            transform: scale(1.001);
            transition: all 0.2s ease;
        }
        
        @keyframes pulse-soft {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        .pending-badge {
            animation: pulse-soft 2s ease-in-out infinite;
        }

        .swal2-popup {
            font-family: 'Poppins', sans-serif !important;
        }

        .status-pending { border-left: 4px solid #f59e0b; }
        .status-in_progress { border-left: 4px solid #3b82f6; }
        .status-done { border-left: 4px solid #10b981; }
        .status-escalated { border-left: 4px solid #ef4444; }
        .status-rejected { border-left: 4px solid #dc2626; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Include Sidebar -->
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Reports Management</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage all hazard reports and validations</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-tertiary to-red-600 text-white px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl" id="pending-count"><?php echo $total_pending; ?></div>
                    <div class="text-xs">Pending Validation</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-exclamation-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Error Loading Reports</p>
                        <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter & Search Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex flex-col gap-4">
                    <!-- Search Bar -->
                    <div class="flex flex-col sm:flex-row gap-2 w-full">
                        <input type="text" id="searchInput" placeholder="Search by ID, reporter, or location..." 
                               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                        <button id="clearSearch" class="bg-gray-400 text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-500 transition flex-shrink-0">
                            <i class="fa fa-times"></i>
                            <span class="hidden sm:inline ml-2">Clear</span>
                        </button>
                    </div>
                    
                    <!-- Filters & Sort -->
                    <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
                        <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                            <select id="filterValidation" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                                <option value="">All Validations</option>
                                <option value="pending">Pending</option>
                                <option value="validated">Validated</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            
                            <select id="filterStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="done">Completed</option>
                                <option value="escalated">Escalated</option>
                            </select>
                            
                            <select id="filterHazard" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                                <option value="">All Hazard Types</option>
                                <option value="pothole">Pothole</option>
                                <option value="flooding">Flooding</option>
                                <option value="landslide">Landslide</option>
                                <option value="debris">Debris</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="flex gap-2 w-full sm:w-auto">
                            <select id="sortBy" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm flex-1 sm:flex-none">
                                <option value="date-desc">Newest First</option>
                                <option value="date-asc">Oldest First</option>
                                <option value="id-asc">ID (Low to High)</option>
                                <option value="id-desc">ID (High to Low)</option>
                            </select>
                            
                            <button id="resetFilters" class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition flex-shrink-0">
                                <i class="fa fa-redo"></i>
                                <span class="hidden sm:inline ml-2">Reset</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Navigation Links -->
                <div class="flex gap-2 mt-4 pt-4 border-t border-gray-200">
                    <a href="archives.php" class="text-lgu-paragraph hover:text-lgu-headline transition whitespace-nowrap bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg text-sm">
                        <i class="fa fa-archive mr-1"></i> Archives
                    </a>
                    <a href="admin-dashboard.php" class="text-lgu-paragraph hover:text-lgu-headline transition whitespace-nowrap bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg text-sm">
                        <i class="fa fa-arrow-left mr-1"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- Reports Table - Responsive -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if (count($reports) === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Reports Found</p>
                        <p class="text-gray-400 text-sm">All reports have been archived or no reports submitted yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">ID</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden sm:table-cell">Reporter</th>
                                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Location</th>
                                    <th class="px-4 py-3 text-left font-semibold">Hazard Level</th>
                                    <th class="px-4 py-3 text-left font-semibold">Validation</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Date</th>
                                    <th class="px-4 py-3 text-center font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($reports as $report): 
                                    $status_class = 'status-' . $report['status'];
                                ?>
                                    <tr class="table-row-hover transition <?php echo $status_class; ?>" data-report-id="<?php echo (int)$report['report_id']; ?>" data-validation="<?php echo htmlspecialchars($report['validation_status']); ?>" data-status="<?php echo htmlspecialchars($report['status']); ?>">
                                        <td class="px-4 py-3 font-bold text-lgu-headline">
                                            #<?php echo htmlspecialchars($report['report_id']); ?>
                                        </td>
                                        <td class="px-4 py-3 hidden sm:table-cell">
                                            <div>
                                                <p class="font-medium text-lgu-headline"><?php echo htmlspecialchars($report['reporter_name'] ?? 'Unknown'); ?></p>
                                                <p class="text-xs text-gray-500"><i class="fa fa-phone mr-1"></i><?php echo htmlspecialchars($report['phone'] ?? 'N/A'); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 hidden md:table-cell">
                                            <span class="text-xs text-lgu-paragraph"><i class="fa fa-map-marker-alt mr-1 text-lgu-tertiary"></i><?php echo htmlspecialchars(substr($report['address'], 0, 40)) . (strlen($report['address']) > 40 ? '...' : ''); ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php 
                                            $ai_data = json_decode($report['ai_analysis_result'] ?? '{}', true);
                                            $hazard_level = $ai_data['hazardLevel'] ?? 'base';
                                            $level_colors = ['high' => 'bg-red-100 text-red-700', 'medium' => 'bg-yellow-100 text-yellow-700', 'low' => 'bg-blue-100 text-blue-700', 'base' => 'bg-gray-100 text-gray-700'];
                                            $color_class = $level_colors[$hazard_level] ?? 'bg-gray-100 text-gray-700';
                                            ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $color_class; ?>"><?php echo ucfirst($hazard_level); ?></span>
                                        </td>
                                        <td class="px-4 py-3 validation-cell">
                                            <?php 
                                            $validation_status = $report['validation_status'] ?? 'pending';
                                            if ($validation_status === 'validated'): ?>
                                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-check-circle mr-1"></i>
                                                    Validated
                                                </span>
                                            <?php elseif ($validation_status === 'rejected'): ?>
                                                <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-times-circle mr-1"></i>
                                                    Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-clock mr-1"></i>
                                                    Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 status-cell">
                                            <?php if ($report['status'] === 'pending'): ?>
                                                <span class="pending-badge bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <span class="w-2 h-2 bg-orange-500 rounded-full mr-2 animate-pulse"></span>
                                                    Pending
                                                </span>
                                            <?php elseif ($report['status'] === 'in_progress'): ?>
                                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-spinner mr-1 fa-spin"></i>
                                                    In Progress
                                                </span>
                                            <?php elseif ($report['status'] === 'done'): ?>
                                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-check-circle mr-1"></i>
                                                    Completed
                                                </span>
                                            <?php elseif ($report['status'] === 'escalated'): ?>
                                                <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-exclamation-triangle mr-1"></i>
                                                    Escalated
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                                            <i class="fa fa-calendar mr-1"></i>
                                            <?php echo date('M d, Y', strtotime($report['created_at'])); ?>
                                            <br>
                                            <i class="fa fa-clock mr-1"></i>
                                            <?php echo date('h:i A', strtotime($report['created_at'])); ?>
                                        </td>
                                        <td class="px-4 py-3 action-cell">
                                            <div class="flex gap-1 justify-center items-center">
                                                <?php if ($report['validation_status'] === 'pending'): ?>
                                                    <!-- Pending Validation: Show Approve and Reject Buttons -->
                                                    <button onclick="approveReport(<?php echo (int)$report['report_id']; ?>)" 
                                                       class="approve-btn bg-green-600 hover:bg-green-700 text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1"
                                                       title="Approve Report">
                                                        <i class="fa fa-check text-xs"></i>
                                                        <span class="hidden lg:inline">Approve</span>
                                                    </button>
                                                    <button onclick="rejectReport(<?php echo (int)$report['report_id']; ?>)" 
                                                       class="reject-btn bg-red-600 hover:bg-red-700 text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1"
                                                       title="Reject Report">
                                                        <i class="fa fa-times text-xs"></i>
                                                        <span class="hidden lg:inline">Reject</span>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- Show Archive button if (status is 'done' AND validation_status is 'validated') OR validation_status is 'rejected' -->
                                                <?php if (($report['status'] === 'done' && $report['validation_status'] === 'validated') || $report['validation_status'] === 'rejected'): ?>
                                                    <button onclick="archiveReport(<?php echo (int)$report['report_id']; ?>)" 
                                                       class="archive-btn bg-gray-600 hover:bg-gray-700 text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1"
                                                       title="Archive Report">
                                                        <i class="fa fa-archive text-xs"></i>
                                                        <span class="hidden lg:inline">Archive</span>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- All Reports: Show View Button -->
                                                <a href="view_report.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                   class="bg-lgu-headline hover:bg-lgu-stroke text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1"
                                                   title="View Details">
                                                    <i class="fa fa-eye text-xs"></i>
                                                    <span class="hidden lg:inline">View</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="bg-gray-50 px-4 py-4 border-t border-gray-200">
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                            <div class="text-sm text-lgu-paragraph">
                                <span class="font-semibold text-lgu-headline" id="total-reports"><?php echo $total_reports; ?></span> active report(s) | 
                                <span class="font-semibold text-orange-600" id="pending-reports"><?php echo $total_pending; ?></span> pending validation |
                                <span class="font-semibold text-green-600" id="validated-reports">
                                    <?php 
                                    $validated = count(array_filter($all_reports, function($r) { 
                                        return $r['validation_status'] === 'validated'; 
                                    }));
                                    echo $validated;
                                    ?>
                                </span> validated
                            </div>
                            <div class="flex items-center gap-2 flex-wrap justify-center">
                                <?php if ($total_pages > 1): ?>
                                    <?php if ($current_page > 1): ?>
                                        <a href="?page=1" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">First</a>
                                        <a href="?page=<?php echo $current_page - 1; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Prev</a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_pages, $current_page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <span class="text-gray-500">...</span>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <?php if ($i === $current_page): ?>
                                            <span class="px-3 py-1 rounded bg-lgu-button text-lgu-button-text font-bold text-sm"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="?page=<?php echo $i; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <span class="text-gray-500">...</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="?page=<?php echo $current_page + 1; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Next</a>
                                        <a href="?page=<?php echo $total_pages; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Last</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Stats -->
            <?php if (count($reports) > 0): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Active</p>
                            <p class="text-2xl font-bold text-gray-600" id="stat-total"><?php echo count($reports); ?></p>
                        </div>
                        <i class="fa fa-list-alt text-3xl text-gray-400 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Pending Validation</p>
                            <p class="text-2xl font-bold text-orange-600" id="stat-pending"><?php echo $total_pending; ?></p>
                        </div>
                        <i class="fa fa-hourglass-half text-3xl text-orange-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Validated</p>
                            <p class="text-2xl font-bold text-green-600" id="stat-validated">
                                <?php 
                                $validated = count(array_filter($reports, function($r) { 
                                    return $r['validation_status'] === 'validated'; 
                                }));
                                echo $validated;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600" id="stat-inprogress">
                                <?php 
                                $in_progress = count(array_filter($reports, function($r) { 
                                    return $r['status'] === 'in_progress'; 
                                }));
                                echo $in_progress;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-spinner text-3xl text-blue-500 opacity-50 fa-spin"></i>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.getElementById('mobile-sidebar-toggle');
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
                });
            }

            // Filter and search functionality
            const searchInput = document.getElementById('searchInput');
            const filterValidation = document.getElementById('filterValidation');
            const filterStatus = document.getElementById('filterStatus');
            const filterHazard = document.getElementById('filterHazard');
            const sortBy = document.getElementById('sortBy');
            const clearSearch = document.getElementById('clearSearch');
            const resetFilters = document.getElementById('resetFilters');

            function applyFiltersAndSort() {
                const searchTerm = searchInput.value.toLowerCase();
                const validationFilter = filterValidation.value;
                const statusFilter = filterStatus.value;
                const hazardFilter = filterHazard.value;
                const sortValue = sortBy.value;
                
                const rows = Array.from(document.querySelectorAll('tbody tr'));
                let visibleRows = [];
                
                // Apply filters
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const validation = row.getAttribute('data-validation') || '';
                    const status = row.getAttribute('data-status') || '';
                    const hazardType = row.querySelector('span.bg-blue-100')?.textContent.toLowerCase() || '';
                    
                    const matchesSearch = text.includes(searchTerm);
                    const matchesValidation = !validationFilter || validation === validationFilter;
                    const matchesStatus = !statusFilter || status === statusFilter;
                    const matchesHazard = !hazardFilter || hazardType.includes(hazardFilter);
                    
                    if (matchesSearch && matchesValidation && matchesStatus && matchesHazard) {
                        row.style.display = '';
                        visibleRows.push(row);
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Apply sorting
                visibleRows.sort((a, b) => {
                    let aVal, bVal;
                    
                    if (sortValue.startsWith('date')) {
                        aVal = new Date(a.querySelector('td:nth-child(7)')?.textContent || 0);
                        bVal = new Date(b.querySelector('td:nth-child(7)')?.textContent || 0);
                        return sortValue === 'date-desc' ? bVal - aVal : aVal - bVal;
                    } else if (sortValue.startsWith('id')) {
                        aVal = parseInt(a.getAttribute('data-report-id')) || 0;
                        bVal = parseInt(b.getAttribute('data-report-id')) || 0;
                        return sortValue === 'id-desc' ? bVal - aVal : aVal - bVal;
                    }
                    return 0;
                });
                
                // Reorder rows in DOM
                const tbody = document.querySelector('tbody');
                visibleRows.forEach(row => tbody.appendChild(row));
                
                // Update visible count
                const totalReports = document.getElementById('total-reports');
                if (totalReports) {
                    totalReports.textContent = visibleRows.length;
                }
            }
            
            // Event listeners
            if (searchInput) {
                searchInput.addEventListener('keyup', applyFiltersAndSort);
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        applyFiltersAndSort();
                    }
                });
            }
            
            if (filterValidation) filterValidation.addEventListener('change', applyFiltersAndSort);
            if (filterStatus) filterStatus.addEventListener('change', applyFiltersAndSort);
            if (filterHazard) filterHazard.addEventListener('change', applyFiltersAndSort);
            if (sortBy) sortBy.addEventListener('change', applyFiltersAndSort);
            
            if (clearSearch) {
                clearSearch.addEventListener('click', function() {
                    searchInput.value = '';
                    applyFiltersAndSort();
                });
            }
            
            if (resetFilters) {
                resetFilters.addEventListener('click', function() {
                    searchInput.value = '';
                    filterValidation.value = '';
                    filterStatus.value = '';
                    filterHazard.value = '';
                    sortBy.value = 'date-desc';
                    applyFiltersAndSort();
                });
            }
        });

        // Function to handle archive button click
        async function archiveReport(reportId) {
            const result = await Swal.fire({
                title: 'Archive Report #' + reportId,
                text: 'This will mark the report as archived. You can view archived reports in the archive section.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fa fa-archive"></i> Archive',
                cancelButtonText: '<i class="fa fa-times"></i> Cancel',
                confirmButtonColor: '#6b7280',
                cancelButtonColor: '#ef4444',
                customClass: {
                    popup: 'font-poppins'
                }
            });

            if (result.isConfirmed) {
                try {
                    const response = await fetch('process_archive.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `report_id=${reportId}&action=archive`
                    });

                    const result = await response.json();

                    if (result.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Report Archived!',
                            text: 'The report has been archived successfully.',
                            confirmButtonColor: '#10b981',
                            timer: 2000,
                            timerProgressBar: true
                        });
                        // Remove the row from the table
                        const row = document.querySelector(`tr[data-report-id="${reportId}"]`);
                        if (row) {
                            row.style.opacity = '0';
                            setTimeout(() => {
                                row.remove();
                                updateStats();
                            }, 300);
                        }
                    } else {
                        throw new Error(result.message || 'Failed to archive report');
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'An error occurred while archiving the report',
                        confirmButtonColor: '#ef4444'
                    });
                }
            }
        }

        // Function to handle validate button click with SweetAlert
        async function validateReport(reportId) {
            const { value: action } = await Swal.fire({
                title: 'Validate Report #' + reportId,
                text: 'Choose an action for this hazard report',
                icon: 'question',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: '<i class="fa fa-check"></i> Approve',
                denyButtonText: '<i class="fa fa-times"></i> Reject',
                cancelButtonText: '<i class="fa fa-ban"></i> Cancel',
                confirmButtonColor: '#10b981',
                denyButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                customClass: {
                    popup: 'font-poppins',
                    confirmButton: 'font-semibold',
                    denyButton: 'font-semibold',
                    cancelButton: 'font-semibold'
                }
            });

            if (action === true) {
                await approveReport(reportId);
            } else if (action === false) {
                await rejectReport(reportId);
            }
        }

        async function approveReport(reportId) {
            Swal.fire({
                title: 'Processing...',
                text: 'Approving report',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const response = await fetch('process_validation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `report_id=${reportId}&action=approve`
                });

                const result = await response.json();

                if (result.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Report Approved!',
                        text: 'The hazard report has been validated and approved.',
                        confirmButtonColor: '#10b981',
                        timer: 2000,
                        timerProgressBar: true
                    });
                    updateReportRow(reportId, 'validated');
                    updateStats();
                } else {
                    throw new Error(result.message || 'Failed to approve report');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message || 'An error occurred while approving the report',
                    confirmButtonColor: '#ef4444'
                });
            }
        }

        async function rejectReport(reportId) {
            const { value: reason } = await Swal.fire({
                title: 'Reject Report #' + reportId,
                text: 'Please provide a reason for rejection',
                input: 'textarea',
                inputPlaceholder: 'Enter rejection reason...',
                inputAttributes: {
                    'aria-label': 'Enter rejection reason'
                },
                showCancelButton: true,
                confirmButtonText: '<i class="fa fa-paper-plane"></i> Submit',
                cancelButtonText: '<i class="fa fa-times"></i> Cancel',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                inputValidator: (value) => {
                    if (!value) {
                        return 'You must provide a reason for rejection!';
                    }
                }
            });

            if (reason) {
                Swal.fire({
                    title: 'Processing...',
                    text: 'Rejecting report',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });

                try {
                    const response = await fetch('process_validation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `report_id=${reportId}&action=reject&reason=${encodeURIComponent(reason)}`
                    });

                    const result = await response.json();

                    if (result.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Report Rejected',
                            text: 'The report has been rejected successfully.',
                            confirmButtonColor: '#ef4444',
                            timer: 2000,
                            timerProgressBar: true
                        });
                        updateReportRow(reportId, 'rejected');
                        updateStats();
                    } else {
                        throw new Error(result.message || 'Failed to reject report');
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'An error occurred while rejecting the report',
                        confirmButtonColor: '#ef4444'
                    });
                }
            }
        }

        // Update report row dynamically after validation status change
        function updateReportRow(reportId, validationStatus) {
            const row = document.querySelector(`tr[data-report-id="${reportId}"]`);
            if (!row) return;

            const validationCell = row.querySelector('.validation-cell');
            const actionCell = row.querySelector('.action-cell');
            const statusCell = row.querySelector('.status-cell');

            if (validationStatus === 'validated') {
                // Update validation status badge to green with checkmark
                validationCell.innerHTML = '<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center"><i class="fa fa-check-circle mr-1"></i>Validated</span>';
                
                // Remove approve and reject buttons
                const approveButton = actionCell.querySelector('.approve-btn');
                const rejectButton = actionCell.querySelector('.reject-btn');
                if (approveButton) approveButton.remove();
                if (rejectButton) rejectButton.remove();
                
                // Update data attribute
                row.setAttribute('data-validation', 'validated');
                
                // Check if status is 'done', then add archive button
                const statusText = statusCell.textContent.toLowerCase();
                if (statusText.includes('completed') || statusText.includes('done')) {
                    // Add archive button if not already present
                    if (!actionCell.querySelector('.archive-btn')) {
                        const viewButton = actionCell.querySelector('a');
                        const archiveButton = document.createElement('button');
                        archiveButton.onclick = () => archiveReport(reportId);
                        archiveButton.className = 'archive-btn bg-gray-600 hover:bg-gray-700 text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1';
                        archiveButton.title = 'Archive Report';
                        archiveButton.innerHTML = '<i class="fa fa-archive text-xs"></i><span class="hidden lg:inline">Archive</span>';
                        if (viewButton && viewButton.parentNode === actionCell) {
                            actionCell.insertBefore(archiveButton, viewButton);
                        } else {
                            actionCell.appendChild(archiveButton);
                        }
                    }
                }
                
                // KEEP THE ROW VISIBLE - Do NOT remove it
                row.style.opacity = '1';
                row.style.display = '';
                
            } else if (validationStatus === 'rejected') {
                // Update validation status badge to red with X
                validationCell.innerHTML = '<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center"><i class="fa fa-times-circle mr-1"></i>Rejected</span>';
                
                // Remove approve and reject buttons
                const approveButton = actionCell.querySelector('.approve-btn');
                const rejectButton = actionCell.querySelector('.reject-btn');
                if (approveButton) approveButton.remove();
                if (rejectButton) rejectButton.remove();
                
                // Add archive button for rejected reports
                if (!actionCell.querySelector('.archive-btn')) {
                    const viewButton = actionCell.querySelector('a');
                    const archiveButton = document.createElement('button');
                    archiveButton.onclick = () => archiveReport(reportId);
                    archiveButton.className = 'archive-btn bg-gray-600 hover:bg-gray-700 text-white px-2 py-1.5 rounded text-xs font-bold transition shadow-sm hover:shadow-md flex items-center gap-1';
                    archiveButton.title = 'Archive Report';
                    archiveButton.innerHTML = '<i class="fa fa-archive text-xs"></i><span class="hidden lg:inline">Archive</span>';
                    if (viewButton && viewButton.parentNode === actionCell) {
                        actionCell.insertBefore(archiveButton, viewButton);
                    } else {
                        actionCell.appendChild(archiveButton);
                    }
                }
                
                // Update data attribute
                row.setAttribute('data-validation', 'rejected');
                
                // KEEP THE ROW VISIBLE - Do NOT remove it
                row.style.opacity = '1';
                row.style.display = '';
            }
        }

        // Update statistics dynamically
        function updateStats() {
            const rows = document.querySelectorAll('tbody tr');
            const allRows = Array.from(rows);
            const visibleRows = allRows.filter(row => row.style.display !== 'none');
            
            let totalPending = 0;
            let validated = 0;
            let rejected = 0;
            let inProgress = 0;

            visibleRows.forEach(row => {
                const validationStatus = row.getAttribute('data-validation') || '';
                const status = row.getAttribute('data-status') || '';
                
                if (validationStatus === 'pending' && status === 'pending') {
                    totalPending++;
                }
                if (validationStatus === 'validated') {
                    validated++;
                }
                if (validationStatus === 'rejected') {
                    rejected++;
                }
                if (status === 'in_progress') {
                    inProgress++;
                }
            });

            // Update the header badge
            document.getElementById('pending-count').textContent = totalPending;
            
            // Update summary section
            document.getElementById('total-reports').textContent = visibleRows.length;
            document.getElementById('pending-reports').textContent = totalPending;
            document.getElementById('validated-reports').textContent = validated;
            
            // Update quick stats
            document.getElementById('stat-total').textContent = visibleRows.length;
            document.getElementById('stat-pending').textContent = totalPending;
            document.getElementById('stat-validated').textContent = validated;
            document.getElementById('stat-inprogress').textContent = inProgress;
        }
    </script>

</body>
</html>