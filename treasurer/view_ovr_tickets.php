<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Auth Check
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

// --- HANDLE PAYMENT CONFIRMATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_payment') {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    
    try {
        // Start transaction
        $pdo->beginTransaction();

        // 1. Update the Ticket - Using exact lowercase 'paid' to match your ENUM
        $stmt = $pdo->prepare("UPDATE ovr_tickets SET 
            payment_status = 'paid', 
            paid_at = NOW(), 
            payment_method = ? 
            WHERE id = ?");
        $stmt->execute([$payment_method, $ticket_id]);

        // 2. CHECK: If your 'payment_records' table doesn't exist yet, 
        // this part will fail and trigger the catch block (rolling back the update).
        // For now, let's verify if the table exists before inserting.
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'payment_records'");
        if ($tableCheck->rowCount() > 0) {
            $stmtLog = $pdo->prepare("INSERT INTO payment_records (ticket_id, amount, payment_method, treasurer_id, payment_date) 
                                   VALUES (?, (SELECT penalty_amount FROM ovr_tickets WHERE id = ?), ?, ?, NOW())");
            $stmtLog->execute([$ticket_id, $ticket_id, $payment_method, $_SESSION['user_id']]);
        }
        
        $pdo->commit();
        $_SESSION['success_message'] = 'Payment successful! Status changed to PAID.';
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $ticket_id);
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // This will tell you exactly what column or table is missing
        $_SESSION['error_message'] = 'DB Error: ' . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $ticket_id);
        exit();
    }
}

// --- FETCH TICKETS ---
$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT ot.*, v.violation_name FROM ovr_tickets ot LEFT JOIN violations v ON ot.violation_id = v.id ORDER BY ot.created_at DESC");
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $tickets = []; }

// --- CALC TOTALS ---
$totalCollected = 0;
$pendingAmount = 0; 
foreach($tickets as $t) {
    $st = strtolower($t['payment_status'] ?? 'unpaid');
    if($st === 'paid') $totalCollected += (float)$t['penalty_amount'];
    elseif($st === 'pending') $pendingAmount += (float)$t['penalty_amount'];
}

// --- GET SELECTED TICKET ---
$ticket_id = intval($_GET['id'] ?? 0);
$selected_ticket = null;
foreach ($tickets as $t) { if ($t['id'] == $ticket_id) { $selected_ticket = $t; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OVR Tickets - Treasurer Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    <style> * { font-family: 'Poppins', sans-serif; } </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">
    
    <?php include 'sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-gradient-to-r from-lgu-headline to-lgu-stroke shadow-lg border-b-4 border-lgu-button">
            <div class="px-4 py-4">
                <h1 class="text-2xl font-bold text-white uppercase tracking-tight">Financial Collections</h1>
                <p class="text-sm text-gray-200">Processing Resident Payments</p>
            </div>
        </header>

        <main class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-white p-5 rounded-xl shadow-sm border-l-8 border-green-500 flex justify-between items-center">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase">Settled Revenue</p>
                        <p class="text-2xl font-black text-lgu-headline">₱<?php echo number_format($totalCollected, 2); ?></p>
                    </div>
                    <i class="fa fa-vault text-3xl text-green-100"></i>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-sm border-l-8 border-orange-400 flex justify-between items-center">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase">Awaiting Confirmation</p>
                        <p class="text-2xl font-black text-orange-600">₱<?php echo number_format($pendingAmount, 2); ?></p>
                    </div>
                    <i class="fa fa-clock-rotate-left text-3xl text-orange-100/50"></i>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <?php if (empty($tickets)): ?>
                        <div class="text-center py-20 bg-white rounded-xl shadow-sm border-2 border-dashed border-gray-200">
                             <p class="text-gray-400">No violation records found.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): 
                            $curStatus = strtolower($ticket['payment_status'] ?? 'unpaid');
                            $cardBorder = ($curStatus === 'paid') ? 'border-green-500' : (($curStatus === 'pending') ? 'border-orange-400' : 'border-gray-300');
                            $badgeColor = ($curStatus === 'paid') ? 'bg-green-100 text-green-700' : (($curStatus === 'pending') ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700');
                        ?>
                        <div onclick="window.location.href='?id=<?php echo $ticket['id']; ?>'" 
                             class="bg-white rounded-lg shadow-sm p-4 cursor-pointer hover:shadow-md transition-all border-l-4 <?php echo $cardBorder; ?> <?php echo ($ticket_id == $ticket['id']) ? 'ring-2 ring-lgu-button shadow-md' : ''; ?>">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h3 class="font-bold text-lgu-headline"><?php echo htmlspecialchars($ticket['ticket_number']); ?></h3>
                                    <p class="text-xs text-lgu-paragraph font-semibold uppercase"><?php echo htmlspecialchars($ticket['offender_name']); ?></p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <span class="bg-lgu-button text-lgu-button-text px-2 py-0.5 rounded text-[10px] font-bold">₱<?php echo number_format($ticket['penalty_amount'], 2); ?></span>
                                    <span class="<?php echo $badgeColor; ?> px-2 py-0.5 rounded text-[9px] font-black uppercase"><?php echo $curStatus; ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="lg:col-span-1">
                    <?php if ($selected_ticket): 
                        $st = strtolower($selected_ticket['payment_status'] ?? 'unpaid');
                    ?>
                    <div class="bg-white rounded-lg shadow-xl p-6 border-2 border-lgu-button sticky top-24">
                        <div class="text-center border-b-2 border-lgu-stroke pb-4 mb-4">
                            <h1 class="text-lg font-bold text-lgu-headline uppercase">Review Payment</h1>
                        </div>

                        <?php if (!empty($selected_ticket['payment_proof'])): ?>
                        <div class="mb-4 bg-gray-50 p-2 rounded border border-dashed border-gray-300">
                            <p class="text-[9px] font-bold text-gray-500 mb-2 uppercase text-center">Reference Proof Attached</p>
                            <a href="../<?php echo $selected_ticket['payment_proof']; ?>" target="_blank" class="block">
                                <img src="../<?php echo $selected_ticket['payment_proof']; ?>" class="w-full h-40 object-cover rounded border shadow-inner" alt="Proof">
                                <span class="text-[10px] text-blue-600 font-bold block mt-2 text-center underline">VIEW FULL IMAGE</span>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="space-y-3 text-sm mb-6">
                            <div class="flex justify-between border-b border-dashed pb-2">
                                <span class="font-semibold text-xs uppercase">Ref #:</span>
                                <span class="font-bold text-blue-800"><?php echo htmlspecialchars($selected_ticket['reference_number'] ?? 'NO REF'); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-dashed pb-2">
                                <span class="font-semibold text-xs uppercase">Total Fine:</span>
                                <span class="text-red-600 font-black">₱<?php echo number_format($selected_ticket['penalty_amount'], 2); ?></span>
                            </div>
                        </div>

                        <?php if ($st === 'pending' || $st === 'unpaid'): ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="ticket_id" value="<?php echo $selected_ticket['id']; ?>">
                            
                            <div class="bg-gray-100 p-3 rounded-lg border border-gray-200">
                                <label class="block text-[10px] font-bold text-lgu-headline uppercase mb-2">Final Payment Method</label>
                                <select name="payment_method" class="w-full bg-white border border-gray-300 rounded px-3 py-2 text-sm font-bold">
                                    <option value="GCash" <?php echo ($selected_ticket['payment_method'] == 'GCash') ? 'selected' : ''; ?>>GCash / E-Wallet</option>
                                    <option value="Cash">Cash (Over-the-Counter)</option>
                                    <option value="Check">Check Payment</option>
                                </select>
                            </div>

                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl transition flex items-center justify-center gap-2 shadow-lg active:scale-95">
                                <i class="fa fa-check-circle"></i> CONFIRM & SETTLE
                            </button>
                        </form>
                        <?php elseif ($st === 'paid'): ?>
                        <div class="bg-green-50 border-2 border-green-200 rounded-xl p-5 text-center">
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fa fa-check text-green-600 text-xl"></i>
                            </div>
                            <p class="text-sm font-black text-green-700 uppercase">Account Cleared</p>
                            <p class="text-[10px] text-green-500 font-bold mt-2 italic">Recorded: <?php echo date('F j, Y', strtotime($selected_ticket['paid_at'] ?? 'now')); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-lg shadow p-10 text-center border-2 border-dashed border-gray-200 sticky top-24">
                        <i class="fa fa-hand-pointer text-4xl text-gray-200 mb-4 block"></i>
                        <p class="text-lgu-paragraph font-bold">Please select a ticket from the left to view details.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <script>Swal.fire({ title: 'Success', text: '<?php echo $_SESSION['success_message']; ?>', icon: 'success', confirmButtonColor: '#00473e' });</script>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <script>Swal.fire({ title: 'System Error', text: '<?php echo $_SESSION['error_message']; ?>', icon: 'error' });</script>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
</body>
</html>