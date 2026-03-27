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

$feedback_data = [];
$stats = [
    'total_feedback' => 0,
    'average_rating' => 0,
    'rating_counts' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]
];
$error_message = '';
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;
$total_pages = 0;

try {
    // Get total count for stats
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM report_feedback");
    $count_stmt->execute();
    $total_count = $count_stmt->fetchColumn();
    $stats['total_feedback'] = $total_count;
    $total_pages = ceil($total_count / $items_per_page);
    
    // Get all feedback for stats calculation
    $all_stmt = $pdo->prepare("
        SELECT rf.rating
        FROM report_feedback rf
    ");
    $all_stmt->execute();
    $all_ratings = $all_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($all_ratings) > 0) {
        $total_rating = 0;
        foreach ($all_ratings as $row) {
            $rating = (int)$row['rating'];
            $total_rating += $rating;
            if (isset($stats['rating_counts'][$rating])) {
                $stats['rating_counts'][$rating]++;
            }
        }
        $stats['average_rating'] = round($total_rating / count($all_ratings), 1);
    }
    
    // Get paginated feedback
    $stmt = $pdo->prepare("
        SELECT 
            rf.id AS feedback_id,
            rf.rating,
            rf.feedback_text,
            rf.created_at AS feedback_date,
            r.id AS report_id,
            r.hazard_type,
            r.address,
            r.status AS report_status,
            r.created_at AS report_date,
            u.id AS user_id,
            u.fullname AS resident_name,
            u.contact_number,
            u.email
        FROM report_feedback rf
        INNER JOIN reports r ON rf.report_id = r.id
        INNER JOIN users u ON r.user_id = u.id
        ORDER BY rf.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$items_per_page, $offset]);
    $feedback_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching feedback data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Feedback - RTIM Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        .rating-5 { border-left: 4px solid #10b981; }
        .rating-4 { border-left: 4px solid #34d399; }
        .rating-3 { border-left: 4px solid #f59e0b; }
        .rating-2 { border-left: 4px solid #f97316; }
        .rating-1 { border-left: 4px solid #ef4444; }
        .star-rating { color: #fbbf24; }
        .empty-star { color: #d1d5db; }
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
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Resident Feedback</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">View feedback and ratings from residents</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $stats['total_feedback']; ?></div>
                    <div class="text-xs">Total Feedback</div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
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
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Feedback</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo $stats['total_feedback']; ?></p>
                        </div>
                        <i class="fa fa-comments text-3xl text-lgu-button opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Average Rating</p>
                            <p class="text-2xl font-bold text-green-600">
                                <?php echo $stats['average_rating']; ?>/5
                            </p>
                            <div class="flex mt-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fa fa-star text-sm <?php echo $i <= round($stats['average_rating']) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <i class="fa fa-star text-3xl text-yellow-400 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">5-Star Ratings</p>
                            <p class="text-2xl font-bold text-blue-600">
                                <?php echo $stats['rating_counts'][5]; ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                <?php echo $stats['total_feedback'] > 0 ? round(($stats['rating_counts'][5] / $stats['total_feedback']) * 100, 1) : 0; ?>% of total
                            </p>
                        </div>
                        <i class="fa fa-thumbs-up text-3xl text-blue-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-purple-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Latest Feedback</p>
                            <p class="text-lg font-bold text-purple-600">
                                <?php echo count($feedback_data) > 0 ? date('M d, Y', strtotime($feedback_data[0]['feedback_date'])) : 'No data'; ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                <?php echo count($feedback_data) > 0 ? 'Most recent entry' : ''; ?>
                            </p>
                        </div>
                        <i class="fa fa-clock text-3xl text-purple-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <!-- Rating Distribution Chart -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-chart-pie mr-2 text-lgu-button"></i>
                        Rating Distribution (Pie Chart)
                    </h3>
                    <div style="height: 350px; display: flex; justify-content: center;">
                        <canvas id="ratingPieChart"></canvas>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-chart-bar mr-2 text-lgu-button"></i>
                        Rating Breakdown
                    </h3>
                    <div class="space-y-3">
                        <?php for ($rating = 5; $rating >= 1; $rating--): 
                            $count = $stats['rating_counts'][$rating];
                            $percentage = $stats['total_feedback'] > 0 ? ($count / $stats['total_feedback']) * 100 : 0;
                        ?>
                            <div class="flex items-center">
                                <div class="w-16 flex items-center">
                                    <span class="text-sm font-semibold text-lgu-paragraph"><?php echo $rating; ?> Star</span>
                                    <div class="flex ml-2">
                                        <?php for ($i = 1; $i <= $rating; $i++): ?>
                                            <i class="fa fa-star text-xs text-yellow-400"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="flex-1 bg-gray-200 rounded-full h-4 ml-2">
                                    <div class="bg-lgu-button h-4 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <div class="w-16 text-right">
                                    <span class="text-sm font-semibold text-lgu-headline"><?php echo $count; ?></span>
                                    <span class="text-xs text-lgu-paragraph ml-1">(<?php echo round($percentage, 1); ?>%)</span>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-chart-bar mr-2 text-lgu-button"></i>
                        Rating Trend (Bar Chart)
                    </h3>
                    <div style="height: 350px;">
                        <canvas id="ratingBarChart"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center">
                        <i class="fa fa-info-circle mr-2 text-lgu-button"></i>
                        Feedback Summary
                    </h3>
                    <div class="space-y-4">
                        <div class="text-center p-4 bg-green-50 rounded-lg border border-green-200">
                            <div class="text-3xl font-bold text-green-600"><?php echo $stats['average_rating']; ?>/5</div>
                            <div class="flex justify-center mt-2">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fa fa-star <?php echo $i <= round($stats['average_rating']) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="text-sm text-green-700 mt-2">Overall Satisfaction Score</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-center">
                            <div class="bg-blue-50 p-3 rounded border border-blue-200">
                                <div class="text-xl font-bold text-blue-600"><?php echo $stats['rating_counts'][5] + $stats['rating_counts'][4]; ?></div>
                                <div class="text-xs text-blue-700">Positive (4-5★)</div>
                            </div>
                            <div class="bg-orange-50 p-3 rounded border border-orange-200">
                                <div class="text-xl font-bold text-orange-600"><?php echo $stats['rating_counts'][3]; ?></div>
                                <div class="text-xs text-orange-700">Neutral (3★)</div>
                            </div>
                            <div class="bg-red-50 p-3 rounded border border-red-200">
                                <div class="text-xl font-bold text-red-600"><?php echo $stats['rating_counts'][2] + $stats['rating_counts'][1]; ?></div>
                                <div class="text-xs text-red-700">Negative (1-2★)</div>
                            </div>
                            <div class="bg-gray-50 p-3 rounded border border-gray-200">
                                <div class="text-xl font-bold text-gray-600"><?php echo $stats['total_feedback']; ?></div>
                                <div class="text-xs text-gray-700">Total Responses</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feedback Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if (count($feedback_data) === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-comment-slash text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Feedback Available</p>
                        <p class="text-gray-400 text-sm">No residents have submitted feedback for reports yet.</p>
                        <a href="reports.php" class="inline-block mt-4 bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition">
                            <i class="fa fa-list mr-2"></i>View Reports
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Feedback ID</th>
                                    <th class="px-4 py-3 text-left font-semibold">Resident</th>
                                    <th class="px-4 py-3 text-left font-semibold">Report Details</th>
                                    <th class="px-4 py-3 text-left font-semibold">Rating</th>
                                    <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Feedback</th>
                                    <th class="px-4 py-3 text-left font-semibold">Date</th>
                                    <th class="px-4 py-3 text-center font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($feedback_data as $feedback): 
                                    $rating_class = 'rating-' . $feedback['rating'];
                                ?>
                                    <tr class="table-row-hover transition <?php echo $rating_class; ?>">
                                        <td class="px-4 py-3 font-bold text-lgu-headline">
                                            #<?php echo htmlspecialchars($feedback['feedback_id']); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-sm mr-2">
                                                    <?php echo strtoupper(substr($feedback['resident_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($feedback['resident_name']); ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($feedback['email']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>
                                                <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold mb-1">
                                                    <i class="fa fa-exclamation-triangle mr-1"></i>
                                                    <?php echo htmlspecialchars(ucfirst($feedback['hazard_type'])); ?>
                                                </span>
                                                <p class="text-xs text-lgu-paragraph">
                                                    Report #<?php echo htmlspecialchars($feedback['report_id']); ?>
                                                </p>
                                                <p class="text-xs text-gray-500 truncate max-w-xs">
                                                    <?php echo htmlspecialchars(substr($feedback['address'], 0, 50)) . (strlen($feedback['address']) > 50 ? '...' : ''); ?>
                                                </p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="flex mr-2">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fa fa-star text-sm <?php echo $i <= $feedback['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="bg-lgu-headline text-white px-2 py-1 rounded text-xs font-bold">
                                                    <?php echo $feedback['rating']; ?>/5
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 hidden lg:table-cell">
                                            <p class="text-sm text-lgu-paragraph">
                                                <?php 
                                                $feedback_text = $feedback['feedback_text'];
                                                echo htmlspecialchars(substr($feedback_text, 0, 80)) . (strlen($feedback_text) > 80 ? '...' : '');
                                                ?>
                                            </p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-xs text-lgu-paragraph">
                                                <p class="font-semibold"><?php echo date('M d, Y', strtotime($feedback['feedback_date'])); ?></p>
                                                <p class="text-gray-500"><?php echo date('h:i A', strtotime($feedback['feedback_date'])); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-2 justify-center">
                                                <!-- View Details Button -->
                                                <button onclick="viewFeedback(<?php echo htmlspecialchars(json_encode($feedback)); ?>)"
                                                        class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1">
                                                    <i class="fa fa-eye"></i>
                                                    View
                                                </button>
                                                
                                                <!-- View Report Button -->
                                                <a href="view_report.php?id=<?php echo (int)$feedback['report_id']; ?>" 
                                                   class="bg-lgu-headline hover:bg-lgu-stroke text-white px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1"
                                                   title="View Report">
                                                    <i class="fa fa-file-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="bg-gray-50 px-4 py-4 border-t border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="text-sm text-lgu-paragraph">
                            Showing <span class="font-semibold text-lgu-headline"><?php echo count($feedback_data); ?></span> of <span class="font-semibold text-lgu-headline"><?php echo $stats['total_feedback']; ?></span> feedback entries
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="flex gap-2 items-center">
                                <?php if ($current_page > 1): ?>
                                    <a href="?page=1" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm font-semibold text-lgu-headline hover:bg-gray-100 transition">
                                        <i class="fa fa-chevron-left mr-1"></i>First
                                    </a>
                                    <a href="?page=<?php echo $current_page - 1; ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm font-semibold text-lgu-headline hover:bg-gray-100 transition">
                                        <i class="fa fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <span class="px-3 py-1 bg-lgu-button text-lgu-button-text rounded text-sm font-semibold">
                                    <?php echo $current_page; ?> / <?php echo $total_pages; ?>
                                </span>
                                
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?page=<?php echo $current_page + 1; ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm font-semibold text-lgu-headline hover:bg-gray-100 transition">
                                        <i class="fa fa-chevron-right"></i>
                                    </a>
                                    <a href="?page=<?php echo $total_pages; ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm font-semibold text-lgu-headline hover:bg-gray-100 transition">
                                        Last<i class="fa fa-chevron-right ml-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white p-4 rounded-t-lg">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-bold">Feedback Details</h3>
                    <button onclick="closeModal()" class="text-white hover:text-gray-200">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div id="modalContent">
                    <!-- Content will be loaded here by JavaScript -->
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-3 rounded-b-lg border-t border-gray-200 flex justify-end">
                <button onclick="closeModal()" class="bg-lgu-headline hover:bg-lgu-stroke text-white px-4 py-2 rounded text-sm font-semibold transition">
                    Close
                </button>
            </div>
        </div>
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

            // Initialize Charts
            initializeCharts();
        });

        function initializeCharts() {
            const ratingCounts = <?php echo json_encode($stats['rating_counts']); ?>;
            const chartColors = ['#ef4444', '#f97316', '#f59e0b', '#34d399', '#10b981'];
            const labels = ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'];
            const data = [ratingCounts[1], ratingCounts[2], ratingCounts[3], ratingCounts[4], ratingCounts[5]];

            // Pie Chart
            const pieCtx = document.getElementById('ratingPieChart')?.getContext('2d');
            if (pieCtx) {
                new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: chartColors,
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { family: "'Poppins', sans-serif", size: 12 },
                                    padding: 15,
                                    usePointStyle: true
                                }
                            }
                        }
                    }
                });
            }

            // Bar Chart
            const barCtx = document.getElementById('ratingBarChart')?.getContext('2d');
            if (barCtx) {
                new Chart(barCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Number of Ratings',
                            data: data,
                            backgroundColor: chartColors,
                            borderColor: chartColors,
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        indexAxis: 'x',
                        plugins: {
                            legend: {
                                display: true,
                                labels: {
                                    font: { family: "'Poppins', sans-serif", size: 12 },
                                    padding: 15
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    font: { family: "'Poppins', sans-serif" }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: { family: "'Poppins', sans-serif" }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        }

        function viewFeedback(feedback) {
            const modal = document.getElementById('feedbackModal');
            const content = document.getElementById('modalContent');
            
            // Format the date
            const feedbackDate = new Date(feedback.feedback_date);
            const formattedDate = feedbackDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Create stars HTML
            let starsHtml = '';
            for (let i = 1; i <= 5; i++) {
                starsHtml += `<i class="fa fa-star text-xl ${i <= feedback.rating ? 'text-yellow-400' : 'text-gray-300'}"></i>`;
            }
            
            content.innerHTML = `
                <div class="space-y-4">
                    <!-- Rating Section -->
                    <div class="text-center p-4 bg-gray-50 rounded-lg">
                        <div class="text-4xl font-bold text-lgu-headline mb-2">${feedback.rating}/5</div>
                        <div class="flex justify-center mb-2">
                            ${starsHtml}
                        </div>
                        <p class="text-sm text-lgu-paragraph">Rating given by resident</p>
                    </div>
                    
                    <!-- Resident Information -->
                    <div>
                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                            <i class="fa fa-user mr-2 text-lgu-button"></i>
                            Resident Information
                        </h4>
                        <div class="bg-gray-50 p-3 rounded text-sm">
                            <p><strong>Name:</strong> ${feedback.resident_name}</p>
                            <p><strong>Email:</strong> ${feedback.email}</p>
                            <p><strong>Contact:</strong> ${feedback.contact_number || 'Not provided'}</p>
                        </div>
                    </div>
                    
                    <!-- Report Information -->
                    <div>
                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                            <i class="fa fa-file-alt mr-2 text-lgu-button"></i>
                            Report Details
                        </h4>
                        <div class="bg-gray-50 p-3 rounded text-sm">
                            <p><strong>Report ID:</strong> #${feedback.report_id}</p>
                            <p><strong>Hazard Type:</strong> ${feedback.hazard_type}</p>
                            <p><strong>Address:</strong> ${feedback.address}</p>
                            <p><strong>Status:</strong> <span class="capitalize">${feedback.report_status.replace('_', ' ')}</span></p>
                        </div>
                    </div>
                    
                    <!-- Feedback Text -->
                    <div>
                        <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
                            <i class="fa fa-comment mr-2 text-lgu-button"></i>
                            Feedback
                        </h4>
                        <div class="bg-gray-50 p-3 rounded text-sm">
                            <p class="whitespace-pre-wrap">${feedback.feedback_text}</p>
                        </div>
                    </div>
                    
                    <!-- Timestamp -->
                    <div class="text-center text-sm text-gray-500">
                        <i class="fa fa-clock mr-1"></i>
                        Submitted on ${formattedDate}
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('feedbackModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('feedbackModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>

</body>
</html>