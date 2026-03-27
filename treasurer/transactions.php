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
$transactions = [];

// Filters
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

try {
    // Get payments
    $payments = [];
    $stmt = $pdo->prepare("
        SELECT id, resident_id, payment_type as type, description, amount, status, 
               payment_date as transaction_date, 'payment' as source, recorded_by
        FROM payments
        WHERE 1=1
        " . ($type_filter && $type_filter !== 'payment' ? "AND 0" : "") . "
        " . ($status_filter ? "AND status = ?" : "") . "
        " . ($date_from ? "AND DATE(payment_date) >= ?" : "") . "
        " . ($date_to ? "AND DATE(payment_date) <= ?" : "") . "
        " . ($search ? "AND (description LIKE ? OR payment_type LIKE ?)" : "") . "
    ");
    
    $params = [];
    if ($status_filter) $params[] = $status_filter;
    if ($date_from) $params[] = $date_from;
    if ($date_to) $params[] = $date_to;
    if ($search) {
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get OVR tickets
    $ovr_tickets = [];
    if (!$type_filter || $type_filter === 'ticket') {
        $stmt = $pdo->prepare("
            SELECT id, resident_id, 'ticket' as type, ticket_number as description, 
                   penalty_amount as amount, payment_status as status, 
                   created_at as transaction_date, 'ovr_ticket' as source, inspector_id
            FROM ovr_tickets
            WHERE 1=1
            " . ($status_filter ? "AND payment_status = ?" : "") . "
            " . ($date_from ? "AND DATE(created_at) >= ?" : "") . "
            " . ($date_to ? "AND DATE(created_at) <= ?" : "") . "
            " . ($search ? "AND (ticket_number LIKE ? OR offender_name LIKE ?)" : "") . "
        ");
        
        $params = [];
        if ($status_filter) $params[] = $status_filter;
        if ($date_from) $params[] = $date_from;
        if ($date_to) $params[] = $date_to;
        if ($search) {
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $stmt->execute($params);
        $ovr_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get violation tickets
    $violation_tickets = [];
    if (!$type_filter || $type_filter === 'fine') {
        $stmt = $pdo->prepare("
            SELECT id, resident_id, 'fine' as type, ticket_number as description, 
                   fine_amount as amount, payment_status as status, 
                   issued_date as transaction_date, 'violation_ticket' as source, inspector_id
            FROM violation_tickets
            WHERE 1=1
            " . ($status_filter ? "AND payment_status = ?" : "") . "
            " . ($date_from ? "AND DATE(issued_date) >= ?" : "") . "
            " . ($date_to ? "AND DATE(issued_date) <= ?" : "") . "
            " . ($search ? "AND (ticket_number LIKE ? OR offender_name LIKE ?)" : "") . "
        ");
        
        $params = [];
        if ($status_filter) $params[] = $status_filter;
        if ($date_from) $params[] = $date_from;
        if ($date_to) $params[] = $date_to;
        if ($search) {
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $stmt->execute($params);
        $violation_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Merge all transactions and sort by date
    $transactions = array_merge($payments, $ovr_tickets, $violation_tickets);
    usort($transactions, function($a, $b) {
        return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
    });
    
} catch (PDOException $e) {
    error_log("Transactions query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>All Transactions - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">All Transactions</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
                    <h2 class="text-2xl font-bold text-lgu-headline">Transactions</h2>
                    <div class="flex gap-2">
                        <a href="?export=csv<?php echo http_build_query(array_filter(['type' => $type_filter, 'status' => $status_filter, 'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search])); ?>" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition">
                            <i class="fa fa-download mr-2"></i> Export CSV
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-gray-50 p-4 rounded-lg mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        </div>

                        <!-- Type Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Type</label>
                            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                                <option value="">All Types</option>
                                <option value="payment" <?php echo $type_filter === 'payment' ? 'selected' : ''; ?>>Payments</option>
                                <option value="ticket" <?php echo $type_filter === 'ticket' ? 'selected' : ''; ?>>Tickets</option>
                                <option value="fine" <?php echo $type_filter === 'fine' ? 'selected' : ''; ?>>Fines</option>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">Status</label>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                                <option value="">All Status</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>

                        <!-- Date From -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        </div>

                        <!-- Date To -->
                        <div>
                            <label class="block text-sm font-semibold text-lgu-headline mb-2">To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-2 items-end">
                            <button type="submit" class="flex-1 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-2 px-4 rounded-lg transition">
                                <i class="fa fa-search mr-2"></i> Filter
                            </button>
                            <a href="transactions.php" class="flex-1 bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded-lg transition text-center">
                                <i class="fa fa-redo mr-2"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs font-semibold text-lgu-paragraph bg-gray-50">
                                <th class="py-3 px-4">Description</th>
                                <th class="py-3 px-4">Amount</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="5" class="py-6 text-center text-lgu-paragraph">No transactions found</td></tr>
                            <?php else: foreach ($transactions as $txn): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="py-3 px-4 font-semibold text-lgu-headline"><?php echo htmlspecialchars($txn['description'] ?? 'N/A'); ?></td>
                                    <td class="py-3 px-4 font-semibold">₱<?php echo number_format($txn['amount'] ?? 0, 2); ?></td>
                                    <td class="py-3 px-4">
                                        <?php 
                                            $type_class = '';
                                            $type_label = '';
                                            if ($txn['source'] === 'payment') {
                                                $type_class = 'bg-blue-100 text-blue-700';
                                                $type_label = ucfirst($txn['type']);
                                            } elseif ($txn['source'] === 'ovr_ticket') {
                                                $type_class = 'bg-purple-100 text-purple-700';
                                                $type_label = 'Ticket';
                                            } elseif ($txn['source'] === 'violation_ticket') {
                                                $type_class = 'bg-orange-100 text-orange-700';
                                                $type_label = 'Fine';
                                            }
                                        ?>
                                        <span class="<?php echo $type_class; ?> px-2 py-1 rounded text-xs font-semibold">
                                            <?php echo $type_label; ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600"><?php echo $txn['transaction_date'] ? date('M d, Y', strtotime($txn['transaction_date'])) : 'N/A'; ?></td>
                                    <td class="py-3 px-4">
                                        <?php 
                                            $status_class = '';
                                            $status = strtolower($txn['status'] ?? 'pending');
                                            if ($status === 'paid') {
                                                $status_class = 'bg-green-100 text-green-700';
                                            } elseif ($status === 'unpaid') {
                                                $status_class = 'bg-red-100 text-red-700';
                                            } elseif ($status === 'pending') {
                                                $status_class = 'bg-yellow-100 text-yellow-700';
                                            } elseif ($status === 'overdue') {
                                                $status_class = 'bg-red-200 text-red-800';
                                            } else {
                                                $status_class = 'bg-gray-100 text-gray-700';
                                            }
                                        ?>
                                        <span class="<?php echo $status_class; ?> px-2 py-1 rounded text-xs font-semibold">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                    <?php 
                        $total_amount = array_sum(array_column($transactions, 'amount'));
                        $paid_count = count(array_filter($transactions, fn($t) => strtolower($t['status']) === 'paid'));
                        $unpaid_count = count(array_filter($transactions, fn($t) => strtolower($t['status']) === 'unpaid'));
                        $pending_count = count(array_filter($transactions, fn($t) => strtolower($t['status']) === 'pending'));
                    ?>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg border border-green-200">
                        <p class="text-sm text-green-700 font-semibold">Total Amount</p>
                        <p class="text-2xl font-bold text-green-900">₱<?php echo number_format($total_amount, 2); ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg border border-blue-200">
                        <p class="text-sm text-blue-700 font-semibold">Paid</p>
                        <p class="text-2xl font-bold text-blue-900"><?php echo $paid_count; ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-red-50 to-red-100 p-4 rounded-lg border border-red-200">
                        <p class="text-sm text-red-700 font-semibold">Unpaid</p>
                        <p class="text-2xl font-bold text-red-900"><?php echo $unpaid_count; ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 p-4 rounded-lg border border-yellow-200">
                        <p class="text-sm text-yellow-700 font-semibold">Pending</p>
                        <p class="text-2xl font-bold text-yellow-900"><?php echo $pending_count; ?></p>
                    </div>
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
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
    
    <?php
    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Description', 'Amount', 'Type', 'Date', 'Status']);
        
        foreach ($transactions as $txn) {
            $type_label = '';
            if ($txn['source'] === 'payment') {
                $type_label = ucfirst($txn['type']);
            } elseif ($txn['source'] === 'ovr_ticket') {
                $type_label = 'Ticket';
            } elseif ($txn['source'] === 'violation_ticket') {
                $type_label = 'Fine';
            }
            
            fputcsv($output, [
                $txn['description'],
                $txn['amount'],
                $type_label,
                $txn['transaction_date'] ? date('M d, Y', strtotime($txn['transaction_date'])) : 'N/A',
                ucfirst($txn['status'])
            ]);
        }
        
        fclose($output);
        exit();
    }
    ?>
</body>
</html>
