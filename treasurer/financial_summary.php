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

try {
    $yearly_stmt = $pdo->prepare("
        SELECT YEAR(approved_at) as year, 
               SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE 0 END) as approved,
               COUNT(CASE WHEN status = 'approved' THEN 1 END) as count_approved,
               COUNT(CASE WHEN status = 'endorsed' THEN 1 END) as count_pending,
               COUNT(CASE WHEN status = 'disapproved' THEN 1 END) as count_rejected
        FROM fund_requests GROUP BY YEAR(approved_at) ORDER BY year DESC
    ");
    $yearly_stmt->execute();
    $yearly = $yearly_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_stmt = $pdo->prepare("
        SELECT SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE 0 END) as total_approved,
               SUM(CASE WHEN status = 'disapproved' THEN approved_amount ELSE 0 END) as total_rejected,
               SUM(CASE WHEN status = 'endorsed' THEN approved_amount ELSE 0 END) as total_pending
        FROM fund_requests
    ");
    $total_stmt->execute();
    $totals = $total_stmt->fetch(PDO::FETCH_ASSOC);
    
    $spent_stmt = $pdo->prepare("
        SELECT YEAR(approved_at) as year, SUM(approved_amount) as spent
        FROM fund_requests WHERE status IN ('completed', 'closed') GROUP BY YEAR(approved_at) ORDER BY year DESC
    ");
    $spent_stmt->execute();
    $yearly_spent = $spent_stmt->fetchAll(PDO::FETCH_ASSOC);
    $spent_map = array_column($yearly_spent, 'spent', 'year');
} catch (PDOException $e) {
    error_log("Summary error: " . $e->getMessage());
    $yearly = [];
    $totals = ['total_approved' => 0, 'total_rejected' => 0, 'total_pending' => 0];
    $spent_map = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Financial Summary - RTIM</title>
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
                'lgu-highlight': '#faae2b'
              }
            }
          }
        }
    </script>
    <style>
        * { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center gap-4 px-4 py-3">
                <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-2xl font-bold text-lgu-headline">Financial Summary</h1>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border-l-4 border-green-500">
                    <p class="text-xs font-semibold text-green-600 uppercase">Total Approved</p>
                    <p class="text-2xl font-bold text-green-700 mt-2">₱<?php echo number_format($totals['total_approved'] ?? 0, 2); ?></p>
                </div>
                <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg shadow p-4 border-l-4 border-yellow-500">
                    <p class="text-xs font-semibold text-yellow-600 uppercase">Total Pending</p>
                    <p class="text-2xl font-bold text-yellow-700 mt-2">₱<?php echo number_format($totals['total_pending'] ?? 0, 2); ?></p>
                </div>
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg shadow p-4 border-l-4 border-red-500">
                    <p class="text-xs font-semibold text-red-600 uppercase">Total Rejected</p>
                    <p class="text-2xl font-bold text-red-700 mt-2">₱<?php echo number_format($totals['total_rejected'] ?? 0, 2); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-lgu-headline">Yearly Breakdown - Approved vs Spent</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-lgu-headline text-white">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold">Year</th>
                                <th class="px-6 py-3 text-right font-semibold">Approved</th>
                                <th class="px-6 py-3 text-right font-semibold">Spent</th>
                                <th class="px-6 py-3 text-right font-semibold">Remaining</th>
                                <th class="px-6 py-3 text-right font-semibold">Utilization %</th>
                                <th class="px-6 py-3 text-center font-semibold">Requests</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (count($yearly) > 0): ?>
                                <?php foreach ($yearly as $y): 
                                    $spent = $spent_map[$y['year']] ?? 0;
                                    $remaining = $y['approved'] - $spent;
                                    $utilization = $y['approved'] > 0 ? ($spent / $y['approved']) * 100 : 0;
                                    $total_requests = ($y['count_approved'] ?? 0) + ($y['count_pending'] ?? 0) + ($y['count_rejected'] ?? 0);
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-bold text-lgu-headline"><?php echo $y['year']; ?></td>
                                    <td class="px-6 py-3 text-right text-green-600 font-bold">₱<?php echo number_format($y['approved'], 2); ?></td>
                                    <td class="px-6 py-3 text-right text-blue-600 font-bold">₱<?php echo number_format($spent, 2); ?></td>
                                    <td class="px-6 py-3 text-right font-bold <?php echo $remaining >= 0 ? 'text-gray-600' : 'text-red-600'; ?>">₱<?php echo number_format($remaining, 2); ?></td>
                                    <td class="px-6 py-3 text-right font-bold text-lgu-headline"><?php echo number_format($utilization, 1); ?>%</td>
                                    <td class="px-6 py-3 text-center text-gray-600"><?php echo $total_requests; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">No yearly data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-lgu-headline">Historical Data - Approved vs Spent</h2>
                </div>
                <div class="p-6">
                    <canvas id="historicalChart" height="80"></canvas>
                </div>
            </div>
        </main>
    </div>

    <script>
        const yearlyData = <?php echo json_encode($yearly); ?>;
        const spentMap = <?php echo json_encode($spent_map); ?>;
        
        const years = yearlyData.map(y => y.year).reverse();
        const approved = yearlyData.map(y => y.approved).reverse();
        const spent = years.map(year => spentMap[year] ?? 0);
        
        const ctx = document.getElementById('historicalChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: years,
                datasets: [
                    {
                        label: 'Approved Amount',
                        data: approved,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Spent Amount',
                        data: spent,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4
                    }
                ]
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
    </script>
</body>
</html>
