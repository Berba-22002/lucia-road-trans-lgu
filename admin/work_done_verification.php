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

// Initialize variables
$projects = [];
$total_projects = 0;
$pending_count = 0;
$approved_count = 0;
$under_construction_count = 0;
$completed_count = 0;
$on_hold_count = 0;
$filter_status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    // Get project counts by status
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'under_construction' THEN 1 ELSE 0 END) as under_construction,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) as on_hold
        FROM projects
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_projects = (int)($counts['total'] ?? 0);
    $pending_count = (int)($counts['pending'] ?? 0);
    $approved_count = (int)($counts['approved'] ?? 0);
    $under_construction_count = (int)($counts['under_construction'] ?? 0);
    $completed_count = (int)($counts['completed'] ?? 0);
    $on_hold_count = (int)($counts['on_hold'] ?? 0);

    // Build query for projects
    $query = "
        SELECT 
            p.*,
            r.hazard_type,
            r.address as report_address,
            r.image_path,
            u.fullname as created_by_name
        FROM projects p
        LEFT JOIN reports r ON p.report_id = r.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE 1=1
    ";

    $params = [];

    // Apply status filter
    if ($filter_status !== 'all') {
        $query .= " AND p.status = ?";
        $params[] = $filter_status;
    }

    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR p.location LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $query .= " ORDER BY p.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Projects - RTIM</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Poppins Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
        
        .filter-btn {
            transition: all 0.2s ease;
        }
        
        .filter-btn.active {
            background: #faae2b;
            color: #00473e;
            font-weight: 600;
        }
        
        .table-row {
            transition: background-color 0.2s ease;
        }
        
        .table-row:hover {
            background-color: #f9fafb;
        }
        
        .progress-bar-container {
            height: 6px;
            background: #e5e7eb;
            border-radius: 9999px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Infrastructure Projects</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage all infrastructure projects</p>
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
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Total</p>
                        <p class="text-2xl sm:text-3xl font-bold text-lgu-headline"><?php echo $total_projects; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Pending</p>
                        <p class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo $pending_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Approved</p>
                        <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $approved_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">In Progress</p>
                        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $under_construction_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Completed</p>
                        <p class="text-2xl sm:text-3xl font-bold text-purple-600"><?php echo $completed_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-gray-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">On Hold</p>
                        <p class="text-2xl sm:text-3xl font-bold text-gray-600"><?php echo $on_hold_count; ?></p>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white rounded-lg shadow p-4 mb-6">
                <form method="GET" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <div class="relative">
                            <input 
                                type="text" 
                                name="search" 
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search projects by title, location, or description..." 
                                class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm"
                            />
                            <i class="fa fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-lgu-paragraph"></i>
                        </div>
                    </div>
                    
                    <div class="flex gap-2 flex-wrap">
                        <button type="submit" name="status" value="all" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'all' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            All
                        </button>
                        <button type="submit" name="status" value="pending" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'pending' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Pending
                        </button>
                        <button type="submit" name="status" value="approved" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'approved' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Approved
                        </button>
                        <button type="submit" name="status" value="under_construction" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'under_construction' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            In Progress
                        </button>
                        <button type="submit" name="status" value="completed" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'completed' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Completed
                        </button>
                        <button type="submit" name="status" value="on_hold" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $filter_status === 'on_hold' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            On Hold
                        </button>
                    </div>
                </form>
            </div>

            <!-- Projects Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <?php if (empty($projects)): ?>
                        <div class="p-12 text-center">
                            <i class="fa fa-folder-open text-6xl text-gray-300 mb-4"></i>
                            <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Projects Found</h3>
                            <p class="text-lgu-paragraph">There are no projects matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-lgu-headline text-white">
                                <tr>
                                    <th class="py-4 px-4 font-semibold">ID</th>
                                    <th class="py-4 px-4 font-semibold">Project Title</th>
                                    <th class="py-4 px-4 font-semibold">Status</th>
                                    <th class="py-4 px-4 font-semibold">Budget</th>
                                    <th class="py-4 px-4 font-semibold">Timeline</th>
                                    <th class="py-4 px-4 font-semibold">Created By</th>
                                    <th class="py-4 px-4 font-semibold text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($projects as $project): 
                                    $status = $project['status'] ?? 'pending';
                                    $progress = (int)($project['progress'] ?? 0);
                                    
                                    // Status styling
                                    $statusConfig = [
                                        'pending' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'icon' => 'fa-clock'],
                                        'approved' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-check-circle'],
                                        'under_construction' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'icon' => 'fa-hard-hat'],
                                        'completed' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'icon' => 'fa-trophy'],
                                        'on_hold' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-700', 'icon' => 'fa-pause-circle']
                                    ];
                                    
                                    $config = $statusConfig[$status] ?? $statusConfig['pending'];
                                    
                                    // Progress bar color
                                    $progressColor = 'bg-blue-500';
                                    if ($progress >= 75) $progressColor = 'bg-green-500';
                                    elseif ($progress >= 50) $progressColor = 'bg-yellow-500';
                                    elseif ($progress >= 25) $progressColor = 'bg-orange-500';
                                ?>
                                    <tr class="table-row">
                                        <td class="py-4 px-4 font-bold text-lgu-headline">
                                            #<?php echo str_pad($project['id'], 4, '0', STR_PAD_LEFT); ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="font-semibold text-lgu-headline max-w-xs truncate">
                                                <?php echo htmlspecialchars($project['project_title'] ?? 'Untitled Project'); ?>
                                            </div>
                                            <?php if (!empty($project['hazard_type'])): ?>
                                                <div class="text-xs text-lgu-paragraph mt-1">
                                                    <i class="fa fa-tag text-lgu-button"></i> 
                                                    <?php echo htmlspecialchars(ucfirst($project['hazard_type'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="status-badge <?php echo $config['bg'] . ' ' . $config['text']; ?>">
                                                <i class="fa <?php echo $config['icon']; ?>"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <?php if (!empty($project['estimated_budget'])): ?>
                                                <div class="text-sm">
                                                    <div class="font-semibold text-lgu-headline">₱<?php echo number_format($project['estimated_budget'], 2); ?></div>
                                                    <?php if (!empty($project['actual_cost'])): ?>
                                                        <div class="text-xs text-lgu-paragraph">Actual: ₱<?php echo number_format($project['actual_cost'], 2); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-xs space-y-1">
                                                <?php if (!empty($project['start_date'])): ?>
                                                    <div class="text-lgu-paragraph">
                                                        <i class="fa fa-play text-green-500 mr-1"></i>
                                                        <?php echo date('M d, Y', strtotime($project['start_date'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($project['expected_completion'])): ?>
                                                    <div class="text-lgu-paragraph">
                                                        <i class="fa fa-flag-checkered text-blue-500 mr-1"></i>
                                                        <?php echo date('M d, Y', strtotime($project['expected_completion'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($project['actual_completion'])): ?>
                                                    <div class="font-semibold text-green-600">
                                                        <i class="fa fa-check mr-1"></i>
                                                        <?php echo date('M d, Y', strtotime($project['actual_completion'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-sm text-lgu-paragraph">
                                                <?php echo htmlspecialchars($project['created_by_name'] ?? 'Unknown'); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo date('M d, Y', strtotime($project['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="project_details.php?id=<?php echo $project['id']; ?>" 
                                                   class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-semibold py-2 px-3 rounded-lg text-xs transition"
                                                   title="View Details">
                                                    <i class="fa fa-eye"></i>
                                                </a>
                                                <?php if ($status === 'under_construction'): ?>
                                                    <a href="update_progress.php?id=<?php echo $project['id']; ?>" 
                                                       class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-3 rounded-lg text-xs transition"
                                                       title="Update Progress">
                                                        <i class="fa fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Section -->
            <?php if (!empty($projects)): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <div class="bg-white rounded-lg shadow p-5">
                        <h3 class="text-sm font-semibold text-lgu-paragraph mb-3 uppercase">Budget Summary</h3>
                        <div class="space-y-2">
                            <?php
                                $total_estimated = array_sum(array_column($projects, 'estimated_budget'));
                                $total_actual = array_sum(array_column($projects, 'actual_cost'));
                            ?>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-lgu-paragraph">Estimated:</span>
                                <span class="text-lg font-bold text-lgu-headline">₱<?php echo number_format($total_estimated, 2); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-lgu-paragraph">Actual:</span>
                                <span class="text-lg font-bold text-blue-600">₱<?php echo number_format($total_actual, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-5">
                        <h3 class="text-sm font-semibold text-lgu-paragraph mb-3 uppercase">Average Progress</h3>
                        <div class="text-center">
                            <?php
                                $avg_progress = count($projects) > 0 ? round(array_sum(array_column($projects, 'progress')) / count($projects)) : 0;
                            ?>
                            <div class="text-4xl font-bold text-lgu-headline"><?php echo $avg_progress; ?>%</div>
                            <div class="progress-bar-container w-full mt-3">
                                <div class="progress-bar-fill bg-gradient-to-r from-blue-500 to-green-500" style="width: <?php echo $avg_progress; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-5">
                        <h3 class="text-sm font-semibold text-lgu-paragraph mb-3 uppercase">Quick Stats</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">Active Projects:</span>
                                <span class="font-bold text-blue-600"><?php echo $under_construction_count; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">Completion Rate:</span>
                                <span class="font-bold text-green-600">
                                    <?php echo $total_projects > 0 ? round(($completed_count / $total_projects) * 100) : 0; ?>%
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">On Hold:</span>
                                <span class="font-bold text-gray-600"><?php echo $on_hold_count; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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

            // Preserve search when filtering
            const searchInput = document.querySelector('input[name="search"]');
            const filterButtons = document.querySelectorAll('.filter-btn');
            
            filterButtons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    if (searchInput && searchInput.value) {
                        const form = this.closest('form');
                        const hiddenSearch = document.createElement('input');
                        hiddenSearch.type = 'hidden';
                        hiddenSearch.name = 'search';
                        hiddenSearch.value = searchInput.value;
                        form.appendChild(hiddenSearch);
                    }
                });
            });
        });
    </script>
</body>
</html>