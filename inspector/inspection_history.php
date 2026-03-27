<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$inspector_id = $_SESSION['user_id'];
$inspector_name = $_SESSION['full_name'] ?? 'Inspector';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$severity_filter = isset($_GET['severity']) ? $_GET['severity'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_clauses = ["ri.inspector_id = ?"];
$params = [$inspector_id];

if ($status_filter !== 'all') {
    $where_clauses[] = "ri.status = ?";
    $params[] = $status_filter;
}

if ($severity_filter !== 'all') {
    $where_clauses[] = "if_.severity = ?";
    $params[] = $severity_filter;
}

if (!empty($search)) {
    $where_clauses[] = "(r.description LIKE ? OR r.address LIKE ? OR r.hazard_type LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Get total count
$count_sql = "SELECT COUNT(DISTINCT ri.id) as total
              FROM report_inspectors ri
              INNER JOIN reports r ON ri.report_id = r.id
              LEFT JOIN inspection_findings if_ ON if_.report_id = r.id AND if_.inspector_id = ri.inspector_id
              WHERE $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get inspection history
$sql = "SELECT 
            ri.id as assignment_id,
            ri.report_id,
            ri.assigned_at,
            ri.completed_at,
            ri.status as assignment_status,
            ri.notes as assignment_notes,
            r.hazard_type,
            r.description,
            r.address,
            r.landmark,
            r.contact_number,
            r.ai_analysis_result,
            r.status as report_status,
            r.image_path,
            r.created_at as report_date,
            r.validation_status,
            if_.severity,
            if_.notes as finding_notes,
            if_.created_at as inspection_date,
            u.fullname as reporter_name,
            u.address as reporter_address,
            u.contact_number as reporter_contact
        FROM report_inspectors ri
        INNER JOIN reports r ON ri.report_id = r.id
        LEFT JOIN inspection_findings if_ ON if_.report_id = r.id AND if_.inspector_id = ri.inspector_id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE $where_sql
        ORDER BY ri.assigned_at DESC
        LIMIT $records_per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inspections = $stmt->fetchAll();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN ri.status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN ri.status = 'assigned' THEN 1 ELSE 0 END) as ongoing,
    SUM(CASE WHEN if_.severity = 'minor' THEN 1 ELSE 0 END) as minor,
    SUM(CASE WHEN if_.severity = 'major' THEN 1 ELSE 0 END) as major
FROM report_inspectors ri
LEFT JOIN inspection_findings if_ ON if_.report_id = ri.report_id AND if_.inspector_id = ri.inspector_id
WHERE ri.inspector_id = ?";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$inspector_id]);
$stats = $stats_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inspection History - Inspector Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'lgu-bg': '#f8faf9',
            'lgu-headline': '#003d34',
            'lgu-stroke': '#00332c',
            'lgu-highlight': '#faae2b',
            'lgu-tertiary': '#f5a217',
          }
        }
      }
    }
  </script>
</head>

<body class="bg-lgu-bg font-poppins">

  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <!-- Main Content -->
  <div class="lg:ml-64 min-h-screen">
    
    <!-- Top Bar -->
    <div class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
      <div class="flex items-center justify-between px-6 py-4">
        <div>
          <h1 class="text-2xl font-bold text-lgu-headline">Inspection History</h1>
          <p class="text-sm text-gray-500 mt-1">View all your completed and ongoing inspections</p>
        </div>
        <div class="flex items-center space-x-4">
          <div class="text-right">
            <p class="text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($inspector_name); ?></p>
            <p class="text-xs text-gray-500">Inspector</p>
          </div>
          <div class="w-10 h-10 rounded-full bg-lgu-highlight flex items-center justify-center text-white font-semibold">
            <?php echo strtoupper(substr($inspector_name, 0, 1)); ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Content Area -->
    <div class="p-6 space-y-6">

      <!-- Statistics Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
        
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-blue-100 text-xs font-medium">Total Inspections</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo $stats['total'] ?? 0; ?></h3>
            </div>
            <i class="fas fa-clipboard-list text-2xl opacity-80"></i>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-green-100 text-xs font-medium">Completed</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo $stats['completed'] ?? 0; ?></h3>
            </div>
            <i class="fas fa-check-circle text-2xl opacity-80"></i>
          </div>
        </div>

        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow-lg p-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-orange-100 text-xs font-medium">Ongoing</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo $stats['ongoing'] ?? 0; ?></h3>
            </div>
            <i class="fas fa-spinner text-2xl opacity-80"></i>
          </div>
        </div>

        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-yellow-100 text-xs font-medium">Minor Cases</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo $stats['minor'] ?? 0; ?></h3>
            </div>
            <i class="fas fa-wrench text-2xl opacity-80"></i>
          </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-red-100 text-xs font-medium">Major Cases</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo $stats['major'] ?? 0; ?></h3>
            </div>
            <i class="fas fa-exclamation-triangle text-2xl opacity-80"></i>
          </div>
        </div>

      </div>

      <!-- Filters and Search -->
      <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                   placeholder="Search by description, location..."
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-lgu-highlight focus:border-transparent">
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-lgu-highlight focus:border-transparent">
              <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
              <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
              <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
              <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Severity</label>
            <select name="severity" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-lgu-highlight focus:border-transparent">
              <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
              <option value="minor" <?php echo $severity_filter === 'minor' ? 'selected' : ''; ?>>Minor</option>
              <option value="major" <?php echo $severity_filter === 'major' ? 'selected' : ''; ?>>Major</option>
            </select>
          </div>

          <div class="flex items-end space-x-2">
            <button type="submit" class="flex-1 bg-lgu-headline hover:bg-lgu-stroke text-white font-semibold py-2 px-4 rounded-lg transition">
              <i class="fas fa-search mr-2"></i>Filter
            </button>
            <a href="inspection_history.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg transition">
              <i class="fas fa-redo"></i>
            </a>
          </div>

        </form>
      </div>

      <!-- Inspection History Table -->
      <div class="bg-white rounded-xl shadow-lg border border-gray-200">
        <div class="p-6 border-b border-gray-200">
          <h2 class="text-xl font-bold text-lgu-headline flex items-center">
            <i class="fas fa-list text-lgu-highlight mr-3"></i>
            Inspection Records
          </h2>
          <p class="text-sm text-gray-600 mt-1">Showing <?php echo count($inspections); ?> of <?php echo $total_records; ?> records</p>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Report Details</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Hazard Type</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Hazard Level</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Severity</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Status</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <?php if (count($inspections) > 0): ?>
                <?php foreach ($inspections as $inspection): ?>
                  <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4">
                      <div class="flex items-start space-x-3">
                        <?php if ($inspection['image_path']): ?>
                          <img src="../uploads/hazard_reports/<?php echo htmlspecialchars($inspection['image_path']); ?>" 
                               alt="Report" 
                               class="w-20 h-20 rounded-lg object-cover border-2 border-gray-200 cursor-pointer hover:border-lgu-highlight transition"
                               onclick="showImageModal('../uploads/hazard_reports/<?php echo htmlspecialchars($inspection['image_path']); ?>')">
                        <?php else: ?>
                          <div class="w-20 h-20 rounded-lg bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-image text-gray-400 text-xl"></i>
                          </div>
                        <?php endif; ?>
                        <div>
                          <p class="font-semibold text-gray-800"><?php echo htmlspecialchars(substr($inspection['description'], 0, 50)); ?>...</p>
                          <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-map-marker-alt mr-1"></i>
                            <?php echo htmlspecialchars(substr($inspection['address'], 0, 40)); ?>...
                          </p>
                          <?php if (!empty($inspection['landmark'])): ?>
                          <p class="text-xs text-gray-400 mt-1">
                            <i class="fas fa-landmark mr-1"></i>
                            <?php echo htmlspecialchars($inspection['landmark']); ?>
                          </p>
                          <?php endif; ?>
                          <p class="text-xs text-gray-400 mt-1">Reporter: <?php echo htmlspecialchars($inspection['reporter_name']); ?></p>
                          <?php if (!empty($inspection['contact_number'])): ?>
                          <p class="text-xs text-gray-400 mt-1">
                            <i class="fas fa-phone mr-1"></i>
                            <?php echo htmlspecialchars($inspection['contact_number']); ?>
                          </p>
                          <?php endif; ?>
                          <?php if (!empty($inspection['ai_analysis_result'])): ?>
                          <p class="text-xs text-blue-600 mt-1">
                            <i class="fas fa-robot mr-1"></i>
                            AI Analysis Available
                          </p>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                        <?php 
                          switch($inspection['hazard_type']) {
                            case 'road': echo 'bg-blue-100 text-blue-800'; break;
                            case 'traffic': echo 'bg-red-100 text-red-800'; break;
                            case 'drainage': echo 'bg-green-100 text-green-800'; break;
                            default: echo 'bg-gray-100 text-gray-800';
                          }
                        ?>">
                        <?php echo ucfirst($inspection['hazard_type']); ?>
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <?php 
                        $hazardLevel = 'Not assessed';
                        $levelClass = 'text-gray-400 text-xs';
                        if (!empty($inspection['ai_analysis_result'])) {
                          try {
                            $analysis = json_decode($inspection['ai_analysis_result'], true);
                            if (isset($analysis['hazardLevel'])) {
                              $hazardLevel = ucfirst($analysis['hazardLevel']);
                              switch($analysis['hazardLevel']) {
                                case 'high': $levelClass = 'bg-red-100 text-red-800'; break;
                                case 'medium': $levelClass = 'bg-yellow-100 text-yellow-800'; break;
                                case 'low': $levelClass = 'bg-blue-100 text-blue-800'; break;
                              }
                            }
                          } catch (Exception $e) {}
                        }
                      ?>
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo $levelClass; ?>">
                        <?php echo $hazardLevel; ?>
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <?php if ($inspection['severity']): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                          <?php echo $inspection['severity'] === 'minor' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'; ?>">
                          <i class="fas <?php echo $inspection['severity'] === 'minor' ? 'fa-wrench' : 'fa-exclamation-triangle'; ?> mr-1"></i>
                          <?php echo ucfirst($inspection['severity']); ?>
                        </span>
                      <?php else: ?>
                        <span class="text-gray-400 text-xs">Not assessed</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                        <?php 
                          switch($inspection['assignment_status']) {
                            case 'assigned': echo 'bg-blue-100 text-blue-800'; break;
                            case 'completed': echo 'bg-green-100 text-green-800'; break;
                            case 'cancelled': echo 'bg-gray-100 text-gray-800'; break;
                            default: echo 'bg-gray-100 text-gray-800';
                          }
                        ?>">
                        <?php echo ucfirst($inspection['assignment_status']); ?>
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <div class="text-sm">
                        <p class="font-medium text-gray-800">
                          <?php echo date('M d, Y', strtotime($inspection['assigned_at'])); ?>
                        </p>
                        <p class="text-xs text-gray-500">
                          <?php echo date('h:i A', strtotime($inspection['assigned_at'])); ?>
                        </p>
                        <?php if ($inspection['inspection_date']): ?>
                          <p class="text-xs text-green-600 mt-1">
                            <i class="fas fa-check-circle mr-1"></i>
                            Inspected: <?php echo date('M d', strtotime($inspection['inspection_date'])); ?>
                          </p>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <button onclick="viewDetails(<?php echo $inspection['report_id']; ?>)" 
                              class="text-lgu-highlight hover:text-lgu-tertiary font-medium text-sm transition">
                        <i class="fas fa-eye mr-1"></i>View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="px-6 py-12 text-center">
                    <div class="text-gray-400">
                      <i class="fas fa-clipboard-list text-4xl mb-3"></i>
                      <p class="text-lg font-medium">No inspection records found</p>
                      <p class="text-sm mt-1">Inspections you complete will appear here</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
          <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-600">
              Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </div>
            <div class="flex space-x-2">
              <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition text-sm font-medium">
                  <i class="fas fa-chevron-left mr-1"></i>Previous
                </a>
              <?php endif; ?>

              <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 <?php echo $i === $page ? 'bg-lgu-headline text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700'; ?> rounded-lg transition text-sm font-medium">
                  <?php echo $i; ?>
                </a>
              <?php endfor; ?>

              <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&severity=<?php echo $severity_filter; ?>&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition text-sm font-medium">
                  Next<i class="fas fa-chevron-right ml-1"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>

    </div>
  </div>

  <!-- Image Modal -->
  <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-75 z-60 hidden flex items-center justify-center p-4">
    <div class="relative max-w-4xl max-h-[90vh] w-full h-full flex items-center justify-center">
      <button onclick="closeImageModal()" class="absolute top-4 right-4 text-white hover:text-gray-300 transition z-10">
        <i class="fas fa-times text-2xl"></i>
      </button>
      <img id="modalImage" src="" alt="Full size image" class="max-w-full max-h-full object-contain rounded-lg">
    </div>
  </div>

  <!-- View Details Modal -->
  <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
      <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <h3 class="text-xl font-bold text-lgu-headline">Inspection Details</h3>
        <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      <div id="modalContent" class="p-6">
        <div class="text-center py-8">
          <i class="fas fa-spinner fa-spin text-4xl text-lgu-highlight"></i>
          <p class="text-gray-600 mt-3">Loading details...</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.getElementById('inspector-sidebar');
      const toggle = document.getElementById('sidebar-toggle');
      const close = document.getElementById('sidebar-close');
      const overlay = document.getElementById('sidebar-overlay');
      const logout = document.getElementById('logout-btn');

      toggle.addEventListener('click', () => {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
      });

      close.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
      });

      overlay.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
      });

      logout.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
          window.location.href = '/BENG/logout.php';
        }
      });
    });

    function viewDetails(reportId) {
      const modal = document.getElementById('detailsModal');
      const modalContent = document.getElementById('modalContent');
      
      modal.classList.remove('hidden');
      
      // Fetch details via AJAX
      fetch(`get_inspection_details.php?report_id=${reportId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            modalContent.innerHTML = generateDetailsHTML(data.data);
          } else {
            modalContent.innerHTML = `
              <div class="text-center py-8">
                <i class="fas fa-exclamation-circle text-4xl text-red-500"></i>
                <p class="text-gray-600 mt-3">${data.message || 'Failed to load details'}</p>
              </div>
            `;
          }
        })
        .catch(error => {
          modalContent.innerHTML = `
            <div class="text-center py-8">
              <i class="fas fa-exclamation-triangle text-4xl text-red-500"></i>
              <p class="text-gray-600 mt-3">Error loading details. Please try again.</p>
            </div>
          `;
        });
    }

    function generateDetailsHTML(data) {
      // Initialize map after modal content is loaded
      setTimeout(() => {
        initReportMap(data.address, data.hazard_type, data.landmark);
      }, 100);
      
      return `
        <div class="space-y-6">
          <!-- Media (Image/Video) -->
          ${data.image_path ? `
            <div class="bg-white rounded-lg p-4">
              ${(() => {
                const ext = data.image_path.split('.').pop().toLowerCase();
                const isVideo = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'ogv'].includes(ext);
                return isVideo ? 
                  `<video controls style="max-height: 500px; width: 100%;" class="rounded-lg"><source src="../uploads/hazard_reports/${data.image_path}"></video>` :
                  `<img src="../uploads/hazard_reports/${data.image_path}" alt="Report" class="w-full h-auto rounded-lg" onclick="showImageModal('../uploads/hazard_reports/${data.image_path}')">`;
              })()}
            </div>
          ` : ''}

          <!-- AI Analysis Section -->
          ${data.ai_analysis_result ? `
            <div class="bg-white rounded-lg p-4">
              <h4 class="font-semibold text-lgu-headline text-lg mb-3">Beripikado AI Analysis</h4>
              ${(() => {
                try {
                  const analysis = JSON.parse(data.ai_analysis_result);
                  let html = '<div class="bg-blue-50 p-3 rounded-lg space-y-2">';
                  if (analysis.topPrediction) {
                    html += `<div class="bg-blue-100 p-2 rounded border-l-4 border-blue-500"><div class="flex justify-between"><span class="font-semibold text-blue-800">${analysis.topPrediction.className}</span><span class="text-sm font-bold text-blue-700">${(analysis.topPrediction.probability * 100).toFixed(1)}%</span></div></div>`;
                  }
                  if (analysis.hazardLevel) {
                    html += `<div class="bg-purple-100 p-2 rounded border-l-4 border-purple-500"><span class="font-semibold text-purple-800">Hazard Level: ${analysis.hazardLevel.toUpperCase()}</span></div>`;
                  }
                  if (analysis.classifiedType) {
                    html += `<div class="bg-green-100 p-2 rounded border-l-4 border-green-500"><span class="font-semibold text-green-800">Type: ${analysis.classifiedType.toUpperCase()}</span></div>`;
                  }
                  html += '</div>';
                  return html;
                } catch (e) {
                  return `<div class="bg-gray-50 p-3 rounded">${data.ai_analysis_result}</div>`;
                }
              })()}
            </div>
          ` : ''}

          <!-- Report Information -->
          <div class="bg-gray-50 rounded-lg p-4 space-y-3">
            <h4 class="font-semibold text-lgu-headline text-lg">Report Information</h4>
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-gray-500">Hazard Type</p>
                <p class="font-semibold text-gray-800">${data.hazard_type.toUpperCase()}</p>
              </div>
              <div>
                <p class="text-gray-500">Report Status</p>
                <p class="font-semibold text-gray-800">${data.report_status.toUpperCase()}</p>
              </div>
              <div class="col-span-2">
                <p class="text-gray-500">Description</p>
                <p class="font-semibold text-gray-800">${data.description}</p>
              </div>
              <div class="col-span-2">
                <p class="text-gray-500">Location</p>
                <p class="font-semibold text-gray-800">${data.address}</p>
                ${data.landmark ? `<p class="text-xs text-gray-600 mt-1"><i class="fas fa-landmark mr-1"></i>${data.landmark}</p>` : ''}
              </div>
              ${data.contact_number ? `
              <div>
                <p class="text-gray-500">Contact Number</p>
                <p class="font-semibold text-gray-800">${data.contact_number}</p>
              </div>
              ` : ''}
              <div class="col-span-2">
                <p class="text-gray-500 mb-2">Location Map</p>
                <div id="reportMap" class="w-full h-48 rounded-lg border border-gray-300"></div>
                <p class="text-xs text-gray-500 mt-1">Red marker shows hazard location</p>
              </div>
              <div>
                <p class="text-gray-500">Reported By</p>
                <p class="font-semibold text-gray-800">${data.reporter_name}</p>
              </div>
              <div>
                <p class="text-gray-500">Report Date</p>
                <p class="font-semibold text-gray-800">${new Date(data.report_date).toLocaleDateString()}</p>
              </div>
            </div>
          </div>

          ${data.severity ? `
            <div class="bg-yellow-50 rounded-lg p-4 space-y-3">
              <h4 class="font-semibold text-lgu-headline text-lg">Inspection Findings</h4>
              <div class="space-y-2 text-sm">
                <div>
                  <p class="text-gray-500">Severity Assessment</p>
                  <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${data.severity === 'minor' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'}">
                    <i class="fas fa-${data.severity === 'minor' ? 'wrench' : 'exclamation-triangle'} mr-2"></i>
                    ${data.severity.toUpperCase()}
                  </span>
                </div>
                <div>
                  <p class="text-gray-500">Inspection Notes</p>
                  <p class="font-semibold text-gray-800">${data.finding_notes || 'No additional notes'}</p>
                </div>
                <div>
                  <p class="text-gray-500">Inspection Date</p>
                  <p class="font-semibold text-gray-800">${new Date(data.inspection_date).toLocaleString()}</p>
                </div>
              </div>
            </div>
          ` : `
            <div class="bg-gray-50 rounded-lg p-4 text-center text-gray-500">
              <i class="fas fa-info-circle text-2xl mb-2"></i>
              <p>No inspection findings recorded yet</p>
            </div>
          `}

          <div class="bg-blue-50 rounded-lg p-4 space-y-3">
            <h4 class="font-semibold text-lgu-headline text-lg">Assignment Details</h4>
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-gray-500">Assignment Status</p>
                <p class="font-semibold text-gray-800">${data.assignment_status.toUpperCase()}</p>
              </div>
              <div>
                <p class="text-gray-500">Assigned Date</p>
                <p class="font-semibold text-gray-800">${new Date(data.assigned_at).toLocaleString()}</p>
              </div>
              ${data.completed_at ? `
                <div>
                  <p class="text-gray-500">Completed Date</p>
                  <p class="font-semibold text-gray-800">${new Date(data.completed_at).toLocaleString()}</p>
                </div>
              ` : ''}
              ${data.assignment_notes ? `
                <div class="col-span-2">
                  <p class="text-gray-500">Assignment Notes</p>
                  <p class="font-semibold text-gray-800">${data.assignment_notes}</p>
                </div>
              ` : ''}
            </div>
          </div>
        </div>
      `;
    }

    function showImageModal(imageSrc) {
      const modal = document.getElementById('imageModal');
      const modalImage = document.getElementById('modalImage');
      modalImage.src = imageSrc;
      modal.classList.remove('hidden');
    }

    function closeImageModal() {
      document.getElementById('imageModal').classList.add('hidden');
    }

    function closeModal() {
      document.getElementById('detailsModal').classList.add('hidden');
    }

    // Close modals on escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeModal();
        closeImageModal();
      }
    });

    // Close image modal when clicking outside the image
    document.getElementById('imageModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeImageModal();
      }
    });
    
    // Map functionality
    const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
    let reportMap = null;
    
    function initReportMap(address, hazardType, landmark) {
      const mapElement = document.getElementById('reportMap');
      if (!mapElement || !address) return;
      
      // Clear existing map
      if (reportMap) {
        reportMap.remove();
      }
      
      // Initialize map
      reportMap = L.map('reportMap').setView([14.5995, 120.9842], 12);
      
      // Add TomTom tile layer
      L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
        attribution: '© TomTom, © OpenStreetMap contributors'
      }).addTo(reportMap);
      
      // Geocode and add marker
      geocodeAndMarkLocation(reportMap, address, hazardType, landmark);
    }
    
    async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
      try {
        const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
        const data = await response.json();
        
        if (data.results && data.results.length > 0) {
          const result = data.results[0];
          const lat = result.position.lat;
          const lng = result.position.lon;
          
          // Add hazard marker
          const marker = L.marker([lat, lng], {
            icon: L.divIcon({
              className: 'custom-marker',
              html: '<div style="background: #ef4444; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
              iconSize: [25, 25],
              iconAnchor: [12, 12]
            })
          }).addTo(map);
          
          // Create popup content
          let popupContent = `
            <div class="p-2">
              <h4 class="font-bold text-red-600 text-sm">Hazard Location</h4>
              <p class="text-xs mt-1"><strong>Type:</strong> ${hazardType}</p>
              <p class="text-xs"><strong>Address:</strong> ${result.address.freeformAddress}</p>
          `;
          
          if (landmark) {
            popupContent += `<p class="text-xs"><strong>Landmark:</strong> ${landmark}</p>`;
          }
          
          popupContent += `</div>`;
          
          marker.bindPopup(popupContent);
          
          // Center map on location
          map.setView([lat, lng], 16);
        }
      } catch (error) {
        console.error('Geocoding error:', error);
      }
    }
  </script>

  <style>
    * { font-family: 'Poppins', sans-serif; }
    .sidebar-link.active { 
      color: #faae2b; 
      background-color: #00332c; 
      border-left: 3px solid #faae2b; 
    }
    #inspector-sidebar nav::-webkit-scrollbar { width: 6px; }
    #inspector-sidebar nav::-webkit-scrollbar-thumb { 
      background: #faae2b; 
      border-radius: 3px; 
    }
    #inspector-sidebar nav::-webkit-scrollbar-thumb:hover { 
      background: #f5a217; 
    }
  </style>

</body>
</html>