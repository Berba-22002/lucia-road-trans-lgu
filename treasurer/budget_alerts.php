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
$budget_limit = 0;
$total_spent = 0;
$percentage = 0;
$alert_level = 'normal';

try {
    $stmt = $pdo->prepare("SELECT SUM(penalty_amount) as total_collected FROM ovr_tickets WHERE payment_status = 'paid'");
    $stmt->execute();
    $collected_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $budget_limit = (float)($collected_row['total_collected'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT SUM(fr.approved_amount) as spent
        FROM fund_requests fr
        INNER JOIN maintenance_assignments ma ON fr.report_id = ma.report_id
        WHERE fr.status = 'approved' AND ma.status = 'completed'
    ");
    $stmt->execute();
    $spent_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_spent = (float)($spent_row['spent'] ?? 0);
    
    $percentage = $budget_limit > 0 ? ($total_spent / $budget_limit) * 100 : 0;
    $alert_level = $percentage >= 90 ? 'critical' : ($percentage >= 75 ? 'warning' : 'normal');
    
} catch (PDOException $e) {
    error_log("Budget alerts error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Budget Alerts - RTIM</title>
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
                <h1 class="text-2xl font-bold text-lgu-headline">Budget Alerts</h1>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <!-- Alert Banner -->
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke rounded-lg p-6 text-white mb-6 shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-3xl font-bold mb-2">Budget Status</h2>
                        <p class="text-gray-200">Collected: ₱<?php echo number_format($budget_limit, 0); ?> | Spent: ₱<?php echo number_format($total_spent, 0); ?></p>
                    </div>
                    <div class="text-right">
                        <div class="text-6xl font-bold"><?php echo number_format($percentage, 0); ?>%</div>
                        <div class="text-sm mt-2 px-4 py-2 rounded-full <?php echo $alert_level === 'critical' ? 'bg-red-500' : ($alert_level === 'warning' ? 'bg-yellow-500' : 'bg-green-500'); ?>">
                            <?php echo strtoupper($alert_level); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="mb-4">
                    <div class="flex justify-between mb-3">
                        <span class="font-semibold text-lgu-headline">Spending Progress</span>
                        <span class="text-sm text-lgu-paragraph"><?php echo number_format($percentage, 1); ?>% of budget used</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-8 overflow-hidden">
                        <div class="<?php echo $alert_level === 'critical' ? 'bg-red-500' : ($alert_level === 'warning' ? 'bg-yellow-500' : 'bg-green-500'); ?> h-8 rounded-full transition-all flex items-center justify-center text-white font-bold text-sm" style="width: <?php echo min($percentage, 100); ?>%">
                            <?php if ($percentage > 10) echo number_format($percentage, 0) . '%'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Three Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-lgu-button">
                    <p class="text-sm font-semibold text-lgu-paragraph uppercase mb-2">Collected Payments</p>
                    <p class="text-4xl font-bold text-lgu-headline">₱<?php echo number_format($budget_limit, 0); ?></p>
                    <p class="text-xs text-lgu-paragraph mt-2">Total budget available</p>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-500">
                    <p class="text-sm font-semibold text-lgu-paragraph uppercase mb-2">Spent</p>
                    <p class="text-4xl font-bold text-red-600">₱<?php echo number_format($total_spent, 0); ?></p>
                    <p class="text-xs text-lgu-paragraph mt-2">Completed maintenance</p>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
                    <p class="text-sm font-semibold text-lgu-paragraph uppercase mb-2">Remaining</p>
                    <p class="text-4xl font-bold text-green-600">₱<?php echo number_format(max(0, $budget_limit - $total_spent), 0); ?></p>
                    <p class="text-xs text-lgu-paragraph mt-2">Available to spend</p>
                </div>
            </div>

            <!-- Guide Section -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-lgu-headline mb-4 flex items-center gap-2">
                    <i class="fa fa-info-circle text-lgu-button"></i> How Budget Alerts Work
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="border-l-4 border-green-500 pl-4">
                        <h4 class="font-semibold text-green-700 mb-2">Normal Status (Green)</h4>
                        <p class="text-sm text-gray-600">Budget usage is below 75%. Your spending is within safe limits.</p>
                    </div>
                    <div class="border-l-4 border-yellow-500 pl-4">
                        <h4 class="font-semibold text-yellow-700 mb-2">Warning Status (Yellow)</h4>
                        <p class="text-sm text-gray-600">Budget usage is between 75-89%. Monitor spending closely and plan accordingly.</p>
                    </div>
                    <div class="border-l-4 border-red-500 pl-4">
                        <h4 class="font-semibold text-red-700 mb-2">Critical Status (Red)</h4>
                        <p class="text-sm text-gray-600">Budget usage is 90% or higher. Immediate action needed to prevent budget overrun.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
