<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$financial_data = [];

try {
    /* Logic: 
       1. Income is pulled from 'ovr_tickets' where status is 'paid'.
       2. Expenses are pulled from 'fund_requests' where status is 'approved' or beyond.
    */
    $stmt = $pdo->prepare("
        SELECT 
            month,
            SUM(income) as income,
            SUM(expense) as expense
        FROM (
            SELECT 
                DATE_FORMAT(paid_at, '%Y-%m') as month,
                penalty_amount as income,
                0 as expense
            FROM ovr_tickets 
            WHERE payment_status = 'paid' AND paid_at IS NOT NULL

            UNION ALL

            SELECT 
                DATE_FORMAT(approved_at, '%Y-%m') as month,
                0 as income,
                approved_amount as expense
            FROM fund_requests 
            WHERE status IN ('approved', 'completed', 'closed') AND approved_at IS NOT NULL
        ) AS combined_data
        GROUP BY month
        ORDER BY month DESC
        LIMIT 12
    ");
    $stmt->execute();
    $financial_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare data arrays for Chart.js
    $chartLabels = [];
    $chartIncome = [];
    $chartExpense = [];

    // Reverse for chronological order in charts (Jan -> Dec)
    $chronological = array_reverse($financial_data);
    foreach ($chronological as $row) {
        $chartLabels[] = date('M Y', strtotime($row['month'] . '-01'));
        $chartIncome[] = (float)$row['income'];
        $chartExpense[] = (float)$row['expense'];
    }

} catch (PDOException $e) {
    error_log("Financial reports query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Financial Reports - RTIM</title>
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
<body class="bg-lgu-bg min-h-screen font-poppins">
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Financial Reports</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Income vs Expense (Overall)</h3>
                    <div class="chart-container">
                        <canvas id="incomeExpenseChart"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Monthly Trend</h3>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-2xl font-bold text-lgu-headline mb-4">Historical Data</h2>
                <div class="chart-container">
                    <canvas id="historicalChart"></canvas>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM</p>
            </div>
        </footer>
    </div>

    <script>
        // Sidebar Toggle
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });

        // Pass PHP Data to JS
        const labels = <?php echo json_encode($chartLabels); ?>;
        const incomeData = <?php echo json_encode($chartIncome); ?>;
        const expenseData = <?php echo json_encode($chartExpense); ?>;

        // 1. Income vs Expense Bar Chart
        const incomeExpenseCtx = document.getElementById('incomeExpenseChart')?.getContext('2d');
        if (incomeExpenseCtx) {
            new Chart(incomeExpenseCtx, {
                type: 'bar',
                data: {
                    labels: ['Total Income', 'Total Expense'],
                    datasets: [{
                        label: 'Current Data',
                        data: [
                            incomeData.reduce((a, b) => a + b, 0), 
                            expenseData.reduce((a, b) => a + b, 0)
                        ],
                        backgroundColor: ['#10b981', '#ef4444']
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });
        }

        // 2. Monthly Trend Line Chart
        const trendCtx = document.getElementById('trendChart')?.getContext('2d');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Income',
                        data: incomeData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Expense',
                        data: expenseData,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // 3. Historical Data Chart
        const historicalCtx = document.getElementById('historicalChart')?.getContext('2d');
        if (historicalCtx) {
            new Chart(historicalCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Income',
                        data: incomeData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }, {
                        label: 'Expense',
                        data: expenseData,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>