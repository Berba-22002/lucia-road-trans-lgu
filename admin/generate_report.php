<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$format = $_GET['format'] ?? 'view';
$type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$period = $_GET['period'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$query = "SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, r.description, u.fullname as reporter_name 
          FROM reports r 
          LEFT JOIN users u ON r.user_id = u.id WHERE 1=1";
$params = [];

if ($type !== 'all') {
    $query .= " AND r.hazard_type = ?";
    $params[] = $type;
}
if ($status !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $status;
}

if ($start_date && $end_date) {
    $query .= " AND DATE(r.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
} elseif ($period === 'year') {
    $query .= " AND YEAR(r.created_at) = YEAR(NOW())";
} elseif ($period === 'month') {
    $query .= " AND YEAR(r.created_at) = YEAR(NOW()) AND MONTH(r.created_at) = MONTH(NOW())";
} elseif ($period === 'week') {
    $query .= " AND WEEK(r.created_at) = WEEK(NOW()) AND YEAR(r.created_at) = YEAR(NOW())";
}

$count_query = str_replace('SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, r.description, u.fullname as reporter_name', 'SELECT COUNT(*) as total', $query);
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total / $per_page);

$query_paginated = $query . " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$params_paginated = array_merge($params, [$per_page, ($page - 1) * $per_page]);

$stmt = $pdo->prepare($query_paginated);
$stmt->execute($params_paginated);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_all = $query . " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($query_all);
$stmt->execute($params);
$all_reports_print = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format === 'csv') {
    $query_all = "SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, r.description, u.fullname as reporter_name 
                  FROM reports r 
                  LEFT JOIN users u ON r.user_id = u.id WHERE 1=1";
    $params_all = [];
    
    if ($type !== 'all') {
        $query_all .= " AND r.hazard_type = ?";
        $params_all[] = $type;
    }
    if ($status !== 'all') {
        $query_all .= " AND r.status = ?";
        $params_all[] = $status;
    }
    if ($start_date && $end_date) {
        $query_all .= " AND DATE(r.created_at) BETWEEN ? AND ?";
        $params_all[] = $start_date;
        $params_all[] = $end_date;
    } elseif ($period === 'year') {
        $query_all .= " AND YEAR(r.created_at) = YEAR(NOW())";
    } elseif ($period === 'month') {
        $query_all .= " AND YEAR(r.created_at) = YEAR(NOW()) AND MONTH(r.created_at) = MONTH(NOW())";
    } elseif ($period === 'week') {
        $query_all .= " AND WEEK(r.created_at) = WEEK(NOW()) AND YEAR(r.created_at) = YEAR(NOW())";
    }
    $query_all .= " ORDER BY r.created_at DESC";
    
    $stmt = $pdo->prepare($query_all);
    $stmt->execute($params_all);
    $all_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'reports_' . $type . '_' . ($start_date ? $start_date . '_to_' . $end_date : $period) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Type', 'Address', 'Status', 'Reporter', 'Date', 'Description']);
    
    foreach ($all_reports as $report) {
        fputcsv($output, [
            $report['id'],
            ucfirst($report['hazard_type']),
            $report['address'],
            ucwords(str_replace('_', ' ', $report['status'])),
            $report['reporter_name'] ?? 'Unknown',
            date('M d, Y', strtotime($report['created_at'])),
            $report['description']
        ]);
    }
    fclose($output);
    exit();
}

if ($format === 'pdf') {
    $query_all = "SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, r.description, u.fullname as reporter_name 
                  FROM reports r 
                  LEFT JOIN users u ON r.user_id = u.id WHERE 1=1";
    $params_all = [];
    
    if ($type !== 'all') {
        $query_all .= " AND r.hazard_type = ?";
        $params_all[] = $type;
    }
    if ($status !== 'all') {
        $query_all .= " AND r.status = ?";
        $params_all[] = $status;
    }
    if ($start_date && $end_date) {
        $query_all .= " AND DATE(r.created_at) BETWEEN ? AND ?";
        $params_all[] = $start_date;
        $params_all[] = $end_date;
    } elseif ($period === 'year') {
        $query_all .= " AND YEAR(r.created_at) = YEAR(NOW())";
    } elseif ($period === 'month') {
        $query_all .= " AND YEAR(r.created_at) = YEAR(NOW()) AND MONTH(r.created_at) = MONTH(NOW())";
    } elseif ($period === 'week') {
        $query_all .= " AND WEEK(r.created_at) = WEEK(NOW()) AND YEAR(r.created_at) = YEAR(NOW())";
    }
    $query_all .= " ORDER BY r.created_at DESC";
    
    $stmt = $pdo->prepare($query_all);
    $stmt->execute($params_all);
    $all_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    require_once __DIR__ . '/../vendor/autoload.php';
    $pdf = new \TCPDF();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage();
    
    $date_range = $start_date ? $start_date . ' to ' . $end_date : ucfirst($period);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Reports Summary - ' . ucfirst($type) . ' (' . $date_range . ')', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Generated: ' . date('M d, Y H:i'), 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(0, 71, 62);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(15, 7, 'ID', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Type', 1, 0, 'C', true);
    $pdf->Cell(50, 7, 'Address', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Status', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Reporter', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Date', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    foreach ($all_reports as $report) {
        $pdf->Cell(15, 6, $report['id'], 1, 0);
        $pdf->Cell(25, 6, ucfirst($report['hazard_type']), 1, 0);
        $pdf->Cell(50, 6, substr($report['address'], 0, 30), 1, 0);
        $pdf->Cell(30, 6, ucwords(str_replace('_', ' ', $report['status'])), 1, 0);
        $pdf->Cell(35, 6, substr($report['reporter_name'] ?? 'Unknown', 0, 20), 1, 0);
        $pdf->Cell(25, 6, date('M d, Y', strtotime($report['created_at'])), 1, 1);
    }
    
    $filename = 'reports_' . $type . '_' . ($start_date ? $start_date . '_to_' . $end_date : $period) . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Report</title>
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
                        'lgu-button-text': '#00473e'
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Poppins', sans-serif; }
        @media print {
            body { background: white; }
            .no-print { display: none; }
            .print-section { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen p-6">
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h1 class="text-3xl font-bold text-lgu-headline mb-6">
                <i class="fas fa-file-export mr-2"></i>Generate Report
            </h1>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-8 gap-4 mb-6 no-print">
                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Hazard Type</label>
                    <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded">
                        <option value="all">All Types</option>
                        <option value="road" <?= $type === 'road' ? 'selected' : '' ?>>Road Only</option>
                        <option value="bridge" <?= $type === 'bridge' ? 'selected' : '' ?>>Bridge Only</option>
                        <option value="traffic" <?= $type === 'traffic' ? 'selected' : '' ?>>Traffic Only</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Period</label>
                    <select name="period" class="w-full px-4 py-2 border border-gray-300 rounded">
                        <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>This Year</option>
                        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>This Week</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="w-full px-4 py-2 border border-gray-300 rounded">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="w-full px-4 py-2 border border-gray-300 rounded">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded">
                        <option value="all">All Status</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>Done</option>
                        <option value="escalated" <?= $status === 'escalated' ? 'selected' : '' ?>>Escalated</option>
                    </select>
                </div>
                
                <div class="flex gap-2 items-end">
                    <button type="submit" class="flex-1 px-4 py-2 bg-lgu-button text-lgu-button-text rounded font-bold hover:bg-yellow-500">
                        <i class="fas fa-filter mr-2"></i>Filter
                    </button>
                </div>
                
                <div class="flex gap-2 items-end">
                    <a href="?format=csv&type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="flex-1 px-4 py-2 bg-green-600 text-white rounded font-bold hover:bg-green-700 text-center">
                        <i class="fas fa-download mr-2"></i>CSV
                    </a>
                </div>
                
                <div class="flex gap-2 items-end">
                    <a href="?format=pdf&type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="flex-1 px-4 py-2 bg-red-600 text-white rounded font-bold hover:bg-red-700 text-center">
                        <i class="fas fa-file-pdf mr-2"></i>PDF
                    </a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 print-section">
            <div class="text-center mb-6 print-header">
                <h2 class="text-2xl font-bold text-lgu-headline">Reports Summary</h2>
                <p class="text-sm text-lgu-paragraph">Generated: <?= date('M d, Y H:i') ?></p>
                <p class="text-sm text-lgu-paragraph">
                    Type: <?= ucfirst($type) ?> | 
                    <?php if ($start_date && $end_date): ?>
                        Date Range: <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?>
                    <?php else: ?>
                        Period: <?= ucfirst($period) ?>
                    <?php endif; ?> | 
                    Status: <?= ucfirst(str_replace('_', ' ', $status)) ?>
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-lgu-headline text-white">
                            <th class="border border-gray-300 px-4 py-2 text-left">ID</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Type</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Address</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Status</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Reporter</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Date</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $display_reports = (isset($_GET['print']) || strpos($_SERVER['HTTP_USER_AGENT'], 'print') !== false) ? $all_reports_print : $reports; ?>
                        <?php if (empty($display_reports)): ?>
                            <tr>
                                <td colspan="7" class="border border-gray-300 px-4 py-3 text-center text-gray-500">No reports found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($display_reports as $report): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-4 py-2 font-bold">#<?= str_pad($report['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td class="border border-gray-300 px-4 py-2"><?= ucfirst($report['hazard_type']) ?></td>
                                    <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($report['address']) ?></td>
                                    <td class="border border-gray-300 px-4 py-2">
                                        <span class="px-2 py-1 rounded text-xs font-semibold
                                            <?php
                                                $colors = [
                                                    'pending' => 'bg-orange-100 text-orange-800',
                                                    'in_progress' => 'bg-blue-100 text-blue-800',
                                                    'done' => 'bg-green-100 text-green-800',
                                                    'escalated' => 'bg-red-100 text-red-800'
                                                ];
                                                echo $colors[$report['status']] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                        ">
                                            <?= ucwords(str_replace('_', ' ', $report['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-2"><?= htmlspecialchars($report['reporter_name'] ?? 'Unknown') ?></td>
                                    <td class="border border-gray-300 px-4 py-2"><?= date('M d, Y', strtotime($report['created_at'])) ?></td>
                                    <td class="border border-gray-300 px-4 py-2 text-xs"><?= substr(htmlspecialchars($report['description']), 0, 50) ?>...</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex justify-between items-center no-print">
                <div class="text-sm text-lgu-paragraph">
                    Showing <?= empty($reports) ? 0 : (($page - 1) * $per_page + 1) ?> to <?= min($page * $per_page, $total) ?> of <?= $total ?> records
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-100">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="?type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $page - 1 ?>" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-100">Prev</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $i ?>" class="px-3 py-1 border rounded <?= $i === $page ? 'bg-lgu-button text-lgu-button-text' : 'border-gray-300 hover:bg-gray-100' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $page + 1 ?>" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-100">Next</a>
                            <a href="?type=<?= $type ?>&status=<?= $status ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $total_pages ?>" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-100">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-4 text-center text-xs text-lgu-paragraph" style="display: none;">
                <p>Total Records: <?= $total ?></p>
            </div>
            <style media="print">
                @page { margin: 0.5cm; }
                table { page-break-inside: avoid; }
                tr { page-break-inside: avoid; }
            </style>
        </div>
    </div>
</body>
</html>
