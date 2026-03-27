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

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where_clauses = [];
$params = [];

if ($status_filter) {
    $where_clauses[] = "fr.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $where_clauses[] = "DATE(fr.approved_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "DATE(fr.approved_at) <= ?";
    $params[] = $date_to;
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM fund_requests fr LEFT JOIN users u ON fr.treasurer_id = u.id LEFT JOIN reports r ON fr.report_id = r.id $where_sql");
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $limit);
    
    $stmt = $pdo->prepare("
        SELECT fr.id, fr.approved_amount, fr.status, fr.approved_at, fr.created_at, 
               u.fullname as approved_by_name, r.description, fr.treasurer_remarks
        FROM fund_requests fr
        LEFT JOIN users u ON fr.treasurer_id = u.id
        LEFT JOIN reports r ON fr.report_id = r.id
        $where_sql
        ORDER BY fr.approved_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary_stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE 0 END) as total_approved,
            SUM(CASE WHEN status = 'disapproved' THEN approved_amount ELSE 0 END) as total_rejected,
            SUM(CASE WHEN status = 'endorsed' THEN approved_amount ELSE 0 END) as total_pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as count_approved,
            COUNT(CASE WHEN status = 'disapproved' THEN 1 END) as count_rejected,
            COUNT(CASE WHEN status = 'endorsed' THEN 1 END) as count_pending
        FROM fund_requests
    ");
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
    $yearly_stmt = $pdo->prepare("
        SELECT YEAR(approved_at) as year, SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE 0 END) as total, COUNT(*) as count
        FROM fund_requests WHERE status = 'approved' GROUP BY YEAR(approved_at) ORDER BY year DESC
    ");
    $yearly_stmt->execute();
    $yearly = $yearly_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $monthly_stmt = $pdo->prepare("
        SELECT DATE_FORMAT(approved_at, '%Y-%m') as month, DATE_FORMAT(approved_at, '%M %Y') as month_label, SUM(approved_amount) as total, COUNT(*) as count
        FROM fund_requests WHERE status = 'approved' AND YEAR(approved_at) = YEAR(NOW()) GROUP BY DATE_FORMAT(approved_at, '%Y-%m') ORDER BY month DESC
    ");
    $monthly_stmt->execute();
    $monthly = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Audit trail error: " . $e->getMessage());
    $audit_logs = [];
    $total_records = 0;
    $total_pages = 1;
    $summary = ['total_approved' => 0, 'total_rejected' => 0, 'total_pending' => 0, 'count_approved' => 0, 'count_rejected' => 0, 'count_pending' => 0];
    $yearly = [];
    $monthly = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Audit Trail - RTIM</title>
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
                <h1 class="text-2xl font-bold text-lgu-headline">Audit Trail</h1>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border-l-4 border-green-500">
                    <p class="text-xs font-semibold text-green-600 uppercase">Total Approved</p>
                    <p class="text-2xl font-bold text-green-700 mt-2">₱<?php echo number_format($summary['total_approved'] ?? 0, 2); ?></p>
                    <p class="text-xs text-green-600 mt-1"><?php echo $summary['count_approved'] ?? 0; ?> requests</p>
                </div>
                <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg shadow p-4 border-l-4 border-yellow-500">
                    <p class="text-xs font-semibold text-yellow-600 uppercase">Pending</p>
                    <p class="text-2xl font-bold text-yellow-700 mt-2">₱<?php echo number_format($summary['total_pending'] ?? 0, 2); ?></p>
                    <p class="text-xs text-yellow-600 mt-1"><?php echo $summary['count_pending'] ?? 0; ?> requests</p>
                </div>
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg shadow p-4 border-l-4 border-red-500">
                    <p class="text-xs font-semibold text-red-600 uppercase">Rejected</p>
                    <p class="text-2xl font-bold text-red-700 mt-2">₱<?php echo number_format($summary['total_rejected'] ?? 0, 2); ?></p>
                    <p class="text-xs text-red-600 mt-1"><?php echo $summary['count_rejected'] ?? 0; ?> requests</p>
                </div>
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <p class="text-xs font-semibold text-blue-600 uppercase">Total Budget</p>
                    <p class="text-2xl font-bold text-blue-700 mt-2">₱<?php echo number_format(($summary['total_approved'] ?? 0) + ($summary['total_pending'] ?? 0) + ($summary['total_rejected'] ?? 0), 2); ?></p>
                    <p class="text-xs text-blue-600 mt-1">All requests</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Yearly Summary</h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php if (count($yearly) > 0): ?>
                            <?php foreach ($yearly as $y): ?>
                            <div class="flex justify-between items-center p-2 border-b">
                                <span class="font-semibold text-lgu-headline"><?php echo $y['year']; ?></span>
                                <div class="text-right">
                                    <p class="font-bold text-green-600">₱<?php echo number_format($y['total'], 2); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $y['count']; ?> requests</p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-4">No yearly data</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-4">
                    <h3 class="text-lg font-bold text-lgu-headline mb-4">Monthly Summary (<?php echo date('Y'); ?>)</h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php if (count($monthly) > 0): ?>
                            <?php foreach ($monthly as $m): ?>
                            <div class="flex justify-between items-center p-2 border-b">
                                <span class="font-semibold text-lgu-headline"><?php echo $m['month_label']; ?></span>
                                <div class="text-right">
                                    <p class="font-bold text-green-600">₱<?php echo number_format($m['total'], 2); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $m['count']; ?> requests</p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-4">No monthly data</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <select name="status" class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        <option value="">All Status</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="endorsed" <?php echo $status_filter === 'endorsed' ? 'selected' : ''; ?>>Pending</option>
                        <option value="disapproved" <?php echo $status_filter === 'disapproved' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button">
                    <button type="submit" class="px-4 py-2 bg-lgu-button text-lgu-button-text rounded-lg font-semibold hover:opacity-90">Filter</button>
                    <a href="audit_trail.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg font-semibold text-center hover:bg-gray-400">Reset</a>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">Date</th>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">What</th>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">Amount</th>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">Who Approved</th>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">Remarks</th>
                            <th class="px-6 py-3 text-left font-semibold text-lgu-paragraph">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($audit_logs) > 0): ?>
                            <?php foreach ($audit_logs as $log): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-600"><?php echo date('M d, Y H:i', strtotime($log['approved_at'] ?? $log['created_at'])); ?></td>
                                <td class="px-6 py-3 font-medium text-lgu-headline"><?php echo htmlspecialchars($log['description'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-3 font-bold text-lgu-headline">₱<?php echo number_format($log['approved_amount'], 2); ?></td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($log['approved_by_name'] ?? 'System'); ?></td>
                                <td class="px-6 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($log['treasurer_remarks'] ?? '-'); ?></td>
                                <td class="px-6 py-3">
                                    <span class="px-2 py-1 rounded text-xs font-bold <?php echo $log['status'] === 'approved' ? 'bg-green-100 text-green-700' : ($log['status'] === 'disapproved' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                        <?php echo ucfirst($log['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No audit records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="flex justify-between items-center mt-6">
                <p class="text-gray-600">Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> records</p>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">First</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">Previous</a>
                    <?php endif; ?>
                    <span class="px-3 py-2">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">Next</a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">Last</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
