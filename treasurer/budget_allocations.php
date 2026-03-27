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
    // Corrected to match your SQL dump: fund_requests table and approved_amount/approved_at columns
    $stmt = $pdo->prepare("
        SELECT fr.*, r.hazard_type, r.description as report_description
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status = 'approved'
        ORDER BY r.hazard_type, fr.approved_at DESC
    ");
    $stmt->execute();
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Budget allocations query error: " . $e->getMessage());
    $allocations = [];
}

// Group by hazard type
$grouped = [];
foreach ($allocations as $alloc) {
    $type = $alloc['hazard_type'] ?? 'Unassigned';
    if (!isset($grouped[$type])) {
        $grouped[$type] = [];
    }
    $grouped[$type][] = $alloc;
}

// Updated to use approved_amount from your SQL schema
$total_amount = array_sum(array_column($allocations, 'approved_amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Budget Allocations - RTIM</title>
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
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Budget Allocations</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline">Approved Budget Allocations</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-blue-50 p-4 rounded-lg border-l-4 border-blue-500">
                        <p class="text-sm text-gray-600">Total Records</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo count($allocations); ?></p>
                    </div>
                    <div class="bg-green-50 p-4 rounded-lg border-l-4 border-green-500">
                        <p class="text-sm text-gray-600">Grand Total</p>
                        <p class="text-2xl font-bold text-green-600">₱<?php echo number_format($total_amount, 2); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg border-l-4 border-blue-600 cursor-pointer hover:shadow-md transition">
                        <div class="flex items-center gap-3">
                            <i class="fa fa-road text-2xl text-blue-600"></i>
                            <div>
                                <p class="text-xs font-semibold text-blue-600 uppercase">Road</p>
                                <p class="text-lg font-bold text-blue-700"><?php echo count(array_filter($allocations, fn($a) => strtolower($a['hazard_type'] ?? '') === 'road')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg border-l-4 border-purple-600 cursor-pointer hover:shadow-md transition">
                        <div class="flex items-center gap-3">
                            <i class="fa fa-bridge text-2xl text-purple-600"></i>
                            <div>
                                <p class="text-xs font-semibold text-purple-600 uppercase">Bridge</p>
                                <p class="text-lg font-bold text-purple-700"><?php echo count(array_filter($allocations, fn($a) => strtolower($a['hazard_type'] ?? '') === 'bridge')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 p-4 rounded-lg border-l-4 border-orange-600 cursor-pointer hover:shadow-md transition">
                        <div class="flex items-center gap-3">
                            <i class="fa fa-traffic-light text-2xl text-orange-600"></i>
                            <div>
                                <p class="text-xs font-semibold text-orange-600 uppercase">Traffic</p>
                                <p class="text-lg font-bold text-orange-700"><?php echo count(array_filter($allocations, fn($a) => strtolower($a['hazard_type'] ?? '') === 'traffic')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($allocations)): ?>
                    <div class="text-center py-8 text-lgu-paragraph">
                        <i class="fa fa-inbox text-4xl mb-4 opacity-50"></i>
                        <p>No approved budget allocations found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped as $type => $items): ?>
                        <div class="mb-8">
                            <div class="bg-lgu-headline text-white p-4 rounded-lg mb-4 flex justify-between items-center">
                                <h3 class="text-lg font-bold"><?php echo htmlspecialchars(ucfirst($type)); ?></h3>
                                <div class="text-right">
                                    <p class="text-sm opacity-90"><?php echo count($items); ?> allocation(s)</p>
                                    <p class="text-xl font-bold">₱<?php echo number_format(array_sum(array_column($items, 'approved_amount')), 2); ?></p>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead>
                                        <tr class="text-xs font-bold text-white bg-lgu-headline border-b-2 border-lgu-button">
                                            <th class="py-4 px-4 uppercase tracking-wide">Description / Repair Method</th>
                                            <th class="py-4 px-4 uppercase tracking-wide">Hazard Type</th>
                                            <th class="py-4 px-4 text-right uppercase tracking-wide">Amount</th>
                                            <th class="py-4 px-4 uppercase tracking-wide">Approved Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): 
                                            $hazardType = strtolower($item['hazard_type'] ?? '');
                                            $hazardBadge = ($hazardType === 'road') ? 'bg-blue-100 text-blue-800' : (($hazardType === 'bridge') ? 'bg-purple-100 text-purple-800' : 'bg-orange-100 text-orange-800');
                                            $hazardIcon = ($hazardType === 'road') ? 'fa-road' : (($hazardType === 'bridge') ? 'fa-bridge' : 'fa-traffic-light');
                                        ?>
                                            <tr class="border-b border-gray-200 hover:bg-lgu-bg transition-colors">
                                                <td class="py-4 px-4">
                                                    <div class="font-semibold text-lgu-headline"><?php echo htmlspecialchars($item['repair_method']); ?></div>
                                                    <div class="text-xs text-lgu-paragraph mt-1"><?php echo htmlspecialchars($item['report_description'] ?? ''); ?></div>
                                                </td>
                                                <td class="py-4 px-4">
                                                    <span class="<?php echo $hazardBadge; ?> px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1 w-fit">
                                                        <i class="fa <?php echo $hazardIcon; ?>"></i> <?php echo ucfirst($hazardType); ?>
                                                    </span>
                                                </td>
                                                <td class="py-4 px-4 text-right">
                                                    <span class="bg-lgu-button text-lgu-button-text px-3 py-1 rounded-lg font-bold text-sm">₱<?php echo number_format($item['approved_amount'], 2); ?></span>
                                                </td>
                                                <td class="py-4 px-4 text-lgu-paragraph font-medium">
                                                    <?php echo $item['approved_at'] ? date('M d, Y', strtotime($item['approved_at'])) : 'N/A'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
</body>
</html>