<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

try {
    $pdo = (new Database())->getConnection();
    
    // Fetch payment statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_tickets,
            SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_tickets,
            SUM(CASE WHEN payment_status = 'paid' THEN penalty_amount ELSE 0 END) as total_collected
        FROM ovr_tickets
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch recent payments
    $stmt = $pdo->query("
        SELECT 
            pt.id,
            pt.ticket_id,
            pt.amount,
            pt.payment_method,
            pt.status,
            pt.created_at,
            ot.ticket_number,
            u.first_name,
            u.last_name
        FROM payment_transactions pt
        JOIN ovr_tickets ot ON pt.ticket_id = ot.id
        JOIN users u ON pt.resident_id = u.id
        ORDER BY pt.created_at DESC
        LIMIT 20
    ");
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Payment dashboard error: " . $e->getMessage());
    $stats = [];
    $payments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Dashboard - RTIM Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                        'lgu-stroke': '#00332c'
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
    <?php include 'sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-gradient-to-r from-lgu-headline to-lgu-stroke shadow-lg border-b-4 border-lgu-button">
            <div class="flex items-center justify-between px-4 py-4 gap-4">
                <div class="flex items-center gap-4 flex-1">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-white">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Payment Dashboard</h1>
                        <p class="text-sm text-gray-200">OVR Ticket Payment Overview</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-semibold">Total Tickets</p>
                            <p class="text-3xl font-bold text-lgu-headline mt-2"><?php echo $stats['total_tickets'] ?? 0; ?></p>
                        </div>
                        <i class="fa fa-file-invoice text-4xl text-blue-200"></i>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-semibold">Paid Tickets</p>
                            <p class="text-3xl font-bold text-green-600 mt-2"><?php echo $stats['paid_tickets'] ?? 0; ?></p>
                        </div>
                        <i class="fa fa-check-circle text-4xl text-green-200"></i>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-semibold">Unpaid Tickets</p>
                            <p class="text-3xl font-bold text-orange-600 mt-2"><?php echo $stats['unpaid_tickets'] ?? 0; ?></p>
                        </div>
                        <i class="fa fa-clock text-4xl text-orange-200"></i>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-lgu-paragraph text-sm font-semibold">Total Collected</p>
                            <p class="text-3xl font-bold text-lgu-headline mt-2">₱<?php echo number_format($stats['total_collected'] ?? 0, 2); ?></p>
                        </div>
                        <i class="fa fa-money-bill text-4xl text-yellow-200"></i>
                    </div>
                </div>
            </div>

            <!-- Recent Payments Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-bold text-lgu-headline">Recent Payments</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-lgu-bg border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Ticket #</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Resident</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Amount</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Method</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Status</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-lgu-paragraph">
                                    <i class="fa fa-inbox text-3xl text-gray-300 mb-2 block"></i>
                                    No payments yet
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                <tr class="border-b border-gray-200 hover:bg-lgu-bg transition">
                                    <td class="px-6 py-4 text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($payment['ticket_number']); ?></td>
                                    <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-lgu-headline">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo ucfirst($payment['payment_method']); ?></td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $payment['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo date('M d, Y H:i', strtotime($payment['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
