<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'maintenance') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$maintenance_team_id = $_SESSION['user_id'];
$message = '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fund_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        maintenance_team_id INT NOT NULL,
        report_id INT,
        status ENUM('assessed', 'endorsed', 'approved', 'disapproved', 'completed', 'closed') DEFAULT 'assessed',
        repair_method TEXT,
        materials TEXT,
        labor_cost DECIMAL(10, 2),
        materials_cost DECIMAL(10, 2),
        total_cost DECIMAL(10, 2),
        target_timeline VARCHAR(100),
        assessment_notes TEXT,
        assessment_date TIMESTAMP NULL,
        admin_id INT,
        admin_notes TEXT,
        endorsed_at TIMESTAMP NULL,
        treasurer_id INT,
        approved_amount DECIMAL(10, 2),
        treasurer_remarks TEXT,
        approved_at TIMESTAMP NULL,
        completion_notes TEXT,
        completed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') === false) {
        error_log("Table creation error: " . $e->getMessage());
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_assessment') {
        try {
            $materials_cost = floatval($_POST['materials_cost']);
            $total = $materials_cost;
            $timeline_start = $_POST['timeline_start'] ?? '';
            $timeline_end = $_POST['timeline_end'] ?? '';
            $target_timeline = $timeline_start . ' to ' . $timeline_end;
            
            $stmt = $pdo->prepare("
                INSERT INTO fund_requests 
                (maintenance_team_id, report_id, status, repair_method, materials, materials_cost, total_cost, target_timeline, assessment_notes, assessment_date)
                VALUES (?, ?, 'assessed', ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $maintenance_team_id,
                $_POST['report_id'],
                $_POST['repair_method'],
                $_POST['materials'] ?? '',
                $materials_cost,
                $total,
                $target_timeline,
                $_POST['assessment_notes']
            ]);
            header('Location: fund_requests.php');
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
        WHERE fr.maintenance_team_id = ?
        ORDER BY fr.created_at DESC
    ");
    $stmt->execute([$maintenance_team_id]);
    $fund_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Query Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $fund_requests = [];
}

$road_reports = $bridge_reports = $traffic_reports = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.description, r.address, r.landmark, r.image_path, ma.id as assignment_id
        FROM reports r
        INNER JOIN maintenance_assignments ma ON r.id = ma.report_id
        WHERE ma.assigned_to = ? AND r.status IN ('pending', 'in_progress') AND ma.team_type = 'road_maintenance'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$maintenance_team_id]);
    $road_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT r.id, r.description, r.address, r.landmark, r.image_path, ma.id as assignment_id
        FROM reports r
        INNER JOIN maintenance_assignments ma ON r.id = ma.report_id
        WHERE ma.assigned_to = ? AND r.status IN ('pending', 'in_progress') AND ma.team_type = 'bridge_maintenance'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$maintenance_team_id]);
    $bridge_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT r.id, r.description, r.address, r.landmark, r.image_path, ma.id as assignment_id
        FROM reports r
        INNER JOIN maintenance_assignments ma ON r.id = ma.report_id
        WHERE ma.assigned_to = ? AND r.status IN ('pending', 'in_progress') AND ma.team_type = 'traffic_management'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$maintenance_team_id]);
    $traffic_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Assigned reports query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Fund Requests - RTIM</title>
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
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; }
        .status-assessed { background: #e3f2fd; color: #1565c0; }
        .status-endorsed { background: #f3e5f5; color: #6a1b9a; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-disapproved { background: #f8d7da; color: #721c24; }
        .status-completed { background: #c8e6c9; color: #2e7d32; }
        .status-closed { background: #e0e0e0; color: #424242; }
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
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Fund Requests</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <?php echo $message; ?>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="font-bold text-blue-900 mb-2"><i class="fas fa-info-circle mr-2"></i>Workflow Process</h3>
                <p class="text-sm text-blue-800">Step 1: Submit Assessment → Step 2: Admin Review → Step 3: Budget Approval → Step 4: Repair Execution → Step 5: Record Keeping</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Assessed</p>
                    <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'assessed')); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Endorsed</p>
                    <p class="text-2xl font-bold text-purple-600 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'endorsed')); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Approved</p>
                    <p class="text-2xl font-bold text-green-600 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'approved')); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Disapproved</p>
                    <p class="text-2xl font-bold text-red-600 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'disapproved')); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                    <p class="text-2xl font-bold text-gray-600 mt-2"><?php echo count(array_filter($fund_requests, fn($r) => $r['status'] === 'completed')); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline">Fund Requests</h2>
                    <button onclick="openModal('newRequestModal')" class="bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-2 px-4 rounded-lg transition">
                        <i class="fa fa-plus mr-2"></i> New Request
                    </button>
                </div>

                <div class="space-y-4">
                    <?php if (empty($fund_requests)): ?>
                        <p class="text-center text-lgu-paragraph py-8">No fund requests yet</p>
                    <?php else: foreach ($fund_requests as $req): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <h3 class="font-bold text-lgu-headline">Request #<?php echo $req['id']; ?> - <?php echo htmlspecialchars($req['hazard_type'] ?? 'N/A'); ?></h3>
                                    <p class="text-sm text-lgu-paragraph mt-1"><?php echo htmlspecialchars($req['description'] ?? 'N/A'); ?></p>
                                </div>
                                <span class="status-badge status-<?php echo $req['status']; ?>">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-3">
                                <div>
                                    <p class="text-xs text-lgu-paragraph font-semibold">Total Cost</p>
                                    <p class="font-bold text-lgu-headline">₱<?php echo number_format($req['total_cost'] ?? 0, 2); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-lgu-paragraph font-semibold">Timeline</p>
                                    <p class="font-bold text-lgu-headline"><?php echo htmlspecialchars($req['target_timeline'] ?? 'N/A'); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-lgu-paragraph font-semibold">Submitted</p>
                                    <p class="font-bold text-lgu-headline"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-lgu-paragraph font-semibold">Approved Amount</p>
                                    <p class="font-bold text-green-600">₱<?php echo number_format($req['approved_amount'] ?? 0, 2); ?></p>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($req)); ?>)" class="text-lgu-headline hover:text-lgu-stroke text-sm font-semibold">
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

    <!-- New Request Modal -->
    <div id="newRequestModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full max-h-[95vh] flex flex-col">
            <div class="bg-lgu-headline text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="text-lg font-bold">New Fund Request - Assessment</h3>
                <button onclick="closeModal('newRequestModal')" class="text-white hover:text-gray-200">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4 overflow-y-auto flex-1">
                <input type="hidden" name="action" value="submit_assessment">
                <input type="hidden" name="materials" value="">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Task Type</label>
                        <select id="taskType" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" onchange="updateTaskSelect()">
                            <option value="">-- Select Type --</option>
                            <option value="road">Road Maintenance</option>
                            <option value="bridge">Bridge Maintenance</option>
                            <option value="traffic">Traffic Management</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Select Task</label>
                        <select name="report_id" id="reportSelect" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" required onchange="updateTaskDetails()">
                            <option value="">-- Select a task --</option>
                        </select>
                    </div>
                </div>

                <div id="taskDetails" class="p-3 bg-gray-50 rounded border border-gray-200 text-sm hidden">
                    <p class="text-xs text-lgu-paragraph uppercase font-semibold mb-2">Task Details</p>
                    <div id="detailsContent" class="space-y-1 text-xs"></div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Repair Method</label>
                    <textarea name="repair_method" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm" rows="2" placeholder="Describe the repair method..."></textarea>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Materials Required</label>
                    <div id="materialsContainer" class="space-y-2">
                        <div class="flex gap-2">
                            <input type="text" class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm material-input" placeholder="Material name">
                            <input type="number" class="w-24 border border-gray-300 rounded px-3 py-2 text-sm material-qty" placeholder="Qty" step="0.01">
                            <input type="number" class="w-24 border border-gray-300 rounded px-3 py-2 text-sm material-cost" placeholder="Cost" step="0.01" onchange="calculateMaterialsCost()">
                            <button type="button" onclick="removeMaterial(this)" class="text-red-600 hover:text-red-800 px-2">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" onclick="addMaterial()" class="mt-2 text-sm text-lgu-button hover:text-yellow-600 font-semibold">
                        <i class="fas fa-plus mr-1"></i> Add Material
                    </button>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded p-3">
                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Materials Cost (₱)</p>
                    <p class="text-2xl font-bold text-blue-600" id="displayMaterialsCost">0.00</p>
                    <input type="hidden" id="totalMaterialsCost" name="materials_cost" value="0">
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                    <p class="text-xs text-lgu-paragraph font-semibold mb-1">Estimated Budget (₱)</p>
                    <p class="text-2xl font-bold text-yellow-600" id="displayEstimatedBudget">0.00</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Start Date</label>
                        <input type="date" name="timeline_start" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">End Date</label>
                        <input type="date" name="timeline_end" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Assessment Notes</label>
                    <textarea name="assessment_notes" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" rows="2" placeholder="Additional notes..."></textarea>
                </div>

                <div class="flex gap-2 pt-4 flex-shrink-0">
                    <button type="button" onclick="closeModal('newRequestModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition">
                        Cancel
                    </button>
                    <button type="button" onclick="validateAndSubmit()" class="flex-1 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text font-bold py-2 px-4 rounded transition">
                        Submit Assessment
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
        </div>
    </div>

    <script>
        const tasksData = {
            road: <?php echo json_encode($road_reports); ?>,
            bridge: <?php echo json_encode($bridge_reports); ?>,
            traffic: <?php echo json_encode($traffic_reports); ?>
        };

        function updateTaskSelect() {
            const type = document.getElementById('taskType').value;
            const select = document.getElementById('reportSelect');
            select.innerHTML = '<option value="">-- Select a task --</option>';
            
            if (type && tasksData[type]) {
                tasksData[type].forEach(task => {
                    const option = document.createElement('option');
                    option.value = task.id;
                    option.textContent = `Report #${task.id} - ${task.description.substring(0, 30)}...`;
                    option.dataset.address = task.address || '';
                    option.dataset.description = task.description || '';
                    select.appendChild(option);
                });
            }
            document.getElementById('taskDetails').classList.add('hidden');
        }

        function updateTaskDetails() {
            const select = document.getElementById('reportSelect');
            const option = select.options[select.selectedIndex];
            const details = document.getElementById('taskDetails');
            const content = document.getElementById('detailsContent');
            
            if (option.value) {
                content.innerHTML = `
                    <div><strong>Report #:</strong> ${option.value}</div>
                    <div><strong>Location:</strong> ${option.dataset.address || 'N/A'}</div>
                    <div><strong>Description:</strong> ${option.dataset.description || 'N/A'}</div>
                `;
                details.classList.remove('hidden');
            } else {
                details.classList.add('hidden');
            }
        }

        function openModal(id) { 
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'flex';
        }
        function closeModal(id) { 
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        }
        
        function viewDetails(req) {
            const detailsModal = document.getElementById('detailsModal');
            const content = detailsModal.querySelector('#detailsContent');
            content.innerHTML = `
                <div class="space-y-3">
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Status</p><span class="status-badge status-${req.status}">${req.status.toUpperCase()}</span></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Repair Method</p><p class="text-sm">${req.repair_method || 'N/A'}</p></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Materials</p><p class="text-sm">${req.materials || 'N/A'}</p></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><p class="text-xs text-lgu-paragraph font-semibold">Labor Cost</p><p class="font-bold">₱${parseFloat(req.labor_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>
                        <div><p class="text-xs text-lgu-paragraph font-semibold">Materials Cost</p><p class="font-bold">₱${parseFloat(req.materials_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>
                    </div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Total Cost</p><p class="text-lg font-bold text-lgu-button">₱${parseFloat(req.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>
                    <div><p class="text-xs text-lgu-paragraph font-semibold">Target Timeline</p><p class="text-sm">${req.target_timeline || 'N/A'}</p></div>
                    ${req.approved_amount ? `<div><p class="text-xs text-lgu-paragraph font-semibold">Approved Amount</p><p class="text-lg font-bold text-green-600">₱${parseFloat(req.approved_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div>` : ''}
                    ${req.treasurer_remarks ? `<div><p class="text-xs text-lgu-paragraph font-semibold">Treasurer Remarks</p><p class="text-sm">${req.treasurer_remarks}</p></div>` : ''}
                </div>
            `;
            detailsModal.style.display = 'flex';
        }

        function validateAndSubmit() {
            const form = document.querySelector('#newRequestModal form');
            const taskType = document.getElementById('taskType').value;
            const reportId = document.getElementById('reportSelect').value;
            const repairMethod = document.querySelector('textarea[name="repair_method"]')?.value || '';
            const timelineStart = document.querySelector('input[name="timeline_start"]')?.value || '';
            const timelineEnd = document.querySelector('input[name="timeline_end"]')?.value || '';
            const materialsCost = document.getElementById('totalMaterialsCost').value;
            
            if (!taskType) {
                Swal.fire('Error', 'Please select a task type', 'error');
                return;
            }
            if (!reportId) {
                Swal.fire('Error', 'Please select a task', 'error');
                return;
            }
            if (!repairMethod) {
                Swal.fire('Error', 'Please enter repair method', 'error');
                return;
            }
            if (!timelineStart || !timelineEnd) {
                Swal.fire('Error', 'Please select start and end dates', 'error');
                return;
            }
            if (!materialsCost || materialsCost === '0') {
                Swal.fire('Error', 'Please add materials with costs', 'error');
                return;
            }
            
            form.querySelector('input[name="materials"]').value = getMaterialsList();
            form.submit();
        }

        function addMaterial() {
            const container = document.getElementById('materialsContainer');
            const div = document.createElement('div');
            div.className = 'flex gap-2';
            div.innerHTML = `
                <input type="text" class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm material-input" placeholder="Material name">
                <input type="number" class="w-24 border border-gray-300 rounded px-3 py-2 text-sm material-qty" placeholder="Qty" step="0.01">
                <input type="number" class="w-24 border border-gray-300 rounded px-3 py-2 text-sm material-cost" placeholder="Cost" step="0.01" onchange="calculateMaterialsCost()">
                <button type="button" onclick="removeMaterial(this)" class="text-red-600 hover:text-red-800 px-2">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(div);
        }

        function removeMaterial(btn) {
            btn.parentElement.remove();
            calculateMaterialsCost();
        }

        function calculateMaterialsCost() {
            const costs = document.querySelectorAll('#materialsContainer > div');
            let total = 0;
            costs.forEach(div => {
                const qty = parseFloat(div.querySelector('.material-qty').value) || 0;
                const cost = parseFloat(div.querySelector('.material-cost').value) || 0;
                total += qty * cost;
            });
            document.getElementById('totalMaterialsCost').value = total.toFixed(2);
            document.getElementById('displayMaterialsCost').textContent = total.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('displayEstimatedBudget').textContent = (total * 1.15).toLocaleString('en-US', {minimumFractionDigits: 2});
        }

        function getMaterialsList() {
            const materials = [];
            document.querySelectorAll('#materialsContainer > div').forEach(div => {
                const name = div.querySelector('.material-input').value;
                const qty = div.querySelector('.material-qty').value;
                const cost = div.querySelector('.material-cost').value;
                if (name) materials.push(`${name} (Qty: ${qty || 0}, Cost: ₱${cost || 0})`);
            });
            return materials.join('; ');
        }
        
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('maintenance-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
