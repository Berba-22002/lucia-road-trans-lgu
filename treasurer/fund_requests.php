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
$treasurer_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve') {
        try {
            $stmt = $pdo->prepare("UPDATE fund_requests SET status = 'approved', treasurer_id = ?, approved_amount = ?, treasurer_remarks = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$treasurer_id, $_POST['approved_amount'] ?? 0, $_POST['treasurer_remarks'] ?? '', $_POST['request_id']]);
            header('Location: fund_requests.php?success=approved');
            exit();
        } catch (PDOException $e) {
            $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } elseif ($_POST['action'] === 'reject') {
        try {
            $stmt = $pdo->prepare("UPDATE fund_requests SET status = 'disapproved', treasurer_id = ?, treasurer_remarks = ? WHERE id = ?");
            $stmt->execute([$treasurer_id, $_POST['treasurer_remarks'] ?? '', $_POST['request_id']]);
            header('Location: fund_requests.php?success=rejected');
            exit();
        } catch (PDOException $e) {
            $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT fr.*, r.description, r.address, r.hazard_type
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        WHERE fr.status IN ('endorsed', 'approved')
        ORDER BY fr.created_at DESC
    ");
    $stmt->execute();
    $fund_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Query Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $fund_requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Fund Requests - Treasurer - RTIM</title>
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
                        'lgu-stroke': '#00332c',
                        'lgu-highlight': '#faae2b'
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Poppins', sans-serif; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; }
        .modal[style*="display: flex"] { display: flex !important; }
        .status-badge { display: inline-block; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; }
        .status-endorsed { background: #f3e8ff; color: #7e22ce; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-disapproved { background: #fee2e2; color: #991b1b; }
        .request-card { transition: all 0.3s ease; }
        .request-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .btn-action { padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; transition: all 0.2s; cursor: pointer; }
        .btn-approve { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .btn-approve:hover:not(:disabled) { background: #bbf7d0; }
        .btn-approve:disabled { background: #e5e7eb; color: #9ca3af; border: 1px solid #d1d5db; cursor: not-allowed; opacity: 0.6; }
        .btn-reject { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .btn-reject:hover:not(:disabled) { background: #fecaca; }
        .btn-reject:disabled { background: #e5e7eb; color: #9ca3af; border: 1px solid #d1d5db; cursor: not-allowed; opacity: 0.6; }
        .btn-view { background: #f0f9ff; color: #0c4a6e; border: 1px solid #bae6fd; }
        .btn-view:hover { background: #e0f2fe; }
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
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Fund Requests Approval</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <?php echo $message; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg shadow-md p-5 border-l-4 border-purple-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-purple-600 uppercase tracking-wide">Endorsed</p>
                            <p class="text-3xl font-bold text-purple-700 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'endorsed')); ?></p>
                        </div>
                        <i class="fas fa-check-circle text-purple-300 text-4xl"></i>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow-md p-5 border-l-4 border-green-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-green-600 uppercase tracking-wide">Approved</p>
                            <p class="text-3xl font-bold text-green-700 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'approved')); ?></p>
                        </div>
                        <i class="fas fa-money-bill-wave text-green-300 text-4xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-t-4 border-lgu-button">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline flex items-center gap-2">
                        <i class="fas fa-wallet text-lgu-button"></i> Budget Approval
                    </h2>
                </div>

                <div class="space-y-4">
                    <?php if (empty($fund_requests)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-inbox text-gray-300 text-5xl mb-3"></i>
                            <p class="text-lgu-paragraph text-lg">No requests pending approval</p>
                        </div>
                    <?php else: foreach ($fund_requests as $req): ?>
                        <div class="request-card border border-gray-200 rounded-lg p-5 bg-gradient-to-r from-white to-gray-50">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <h3 class="font-bold text-lgu-headline text-lg">Request #<?php echo $req['id']; ?></h3>
                                    <p class="text-sm text-lgu-paragraph mt-1 line-clamp-2"><?php echo htmlspecialchars($req['description'] ?? 'N/A'); ?></p>
                                </div>
                                <span class="status-badge status-<?php echo $req['status']; ?> ml-3">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-sm">
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Requested Amount</p>
                                    <p class="font-bold text-lgu-headline">₱<?php echo number_format($req['total_cost'] ?? 0, 2); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Approved Amount</p>
                                    <p class="font-bold text-green-600">₱<?php echo number_format($req['approved_amount'] ?? 0, 2); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Timeline</p>
                                    <p class="font-bold text-lgu-headline text-xs"><?php echo htmlspecialchars($req['target_timeline'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Submitted</p>
                                    <p class="font-bold text-lgu-headline"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></p>
                                </div>
                            </div>

                            <div class="flex gap-2 flex-wrap">
                                <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($req)); ?>)" class="btn-action btn-view">
                                    <i class="fas fa-eye mr-1"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM</p>
            </div>
        </footer>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full max-h-[95vh] flex flex-col">
            <div class="bg-lgu-headline text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="text-lg font-bold" id="approvalTitle">Approve Request</h3>
                <button onclick="closeModal('approvalModal')" class="text-white hover:text-gray-200">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4 overflow-y-auto flex-1">
                <input type="hidden" name="action" id="approvalAction" value="">
                <input type="hidden" name="request_id" id="approvalRequestId" value="">
                
                <div id="requestSummary" class="bg-gray-50 p-3 rounded border border-gray-200 text-sm space-y-2"></div>

                <div id="amountField" class="hidden">
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Approved Amount (₱)</label>
                    <input type="number" name="approved_amount" id="approvedAmount" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" step="0.01" placeholder="Enter approved amount">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Remarks</label>
                    <textarea name="treasurer_remarks" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" rows="3" placeholder="Enter your remarks..."></textarea>
                </div>

                <div class="flex gap-2 pt-4 flex-shrink-0">
                    <button type="button" onclick="closeModal('approvalModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-2 px-4 rounded transition">
                        Submit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full max-h-[95vh] flex flex-col">
            <div class="bg-lgu-headline text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="text-lg font-bold">Request Details</h3>
                <button onclick="closeModal('detailsModal')" class="text-white hover:text-gray-200">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div id="detailsContent" class="p-6 space-y-4 overflow-y-auto flex-1"></div>
            <div id="detailsActions" class="p-6 border-t border-gray-200 flex gap-2 flex-shrink-0">
                <button type="button" onclick="closeModal('detailsModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                    Close
                </button>
                <button type="button" id="approveFromDetails" class="flex-1 btn-action btn-approve">
                    <i class="fas fa-check mr-1"></i> Approve
                </button>
                <button type="button" id="rejectFromDetails" class="flex-1 btn-action btn-reject">
                    <i class="fas fa-times mr-1"></i> Reject
                </button>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) { 
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'flex';
        }
        function closeModal(id) { 
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        }

        function openApprovalModal(req, action) {
            document.getElementById('approvalAction').value = action;
            document.getElementById('approvalRequestId').value = req.id;
            document.getElementById('approvalTitle').textContent = action === 'approve' ? 'Approve Request' : 'Reject Request';
            
            const amountField = document.getElementById('amountField');
            if (action === 'approve') {
                amountField.classList.remove('hidden');
                document.getElementById('approvedAmount').value = req.total_cost || 0;
            } else {
                amountField.classList.add('hidden');
            }
            
            const summary = document.getElementById('requestSummary');
            summary.innerHTML = `
                <div><strong>Request #:</strong> ${req.id}</div>
                <div><strong>Requested Amount:</strong> ₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                <div><strong>Timeline:</strong> ${req.target_timeline || 'N/A'}</div>
            `;
            openModal('approvalModal');
        }

        function viewDetails(req) {
            const content = document.getElementById('detailsContent');
            content.innerHTML = `
                <div class="space-y-3">
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Status</p><span class="status-badge status-${req.status}">${req.status.toUpperCase()}</span></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Location</p><p class="text-sm">${req.address || 'N/A'}</p></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Description</p><p class="text-sm">${req.description || 'N/A'}</p></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Repair Method</p><p class="text-sm">${req.repair_method || 'N/A'}</p></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Materials</p><p class="text-sm">${req.materials || 'N/A'}</p></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><p class="text-xs text-lgu-paragraph font-semibold">Materials Cost</p><p class="font-bold">₱${parseFloat(req.materials_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>
                        <div><p class="text-xs text-lgu-paragraph font-semibold">Total Cost</p><p class="font-bold">₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>
                    </div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Timeline</p><p class="text-sm">${req.target_timeline || 'N/A'}</p></div>
                    ${req.admin_notes ? `<div><p class="text-xs text-lgu-paragraph font-semibold">Admin Notes</p><p class="text-sm">${req.admin_notes}</p></div>` : ''}
                </div>
            `;
            
            const approveBtn = document.getElementById('approveFromDetails');
            const rejectBtn = document.getElementById('rejectFromDetails');
            
            if (req.status !== 'endorsed') {
                approveBtn.disabled = true;
                rejectBtn.disabled = true;
            } else {
                approveBtn.disabled = false;
                rejectBtn.disabled = false;
                approveBtn.onclick = () => { closeModal('detailsModal'); openApprovalModal(req, 'approve'); };
                rejectBtn.onclick = () => { closeModal('detailsModal'); openApprovalModal(req, 'reject'); };
            }
            
            openModal('detailsModal');
        }

        window.addEventListener('load', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('success') === 'approved') {
                Swal.fire({
                    title: 'Success!',
                    text: 'Request has been approved successfully',
                    icon: 'success',
                    confirmButtonColor: '#16a34a'
                });
            } else if (params.get('success') === 'rejected') {
                Swal.fire({
                    title: 'Success!',
                    text: 'Request has been rejected',
                    icon: 'success',
                    confirmButtonColor: '#dc2626'
                });
            }
        });

        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('treasurer-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
