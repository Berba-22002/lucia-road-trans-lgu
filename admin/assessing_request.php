<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: ../../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $request_id = $_POST['request_id'] ?? '';
    $request_type = $_POST['request_type'] ?? 'event';
    $remarks = $_POST['remarks'] ?? '';
    
    if ($action == 'approve' || $action == 'reject') {
        try {
            $status = $action == 'approve' ? 'approved' : 'rejected';
            $table = $request_type == 'utility' ? 'utility_billing_requests' : 'event_requests';
            $stmt = $pdo->prepare("UPDATE $table SET status = :status, remarks = :remarks, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                ':status' => $status,
                ':remarks' => $remarks,
                ':id' => $request_id
            ]);
            
            $_SESSION['alert_message'] = "Request " . $status . " successfully!";
            $_SESSION['alert_type'] = 'success';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['alert_message'] = "Error updating request";
            $_SESSION['alert_type'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

$show_alert = false;
$message = '';
$message_type = '';

if (isset($_SESSION['alert_message'])) {
    $show_alert = true;
    $message = $_SESSION['alert_message'];
    $message_type = $_SESSION['alert_type'];
    unset($_SESSION['alert_message']);
    unset($_SESSION['alert_type']);
}

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterSystem = isset($_GET['system']) ? $_GET['system'] : '';

try {
    $stmt1 = $pdo->prepare("SELECT *, 'event' as request_type FROM event_requests");
    $stmt1->execute();
    $eventRequests = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt2 = $pdo->prepare("SELECT *, 'utility' as request_type FROM utility_billing_requests");
    $stmt2->execute();
    $utilityRequests = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    $allRequests = array_merge($eventRequests, $utilityRequests);
    usort($allRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    if ($searchQuery) {
        $allRequests = array_filter($allRequests, function($req) use ($searchQuery) {
            return stripos($req['event_type'], $searchQuery) !== false || 
                   stripos($req['location'], $searchQuery) !== false ||
                   stripos($req['system_name'], $searchQuery) !== false;
        });
    }
    
    if ($filterStatus) {
        $allRequests = array_filter($allRequests, function($req) use ($filterStatus) {
            return $req['status'] === $filterStatus;
        });
    }
    
    if ($filterSystem) {
        $allRequests = array_filter($allRequests, function($req) use ($filterSystem) {
            return $req['system_name'] === $filterSystem;
        });
    }
    
    $itemsPerPage = 10;
    $totalItems = count($allRequests);
    $totalPages = ceil($totalItems / $itemsPerPage);
    $currentPage = isset($_GET['page']) ? max(1, min((int)$_GET['page'], $totalPages)) : 1;
    $offset = ($currentPage - 1) * $itemsPerPage;
    $requests = array_slice($allRequests, $offset, $itemsPerPage);
} catch (PDOException $e) {
    $requests = [];
    $totalPages = 0;
    $currentPage = 1;
    $totalItems = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Assessment Requests - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
        
        table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .status-approved {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>
    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 min-h-screen">
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center px-4 py-3 lg:px-6">
                <button id="mobile-menu-btn" class="lg:hidden mr-4 p-2 text-lgu-headline hover:bg-gray-100 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-lgu-headline">Event Assessment Requests</h1>
                    <p class="text-sm text-lgu-paragraph">Review and approve traffic event requests</p>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <form method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <input type="text" name="search" placeholder="Search by event type, location, or system..." value="<?php echo htmlspecialchars($searchQuery); ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:outline-none focus:border-lgu-button">
                            </div>
                            <div>
                                <select name="status" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:outline-none focus:border-lgu-button">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div>
                                <select name="system" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:outline-none focus:border-lgu-button">
                                    <option value="">All Systems</option>
                                    <option value="Public" <?php echo $filterSystem === 'Public' ? 'selected' : ''; ?>>Public</option>
                                    <option value="Utility" <?php echo $filterSystem === 'Utility' ? 'selected' : ''; ?>>Utility</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded font-semibold text-sm hover:bg-opacity-90 transition">
                                <i class="fas fa-search mr-1"></i>Search
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="bg-gray-300 text-gray-700 px-4 py-2 rounded font-semibold text-sm hover:bg-gray-400 transition">
                                <i class="fas fa-redo mr-1"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
                <?php if (empty($requests)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-inbox text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">No requests found</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-lgu-headline text-white">
                                <tr>
                                    <th class="px-6 py-3 text-left font-bold">System</th>
                                    <th class="px-6 py-3 text-left font-bold">Event Type</th>
                                    <th class="px-6 py-3 text-left font-bold">Location</th>
                                    <th class="px-6 py-3 text-left font-bold">Start Date</th>
                                    <th class="px-6 py-3 text-left font-bold">Status</th>
                                    <th class="px-6 py-3 text-center font-bold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td class="px-6 py-4 text-gray-700 font-semibold"><?php echo htmlspecialchars($req['system_name']); ?></td>
                                        <td class="px-6 py-4 text-gray-700"><?php echo htmlspecialchars($req['event_type']); ?></td>
                                        <td class="px-6 py-4 text-gray-700 truncate" title="<?php echo htmlspecialchars($req['location']); ?>"><?php echo htmlspecialchars(substr($req['location'], 0, 30)); ?></td>
                                        <td class="px-6 py-4 text-gray-700"><?php echo date('M d, Y H:i', strtotime($req['start_date'])); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="status-badge status-<?php echo $req['status']; ?>">
                                                <i class="fas fa-<?php echo $req['status'] == 'approved' ? 'check-circle' : ($req['status'] == 'rejected' ? 'times-circle' : 'clock'); ?>"></i>
                                                <?php echo ucfirst($req['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <button onclick="openModal(<?php echo htmlspecialchars(json_encode($req)); ?>)" class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded hover:bg-opacity-90 transition font-semibold text-sm">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($totalPages > 1): ?>
                <div class="flex items-center justify-between px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="text-sm text-gray-600">
                        Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?> (<?php echo $totalItems; ?> total)
                    </div>
                    <div class="flex gap-2">
                        <?php if ($currentPage > 1): ?>
                        <a href="?page=1" class="px-3 py-2 bg-white border border-gray-300 rounded hover:bg-gray-50 text-sm font-semibold">First</a>
                        <a href="?page=<?php echo $currentPage - 1; ?>" class="px-3 py-2 bg-white border border-gray-300 rounded hover:bg-gray-50 text-sm font-semibold">Previous</a>
                        <?php endif; ?>
                        <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?php echo $currentPage + 1; ?>" class="px-3 py-2 bg-white border border-gray-300 rounded hover:bg-gray-50 text-sm font-semibold">Next</a>
                        <a href="?page=<?php echo $totalPages; ?>" class="px-3 py-2 bg-white border border-gray-300 rounded hover:bg-gray-50 text-sm font-semibold">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="viewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl p-6 max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-lgu-headline">Request Details</h2>
                <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="modalContent" class="space-y-4 mb-6"></div>

            <div id="mapContainer" class="mb-6">
                <p class="text-xs font-bold text-gray-600 uppercase mb-2">Location Map</p>
                <div id="detailMap" class="w-full h-64 rounded-lg border-2 border-gray-300 bg-gray-100"></div>
            </div>

            <form id="actionForm" method="POST" class="space-y-3">
                <input type="hidden" name="request_id" id="modalRequestId">
                <input type="hidden" name="request_type" id="modalRequestType">
                <input type="hidden" name="action" id="actionInput">
                
                <div id="remarksContainer" class="hidden">
                    <textarea name="remarks" id="remarksField" placeholder="Add remarks (optional)" class="w-full border border-gray-300 rounded p-3 text-sm focus:outline-none focus:border-lgu-button" rows="2"></textarea>
                </div>
                
                <div id="actionButtons" class="flex gap-3 w-full"></div>
            </form>
        </div>
    </div>

    <script>
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        let detailMap = null;

        <?php if ($show_alert): ?>
        Swal.fire({
            icon: '<?php echo $message_type; ?>',
            title: '<?php echo $message_type == 'success' ? 'Success' : 'Error'; ?>',
            text: '<?php echo htmlspecialchars($message); ?>',
            confirmButtonColor: '#00473e',
            timer: 2000
        });
        <?php endif; ?>

        function openModal(request) {
            const modal = document.getElementById('viewModal');
            const content = document.getElementById('modalContent');
            const requestId = document.getElementById('modalRequestId');
            const requestType = document.getElementById('modalRequestType');
            const actionButtons = document.getElementById('actionButtons');
            const remarksContainer = document.getElementById('remarksContainer');
            
            requestId.value = request.id;
            requestType.value = request.request_type;
            
            content.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">Event Type</p>
                        <p class="text-gray-700">${request.event_type}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">System</p>
                        <p class="text-gray-700">${request.system_name}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">Location</p>
                        <p class="text-gray-700">${request.location}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">Landmark</p>
                        <p class="text-gray-700">${request.landmark || '-'}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">Start Date</p>
                        <p class="text-gray-700">${new Date(request.start_date).toLocaleString()}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-600 uppercase">End Date</p>
                        <p class="text-gray-700">${new Date(request.end_date).toLocaleString()}</p>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-600 uppercase">Description</p>
                    <p class="text-gray-700 bg-gray-50 p-3 rounded">${request.description}</p>
                </div>
                ${request.remarks ? `<div class="bg-blue-50 p-3 rounded border-l-4 border-blue-400"><p class="text-sm text-blue-900"><strong>Remarks:</strong> ${request.remarks}</p></div>` : ''}
            `;
            
            if (request.status === 'pending') {
                remarksContainer.classList.remove('hidden');
                actionButtons.innerHTML = `
                    <button type="button" onclick="submitAction('approve')" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 text-white px-4 py-3 rounded-lg hover:from-green-600 hover:to-green-700 transition font-semibold">
                        <i class="fas fa-check mr-1"></i> Approve
                    </button>
                    <button type="button" onclick="submitAction('reject')" class="flex-1 bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-3 rounded-lg hover:from-red-600 hover:to-red-700 transition font-semibold">
                        <i class="fas fa-times mr-1"></i> Reject
                    </button>
                `;
            } else {
                remarksContainer.classList.add('hidden');
                actionButtons.innerHTML = `
                    <button type="button" onclick="closeModal()" class="w-full bg-gray-300 text-gray-700 px-4 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                        Close
                    </button>
                `;
            }
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                initDetailMap(request.location);
            }, 300);
        }

        function initDetailMap(location) {
            if (detailMap) {
                detailMap.remove();
                detailMap = null;
            }
            
            detailMap = L.map('detailMap').setView([14.6760, 120.9626], 11);
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom'
            }).addTo(detailMap);
            
            detailMap.invalidateSize();
            
            fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(location)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`)
                .then(r => r.json())
                .then(data => {
                    if (data.results && data.results.length > 0) {
                        const result = data.results[0];
                        const lat = result.position.lat;
                        const lng = result.position.lon;
                        L.marker([lat, lng]).addTo(detailMap).bindPopup(result.address.freeformAddress);
                        detailMap.setView([lat, lng], 16);
                    }
                })
                .catch(err => console.error('Geocoding error:', err));
        }

        function closeModal() {
            document.getElementById('viewModal').classList.add('hidden');
            document.getElementById('actionForm').reset();
            if (detailMap) {
                detailMap.remove();
                detailMap = null;
            }
        }

        function submitAction(action) {
            const actionText = action === 'approve' ? 'Approve' : 'Reject';
            
            Swal.fire({
                title: 'Confirm ' + actionText,
                text: 'Are you sure you want to ' + actionText.toLowerCase() + ' this request?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: action === 'approve' ? '#22c55e' : '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, ' + actionText.toLowerCase() + ' it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('actionInput').value = action;
                    document.getElementById('actionForm').submit();
                }
            });
        }

        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        });
    </script>
</body>
</html>
