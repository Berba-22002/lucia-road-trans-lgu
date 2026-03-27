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

// Initialize variables
$projects = [];
$total_projects = 0;
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

try {
    // Get total count of under construction projects
    $countStmt = $pdo->query("
        SELECT COUNT(*) as total
        FROM projects
        WHERE status = 'under_construction'
    ");
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_projects = (int)($counts['total'] ?? 0);

    // Build query for under construction projects
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
        WHERE p.status = 'under_construction'
    ";

    $params = [];

    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR p.location LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Allow safe sorting
    $allowedSorts = ['project_title', 'location', 'progress', 'start_date', 'expected_completion', 'created_at'];
    $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $query .= " ORDER BY p.$sort $order";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching under construction projects: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Under Construction Projects - RTIM</title>

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
            background: #dbeafe;
            color: #1e40af;
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Under Construction Projects</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Projects currently in progress</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
                            <i class="fa fa-user text-lgu-button-text font-semibold text-sm sm:text-base"></i>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Inspector', 0, 15)); ?></p>
                            <p class="text-xs text-lgu-paragraph">Inspector</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Total In Progress</p>
                        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $total_projects; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Average Progress</p>
                        <p class="text-2xl sm:text-3xl font-bold text-green-600">
                            <?php echo count($projects) > 0 ? round(array_sum(array_column($projects, 'progress')) / count($projects)) : 0; ?>%
                        </p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Near Completion</p>
                        <p class="text-2xl sm:text-3xl font-bold text-purple-600">
                            <?php echo count(array_filter($projects, fn($p) => (int)($p['progress'] ?? 0) >= 75)); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
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
                        <select name="sort" class="px-3 py-2 border border-gray-300 rounded-lg text-xs sm:text-sm bg-white text-lgu-paragraph focus:outline-none focus:ring-2 focus:ring-lgu-button">
                            <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Date Created</option>
                            <option value="project_title" <?php echo $sort === 'project_title' ? 'selected' : ''; ?>>Title</option>
                            <option value="location" <?php echo $sort === 'location' ? 'selected' : ''; ?>>Location</option>
                            <option value="progress" <?php echo $sort === 'progress' ? 'selected' : ''; ?>>Progress</option>
                            <option value="expected_completion" <?php echo $sort === 'expected_completion' ? 'selected' : ''; ?>>Expected Completion</option>
                        </select>
                        
                        <button type="submit" class="px-3 py-2 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-semibold rounded-lg text-xs sm:text-sm transition">
                            <i class="fa fa-search mr-1"></i> Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Projects Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <?php if (empty($projects)): ?>
                        <div class="p-12 text-center">
                            <i class="fa fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                            <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Projects Under Construction</h3>
                            <p class="text-lgu-paragraph">There are currently no projects in progress matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-blue-600 text-white">
                                <tr>
                                    <th class="py-4 px-4 font-semibold">Project ID</th>
                                    <th class="py-4 px-4 font-semibold">Project Title</th>
                                    <th class="py-4 px-4 font-semibold">Location</th>
                                    <th class="py-4 px-4 font-semibold">Progress</th>
                                    <th class="py-4 px-4 font-semibold">Timeline</th>
                                    <th class="py-4 px-4 font-semibold">Start Date</th>
                                    <th class="py-4 px-4 font-semibold">Expected End</th>
                                    <th class="py-4 px-4 font-semibold text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($projects as $project): 
                                    $progress = (int)($project['progress'] ?? 0);
                                    
                                    // Progress bar color based on percentage
                                    $progressColor = 'bg-blue-500';
                                    if ($progress >= 90) $progressColor = 'bg-green-500';
                                    elseif ($progress >= 75) $progressColor = 'bg-green-400';
                                    elseif ($progress >= 50) $progressColor = 'bg-yellow-500';
                                    elseif ($progress >= 25) $progressColor = 'bg-orange-500';
                                    
                                    // Days remaining
                                    $daysRemaining = '';
                                    if (!empty($project['expected_completion'])) {
                                        $completion = new DateTime($project['expected_completion']);
                                        $today = new DateTime();
                                        $interval = $today->diff($completion);
                                        if ($interval->invert) {
                                            $daysRemaining = '<span class="text-red-600 font-semibold">Overdue</span>';
                                        } else {
                                            $daysRemaining = '<span class="text-gray-600">' . $interval->days . ' days left</span>';
                                        }
                                    }
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
                                            <div class="text-lgu-paragraph max-w-xs truncate">
                                                <i class="fa fa-map-marker-alt text-lgu-button mr-1"></i>
                                                <?php echo htmlspecialchars($project['location'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="flex items-center gap-3">
                                                <div class="progress-bar-container w-24">
                                                    <div class="progress-bar-fill <?php echo $progressColor; ?>" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <span class="text-sm font-bold text-lgu-headline min-w-fit"><?php echo $progress; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-sm">
                                                <?php echo $daysRemaining; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-sm text-lgu-paragraph">
                                                <?php echo !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : 'N/A'; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-sm text-lgu-paragraph">
                                                <?php echo !empty($project['expected_completion']) ? date('M d, Y', strtotime($project['expected_completion'])) : 'N/A'; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="project_details.php?id=<?php echo $project['id']; ?>" 
                                                   class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-semibold py-2 px-3 rounded-lg text-xs transition"
                                                   title="View Details">
                                                    <i class="fa fa-eye"></i>
                                                </a>
                                                <a href="report_inspection.php?project_id=<?php echo $project['id']; ?>" 
                                                   class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-3 rounded-lg text-xs transition"
                                                   title="Report Inspection">
                                                    <i class="fa fa-file-alt"></i>
                                                </a>
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                    <div class="bg-white rounded-lg shadow p-5">
                        <h3 class="text-sm font-semibold text-lgu-paragraph mb-4 uppercase">Progress Distribution</h3>
                        <div class="space-y-3">
                            <?php
                                $ranges = [
                                    '0-25%' => 0,
                                    '26-50%' => 0,
                                    '51-75%' => 0,
                                    '76-100%' => 0
                                ];
                                foreach ($projects as $p) {
                                    $prog = (int)($p['progress'] ?? 0);
                                    if ($prog <= 25) $ranges['0-25%']++;
                                    elseif ($prog <= 50) $ranges['26-50%']++;
                                    elseif ($prog <= 75) $ranges['51-75%']++;
                                    else $ranges['76-100%']++;
                                }
                            ?>
                            <?php foreach ($ranges as $range => $count): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-lgu-paragraph"><?php echo $range; ?></span>
                                    <span class="font-bold text-lgu-headline"><?php echo $count; ?> projects</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-5">
                        <h3 class="text-sm font-semibold text-lgu-paragraph mb-4 uppercase">At-Risk Projects</h3>
                        <div class="space-y-2 text-sm">
                            <?php
                                $overdue = 0;
                                $nearDeadline = 0;
                                $lowProgress = 0;
                                
                                foreach ($projects as $p) {
                                    if (!empty($p['expected_completion'])) {
                                        $completion = new DateTime($p['expected_completion']);
                                        $today = new DateTime();
                                        if ($today > $completion) $overdue++;
                                        else {
                                            $interval = $today->diff($completion);
                                            if ($interval->days < 7) $nearDeadline++;
                                        }
                                    }
                                    if ((int)($p['progress'] ?? 0) < 25) $lowProgress++;
                                }
                            ?>
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">Overdue Projects:</span>
                                <span class="font-bold <?php echo $overdue > 0 ? 'text-red-600' : 'text-gray-600'; ?>"><?php echo $overdue; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">Deadline Within 7 Days:</span>
                                <span class="font-bold <?php echo $nearDeadline > 0 ? 'text-orange-600' : 'text-gray-600'; ?>"><?php echo $nearDeadline; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-lgu-paragraph">Low Progress (&lt;25%):</span>
                                <span class="font-bold <?php echo $lowProgress > 0 ? 'text-yellow-600' : 'text-gray-600'; ?>"><?php echo $lowProgress; ?></span>
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

            // Preserve search when sorting
            const searchInput = document.querySelector('input[name="search"]');
            const sortSelect = document.querySelector('select[name="sort"]');
            
            if (sortSelect && searchInput) {
                sortSelect.addEventListener('change', function() {
                    if (searchInput.value) {
                        const form = this.closest('form');
                        // Search param will be preserved by form submission
                    }
                });
            }
        });
    </script>
</body>
</html>