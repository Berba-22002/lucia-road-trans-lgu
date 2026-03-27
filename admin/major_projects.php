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

// Handle AJAX status check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_project_status') {
    header('Content-Type: application/json');
    $projectId = $_POST['project_id'] ?? '';
    
    if (!$projectId) {
        echo json_encode(['success' => false, 'message' => 'Project ID is required']);
        exit();
    }
    
    try {
        // Try to get status from external API
        $statusUrl = 'https://infra-pm.local-government-unit-1-ph.com/api/integrations/ProjectRequestStatus.php?project_id=' . urlencode($projectId);
        $ch = curl_init($statusUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && !$curlError && $response) {
            $decodedResponse = json_decode($response, true);
            if ($decodedResponse && isset($decodedResponse['success']) && $decodedResponse['success']) {
                // Map IPM response to expected format
                $projectData = $decodedResponse['data'];
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'status' => $projectData['overall_status'] ?? $projectData['status'] ?? 'pending',
                        'bid_status' => $projectData['bid_application_statuses'] ?? $projectData['bid_status'] ?? null
                    ]
                ]);
                exit();
            }
        }
        
        // Fallback: Get status from local database
        $stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($project) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'status' => $project['status'] ?? 'pending',
                    'bid_status' => null // No bid status available locally
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
        }
        
    } catch (Exception $e) {
        // Fallback to local database on any error
        try {
            $stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($project) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'status' => $project['status'] ?? 'pending',
                        'bid_status' => null
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Project not found']);
            }
        } catch (Exception $dbError) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    }
    exit();
}

$filter_status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    // Get escalated projects with full details
    $query = "
        SELECT 
            p.id,
            p.project_title,
            p.requesting_office,
            p.project_category,
            p.priority_level,
            p.status,
            p.project_location as location,
            p.created_at,
            r.status as report_status
        FROM projects p
        LEFT JOIN reports r ON p.report_id = r.id
        WHERE r.status = 'escalated'
    ";

    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR p.project_location LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $query .= " ORDER BY p.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
    $projects = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Major Projects - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'poppins': ['Poppins', 'sans-serif'] },
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
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .escalated-row {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
        }
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
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Major Projects</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Escalated projects from road system</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
                            <i class="fa fa-user text-lgu-button-text font-semibold text-sm sm:text-base"></i>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 15)); ?></p>
                            <p class="text-xs text-lgu-paragraph">Admin</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
            <?php if (count($projects) > 0): ?>
                <div class="bg-orange-50 border border-orange-200 text-orange-800 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i class="fa fa-clock text-orange-600 mr-3 text-lg"></i>
                        <div>
                            <p class="font-semibold">Infrastructure Approval Required</p>
                            <p class="text-sm">There are <strong><?php echo count($projects); ?></strong> escalated project(s) pending infrastructure approval.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="bg-white rounded-lg shadow p-4 mb-6">
                <form method="GET" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search projects..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm" />
                            <i class="fa fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-lgu-paragraph"></i>
                        </div>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-lgu-button text-lgu-button-text rounded-lg hover:bg-yellow-500 transition-colors">
                        <i class="fa fa-search mr-2"></i>Search
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <?php if (empty($projects)): ?>
                        <div class="p-12 text-center">
                            <i class="fa fa-folder-open text-6xl text-gray-300 mb-4"></i>
                            <h3 class="text-xl font-semibold text-lgu-headline mb-2">No Escalated Projects</h3>
                            <p class="text-lgu-paragraph">There are no escalated projects from the road system.</p>
                        </div>
                    <?php else: ?>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-lgu-headline text-white">
                                <tr>
                                    <th class="py-4 px-4 font-semibold">ID</th>
                                    <th class="py-4 px-4 font-semibold">Project Details</th>
                                    <th class="py-4 px-4 font-semibold">Office/Barangay</th>
                                    <th class="py-4 px-4 font-semibold">Category</th>
                                    <th class="py-4 px-4 font-semibold">Priority</th>
                                    <th class="py-4 px-4 font-semibold">Bid Status</th>
                                    <th class="py-4 px-4 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($projects as $project): ?>
                                    <tr class="escalated-row hover:bg-red-50">
                                        <td class="py-4 px-4 font-bold text-lgu-headline">
                                            PR-<?php echo str_pad($project['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="space-y-1">
                                                <div class="text-xs font-bold text-lgu-headline line-clamp-2"><?php echo htmlspecialchars($project['project_title']); ?></div>
                                                <div class="text-xs text-lgu-paragraph">
                                                    <i class="fa fa-map-marker-alt text-lgu-button mr-1"></i>
                                                    <?php echo htmlspecialchars($project['location'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4 text-xs text-lgu-paragraph font-medium"><?php echo htmlspecialchars($project['requesting_office'] ?? 'N/A'); ?></td>
                                        <td class="py-4 px-4 text-xs text-lgu-paragraph"><?php echo htmlspecialchars($project['project_category'] ?? 'N/A'); ?></td>
                                        <td class="py-4 px-4">
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-bold rounded-full shadow-sm
                                                <?php 
                                                echo ($project['priority_level'] ?? '') === 'high' ? 'bg-red-100 text-red-800 border border-red-200' : 
                                                    (($project['priority_level'] ?? '') === 'medium' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 'bg-gray-100 text-gray-800 border border-gray-200');
                                                ?>">
                                                <?php echo ucfirst($project['priority_level'] ?? 'low'); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div id="bid_status_<?php echo $project['id']; ?>">
                                                <span class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-gray-50 text-gray-500 border border-gray-200">
                                                    <i class="fa fa-sync-alt fa-spin mr-1"></i>Loading...
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div id="status_<?php echo $project['id']; ?>">
                                                <span class="status-badge bg-gray-100 text-gray-700">
                                                    <i class="fa fa-sync-alt fa-spin"></i>
                                                    Loading...
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($projects)): ?>
                <div class="mt-6 text-sm text-lgu-paragraph text-center">
                    <p>Showing <span class="font-bold text-lgu-headline"><?php echo count($projects); ?></span> escalated project(s)</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-load status for all projects
            <?php foreach ($projects as $project): ?>
            loadProjectStatus(<?php echo $project['id']; ?>);
            <?php endforeach; ?>
        });
        
        function loadProjectStatus(projectId) {
            const statusDiv = document.getElementById('status_' + projectId);
            const bidStatusDiv = document.getElementById('bid_status_' + projectId);
            
            const formData = new FormData();
            formData.append('action', 'get_project_status');
            formData.append('project_id', projectId);
            
            fetch('major_projects.php', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    const status = data.data.status || 'pending';
                    const bidStatus = data.data.bid_status;
                    
                    let statusClass = 'bg-orange-100 text-orange-700';
                    let icon = 'fa-clock';
                    
                    switch(status.toLowerCase()) {
                        case 'approved':
                            statusClass = 'bg-green-100 text-green-700';
                            icon = 'fa-check-circle';
                            break;
                        case 'rejected':
                            statusClass = 'bg-red-100 text-red-700';
                            icon = 'fa-times-circle';
                            break;
                        case 'pending':
                        default:
                            statusClass = 'bg-orange-100 text-orange-700';
                            icon = 'fa-clock';
                            break;
                    }
                    
                    statusDiv.innerHTML = `<span class="status-badge ${statusClass}"><i class="fa ${icon}"></i> ${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
                    
                    if (bidStatus) {
                        const bidStatusColors = {
                            'submitted': 'bg-blue-100 text-blue-800 border border-blue-200',
                            'under_review': 'bg-purple-100 text-purple-800 border border-purple-200',
                            'accepted': 'bg-green-100 text-green-800 border border-green-200',
                            'rejected': 'bg-red-100 text-red-800 border border-red-200',
                            'completed': 'bg-green-200 text-green-900 border border-green-300'
                        };
                        const bidClass = bidStatusColors[bidStatus] || 'bg-gray-100 text-gray-800 border border-gray-200';
                        bidStatusDiv.innerHTML = `<span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full shadow-sm ${bidClass}"><div class="w-2 h-2 rounded-full bg-current mr-2 opacity-75"></div>${bidStatus.replace('_', ' ').toUpperCase()}</span>`;
                    } else {
                        bidStatusDiv.innerHTML = '<span class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-gray-50 text-gray-500 border border-gray-200"><svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>No bid</span>';
                    }
                } else {
                    // Show a more user-friendly message
                    statusDiv.innerHTML = '<span class="status-badge bg-yellow-100 text-yellow-700"><i class="fa fa-exclamation-triangle"></i> Pending</span>';
                    bidStatusDiv.innerHTML = '<span class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-gray-50 text-gray-500 border border-gray-200"><i class="fa fa-minus mr-1"></i>N/A</span>';
                }
            })
            .catch(error => {
                console.error('Error loading project status:', error);
                // Show fallback status instead of error
                statusDiv.innerHTML = '<span class="status-badge bg-yellow-100 text-yellow-700"><i class="fa fa-clock"></i> Pending</span>';
                bidStatusDiv.innerHTML = '<span class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full bg-gray-50 text-gray-500 border border-gray-200"><i class="fa fa-minus mr-1"></i>N/A</span>';
            });
        }
    </script>
</body>
</html>