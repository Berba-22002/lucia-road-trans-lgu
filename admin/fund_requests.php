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
$admin_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'endorse') {
        try {
            $stmt = $pdo->prepare("UPDATE fund_requests SET status = 'endorsed', admin_id = ?, admin_notes = ?, endorsed_at = NOW() WHERE id = ?");
            $stmt->execute([$admin_id, $_POST['admin_notes'] ?? '', $_POST['request_id']]);
            header('Location: fund_requests.php?success=endorsed');
            exit();
        } catch (PDOException $e) {
            $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } elseif ($_POST['action'] === 'disapprove') {
        try {
            $stmt = $pdo->prepare("UPDATE fund_requests SET status = 'disapproved', admin_id = ?, admin_notes = ? WHERE id = ?");
            $stmt->execute([$admin_id, $_POST['admin_notes'] ?? '', $_POST['request_id']]);
            header('Location: fund_requests.php?success=disapproved');
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
        WHERE fr.status IN ('assessed', 'endorsed')
        ORDER BY fr.created_at DESC
    ");
    $stmt->execute();
    $fund_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all records for table
    $stmt_all = $pdo->prepare("
        SELECT fr.*, r.description, r.address, r.hazard_type, u.fullname as submitted_by
        FROM fund_requests fr
        LEFT JOIN reports r ON fr.report_id = r.id
        LEFT JOIN users u ON fr.maintenance_team_id = u.id
        ORDER BY fr.created_at DESC
    ");
    $stmt_all->execute();
    $all_fund_requests = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Query Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $fund_requests = [];
    $all_fund_requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Fund Requests - Admin - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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
    <style>
        * { font-family: 'Poppins', sans-serif; }
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        .sidebar-link { color:#9CA3AF; }
        .sidebar-link:hover { color:#FFF; background:#00332c; }
        .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
        .stat-card { transition: transform .15s ease; }
        .stat-card:hover { transform: translateY(-4px); }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; }
        .modal[style*="display: flex"] { display: flex !important; }
        .status-badge { display: inline-block; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; }
        .status-assessed { background: #dbeafe; color: #1e40af; }
        .status-endorsed { background: #f3e8ff; color: #7e22ce; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-disapproved { background: #fee2e2; color: #991b1b; }
        .request-card { transition: all 0.3s ease; }
        .request-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .btn-action { padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; transition: all 0.2s; cursor: pointer; }
        .btn-endorse { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .btn-endorse:hover:not(:disabled) { background: #bbf7d0; }
        .btn-endorse:disabled { background: #e5e7eb; color: #9ca3af; border: 1px solid #d1d5db; cursor: not-allowed; opacity: 0.6; }
        .btn-disapprove { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .btn-disapprove:hover:not(:disabled) { background: #fecaca; }
        .btn-disapprove:disabled { background: #e5e7eb; color: #9ca3af; border: 1px solid #d1d5db; cursor: not-allowed; opacity: 0.6; }
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
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Fund Requests Review</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <?php echo $message; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg shadow-md p-5 border-l-4 border-blue-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide">Assessed</p>
                            <p class="text-3xl font-bold text-blue-700 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'assessed')); ?></p>
                        </div>
                        <i class="fas fa-file-alt text-blue-300 text-4xl"></i>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg shadow-md p-5 border-l-4 border-purple-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-purple-600 uppercase tracking-wide">Endorsed</p>
                            <p class="text-3xl font-bold text-purple-700 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'endorsed')); ?></p>
                        </div>
                        <i class="fas fa-check-circle text-purple-300 text-4xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-t-4 border-lgu-button mb-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline flex items-center gap-2">
                        <i class="fas fa-tasks text-lgu-button"></i> Pending Review
                    </h2>
                </div>

                <div class="space-y-4">
                    <?php if (empty($fund_requests)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-inbox text-gray-300 text-5xl mb-3"></i>
                            <p class="text-lgu-paragraph text-lg">No requests pending review</p>
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
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Total Cost</p>
                                    <p class="font-bold text-lgu-headline">₱<?php echo number_format($req['total_cost'] ?? 0, 2); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Timeline</p>
                                    <p class="font-bold text-lgu-headline text-xs"><?php echo htmlspecialchars($req['target_timeline'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Submitted</p>
                                    <p class="font-bold text-lgu-headline"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></p>
                                </div>
                                <div class="bg-white p-3 rounded border border-gray-100">
                                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Hazard Type</p>
                                    <p class="font-bold text-lgu-headline text-xs"><?php echo htmlspecialchars($req['hazard_type'] ?? 'N/A'); ?></p>
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

            <!-- All Records Table -->
            <div class="bg-white rounded-lg shadow-lg border-t-4 border-lgu-headline overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-lgu-headline flex items-center gap-2">
                        <i class="fas fa-table text-lgu-headline"></i> All Fund Requests
                    </h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-lgu-headline text-white sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold whitespace-nowrap">ID</th>
                                <th class="px-4 py-3 text-left font-semibold">Location</th>
                                <th class="px-4 py-3 text-left font-semibold whitespace-nowrap">Hazard Type</th>
                                <th class="px-4 py-3 text-right font-semibold whitespace-nowrap">Total Cost</th>
                                <th class="px-4 py-3 text-left font-semibold whitespace-nowrap">Timeline</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold whitespace-nowrap">Submitted</th>
                                <th class="px-4 py-3 text-center font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($all_fund_requests)): ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-lgu-paragraph">
                                        <i class="fas fa-inbox text-gray-300 text-3xl mb-2 block"></i>
                                        No fund requests found
                                    </td>
                                </tr>
                            <?php else: foreach ($all_fund_requests as $req): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-4 py-3 font-semibold text-lgu-headline whitespace-nowrap">#<?php echo $req['id']; ?></td>
                                    <td class="px-4 py-3 text-lgu-paragraph text-xs">
                                        <div class="max-w-xs truncate" title="<?php echo htmlspecialchars($req['address'] ?? 'N/A'); ?>">
                                            <?php echo htmlspecialchars($req['address'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-lgu-paragraph">
                                        <span class="inline-block px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-semibold">
                                            <?php echo htmlspecialchars($req['hazard_type'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-lgu-headline whitespace-nowrap">
                                        ₱<?php echo number_format($req['total_cost'] ?? 0, 2); ?>
                                    </td>
                                    <td class="px-4 py-3 text-lgu-paragraph text-xs whitespace-nowrap">
                                        <?php echo htmlspecialchars($req['target_timeline'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-badge status-<?php echo $req['status']; ?>">
                                            <?php echo ucfirst($req['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-lgu-paragraph text-xs whitespace-nowrap">
                                        <?php echo date('M d, Y', strtotime($req['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($req)); ?>)" class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded text-xs font-semibold transition">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM</p>
            </div>
        </footer>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full max-h-[95vh] flex flex-col">
            <div class="bg-lgu-headline text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="text-lg font-bold" id="reviewTitle">Review Request</h3>
                <button onclick="closeModal('reviewModal')" class="text-white hover:text-gray-200">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4 overflow-y-auto flex-1">
                <input type="hidden" name="action" id="reviewAction" value="">
                <input type="hidden" name="request_id" id="reviewRequestId" value="">
                
                <div id="requestSummary" class="bg-gray-50 p-3 rounded border border-gray-200 text-sm space-y-2"></div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Admin Notes</label>
                    <textarea name="admin_notes" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" rows="3" placeholder="Enter your review notes..."></textarea>
                </div>

                <div class="flex gap-2 pt-4 flex-shrink-0">
                    <button type="button" onclick="closeModal('reviewModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-2 px-4 rounded transition">
                        Submit Review
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
                <button type="button" id="endorseFromDetails" class="flex-1 btn-action btn-endorse">
                    <i class="fas fa-check mr-1"></i> Endorse
                </button>
                <button type="button" id="disapproveFromDetails" class="flex-1 btn-action btn-disapprove">
                    <i class="fas fa-times mr-1"></i> Disapprove
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

        function confirmEndorse(req) {
            if (req.status !== 'assessed') return;
            Swal.fire({
                title: 'Endorse Request?',
                html: `<p>Request #${req.id}</p><p class="text-sm text-gray-600">₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#7e22ce',
                cancelButtonColor: '#d1d5db',
                confirmButtonText: 'Yes, Endorse',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    openReviewModal(req, 'endorse');
                }
            });
        }

        function confirmDisapprove(req) {
            if (req.status !== 'assessed') return;
            Swal.fire({
                title: 'Disapprove Request?',
                html: `<p>Request #${req.id}</p><p class="text-sm text-gray-600">₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#d1d5db',
                confirmButtonText: 'Yes, Disapprove',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    openReviewModal(req, 'disapprove');
                }
            });
        }

        function openReviewModal(req, action) {
            document.getElementById('reviewAction').value = action;
            document.getElementById('reviewRequestId').value = req.id;
            document.getElementById('reviewTitle').textContent = action === 'endorse' ? 'Endorse Request' : 'Disapprove Request';
            
            const summary = document.getElementById('requestSummary');
            summary.innerHTML = `
                <div><strong>Request #:</strong> ${req.id}</div>
                <div><strong>Total Cost:</strong> ₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                <div><strong>Timeline:</strong> ${req.target_timeline || 'N/A'}</div>
                <div><strong>Repair Method:</strong> ${req.repair_method || 'N/A'}</div>
            `;
            openModal('reviewModal');
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
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Assessment Notes</p><p class="text-sm">${req.assessment_notes || 'N/A'}</p></div>
                </div>
            `;
            
            const endorseBtn = document.getElementById('endorseFromDetails');
            const disapproveBtn = document.getElementById('disapproveFromDetails');
            
            if (req.status !== 'assessed') {
                endorseBtn.disabled = true;
                disapproveBtn.disabled = true;
            } else {
                endorseBtn.disabled = false;
                disapproveBtn.disabled = false;
                endorseBtn.onclick = () => { closeModal('detailsModal'); confirmEndorse(req); };
                disapproveBtn.onclick = () => { closeModal('detailsModal'); confirmDisapprove(req); };
            }
            
            openModal('detailsModal');
        }

        document.querySelector('#reviewModal form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const action = document.getElementById('reviewAction').value;
            Swal.fire({
                title: 'Confirm',
                text: `Are you sure you want to ${action} this request?`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#faae2b',
                cancelButtonColor: '#d1d5db',
                confirmButtonText: 'Yes, Confirm'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });

        window.addEventListener('load', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('success') === 'endorsed') {
                Swal.fire({
                    title: 'Success!',
                    text: 'Request has been endorsed successfully',
                    icon: 'success',
                    confirmButtonColor: '#7e22ce'
                });
            } else if (params.get('success') === 'disapproved') {
                Swal.fire({
                    title: 'Success!',
                    text: 'Request has been disapproved',
                    icon: 'success',
                    confirmButtonColor: '#dc2626'
                });
            }
        });

        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('admin-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
