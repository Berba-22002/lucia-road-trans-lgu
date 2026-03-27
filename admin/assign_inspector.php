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
$inspectors = [];
$error_message = '';
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;
$total_reports = 0;
$total_pages = 0;

// Get filter and search parameters
$hazard_filter = $_GET['hazard_type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$assignment_filter = $_GET['assignment'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

try {
    // Build filter conditions
    $filter_conditions = ["r.validation_status = 'validated' AND r.status != 'archived'"];
    $filter_params = [];
    
    if ($hazard_filter !== 'all') {
        $filter_conditions[] = "r.hazard_type = ?";
        $filter_params[] = $hazard_filter;
    }
    
    if ($status_filter !== 'all') {
        $filter_conditions[] = "r.status = ?";
        $filter_params[] = $status_filter;
    }
    
    if ($assignment_filter === 'assigned') {
        $filter_conditions[] = "ri.inspector_id IS NOT NULL";
    } elseif ($assignment_filter === 'unassigned') {
        $filter_conditions[] = "ri.inspector_id IS NULL";
    }
    
    if (!empty($search)) {
        $filter_conditions[] = "(r.id LIKE ? OR r.address LIKE ? OR u.fullname LIKE ?)";
        $filter_params[] = "%$search%";
        $filter_params[] = "%$search%";
        $filter_params[] = "%$search%";
    }
    
    $filter_sql = implode(' AND ', $filter_conditions);
    
    // Build sort clause
    $sort_sql = match($sort) {
        'oldest' => 'r.created_at ASC',
        'hazard' => 'r.hazard_type ASC',
        'reporter' => 'u.fullname ASC',
        'status' => 'r.status ASC',
        default => 'r.created_at DESC'
    };
    
    // Get total count
    $count_query = "SELECT COUNT(DISTINCT r.id) as total FROM reports r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN report_inspectors ri ON r.id = ri.report_id AND ri.status = 'assigned' WHERE $filter_sql";
    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($filter_params);
    $total_reports = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_reports / $items_per_page);
    
    // Get paginated validated reports
    $query = "
        SELECT DISTINCT
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
            ri.inspector_id,
            ri.assigned_at,
            ui.fullname AS assigned_inspector_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN report_inspectors ri ON r.id = ri.report_id AND ri.status = 'assigned'
        LEFT JOIN users ui ON ri.inspector_id = ui.id
        WHERE $filter_sql
        ORDER BY $sort_sql
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($query);
    $filter_params[] = $items_per_page;
    $filter_params[] = $offset;
    $stmt->execute($filter_params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total inspector count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'inspector' AND status = 'active'");
    $count_stmt->execute();
    $total_inspectors = $count_stmt->fetchColumn();
    // Get all inspectors for dropdown (no pagination)
    $all_inspectors_stmt = $pdo->prepare("
        SELECT id, fullname 
        FROM users 
        WHERE role = 'inspector' 
        AND status = 'active'
        ORDER BY fullname
    ");
    $all_inspectors_stmt->execute();
    $all_inspectors = $all_inspectors_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching data: " . $e->getMessage();
}

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_inspector'])) {
    
    $report_id = (int)$_POST['report_id'];
    $inspector_id = (int)$_POST['inspector_id'];
    
    error_log("Assignment attempt - Report: $report_id, Inspector: $inspector_id");
    
    if ($report_id <= 0 || $inspector_id <= 0) {
        $error_message = "Invalid report or inspector ID";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if report exists and is validated
            $check_report_stmt = $pdo->prepare("SELECT id FROM reports WHERE id = ? AND validation_status = 'validated' AND status != 'archived'");
            $check_report_stmt->execute([$report_id]);
            
            if (!$check_report_stmt->fetch()) {
                throw new Exception("Report not found or not validated");
            }
            
            // Check if inspector exists and is active
            $check_inspector_stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'inspector' AND status = 'active'");
            $check_inspector_stmt->execute([$inspector_id]);
            
            if (!$check_inspector_stmt->fetch()) {
                throw new Exception("Inspector not found or not active");
            }
            
            // Check if already assigned to ANY inspector
            $check_existing_stmt = $pdo->prepare("SELECT id FROM report_inspectors WHERE report_id = ? AND status = 'assigned'");
            $check_existing_stmt->execute([$report_id]);
            
            if ($check_existing_stmt->fetch()) {
                $_SESSION['success_message'] = "This report is already assigned to an inspector!";
            } else {
                // Create new assignment
                $insert_stmt = $pdo->prepare("
                    INSERT INTO report_inspectors (report_id, inspector_id, assigned_at, status) 
                    VALUES (?, ?, NOW(), 'assigned')
                ");
                $insert_result = $insert_stmt->execute([$report_id, $inspector_id]);
                
                if (!$insert_result) {
                    throw new Exception("Failed to assign inspector");
                }
                
                // Update report status to in_progress if it's pending
                $update_report_stmt = $pdo->prepare("
                    UPDATE reports 
                    SET status = 'in_progress' 
                    WHERE id = ? AND status = 'pending'
                ");
                $update_report_stmt->execute([$report_id]);
                
                // Send notification to assigned inspector
                $report_stmt = $pdo->prepare("SELECT hazard_type, address FROM reports WHERE id = ?");
                $report_stmt->execute([$report_id]);
                $report_data = $report_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($report_data) {
                    $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $notification_stmt->execute([
                        $inspector_id,
                        'New Report Assignment',
                        'You have been assigned to inspect a ' . ucfirst($report_data['hazard_type']) . ' report #' . $report_id . ' at ' . $report_data['address'],
                        'info'
                    ]);
                }
                
                $_SESSION['success_message'] = "Inspector assigned successfully!";
            }
            
            $pdo->commit();
            header("Location: assign_inspector.php");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            // Check if it's a unique constraint violation
            if ($e->getCode() == '23000') {
                $error_message = "This report already has an active assignment. Please cancel the existing assignment first.";
            } else {
                $error_message = "Database error: " . $e->getMessage();
            }
            error_log("Assignment error: " . $e->getMessage());
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = $e->getMessage();
            error_log("Assignment error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Inspector - RTIM Admin</title>
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
        .table-row-hover:hover { background-color: #f9fafb; transform: scale(1.001); transition: all 0.2s ease; }
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-in_progress { border-left: 4px solid #3b82f6; }
        .status-done { border-left: 4px solid #10b981; }
        .status-escalated { border-left: 4px solid #ef4444; }
        .assigned-badge { background: linear-gradient(135deg, #10b981, #059669); }
        .unassigned-badge { background: linear-gradient(135deg, #f59e0b, #d97706); }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Assign Inspector</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Assign validated reports to inspectors | <span id="live-time"></span></p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $total_reports; ?></div>
                    <div class="text-xs">Validated Reports</div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: '<?php echo addslashes($_SESSION['success_message']); ?>',
                            confirmButtonColor: '#faae2b',
                            timer: 3000,
                            timerProgressBar: true
                        });
                    });
                </script>
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Validated</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo count($reports); ?></p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Unassigned</p>
                            <p class="text-2xl font-bold text-orange-600">
                                <?php 
                                $unassigned = count(array_filter($reports, function($r) { 
                                    return empty($r['inspector_id']); 
                                }));
                                echo $unassigned;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-user-clock text-3xl text-orange-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Assigned</p>
                            <p class="text-2xl font-bold text-blue-600">
                                <?php 
                                $assigned = count(array_filter($reports, function($r) { 
                                    return !empty($r['inspector_id']); 
                                }));
                                echo $assigned;
                                ?>
                            </p>
                        </div>
                        <i class="fa fa-user-check text-3xl text-blue-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-purple-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Available Inspectors</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo $total_inspectors; ?></p>
                        </div>
                        <i class="fa fa-users text-3xl text-purple-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Search</label>
                            <input type="text" name="search" placeholder="Report ID, Address, Reporter..." 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                        </div>
                        
                        <!-- Hazard Type Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Hazard Type</label>
                            <select name="hazard_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="all">All Types</option>
                                <option value="road" <?php echo $hazard_filter === 'road' ? 'selected' : ''; ?>>Road</option>
                                <option value="bridge" <?php echo $hazard_filter === 'bridge' ? 'selected' : ''; ?>>Bridge</option>
                                <option value="traffic" <?php echo $hazard_filter === 'traffic' ? 'selected' : ''; ?>>Traffic</option>
                            </select>
                        </div>
                        
                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Status</label>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="all">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="done" <?php echo $status_filter === 'done' ? 'selected' : ''; ?>>Done</option>
                                <option value="escalated" <?php echo $status_filter === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                            </select>
                        </div>
                        
                        <!-- Assignment Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Assignment</label>
                            <select name="assignment" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="all" <?php echo $assignment_filter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="assigned" <?php echo $assignment_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="unassigned" <?php echo $assignment_filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            </select>
                        </div>
                        
                        <!-- Sort -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Sort By</label>
                            <select name="sort" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="hazard" <?php echo $sort === 'hazard' ? 'selected' : ''; ?>>Hazard Type</option>
                                <option value="reporter" <?php echo $sort === 'reporter' ? 'selected' : ''; ?>>Reporter Name</option>
                                <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex gap-2 justify-end">
                        <button type="submit" class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-4 py-2 rounded-lg font-semibold transition flex items-center gap-2">
                            <i class="fa fa-search"></i>
                            Apply Filters
                        </button>
                        <a href="assign_inspector.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg font-semibold transition flex items-center gap-2">
                            <i class="fa fa-redo"></i>
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Reports Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if (count($reports) === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Validated Reports</p>
                        <p class="text-gray-400 text-sm">All validated reports have been assigned or no reports are validated yet.</p>
                        <a href="reports.php" class="inline-block mt-4 bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition">
                            <i class="fa fa-list mr-2"></i>View All Reports
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Report ID</th>
                                    <th class="px-4 py-3 text-left font-semibold">Hazard Type</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Location</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                    <th class="px-4 py-3 text-left font-semibold">Assignment</th>
                                    <th class="px-4 py-3 text-left font-semibold">Assigned Inspector</th>
                                    <th class="px-4 py-3 text-center font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($reports as $report): 
                                    $status_class = 'status-' . $report['status'];
                                    $is_assigned = !empty($report['inspector_id']);
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
                                        <td class="px-4 py-3 hidden md:table-cell">
                                            <span class="text-xs text-lgu-paragraph">
                                                <i class="fa fa-map-marker-alt mr-1 text-lgu-tertiary"></i>
                                                <?php echo htmlspecialchars(substr($report['address'], 0, 40)) . (strlen($report['address']) > 40 ? '...' : ''); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($report['status'] === 'pending'): ?>
                                                <span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <span class="w-2 h-2 bg-orange-500 rounded-full mr-2"></span>
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
                                                <span class="bg-red-100 text-red-700 px-3py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-exclamation-triangle mr-1"></i>
                                                    Escalated
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($is_assigned): ?>
                                                <span class="assigned-badge text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-check-circle mr-1"></i>
                                                    Assigned
                                                </span>
                                            <?php else: ?>
                                                <span class="unassigned-badge text-white px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                                    <i class="fa fa-clock mr-1"></i>
                                                    Unassigned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($is_assigned): ?>
                                                <div class="flex items-center">
                                                    <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-sm mr-2">
                                                        <?php echo strtoupper(substr($report['assigned_inspector_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($report['assigned_inspector_name']); ?></p>
                                                        <p class="text-xs text-gray-500">
                                                            <?php echo date('D, M d, Y H:i:s', strtotime($report['assigned_at'])); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs italic">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-2 justify-center">
                                                <?php if ($is_assigned): ?>
                                                    <!-- View Button Only -->
                                                    <a href="view_report.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                       class="bg-lgu-headline hover:bg-lgu-stroke text-white px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1"
                                                       title="View Details">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Assignment Form -->
                                                    <form method="POST" class="flex gap-2 items-center">
                                                        <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                                        <select name="inspector_id" class="text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-lgu-button" required>
                                                            <option value="">Select Inspector</option>
                                                            <?php foreach ($all_inspectors as $inspector): ?>
                                                                <option value="<?php echo (int)$inspector['id']; ?>">
                                                                    <?php echo htmlspecialchars($inspector['fullname']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="assign_inspector" 
                                                                class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1">
                                                            <i class="fa fa-user-plus"></i>
                                                            Assign
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- View Button -->
                                                    <a href="view_report.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                       class="bg-lgu-headline hover:bg-lgu-stroke text-white px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1"
                                                       title="View Details">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Summary Section -->
                    <div class="bg-gray-50 px-4 py-4 border-t border-gray-200">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div class="text-sm text-lgu-paragraph">
                                <span class="font-semibold text-lgu-headline"><?php echo $total_reports; ?></span> validated report(s) | 
                                <span class="font-semibold text-orange-600">
                                    <?php 
                                    $unassigned = count(array_filter($reports, function($r) { 
                                        return empty($r['inspector_id']); 
                                    }));
                                    echo $unassigned;
                                    ?>
                                </span> unassigned |
                                <span class="font-semibold text-blue-600">
                                    <?php 
                                    $assigned = count(array_filter($reports, function($r) { 
                                        return !empty($r['inspector_id']); 
                                    }));
                                    echo $assigned;
                                    ?>
                                </span> assigned
                            </div>
                            <div class="flex items-center gap-2 flex-wrap justify-center">
                                <?php if ($total_pages > 1): ?>
                                    <?php 
                                    $query_params = http_build_query([
                                        'search' => $search,
                                        'hazard_type' => $hazard_filter,
                                        'status' => $status_filter,
                                        'assignment' => $assignment_filter,
                                        'sort' => $sort
                                    ]);
                                    ?>
                                    <?php if ($current_page > 1): ?>
                                        <a href="?page=1&<?php echo $query_params; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">First</a>
                                        <a href="?page=<?php echo $current_page - 1; ?>&<?php echo $query_params; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Prev</a>
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
                                            <a href="?page=<?php echo $i; ?>&<?php echo $query_params; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <span class="text-gray-500">...</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="?page=<?php echo $current_page + 1; ?>&<?php echo $query_params; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Next</a>
                                        <a href="?page=<?php echo $total_pages; ?>&<?php echo $query_params; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Last</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
                
            </div>
        </footer>
    </div>

    <script>
        function updateLiveTime() {
            const now = new Date();
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const day = days[now.getDay()];
            const month = months[now.getMonth()];
            const date = now.getDate();
            const year = now.getFullYear();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('live-time').textContent = `${day}, ${month} ${date}, ${year} ${hours}:${minutes}:${seconds}`;
        }
        updateLiveTime();
        setInterval(updateLiveTime, 1000);

        // Mobile sidebar toggle
        const sidebar = document.getElementById('admin-sidebar');
        const toggle = document.getElementById('mobile-sidebar-toggle');
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('-translate-x-full');
                document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
            });
        }

        // Simple form submission
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const inspectorId = this.querySelector('select[name="inspector_id"]').value;
                if (!inspectorId) {
                    e.preventDefault();
                    alert('Please select an inspector');
                    return false;
                }
                
                // Show simple confirmation
                const reportId = this.querySelector('input[name="report_id"]').value;
                const inspectorName = this.querySelector('select[name="inspector_id"]').options[this.querySelector('select[name="inspector_id"]').selectedIndex].text;
                
                if (!confirm(`Assign report #${reportId} to ${inspectorName}?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>

</body>
</html>