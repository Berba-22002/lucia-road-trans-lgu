<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$resident_id = $_SESSION['user_id'];

// --- SUCCESS/ERROR HANDLING LOGIC ---
$status_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment_internal'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $method = $_POST['payment_method'];
    $ref_no = $_POST['reference_number'];
    
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === 0) {
        $upload_dir = __DIR__ . '/../uploads/proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file_ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
        $file_name = "proof_" . $ticket_id . "_" . time() . "." . $file_ext;
        
        if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $file_name)) {
            $proof_path = 'uploads/proofs/' . $file_name;
            // SET STATUS TO PENDING (Requires Treasurer Approval)
            $stmt = $pdo->prepare("UPDATE ovr_tickets SET payment_status = 'pending', payment_method = ?, reference_number = ?, payment_proof = ? WHERE id = ? AND resident_id = ?");
            $stmt->execute([$method, $ref_no, $proof_path, $ticket_id, $resident_id]);
            $status_msg = "pending_success";
        } else {
            $status_msg = "upload_fail";
        }
    }
}

// Fetch all tickets for the list
$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT ot.*, v.violation_name FROM ovr_tickets ot LEFT JOIN violations v ON ot.violation_id = v.id WHERE ot.resident_id = ? ORDER BY ot.created_at DESC");
    $stmt->execute([$resident_id]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

// Handle Selection
$ticket_id = intval($_GET['id'] ?? 0);
$selected_ticket = null;
if ($ticket_id) {
    foreach ($tickets as $t) {
        if ($t['id'] == $ticket_id) { $selected_ticket = $t; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Violation Tickets - RTIM Resident</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        @media print { .no-print { display: none; } body { background: white; } .lg\:ml-64 { margin-left: 0; } }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">
    
    <?php if($status_msg === "pending_success"): ?>
        <script>Swal.fire('Payment Submitted', 'Your payment is now being verified by the Treasurer. Status will remain "Pending" until approved.', 'info');</script>
    <?php endif; ?>

    <div class="no-print">
        <?php include 'sidebar.php'; ?>
    </div>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200 no-print">
            <div class="flex items-center justify-between px-4 py-3">
                <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline">My Violation Tickets</h1>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <?php if (empty($tickets)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <i class="fa fa-file-invoice text-6xl text-gray-300 mb-4 block"></i>
                <h3 class="text-xl font-semibold text-lgu-headline">No Violation Tickets Found</h3>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 space-y-4 no-print">
                    <?php foreach ($tickets as $ticket): 
                        $status = $ticket['payment_status'];
                        $borderColor = ($status === 'paid') ? 'border-green-500' : (($status === 'pending') ? 'border-blue-500' : 'border-orange-500');
                        $badgeColor = ($status === 'paid') ? 'bg-green-100 text-green-800' : (($status === 'pending') ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800');
                    ?>
                    <div class="bg-white rounded-xl shadow p-4 cursor-pointer hover:shadow-lg transition border-l-4 <?php echo $borderColor; ?>" onclick="window.location.href='?id=<?php echo $ticket['id']; ?>'">
                        <div class="flex justify-between items-start mb-2">
                            <div>
                                <h3 class="font-bold text-lgu-headline"><?php echo htmlspecialchars($ticket['ticket_number']); ?></h3>
                                <p class="text-sm text-lgu-paragraph"><?php echo htmlspecialchars($ticket['violation_name'] ?? 'N/A'); ?></p>
                            </div>
                            <span class="<?php echo $badgeColor; ?> px-3 py-1 rounded-full text-xs font-bold uppercase">
                                <?php echo $status; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center text-xs text-lgu-paragraph">
                            <span><i class="fa fa-calendar-alt mr-1"></i><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                            <span class="font-bold text-lgu-headline">₱<?php echo number_format($ticket['penalty_amount'], 2); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="lg:col-span-1">
                    <?php if ($selected_ticket): ?>
                    <div id="ticket-detail" class="bg-white rounded-xl shadow-xl p-6 border-2 border-lgu-button sticky top-20">
                        <div class="text-center border-b-2 border-lgu-stroke pb-4 mb-4">
                            <p class="text-[10px] font-bold text-lgu-headline uppercase tracking-widest">Republic of the Philippines</p>
                            <p class="text-[10px] font-bold text-lgu-headline uppercase tracking-widest">Local Government Unit</p>
                            <h1 class="text-lg font-bold text-lgu-headline uppercase leading-tight">Ordinance Violation Receipt</h1>
                        </div>

                        <div class="bg-lgu-button text-lgu-button-text rounded-lg p-3 mb-4 text-center">
                            <p class="text-[10px] font-semibold opacity-80 uppercase">OVR Serial Number</p>
                            <p class="text-2xl font-bold tracking-tighter"><?php echo htmlspecialchars($selected_ticket['ticket_number']); ?></p>
                        </div>

                        <div class="space-y-3 text-sm mb-4">
                            <div class="flex justify-between border-b border-gray-100 pb-2">
                                <span class="font-semibold text-lgu-headline">Violation:</span>
                                <span class="text-lgu-paragraph text-right"><?php echo htmlspecialchars($selected_ticket['violation_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-2">
                                <span class="font-semibold text-lgu-headline">Category:</span>
                                <span class="text-lgu-paragraph"><?php echo htmlspecialchars($selected_ticket['violation_category'] ?? 'General'); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-2">
                                <span class="font-semibold text-lgu-headline">Date:</span>
                                <span class="text-lgu-paragraph"><?php echo date('M d, Y', strtotime($selected_ticket['violation_date'])); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-2">
                                <span class="font-semibold text-lgu-headline">Time:</span>
                                <span class="text-lgu-paragraph"><?php echo date('h:i A', strtotime($selected_ticket['violation_time'])); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-2">
                                <span class="font-semibold text-lgu-headline">Location:</span>
                                <span class="text-lgu-paragraph text-right text-xs max-w-[150px]"><?php echo htmlspecialchars($selected_ticket['location'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-2 bg-red-50 p-2 rounded mt-2">
                                <span class="font-semibold text-lgu-headline">Total Fine:</span>
                                <span class="text-red-600 font-bold">₱<?php echo number_format($selected_ticket['penalty_amount'], 2); ?></span>
                            </div>
                        </div>

                        <?php if ($selected_ticket['violation_description']): ?>
                        <div class="bg-gray-50 p-3 rounded-lg mb-4 text-xs">
                            <p class="font-semibold text-lgu-headline mb-1 uppercase text-[9px]">Official Remarks:</p>
                            <p class="text-lgu-paragraph italic">"<?php echo nl2br(htmlspecialchars($selected_ticket['violation_description'])); ?>"</p>
                        </div>
                        <?php endif; ?>

                        <?php if ($selected_ticket['payment_status'] === 'paid'): ?>
                        <div class="bg-green-50 border-2 border-green-500 rounded-xl p-4 mb-4 text-center">
                            <i class="fa fa-check-circle text-green-600 text-3xl mb-2"></i>
                            <p class="text-green-700 font-bold uppercase">Settled</p>
                            <p class="text-[10px] text-green-600">Ref: <?php echo htmlspecialchars($selected_ticket['reference_number']); ?></p>
                        </div>
                        <?php elseif ($selected_ticket['payment_status'] === 'pending'): ?>
                        <div class="bg-blue-50 border-2 border-blue-500 rounded-xl p-4 mb-4 text-center">
                            <i class="fa fa-clock text-blue-600 text-3xl mb-2 animate-pulse"></i>
                            <p class="text-blue-700 font-bold uppercase">Payment Pending</p>
                            <p class="text-[10px] text-blue-600">Awaiting verification for Ref: <?php echo htmlspecialchars($selected_ticket['reference_number']); ?></p>
                        </div>
                        <?php else: ?>
                        <button onclick="openPaymentModal(<?php echo $selected_ticket['id']; ?>, <?php echo $selected_ticket['penalty_amount']; ?>)" class="w-full bg-lgu-button hover:bg-yellow-500 text-lgu-button-text py-3 rounded-xl font-bold shadow-md transition no-print">
                            <i class="fa fa-wallet mr-2"></i>Pay Violation Fine
                        </button>
                        <?php endif; ?>
                        
                        <div class="flex gap-2 no-print">
                            <button onclick="window.print()" class="flex-1 mt-2 border border-gray-300 py-2 rounded-lg text-gray-500 hover:text-lgu-headline text-xs font-bold transition">
                                <i class="fa fa-print mr-1"></i>Print
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-xl shadow p-12 text-center border-2 border-dashed border-gray-200 no-print">
                        <i class="fa fa-mouse-pointer text-gray-300 text-4xl mb-3"></i>
                        <p class="text-lgu-paragraph text-sm">Select a ticket from the left to view full receipt details.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="paymentModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="ticket_id" id="modalTicketId">
                <input type="hidden" name="process_payment_internal" value="1">

                <div class="bg-lgu-headline p-6 text-white flex justify-between items-center">
                    <h2 class="text-xl font-bold uppercase tracking-tight">Verify Payment</h2>
                    <button type="button" onclick="closePaymentModal()" class="hover:rotate-90 transition-transform"><i class="fa fa-times"></i></button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="bg-lgu-bg rounded-xl p-4 flex justify-between items-center border border-gray-100">
                        <span class="text-sm font-medium">Amount Due:</span>
                        <span class="text-2xl font-black text-lgu-headline" id="modalAmount">₱0.00</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="cursor-pointer group">
                            <input type="radio" name="payment_method" value="GCash" class="hidden peer" checked>
                            <div class="border-2 border-gray-100 rounded-xl p-3 flex flex-col items-center peer-checked:border-blue-500 peer-checked:bg-blue-50 transition h-full">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5a/GCash_logo.svg/1200px-GCash_logo.svg.png" class="h-8 mb-2 object-contain">
                                <span class="text-[10px] font-bold text-gray-400 peer-checked:text-blue-600">GCASH</span>
                            </div>
                        </label>
                        <label class="cursor-pointer group">
                            <input type="radio" name="payment_method" value="Maya" class="hidden peer">
                            <div class="border-2 border-gray-100 rounded-xl p-3 flex flex-col items-center peer-checked:border-green-500 peer-checked:bg-green-50 transition h-full">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/9e/Maya_logo.svg/1200px-Maya_logo.svg.png" class="h-8 mb-2 object-contain">
                                <span class="text-[10px] font-bold text-gray-400 peer-checked:text-green-600">MAYA</span>
                            </div>
                        </label>
                    </div>

                    <div>
                        <label class="text-xs font-bold text-lgu-headline mb-1 block uppercase">Reference Number</label>
                        <input type="text" name="reference_number" required placeholder="Enter transaction ID" class="w-full border-2 border-gray-100 rounded-xl p-3 text-sm focus:border-lgu-button outline-none transition">
                    </div>

                    <div>
                        <label class="text-xs font-bold text-lgu-headline mb-1 block uppercase">Proof of Payment</label>
                        <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center relative hover:bg-gray-50 transition">
                            <input type="file" name="payment_proof" accept="image/*" required class="absolute inset-0 opacity-0 cursor-pointer" onchange="document.getElementById('fileNameLabel').innerText = this.files[0].name">
                            <i class="fa fa-image text-gray-300 mb-2 block text-2xl"></i>
                            <span id="fileNameLabel" class="text-xs text-gray-500">Upload Screenshot</span>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-lgu-button text-lgu-button-text font-black py-4 rounded-xl shadow-lg hover:shadow-xl transition transform active:scale-95">
                        SUBMIT FOR VERIFICATION
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPaymentModal(id, amount) {
            document.getElementById('modalTicketId').value = id;
            document.getElementById('modalAmount').innerText = '₱' + parseFloat(amount).toLocaleString();
            document.getElementById('paymentModal').classList.remove('hidden');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
        }
    </script>
</body>
</html>