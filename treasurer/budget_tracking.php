<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$total_collections = 0;
$total_spent = 0;
$burndown_labels = ['Initial'];
$burndown_values = [];

try {
    // INCOME: Sum of all penalty amounts from residents that are PAID
    $income_stmt = $pdo->prepare("SELECT SUM(penalty_amount) as total FROM ovr_tickets WHERE payment_status = 'paid'");
    $income_stmt->execute();
    $income_data = $income_stmt->fetch(PDO::FETCH_ASSOC);
    $total_collections = $income_data['total'] ?? 0;

    // SPENT: Sum of all approved amounts from fund requests
    $spent_stmt = $pdo->prepare("SELECT SUM(approved_amount) as total FROM fund_requests WHERE status = 'approved'");
    $spent_stmt->execute();
    $spent_data = $spent_stmt->fetch(PDO::FETCH_ASSOC);
    $total_spent = $spent_data['total'] ?? 0;

    // BURNDOWN LOGIC: Track balance reduction over time
    $history_stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, SUM(approved_amount) as daily_spent 
        FROM fund_requests 
        WHERE status = 'approved' 
        GROUP BY DATE(created_at) 
        ORDER BY date ASC
    ");
    $history_stmt->execute();
    $history_results = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    $running_balance = $total_collections;
    $burndown_values[] = $running_balance; // Starting point

    foreach ($history_results as $row) {
        $running_balance -= $row['daily_spent'];
        $burndown_labels[] = date('M d', strtotime($row['date']));
        $burndown_values[] = $running_balance;
    }

} catch (PDOException $e) {
    error_log("Budget tracking error: " . $e->getMessage());
}

$available_cash = $total_collections - $total_spent;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Budget Tracking - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
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
        .chart-container { position: relative; height: 300px; width: 100%; }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins text-lgu-paragraph">
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Budget Tracking</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-center md:text-left">
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-lgu-button">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Collection</p>
                    <p class="text-3xl font-bold text-lgu-headline mt-2">₱<?php echo number_format($total_collections, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-500">
                    <p class="text-xs font-semibold text-red-500 uppercase">Total Spent</p>
                    <p class="text-3xl font-bold text-red-600 mt-2">₱<?php echo number_format($total_spent, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
                    <p class="text-xs font-semibold text-green-600 uppercase">Available Cash</p>
                    <p class="text-3xl font-bold text-green-600 mt-2">₱<?php echo number_format($available_cash, 2); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-bold text-lgu-headline mb-4">Cash Burndown History</h3>
                
                <div class="h-[350px]">
                    <canvas id="burndownChart"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg shadow p-6 text-center">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Budget Distribution</h3>
                    <div class="chart-container">
                        <canvas id="budgetChart"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Financial Summary</h3>
                    <div class="space-y-4 pt-4">
                        <div class="flex justify-between border-b pb-2 items-center">
                            <span class="text-sm">All money coming in:</span>
                            <span class="font-bold text-lgu-headline">₱<?php echo number_format($total_collections, 2); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-2 items-center">
                            <span class="text-sm text-red-500">All money spent:</span>
                            <span class="font-bold text-red-500">- ₱<?php echo number_format($total_spent, 2); ?></span>
                        </div>
                        <div class="flex justify-between font-bold text-xl pt-4 items-center">
                            <span class="text-lgu-headline">Current Money:</span>
                            <span class="text-green-600">₱<?php echo number_format($available_cash, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0 text-center">
            <p class="text-xs sm:text-sm tracking-widest">&copy; <?php echo date('Y'); ?> RTIM - TREASURY</p>
        </footer>
    </div>

    <script>
        // Sidebar logic
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });

        // Burndown Chart Logic
        new Chart(document.getElementById('burndownChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($burndown_labels); ?>,
                datasets: [{
                    label: 'Remaining Cash Balance',
                    data: <?php echo json_encode($burndown_values); ?>,
                    borderColor: '#faae2b',
                    backgroundColor: 'rgba(250, 174, 43, 0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 4,
                    pointRadius: 5,
                    pointBackgroundColor: '#00473e'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { 
                        beginAtZero: true,
                        ticks: { callback: value => '₱' + value.toLocaleString() }
                    }
                }
            }
        });

        // Distribution Chart Logic
        new Chart(document.getElementById('budgetChart'), {
            type: 'doughnut',
            data: {
                labels: ['Spent', 'Available'],
                datasets: [{
                    data: [<?php echo $total_spent; ?>, <?php echo max(0, $available_cash); ?>],
                    backgroundColor: ['#ef4444', '#10b981']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    </script>
</body>
</html>