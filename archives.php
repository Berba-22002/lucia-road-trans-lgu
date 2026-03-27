<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$archived_reports = [];
$total_archived = 0;

try {
    // Get all archived reports with reporter info
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
            u.fullname AS reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status = 'archived'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $archived_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_archived = count($archived_reports);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching archived reports: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Reports - BENG Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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
        }
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Archived Reports</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Previously validated and archived hazard reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-500 to-gray-700 text-white px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $total_archived; ?></div>
                    <div class="text-xs">Archived</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex items-start">
                    <i class="fa fa-exclamation-circle mr-3 mt-0.5"></i>
                    <div>
                        <p class="font-semibold">Error Loading Archived Reports</p>
                        <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter & Search Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                    <div class="flex-1 flex gap-2 w-full sm:w-auto">
                        <input type="text" id="searchInput" placeholder="Search archived reports..." 
                               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                        <button class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition flex-shrink-0">
                            <i class="fa fa-search"></i>
                            <span class="hidden sm:inline ml-2">Search</span>
                        </button>
                    </div>
                    <a href="incoming_reports.php" class="text-lgu-paragraph hover:text-lgu-headline transition whitespace-nowrap">
                        <i class="fa fa-arrow-left mr-1"></i> Back to Reports
                    </a>
                </div>
            </div>

            <!-- Archived Reports Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if ($total_archived === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-archive text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Archived Reports</p>
                        <p class="text-gray-400 text-sm">No reports have been archived yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-gray-600 to-gray-800 text-white sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">ID</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden sm:table-cell">Reporter</th>
                                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Location</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Archived Date</th>
                                    <th class="px-4 py-3 text-center font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($archived_reports as $report): ?>
                                    <tr class="table-row-hover transition" data-report-id="<?php echo (int)$report['report_id']; ?>">
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
                                            <span class="inline-block bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                                <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 hidden md:table-cell">
                                            <span class="text-xs text-lgu-paragraph"><i class="fa fa-map-marker-alt mr-1 text-lgu-tertiary"></i><?php echo htmlspecialchars(substr($report['address'], 0, 40)) . (strlen($report['address']) > 40 ? '...' : ''); ?></span>
                                        </td>
                                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                                            <i class="fa fa-calendar mr-1"></i>
                                            <?php echo date('M d, Y', strtotime($report['created_at'])); ?>
                                            <br>
                                            <i class="fa fa-clock mr-1"></i>
                                            <?php echo date('h:i A', strtotime($report['created_at'])); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-2 justify-center flex-wrap">
                                                <a href="view_report.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                   class="bg-lgu-headline hover:bg-lgu-stroke text-white px-3 py-2 rounded-lg text-xs font-bold transition shadow-md hover:shadow-lg flex items-center gap-1">
                                                    <i class="fa fa-eye"></i>
                                                    <span class="hidden sm:inline">View</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Summary -->
                    <div class="bg-gray-50 px-4 py-3 border-t border-gray-200 flex items-center justify-between">
                        <div class="text-sm text-lgu-paragraph">
                            Showing <span class="font-semibold text-lgu-headline"><?php echo count($archived_reports); ?></span> archived report(s)
                        </div>
                        <div class="text-xs text-gray-500">
                            <i class="fa fa-info-circle mr-1"></i> These reports have been validated and archived
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> BENG - Infrastructure Monitoring System</p>
                <p class="text-xs mt-1 text-gray-300">Empowering communities through efficient hazard reporting</p>
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

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    let visibleCount = 0;
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const isVisible = text.includes(searchTerm);
                        row.style.display = isVisible ? '' : 'none';
                        if (isVisible) visibleCount++;
                    });

                    // Update visible count
                    const countSpan = document.querySelector('.text-lgu-headline');
                    if (countSpan) {
                        countSpan.textContent = visibleCount;
                    }
                });

                // Clear search on escape key
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        this.dispatchEvent(new Event('keyup'));
                    }
                });
            }
        });
    </script>

</body>
</html>