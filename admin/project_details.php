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

// Get project ID
$project_id = (int)($_GET['id'] ?? 0);
if (!$project_id) {
    header("Location: major_projects.php");
    exit();
}

$project = null;

try {
    // Get project details
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            r.hazard_type,
            r.address as report_address,
            r.image_path,
            r.description as report_description,
            u.fullname as created_by_name
        FROM projects p
        LEFT JOIN reports r ON p.report_id = r.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header("Location: major_projects.php");
        exit();
    }

} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    header("Location: major_projects.php");
    exit();
}

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Project Details - RTIM</title>

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
        
        .progress-bar-container {
            height: 12px;
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
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Project Details</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate"><?php echo htmlspecialchars($project['project_title']); ?></p>
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <a href="major_projects.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <i class="fa fa-arrow-left mr-2"></i>Back
                    </a>
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
            <!-- Project Header Card -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-2xl font-bold text-lgu-headline"><?php echo htmlspecialchars($project['project_title']); ?></h2>
                            <span class="text-sm font-mono bg-gray-100 px-3 py-1 rounded text-lgu-paragraph">
                                #<?php echo str_pad($project['id'], 4, '0', STR_PAD_LEFT); ?>
                            </span>
                        </div>
                        
                        <div class="flex items-center gap-4 text-sm text-lgu-paragraph mb-4">
                            <div class="flex items-center gap-2">
                                <i class="fa fa-map-marker-alt text-lgu-button"></i>
                                <span><?php echo htmlspecialchars($project['project_location'] ?? $project['location'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa fa-user text-lgu-button"></i>
                                <span><?php echo htmlspecialchars($project['created_by_name'] ?? 'Unknown'); ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa fa-calendar text-lgu-button"></i>
                                <span><?php echo date('M d, Y', strtotime($project['created_at'])); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($project['description'])): ?>
                            <p class="text-lgu-paragraph leading-relaxed"><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col gap-3">
                        <span class="status-badge <?php echo $config['bg'] . ' ' . $config['text']; ?>">
                            <i class="fa <?php echo $config['icon']; ?>"></i>
                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                        </span>
                        
                        <div class="bg-gray-50 rounded-lg p-4 min-w-[200px]">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-lgu-paragraph">Progress</span>
                                <span class="text-lg font-bold text-lgu-headline"><?php echo $progress; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill <?php echo $progressColor; ?>" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Project Details Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Financial Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                        <i class="fa fa-money-bill-wave text-lgu-button"></i>
                        Financial Information
                    </h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="text-lgu-paragraph">Estimated Budget:</span>
                            <span class="font-bold text-lgu-headline">
                                <?php echo !empty($project['estimated_budget']) ? '₱' . number_format($project['estimated_budget'], 2) : 'N/A'; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="text-lgu-paragraph">Actual Cost:</span>
                            <span class="font-bold text-blue-600">
                                <?php echo !empty($project['actual_cost']) ? '₱' . number_format($project['actual_cost'], 2) : 'N/A'; ?>
                            </span>
                        </div>
                        <?php if (!empty($project['estimated_budget']) && !empty($project['actual_cost'])): ?>
                            <?php 
                                $variance = $project['actual_cost'] - $project['estimated_budget'];
                                $variance_percent = ($variance / $project['estimated_budget']) * 100;
                            ?>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-lgu-paragraph">Variance:</span>
                                <span class="font-bold <?php echo $variance >= 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                    <?php echo ($variance >= 0 ? '+' : '') . '₱' . number_format($variance, 2); ?>
                                    (<?php echo ($variance >= 0 ? '+' : '') . number_format($variance_percent, 1); ?>%)
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Timeline Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                        <i class="fa fa-clock text-lgu-button"></i>
                        Timeline Information
                    </h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="text-lgu-paragraph">Start Date:</span>
                            <span class="font-bold text-lgu-headline">
                                <?php echo !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : 'N/A'; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="text-lgu-paragraph">Expected Completion:</span>
                            <span class="font-bold text-blue-600">
                                <?php echo !empty($project['expected_completion']) ? date('M d, Y', strtotime($project['expected_completion'])) : 'N/A'; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center py-2">
                            <span class="text-lgu-paragraph">Actual Completion:</span>
                            <span class="font-bold text-green-600">
                                <?php echo !empty($project['actual_completion']) ? date('M d, Y', strtotime($project['actual_completion'])) : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Related Report Information -->
            <?php if (!empty($project['report_id'])): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                        <i class="fa fa-file-alt text-lgu-button"></i>
                        Related Report Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <?php if (!empty($project['hazard_type'])): ?>
                                <div class="flex items-center gap-3">
                                    <span class="text-lgu-paragraph font-semibold">Hazard Type:</span>
                                    <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-semibold">
                                        <?php echo htmlspecialchars(ucfirst($project['hazard_type'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($project['report_address'])): ?>
                                <div>
                                    <span class="text-lgu-paragraph font-semibold">Report Address:</span>
                                    <p class="text-lgu-headline mt-1"><?php echo htmlspecialchars($project['report_address']); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($project['report_description'])): ?>
                                <div>
                                    <span class="text-lgu-paragraph font-semibold">Report Description:</span>
                                    <p class="text-lgu-headline mt-1"><?php echo nl2br(htmlspecialchars($project['report_description'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($project['image_path'])): ?>
                            <div>
                                <span class="text-lgu-paragraph font-semibold">Report Image:</span>
                                <div class="mt-2">
                                    <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($project['image_path']); ?>" 
                                         alt="Report Image" 
                                         class="w-full max-w-sm rounded-lg shadow border"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div class="bg-gray-100 p-4 rounded text-center text-gray-500" style="display:none;">
                                        <i class="fa fa-image text-2xl mb-2"></i>
                                        <p>Image not available</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
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
        });
    </script>
</body>
</html>