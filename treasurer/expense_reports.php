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

// --- FIXED FILTER LOGIC ---
$filter = $_GET['range'] ?? 'month';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Priority 1: Reset functionality
if (isset($_GET['reset'])) {
    header("Location: expense_reports.php");
    exit();
}

// Priority 2: If manual dates are empty, calculate based on Quick Range
if (empty($start_date) || empty($end_date)) {
    switch ($filter) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
        case 'month':
        default:
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
    }
}

$ledger = [];

try {
    $query = "
        SELECT * FROM (
            SELECT 
                ticket_number as reference,
                'Income (Ticket)' as type,
                offender_name as description,
                penalty_amount as amount,
                paid_at as date,
                'paid' as status
            FROM ovr_tickets 
            WHERE payment_status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?

            UNION ALL

            SELECT 
                CAST(id AS CHAR) as reference,
                'Expense (Fund)' as type,
                repair_method as description,
                -approved_amount as amount,
                approved_at as date,
                status
            FROM fund_requests 
            WHERE status IN ('approved', 'completed', 'closed') AND DATE(approved_at) BETWEEN ? AND ?
        ) AS transaction_ledger
        ORDER BY date DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
    $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Report error: " . $e->getMessage());
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
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'sans': ['Poppins', 'sans-serif'] },
                    colors: {
                        'lgu-bg': '#f2f7f5',
                        'lgu-headline': '#00473e',
                        'lgu-paragraph': '#475d5b',
                        'lgu-button': '#faae2b',
                        'lgu-button-text': '#00473e',
                        'lgu-stroke': '#00332c'
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Poppins', sans-serif !important; }
        @media print {
            .no-print { display: none !important; }
            .lg\:ml-64 { margin-left: 0 !important; }
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
    <div class="no-print">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </div>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200 no-print">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Financial Ledger</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <div class="bg-white rounded-lg shadow p-6 mb-6 no-print">
                <form method="GET" action="expense_reports.php" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-lgu-headline mb-1 uppercase">Quick Range</label>
                        <select name="range" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-lgu-button outline-none font-medium text-lgu-paragraph">
                            <option value="month" <?php echo $filter == 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="week" <?php echo $filter == 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="year" <?php echo $filter == 'year' ? 'selected' : ''; ?>>This Year</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-lgu-headline mb-1 uppercase">Start Date</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-lgu-button outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-lgu-headline mb-1 uppercase">End Date</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-lgu-button outline-none">
                    </div>
                    <div class="md:col-span-2 flex gap-2">
                        <button type="submit" class="flex-1 bg-lgu-headline text-white font-bold py-2 px-4 rounded-lg hover:bg-opacity-90 transition text-sm uppercase tracking-wider">
                            <i class="fa fa-filter mr-2"></i>Apply Filter
                        </button>
                        <a href="expense_reports.php?reset=1" class="flex-1 bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition text-sm text-center flex items-center justify-center uppercase tracking-wider">
                            <i class="fa fa-undo mr-2"></i>Reset
                        </a>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-lgu-headline">Transaction History</h2>
                        <p class="text-xs text-lgu-paragraph italic">Active Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                    </div>
                    <div class="flex gap-2 no-print">
                        <a href="export_handler.php?format=csv&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition text-sm">
                            <i class="fa fa-download mr-2"></i> CSV
                        </a>
                        <button onclick="window.print()" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg transition text-sm">
                            <i class="fa fa-print mr-2"></i> PDF
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Reference</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Description</th>
                                <th class="py-3 px-4 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_income = 0;
                            $total_expense = 0;
                            if (empty($ledger)): ?>
                                <tr><td colspan="5" class="py-10 text-center text-lgu-paragraph italic">No transaction records for this period.</td></tr>
                            <?php else: foreach ($ledger as $row): 
                                $is_income = $row['amount'] > 0;
                                if ($is_income) $total_income += $row['amount'];
                                else $total_expense += abs($row['amount']);
                            ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="py-3 px-4 text-gray-600"><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                                    <td class="py-3 px-4 font-mono text-xs text-lgu-stroke"><?php echo $row['reference']; ?></td>
                                    <td class="py-3 px-4">
                                        <span class="<?php echo $is_income ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50'; ?> px-2 py-1 rounded text-[10px] font-bold uppercase">
                                            <?php echo $row['type']; ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-lgu-paragraph truncate max-w-[200px]"><?php echo htmlspecialchars($row['description']); ?></td>
                                    <td class="py-3 px-4 text-right font-bold <?php echo $is_income ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo ($is_income ? '+' : '-') . ' ₱' . number_format(abs($row['amount']), 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($ledger)): ?>
                        <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                            <tr>
                                <td colspan="4" class="py-2 px-4 text-right text-xs font-bold text-lgu-paragraph uppercase">Total Income:</td>
                                <td class="py-2 px-4 text-right text-green-600 font-bold">₱<?php echo number_format($total_income, 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="py-2 px-4 text-right text-xs font-bold text-lgu-paragraph uppercase">Total Expenses:</td>
                                <td class="py-2 px-4 text-right text-red-600 font-bold">₱<?php echo number_format($total_expense, 2); ?></td>
                            </tr>
                            <tr class="bg-lgu-headline text-white font-bold">
                                <td colspan="4" class="py-3 px-4 text-right uppercase tracking-wider">Net Balance:</td>
                                <td class="py-3 px-4 text-right text-lg font-bold">
                                    ₱<?php echo number_format($total_income - $total_expense, 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
        });
    </script>
</body>
</html>