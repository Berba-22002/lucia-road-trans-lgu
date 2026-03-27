<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector role
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

// Initialize variables
$projects = [];
$verification_filter = $_GET['verification'] ?? 'pending';
$search = $_GET['search'] ?? '';
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;

try {
    // Get verification counts
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN verification_status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM projects
        WHERE status IN ('approved', 'under_construction', 'completed')
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_count = (int)($counts['pending'] ?? 0);
    $approved_count = (int)($counts['approved'] ?? 0);
    $rejected_count = (int)($counts['rejected'] ?? 0);

    // Build query for projects
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
            p.verification_status,
            p.verified_by,
            p.verification_date,
            p.verification_notes,
            r.hazard_type,
            r.address as report_address,
            r.image_path,
            u.fullname as created_by_name,
            v.fullname as verified_by_name
        FROM projects p
        LEFT JOIN reports r ON p.report_id = r.id
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN users v ON p.verified_by = v.id
        WHERE p.status IN ('approved', 'under_construction', 'completed')
    ";

    $params = [];

    // Apply verification filter
    if ($verification_filter !== 'all') {
        $query .= " AND p.verification_status = ?";
        $params[] = $verification_filter;
    }

    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR p.location LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $query .= " ORDER BY 
        CASE WHEN p.verification_status = 'pending' THEN 0 ELSE 1 END,
        p.progress DESC, 
        p.created_at DESC";

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
    <title>Work Done Verification - RTIM Inspector</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Poppins Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
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

        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .action-btn {
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Work Done Verification</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Verify completed work and progress for approved projects</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
                            <i class="fa fa-clipboard-check text-lgu-button-text font-semibold text-sm sm:text-base"></i>
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
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Pending Verification</p>
                        <p class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo $pending_count; ?></p>
                        <p class="text-xs text-lgu-paragraph mt-1">Awaiting inspection</p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Approved</p>
                        <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $approved_count; ?></p>
                        <p class="text-xs text-lgu-paragraph mt-1">Verification passed</p>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
                    <div class="text-center">
                        <p class="text-xs font-semibold text-lgu-paragraph uppercase mb-2">Rejected</p>
                        <p class="text-2xl sm:text-3xl font-bold text-red-600"><?php echo $rejected_count; ?></p>
                        <p class="text-xs text-lgu-paragraph mt-1">Needs revision</p>
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
                        <button type="submit" name="verification" value="all" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $verification_filter === 'all' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            <i class="fa fa-list mr-1"></i> All
                        </button>
                        <button type="submit" name="verification" value="pending" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $verification_filter === 'pending' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            <i class="fa fa-hourglass-half mr-1"></i> Pending
                        </button>
                        <button type="submit" name="verification" value="approved" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $verification_filter === 'approved' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            <i class="fa fa-check-circle mr-1"></i> Approved
                        </button>
                        <button type="submit" name="verification" value="rejected" class="filter-btn px-3 py-2 rounded-lg border border-gray-300 text-xs sm:text-sm <?php echo $verification_filter === 'rejected' ? 'active' : 'bg-white text-lgu-paragraph hover:bg-gray-50'; ?>">
                            <i class="fa fa-times-circle mr-1"></i> Rejected
                        </button>
                    </div>
                </form>
            </div>

            <!-- Projects Grid -->
            <div class="grid grid-cols-1 gap-6">
                <?php if (empty($projects)): ?>
                    <div class="bg-white rounded-lg shadow p-12 text-center">
                        <i class="fa fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Projects Found</h3>
                        <p class="text-lgu-paragraph">There are no projects matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($projects as $project): 
                        $status = $project['status'] ?? 'pending';
                        $progress = (int)($project['progress'] ?? 0);
                        $verification_status = $project['verification_status'] ?? 'pending';
                        
                        // Status styling
                        $statusConfig = [
                            'approved' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-check-circle'],
                            'under_construction' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'icon' => 'fa-hard-hat'],
                            'completed' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'icon' => 'fa-flag-checkered']
                        ];
                        
                        $statusStyle = $statusConfig[$status] ?? $statusConfig['approved'];
                        
                        // Verification status styling
                        $verificationConfig = [
                            'pending' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'icon' => 'fa-hourglass-half'],
                            'approved' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-check-circle'],
                            'rejected' => ['bg' => 'bg-red-100', 'text' => 'text-red-700', 'icon' => 'fa-times-circle']
                        ];
                        
                        $verificationStyle = $verificationConfig[$verification_status] ?? $verificationConfig['pending'];
                        
                        // Progress bar color
                        $progressColor = 'bg-blue-500';
                        if ($progress >= 75) $progressColor = 'bg-green-500';
                        elseif ($progress >= 50) $progressColor = 'bg-yellow-500';
                        elseif ($progress >= 25) $progressColor = 'bg-orange-500';
                    ?>
                        <div class="project-card bg-white rounded-lg shadow overflow-hidden border-l-4 <?php echo $verification_status === 'pending' ? 'border-orange-500' : 'border-gray-300'; ?>">
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
                                        <span class="status-badge <?php echo $statusStyle['bg'] . ' ' . $statusStyle['text']; ?>">
                                            <i class="fa <?php echo $statusStyle['icon']; ?>"></i>
                                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                        </span>
                                        <span class="verification-badge <?php echo $verificationStyle['bg'] . ' ' . $verificationStyle['text']; ?>">
                                            <i class="fa <?php echo $verificationStyle['icon']; ?>"></i>
                                            <?php echo ucfirst($verification_status); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Description -->
                                <?php if (!empty($project['description'])): ?>
                                    <p class="text-sm text-lgu-paragraph mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars(substr($project['description'], 0, 150)); ?>
                                        <?php echo strlen($project['description']) > 150 ? '...' : ''; ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Progress Bar -->
                                <div class="mb-4">
                                    <div class="flex justify-between items-center mb-2">
                                        <p class="text-xs font-semibold text-lgu-paragraph uppercase">Progress</p>
                                        <p class="text-sm font-bold text-lgu-headline"><?php echo $progress; ?>%</p>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-fill <?php echo $progressColor; ?>" style="width: <?php echo $progress; ?>%"></div>
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
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-1">Expected End</p>
                                        <p class="text-sm font-bold text-lgu-headline">
                                            <?php echo !empty($project['expected_completion']) ? date('M d, Y', strtotime($project['expected_completion'])) : 'N/A'; ?>
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

                                <!-- Verification Info -->
                                <?php if ($verification_status !== 'pending'): ?>
                                    <div class="mb-4 pb-4 border-b border-gray-200">
                                        <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-2">
                                            <i class="fa fa-info-circle mr-1"></i> Verification Details
                                        </p>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm mb-2">
                                                <div>
                                                    <p class="text-xs text-gray-500">Inspector</p>
                                                    <p class="font-semibold text-lgu-headline">
                                                        <?php echo htmlspecialchars($project['verified_by_name'] ?? 'N/A'); ?>
                                                    </p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-500">Verification Date</p>
                                                    <p class="font-semibold text-lgu-headline">
                                                        <?php echo !empty($project['verification_date']) ? date('M d, Y', strtotime($project['verification_date'])) : 'N/A'; ?>
                                                    </p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-500">Status</p>
                                                    <p class="font-semibold <?php echo $verification_status === 'approved' ? 'text-green-600' : 'text-red-600'; ?>">
                                                        <?php echo ucfirst($verification_status); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <?php if (!empty($project['verification_notes'])): ?>
                                                <div class="mt-2 pt-2 border-t border-gray-200">
                                                    <p class="text-xs text-gray-500 mb-1">Notes</p>
                                                    <p class="text-sm text-lgu-paragraph italic">
                                                        "<?php echo htmlspecialchars($project['verification_notes']); ?>"
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Action Buttons -->
                                <div class="flex flex-col sm:flex-row gap-2 justify-end">
                                    <a href="project_details.php?id=<?php echo $project['id']; ?>" 
                                       class="action-btn inline-flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-lgu-headline font-semibold py-2 px-4 rounded-lg text-sm transition">
                                        <i class="fa fa-eye"></i>
                                        View Details
                                    </a>
                                    
                                    <?php if ($verification_status === 'pending'): ?>
                                        <button onclick="markAsDone(<?php echo $project['id']; ?>, '<?php echo addslashes($project['project_title']); ?>', <?php echo $progress; ?>)" 
                                                class="action-btn inline-flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition">
                                            <i class="fa fa-check-circle"></i>
                                            Mark as Done
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

        function markAsDone(projectId, projectTitle, currentProgress) {
            // Check if progress is 100%
            if (currentProgress < 100) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Progress',
                    html: `
                        <p class="text-gray-700 mb-2">This project is only <strong>${currentProgress}%</strong> complete.</p>
                        <p class="text-gray-600 text-sm">Are you sure you want to mark it as done?</p>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Mark as Done',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#6b7280',
                    customClass: {
                        popup: 'rounded-lg',
                        confirmButton: 'px-6 py-2 rounded-lg font-semibold',
                        cancelButton: 'px-6 py-2 rounded-lg font-semibold'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        showDoneModal(projectId, projectTitle);
                    }
                });
            } else {
                showDoneModal(projectId, projectTitle);
            }
        }

        function showDoneModal(projectId, projectTitle) {
            Swal.fire({
                title: 'Mark Project as Done',
                html: `
                    <div class="text-left mb-4">
                        <p class="text-sm text-gray-600 mb-2">Project: <strong>${projectTitle}</strong></p>
                        <p class="text-sm text-gray-600 mb-4">ID: #${String(projectId).padStart(4, '0')}</p>
                        <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
                            <p class="text-sm text-green-800">
                                <i class="fa fa-info-circle mr-1"></i>
                                This will mark the project as <strong>completed</strong> and automatically approve the verification.
                            </p>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-left text-sm font-semibold text-gray-700 mb-2">
                            Completion Notes <span class="text-red-500">*</span>
                        </label>
                        <textarea 
                            id="completion-notes" 
                            rows="4" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
                            placeholder="Describe the completed work, quality of construction, and any final observations..."
                        ></textarea>
                        <p class="text-xs text-gray-500 mt-1">Provide details about the final inspection and completion status.</p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-left text-sm font-semibold text-gray-700 mb-2">
                            Completion Date <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="date" 
                            id="completion-date" 
                            value="${new Date().toISOString().split('T')[0]}"
                            max="${new Date().toISOString().split('T')[0]}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                        />
                    </div>
                `,
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fa fa-check-circle mr-2"></i>Confirm Completion',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                width: '600px',
                customClass: {
                    popup: 'rounded-lg',
                    title: 'text-xl font-bold text-lgu-headline',
                    confirmButton: 'px-6 py-2 rounded-lg font-semibold',
                    cancelButton: 'px-6 py-2 rounded-lg font-semibold'
                },
                preConfirm: () => {
                    const notes = document.getElementById('completion-notes').value.trim();
                    const completionDate = document.getElementById('completion-date').value;
                    
                    if (!notes) {
                        Swal.showValidationMessage('Please provide completion notes');
                        return false;
                    }
                    
                    if (notes.length < 10) {
                        Swal.showValidationMessage('Notes must be at least 10 characters long');
                        return false;
                    }

                    if (!completionDate) {
                        Swal.showValidationMessage('Please select a completion date');
                        return false;
                    }
                    
                    return { notes, completionDate };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    submitCompletion(projectId, result.value.notes, result.value.completionDate);
                }
            });
        }

        function submitCompletion(projectId, notes, completionDate) {
            // Show loading
            Swal.fire({
                title: 'Marking as Done...',
                text: 'Please wait while we process the completion.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Submit completion
            fetch('process_completion.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    project_id: projectId,
                    completion_notes: notes,
                    completion_date: completionDate
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Project Marked as Done!',
                        html: `
                            <p class="text-gray-700 mb-2">${data.message}</p>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-3 mt-3">
                                <p class="text-sm text-green-800">
                                    <i class="fa fa-check-circle mr-1"></i>
                                    Project status: <strong>Completed</strong><br>
                                    <i class="fa fa-check-circle mr-1"></i>
                                    Verification: <strong>Approved</strong>
                                </p>
                            </div>
                        `,
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Mark as Done',
                        text: data.message || 'Failed to mark project as done. Please try again.',
                        confirmButtonColor: '#ef4444',
                        confirmButtonText: 'OK'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while marking the project as done. Please try again.',
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'OK'
                });
            });
        }
    </script>
</body>
</html>