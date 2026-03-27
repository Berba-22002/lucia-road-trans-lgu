<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin role
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

// Initialize variables
$projects = [];
$report_filter = $_GET['report'] ?? 'pending';
$search = $_GET['search'] ?? '';
$pending_count = 0;
$submitted_count = 0;
$archived_count = 0;

try {
    // Get report counts
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN report_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN report_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN report_status = 'archived' THEN 1 ELSE 0 END) as archived
        FROM projects
        WHERE status = 'completed'
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_count = (int)($counts['pending'] ?? 0);
    $submitted_count = (int)($counts['submitted'] ?? 0);
    $archived_count = (int)($counts['archived'] ?? 0);

    // Build query for completed projects
    $query = "
        SELECT 
            p.id,
            p.project_title,
            p.location,
            p.status,
            p.progress,
            p.description,
            p.estimated_budget,
            p.actual_cost,
            p.start_date,
            p.expected_completion,
            p.actual_completion,
            p.report_status,
            p.report_submitted_date,
            p.report_notes,
            r.hazard_type,
            r.address as report_address,
            r.image_path,
            u.fullname as created_by_name
        FROM projects p
        LEFT JOIN reports r ON p.report_id = r.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.status = 'completed'
    ";

    $params = [];

    // Apply report status filter
    if ($report_filter !== 'all') {
        $query .= " AND p.report_status = ?";
        $params[] = $report_filter;
    }

    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR p.location LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $query .= " ORDER BY p.actual_completion DESC, p.created_at DESC";

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
    <title>Infrastructure Project Reports - RTIM</title>

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
        
        .project-card {
            transition: all 0.3s ease;
        }
        
        .project-card:hover {
            box-shadow: 0 12px 24px rgba(0, 71, 62, 0.15);
            transform: translateY(-2px);
        }
        
        .progress-bar-container {
            height: 8px;
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
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .report-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #f2f7f5;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Infrastructure Project Reports</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage and submit completed project reports</p>
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
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Pending Report</p>
                        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $pending_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Submitted</p>
                        <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $submitted_count; ?></p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Archived</p>
                        <p class="text-2xl sm:text-3xl font-bold text-purple-600"><?php echo $archived_count; ?></p>
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
                        <button type="submit" name="report" value="all" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $report_filter === 'all' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            All
                        </button>
                        <button type="submit" name="report" value="pending" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $report_filter === 'pending' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Pending
                        </button>
                        <button type="submit" name="report" value="submitted" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $report_filter === 'submitted' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Submitted
                        </button>
                        <button type="submit" name="report" value="archived" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $report_filter === 'archived' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            Archived
                        </button>
                    </div>
                </form>
            </div>

            <!-- Projects Grid -->
            <div class="grid grid-cols-1 gap-6">
                <?php if (empty($projects)): ?>
                    <div class="bg-white rounded-lg shadow p-12 text-center">
                        <i class="fa fa-file-alt text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Completed Projects Found</h3>
                        <p class="text-lgu-paragraph">There are no completed projects to generate reports.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($projects as $project): 
                        $progress = (int)($project['progress'] ?? 0);
                        $report_status = $project['report_status'] ?? 'pending';
                        
                        // Report status styling
                        $reportConfig = [
                            'pending' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'icon' => 'fa-hourglass-half'],
                            'submitted' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-paper-plane'],
                            'archived' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'icon' => 'fa-archive']
                        ];
                        
                        $reportStyle = $reportConfig[$report_status] ?? $reportConfig['pending'];
                    ?>
                        <div class="project-card bg-white rounded-lg shadow overflow-hidden border-l-4 border-lgu-button">
                            <div class="p-6">
                                <!-- Header Row -->
                                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <h3 class="text-lg sm:text-xl font-bold text-lgu-headline">
                                                <?php echo htmlspecialchars($project['project_title'] ?? 'Untitled Project'); ?>
                                            </h3>
                                            <span class="text-xs font-mono bg-gray-100 px-2 py-1 rounded text-lgu-paragraph">
                                                #<?php echo str_pad($project['id'], 4, '0', STR_PAD_LEFT); ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-lgu-paragraph">
                                            <i class="fa fa-map-marker-alt text-lgu-button mr-2"></i>
                                            <?php echo htmlspecialchars($project['location'] ?? 'N/A'); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="flex gap-2 flex-wrap justify-start sm:justify-end">
                                        <span class="status-badge bg-purple-100 text-purple-700">
                                            <i class="fa fa-trophy"></i>
                                            Completed
                                        </span>
                                        <span class="report-badge <?php echo $reportStyle['bg'] . ' ' . $reportStyle['text']; ?>">
                                            <i class="fa <?php echo $reportStyle['icon']; ?>"></i>
                                            <?php echo ucfirst($report_status); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Description -->
                                <?php if (!empty($project['description'])): ?>
                                    <p class="text-sm text-lgu-paragraph mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars(substr($project['description'], 0, 150)); ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Progress Bar (100% for completed) -->
                                <div class="mb-4">
                                    <div class="flex justify-between items-center mb-2">
                                        <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completion</p>
                                        <p class="text-sm font-bold text-lgu-headline">100%</p>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-fill bg-green-500" style="width: 100%"></div>
                                    </div>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4 pb-4 border-b border-gray-200">
                                    <div>
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-1">Start Date</p>
                                        <p class="text-sm font-bold text-lgu-headline">
                                            <?php echo !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : 'N/A'; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-1">Completed</p>
                                        <p class="text-sm font-bold text-green-600">
                                            <?php echo !empty($project['actual_completion']) ? date('M d, Y', strtotime($project['actual_completion'])) : 'N/A'; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-1">Budget</p>
                                        <p class="text-sm font-bold text-lgu-headline">
                                            ₱<?php echo number_format($project['estimated_budget'] ?? 0, 0); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-1">Actual Cost</p>
                                        <p class="text-sm font-bold text-blue-600">
                                            ₱<?php echo number_format($project['actual_cost'] ?? 0, 0); ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Report Info -->
                                <?php if ($report_status !== 'pending'): ?>
                                    <div class="mb-4 pb-4 border-b border-gray-200">
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-2">Report Details</p>
                                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                                            <div>
                                                <p class="text-xs text-gray-500">Submitted Date</p>
                                                <p class="font-semibold text-lgu-headline"><?php echo !empty($project['report_submitted_date']) ? date('M d, Y', strtotime($project['report_submitted_date'])) : 'N/A'; ?></p>
                                            </div>
                                            <?php if (!empty($project['report_notes'])): ?>
                                                <div class="col-span-2 sm:col-span-3">
                                                    <p class="text-xs text-gray-500">Notes</p>
                                                    <p class="text-sm text-lgu-paragraph italic">
                                                        <?php echo htmlspecialchars($project['report_notes']); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Action Buttons -->
                                <div class="flex gap-2 justify-end flex-wrap">
                                    <a href="project_details.php?id=<?php echo $project['id']; ?>" 
                                       class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-lgu-paragraph font-semibold py-2 px-4 rounded-lg text-sm transition">
                                        <i class="fa fa-eye"></i>
                                        View Details
                                    </a>
                                    <?php if ($report_status === 'pending'): ?>
                                        <button type="button" onclick="openReportModal(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars(addslashes($project['project_title'])); ?>')" 
                                                class="inline-flex items-center gap-2 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-semibold py-2 px-4 rounded-lg text-sm transition">
                                            <i class="fa fa-paper-plane"></i>
                                            Submit Report
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openReportModal(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars(addslashes($project['project_title'])); ?>')" 
                                                class="inline-flex items-center gap-2 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg text-sm transition">
                                            <i class="fa fa-edit"></i>
                                            Edit Report
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 sm:py-8 mt-8 sm:mt-12 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="bg-white rounded-lg shadow-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-lgu-headline">Submit Project Report</h3>
                    <button type="button" onclick="closeReportModal()" class="text-gray-500 hover:text-gray-700 text-2xl">
                        <i class="fa fa-times"></i>
                    </button>
                </div>

                <form id="reportForm" method="POST" action="process_report.php" class="space-y-4">
                    <input type="hidden" id="projectId" name="project_id" value="">
                    
                    <div>
                        <label for="projectTitle" class="block text-sm font-semibold text-lgu-headline mb-2">Project Title</label>
                        <input type="text" id="projectTitle" readonly class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50 text-lgu-paragraph">
                    </div>

                    <div>
                        <label for="reportNotes" class="block text-sm font-semibold text-lgu-headline mb-2">Report Notes / Comments</label>
                        <textarea 
                            id="reportNotes" 
                            name="report_notes" 
                            rows="4" 
                            placeholder="Enter final remarks, achievements, and any additional information about the completed project..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button resize-none"
                            required></textarea>
                    </div>

                    <div>
                        <label for="submissionDate" class="block text-sm font-semibold text-lgu-headline mb-2">Submission Date</label>
                        <input type="date" id="submissionDate" name="submission_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Report Status</label>
                        <select name="report_status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button" required>
                            <option value="submitted">Submitted</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-700">
                        <i class="fa fa-info-circle mr-2"></i>
                        This report will be submitted to the infrastructure department for record-keeping.
                    </div>

                    <div class="flex gap-3 justify-end pt-4 border-t border-gray-200">
                        <button type="button" onclick="closeReportModal()" class="px-4 py-2 rounded-lg border border-gray-300 text-lgu-paragraph hover:bg-gray-50 font-semibold transition">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-semibold transition">
                            <i class="fa fa-check mr-2"></i>Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
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

        function openReportModal(projectId, projectTitle) {
            document.getElementById('projectId').value = projectId;
            document.getElementById('projectTitle').value = projectTitle;
            document.getElementById('reportNotes').value = '';
            document.getElementById('reportModal').classList.add('show');
        }

        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('show');
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('reportModal');
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>