<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'inspector', 'resident'])) {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$hazard_filter = $_GET['hazard_type'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

try {
    $check_table = "SHOW TABLES LIKE 'inspection_findings'";
    $stmt = $pdo->query($check_table);
    if (!$stmt->fetch()) {
        $create_table = "CREATE TABLE inspection_findings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            inspector_id INT NOT NULL,
            severity ENUM('minor', 'major') NOT NULL,
            notes TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
            FOREIGN KEY (inspector_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $pdo->exec($create_table);
    }
    
    $filter_conditions = [];
    $filter_params = [];
    
    if ($hazard_filter !== 'all') {
        $filter_conditions[] = "r.hazard_type = ?";
        $filter_params[] = $hazard_filter;
    }
    
    if ($severity_filter !== 'all') {
        $filter_conditions[] = "f.severity = ?";
        $filter_params[] = $severity_filter;
    }
    
    if (!empty($search)) {
        $filter_conditions[] = "(r.id LIKE ? OR u.fullname LIKE ?)";
        $filter_params[] = "%$search%";
        $filter_params[] = "%$search%";
    }
    
    $filter_sql = !empty($filter_conditions) ? 'WHERE ' . implode(' AND ', $filter_conditions) : '';
    
    $sort_sql = match($sort) {
        'date_asc' => 'ORDER BY r.created_at ASC',
        'status' => 'ORDER BY r.status ASC',
        'reporter' => 'ORDER BY u.fullname ASC',
        default => 'ORDER BY r.created_at DESC'
    };
    
    $count_query = "SELECT COUNT(DISTINCT r.id) as total FROM reports r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN inspection_findings f ON r.id = f.report_id $filter_sql";
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($filter_params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total / $per_page);
    $offset = ($page - 1) * $per_page;
    
    $query = "SELECT DISTINCT r.id, r.description, r.status, r.hazard_type, r.created_at, u.fullname as reporter_name, COUNT(f.id) as findings_count FROM reports r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN inspection_findings f ON r.id = f.report_id $filter_sql GROUP BY r.id $sort_sql LIMIT $offset, $per_page";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($filter_params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Fetch reports error: " . $e->getMessage());
    $reports = [];
    $total = 0;
    $total_pages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection Findings - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {'poppins': ['Poppins', 'sans-serif']},
                    colors: {
                        'lgu-bg': '#f2f7f5',
                        'lgu-headline': '#00473e',
                        'lgu-paragraph': '#475d5b',
                        'lgu-button': '#faae2b',
                        'lgu-button-text': '#00473e',
                        'lgu-stroke': '#00332c',
                        'lgu-highlight': '#faae2b',
                        'success': '#10b981',
                        'warning': '#f59e0b',
                        'danger': '#ef4444',
                        'info': '#3b82f6'
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Poppins', sans-serif; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 1rem; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 700px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); position: relative; }
        .modal-close-btn { position: relative; z-index: 10000; }
        .lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.98); z-index: 50; align-items: center; justify-content: center; padding: 40px 20px; }
        .lightbox.active { display: flex; }
        .lightbox-content { position: relative; width: 100%; height: auto; max-height: 90vh; display: flex; align-items: center; justify-content: center; }
        .lightbox-close { position: absolute; top: 20px; right: 20px; color: white; font-size: 40px; cursor: pointer; z-index: 51; }
        .lightbox img, .lightbox video { width: auto; height: auto; max-width: 100%; max-height: 80vh; object-fit: contain; }
        .status-pending { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-assigned { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-in_progress { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-inspection_ongoing { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-inspection_ended { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-resolved { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-done { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-rejected { background-color: #fee2e2 !important; color: #991b1b !important; }
        .status-escalated { background-color: #fee2e2 !important; color: #991b1b !important; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins min-h-screen">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Inspection Findings</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">View and manage all inspection reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $total; ?></div>
                    <div class="text-xs">Total Reports</div>
                </div>
                <div class="relative">
                    <button id="notificationBell" class="relative p-2 text-lgu-headline hover:bg-lgu-bg rounded-lg transition">
                        <i class="fas fa-bell text-xl"></i>
                        <span id="notificationBadge" class="absolute top-0 right-0 bg-danger text-white text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold hidden">0</span>
                    </button>
                    <div id="notificationPanel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-lgu-stroke hidden z-50 max-h-96 overflow-y-auto">
                        <div class="p-4 border-b border-lgu-stroke flex justify-between items-center">
                            <h3 class="font-semibold text-lgu-headline">Notifications</h3>
                            <button onclick="clearNotifications()" class="text-xs text-lgu-paragraph hover:text-lgu-headline">Clear All</button>
                        </div>
                        <div id="notificationList" class="divide-y divide-lgu-stroke">
                            <div class="p-4 text-center text-sm text-lgu-paragraph">No notifications</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-lgu-button">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Reports</p>
                            <p class="text-2xl font-bold text-gray-600"><?php echo $total; ?></p>
                        </div>
                        <i class="fa fa-file-alt text-3xl text-lgu-button opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600">0</p>
                        </div>
                        <i class="fa fa-spinner text-3xl text-blue-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Completed</p>
                            <p class="text-2xl font-bold text-green-600">0</p>
                        </div>
                        <i class="fa fa-check-circle text-3xl text-green-500 opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-lgu-paragraph uppercase">Escalated</p>
                            <p class="text-2xl font-bold text-red-600">0</p>
                        </div>
                        <i class="fa fa-exclamation-triangle text-3xl text-red-500 opacity-50"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <input type="text" id="searchInput" placeholder="Search by ID or Reporter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                    <select id="hazardFilter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                        <option value="all">All Types</option>
                        <option value="road" <?php echo $hazard_filter === 'road' ? 'selected' : ''; ?>>Road</option>
                        <option value="traffic" <?php echo $hazard_filter === 'traffic' ? 'selected' : ''; ?>>Traffic</option>
                        <option value="bridge" <?php echo $hazard_filter === 'bridge' ? 'selected' : ''; ?>>Bridge</option>
                    </select>
                    <select id="severityFilter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                        <option value="all">All Severity</option>
                        <option value="minor" <?php echo $severity_filter === 'minor' ? 'selected' : ''; ?>>Minor</option>
                        <option value="major" <?php echo $severity_filter === 'major' ? 'selected' : ''; ?>>Major</option>
                    </select>
                    <select id="sortFilter" class="px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-lgu-button">
                        <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>By Status</option>
                        <option value="reporter" <?php echo $sort === 'reporter' ? 'selected' : ''; ?>>By Reporter</option>
                    </select>
                    <button onclick="applyFilters()" class="px-4 py-2 bg-lgu-button hover:bg-yellow-500 text-lgu-button-text rounded font-bold transition">Apply</button>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Report ID</th>
                                <th class="px-4 py-3 text-left font-semibold">Hazard Type</th>
                                <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Reporter</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Findings</th>
                                <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Date</th>
                                <th class="px-4 py-3 text-center font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (count($reports) > 0): ?>
                                <?php foreach ($reports as $report): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-4 py-3 font-bold text-lgu-headline">#<?php echo $report['id']; ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                            <i class="fa fa-exclamation-triangle mr-1"></i>
                                            <?php echo ucfirst($report['hazard_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 hidden md:table-cell">
                                        <span class="text-xs text-lgu-paragraph">
                                            <i class="fa fa-user mr-1"></i>
                                            <?php echo htmlspecialchars($report['reporter_name']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-<?php echo $report['status']; ?> px-3 py-1 rounded-full text-xs font-bold inline-flex items-center">
                                            <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-lgu-headline"><?php echo $report['findings_count']; ?></td>
                                    <td class="px-4 py-3 hidden md:table-cell text-xs text-lgu-paragraph"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <button onclick="openModal(<?php echo $report['id']; ?>)" class="bg-lgu-headline hover:bg-lgu-stroke text-white px-3 py-1 rounded text-xs font-bold transition flex items-center gap-1 justify-center">
                                            <i class="fa fa-eye"></i>
                                            View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-lgu-paragraph">
                                        <i class="fas fa-inbox text-3xl text-lgu-stroke mb-3 block"></i>
                                        No inspection reports found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="bg-gray-50 px-4 py-4 border-t border-gray-200">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="text-sm text-lgu-paragraph">
                            <span class="font-semibold text-lgu-headline"><?php echo $total; ?></span> total report(s)
                        </div>
                        <div class="flex items-center gap-2 flex-wrap justify-center">
                            <?php if ($total_pages > 1): ?>
                                <?php if ($page > 1): ?>
                                    <a href="?page=1&hazard_type=<?php echo $hazard_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">First</a>
                                    <a href="?page=<?php echo $page - 1; ?>&hazard_type=<?php echo $hazard_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Prev</a>
                                <?php endif; ?>
                                
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1): ?>
                                    <span class="text-gray-500">...</span>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <span class="px-3 py-1 rounded bg-lgu-button text-lgu-button-text font-bold text-sm"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?page=<?php echo $i; ?>&hazard_type=<?php echo $hazard_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <span class="text-gray-500">...</span>
                                <?php endif; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&hazard_type=<?php echo $hazard_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Next</a>
                                    <a href="?page=<?php echo $total_pages; ?>&hazard_type=<?php echo $hazard_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="px-3 py-1 rounded border border-gray-300 text-sm hover:bg-gray-200 transition">Last</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM - Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
    </div>

    <div id="detailsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="bg-white border-b border-lgu-stroke px-6 py-4 flex justify-between items-center relative z-10">
                <div>
                    <h2 class="text-lg font-bold text-lgu-headline">Report Details</h2>
                    <p id="modalTimestamp" class="text-xs text-lgu-paragraph mt-1"></p>
                </div>
                <button onclick="closeModal()" class="modal-close-btn text-gray-500 hover:text-gray-700 text-2xl font-bold flex-shrink-0">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modalContent" class="p-6">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-lgu-button"></i>
                </div>
            </div>
        </div>
    </div>

    <div id="lightbox" class="lightbox">
        <div class="lightbox-content">
            <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
            <img id="lightboxImage" style="display:none;" alt="Full screen view">
            <video id="lightboxVideo" style="display:none;" controls></video>
        </div>
    </div>

    <script>
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        let map = null;
        let notificationCount = 0;

        document.getElementById('notificationBell').addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('notificationPanel').classList.toggle('hidden');
        });

        document.getElementById('notificationPanel').addEventListener('click', (e) => {
            e.stopPropagation();
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#notificationBell') && !e.target.closest('#notificationPanel')) {
                document.getElementById('notificationPanel').classList.add('hidden');
            }
        });

        function fetchNotifications() {
            fetch('get_notifications.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.notifications) {
                        notificationCount = data.notifications.length;
                        const badge = document.getElementById('notificationBadge');
                        if (notificationCount > 0) {
                            badge.textContent = notificationCount > 9 ? '9+' : notificationCount;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                        renderNotifications(data.notifications);
                    }
                })
                .catch(e => console.error('Notification fetch error:', e));
        }

        function renderNotifications(notifications) {
            const list = document.getElementById('notificationList');
            if (notifications.length === 0) {
                list.innerHTML = '<div class="p-4 text-center text-sm text-lgu-paragraph">No notifications</div>';
                return;
            }
            list.innerHTML = notifications.map(n => `
                <div class="p-3 hover:bg-lgu-bg transition cursor-pointer" onclick="markNotificationRead(${n.id})">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-lgu-headline">${n.title}</p>
                            <p class="text-xs text-lgu-paragraph mt-1">${n.message}</p>
                        </div>
                        ${!n.is_read ? '<span class="w-2 h-2 bg-danger rounded-full mt-1 flex-shrink-0"></span>' : ''}
                    </div>
                    <p class="text-xs text-lgu-paragraph mt-2">${new Date(n.created_at).toLocaleTimeString()}</p>
                </div>
            `).join('');
        }

        function markNotificationRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({notification_id: notificationId})
            }).then(() => fetchNotifications());
        }

        function clearNotifications() {
            fetch('clear_notifications.php', {method: 'POST'})
                .then(() => fetchNotifications());
        }

        fetchNotifications();
        setInterval(fetchNotifications, 5000);

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const hazard = document.getElementById('hazardFilter').value;
            const severity = document.getElementById('severityFilter').value;
            const sort = document.getElementById('sortFilter').value;
            window.location.href = `?page=1&search=${encodeURIComponent(search)}&hazard_type=${hazard}&severity=${severity}&sort=${sort}`;
        }

        function openModal(reportId) {
            document.getElementById('detailsModal').classList.add('active');
            document.getElementById('modalTimestamp').textContent = 'Opened: ' + new Date().toLocaleString();
            fetch(`get_report_details.php?report_id=${reportId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) renderModalContent(data.report, data.findings, data.inspector, data.ai_analysis, data.location);
                });
        }

        function closeModal() {
            document.getElementById('detailsModal').classList.remove('active');
            if (map) { map.remove(); map = null; }
        }

        function openLightbox(src, type) {
            const lightbox = document.getElementById('lightbox');
            const img = document.getElementById('lightboxImage');
            const video = document.getElementById('lightboxVideo');
            if (type === 'image') {
                img.src = src;
                img.style.display = 'block';
                video.style.display = 'none';
            } else {
                video.src = src;
                video.style.display = 'block';
                img.style.display = 'none';
            }
            lightbox.classList.add('active');
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
        }

        function renderModalContent(report, findings, inspector, aiAnalysis, location) {
            const statusClass = `status-${report.status}`;
            const isVideo = report.image_path && (report.image_path.endsWith('.webm') || report.image_path.endsWith('.mp4') || report.image_path.endsWith('.mov') || report.image_path.endsWith('.avi'));

            let html = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-sm font-semibold text-lgu-headline mb-2">Report Information</h3>
                        <div class="space-y-2 text-sm">
                            <p><span class="text-lgu-paragraph">ID:</span> <span class="font-medium text-lgu-headline">#${report.id}</span></p>
                            <p><span class="text-lgu-paragraph">Type:</span> <span class="font-medium text-lgu-headline">${report.hazard_type}</span></p>
                            <p><span class="text-lgu-paragraph">Reporter:</span> <span class="font-medium text-lgu-headline">${report.reporter_name}</span></p>
                            <p><span class="text-lgu-paragraph">Contact:</span> <span class="font-medium text-lgu-headline">${report.reporter_contact}</span></p>
                            <p><span class="text-lgu-paragraph">Status:</span> <span class="${statusClass} px-2 py-1 rounded text-xs font-semibold">${report.status.replace(/_/g, ' ')}</span></p>
                            <p><span class="text-lgu-paragraph">Date:</span> <span class="font-medium text-lgu-headline">${new Date(report.created_at).toLocaleDateString()}</span></p>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-lgu-headline mb-2">Description</h3>
                        <p class="text-sm text-lgu-paragraph bg-lgu-bg p-3 rounded-lg">${report.description}</p>
                    </div>
                </div>

                <div class="mt-6 border-t border-lgu-stroke pt-6">
                    <h3 class="text-sm font-semibold text-lgu-headline mb-3">Location</h3>
                    <p class="text-sm text-lgu-paragraph mb-2"><strong>Address:</strong> ${location?.address || report.address}</p>
                    ${report.landmark ? `<p class="text-sm text-lgu-paragraph mb-3"><strong>Landmark:</strong> ${report.landmark}</p>` : ''}
                    <div id="reportMap" class="w-full h-64 rounded-lg border border-lgu-stroke mb-3"></div>
                </div>

                ${report.image_path ? `
                <div class="mt-6 border-t border-lgu-stroke pt-6">
                    <h3 class="text-sm font-semibold text-lgu-headline mb-3">Media Evidence</h3>
                    ${isVideo ? `
                        <video onclick="openLightbox('../uploads/hazard_reports/${report.image_path}', 'video')" class="w-32 h-32 rounded-lg object-cover cursor-pointer hover:opacity-80 transition" controls></video>
                    ` : `
                        <img onclick="openLightbox('../uploads/hazard_reports/${report.image_path}', 'image')" src="../uploads/hazard_reports/${report.image_path}" alt="Evidence" class="w-32 h-32 rounded-lg object-cover cursor-pointer hover:opacity-80 transition">
                    `}
                </div>
                ` : ''}

                ${aiAnalysis ? `
                <div class="mt-6 border-t border-lgu-stroke pt-6">
                    <h3 class="text-sm font-semibold text-lgu-headline mb-3">AI Analysis</h3>
                    <div class="bg-lgu-headline text-white p-4 rounded-lg space-y-2">
                        ${aiAnalysis.topPrediction ? `<div class="pb-2 border-b border-lgu-button"><p class="text-xs font-semibold mb-2">Primary Detection</p><div class="flex justify-between items-center"><span class="text-sm">${aiAnalysis.topPrediction.className}</span><span class="bg-lgu-button text-lgu-button-text px-2 py-1 rounded text-xs font-bold">${(aiAnalysis.topPrediction.probability * 100).toFixed(1)}%</span></div></div>` : ''}
                        ${aiAnalysis.hazardLevel ? `<div class="pt-2"><p class="text-xs font-semibold mb-2">Hazard Level</p><span class="inline-block ${aiAnalysis.hazardLevel === 'high' ? 'bg-red-500' : aiAnalysis.hazardLevel === 'medium' ? 'bg-lgu-button' : 'bg-green-500'} px-3 py-1 rounded text-xs font-bold">${aiAnalysis.hazardLevel.toUpperCase()}</span></div>` : ''}
                    </div>
                </div>
                ` : ''}

                <div class="mt-6 border-t border-lgu-stroke pt-6">
                    <h3 class="text-sm font-semibold text-lgu-headline mb-3">Inspection Findings (${findings.length})</h3>
                    ${findings.length > 0 ? `<div class="space-y-3">${findings.map((f, i) => `<div class="bg-lgu-bg p-3 rounded-lg border border-lgu-stroke"><div class="flex justify-between items-start mb-2"><span class="text-xs font-semibold text-lgu-paragraph">Finding #${i + 1}</span><span class="${f.severity === 'major' ? 'bg-danger' : 'bg-lgu-button'} text-white px-2 py-1 rounded text-xs font-bold">${f.severity}</span></div><p class="text-sm text-lgu-paragraph">${f.notes}</p><p class="text-xs text-lgu-paragraph mt-2"><i class="fas fa-user-check mr-1"></i>${f.inspector_name}</p></div>`).join('')}</div>` : '<p class="text-sm text-lgu-paragraph">No findings recorded yet</p>'}
                </div>

                ${inspector ? `
                <div class="mt-6 border-t border-lgu-stroke pt-6">
                    <h3 class="text-sm font-semibold text-lgu-headline mb-3">Inspector Information</h3>
                    <div class="bg-lgu-bg p-3 rounded-lg space-y-2 text-sm">
                        <p><span class="text-lgu-paragraph">Name:</span> <span class="font-medium text-lgu-headline">${inspector.name}</span></p>
                        <p><span class="text-lgu-paragraph">Contact:</span> <span class="font-medium text-lgu-headline">${inspector.contact}</span></p>
                        <p><span class="text-lgu-paragraph">Email:</span> <span class="font-medium text-lgu-headline">${inspector.email}</span></p>
                    </div>
                </div>
                ` : ''}
            `;

            document.getElementById('modalContent').innerHTML = html;
            setTimeout(() => initMap(location), 100);
        }

        function initMap(location) {
            if (!document.getElementById('reportMap')) return;
            if (map) map.remove();
            map = L.map('reportMap').setView([14.5995, 120.9842], 12);
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {attribution: '© TomTom', maxZoom: 19}).addTo(map);
            setTimeout(() => map.invalidateSize(), 100);
            if (location && location.latitude && location.longitude) {
                const redIcon = L.divIcon({html: `<div style="background: #ef4444; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-map-pin" style="color: white; font-size: 14px;"></i></div>`, iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -15]});
                L.marker([location.latitude, location.longitude], { icon: redIcon }).addTo(map).bindPopup(`<div style="font-size: 12px; font-weight: 500;"><strong>Hazard Location</strong><br>${location.address}</div>`).openPopup();
                map.setView([location.latitude, location.longitude], 15);
            }
        }

        document.getElementById('detailsModal').addEventListener('click', (e) => { if (e.target.id === 'detailsModal') closeModal(); });
        document.getElementById('lightbox').addEventListener('click', (e) => { if (e.target.id === 'lightbox') closeLightbox(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeLightbox(); closeModal(); } });
        document.getElementById('modalContent').addEventListener('click', (e) => { e.stopPropagation(); });
    </script>
</body>
</html>
