<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin users
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query based on filters
$query = "
    SELECT 
        ma.id as assignment_id,
        ma.report_id,
        ma.forward_id,
        ma.completion_deadline,
        ma.notes as assignment_notes,
        ma.status as assignment_status,
        ma.team_type,
        ma.started_at,
        ma.completed_at,
        ma.created_at as assigned_date,
        ma.completion_image_path,
        r.hazard_type,
        r.description,
        r.address,
        r.landmark,
        r.image_path,
        r.contact_number,
        r.ai_analysis_result,
        r.status as report_status,
        u_assigned_by.fullname as assigned_by_name,
        u_assigned_to.fullname as assigned_to_name,
        u_assigned_to.email as assigned_to_email,
        rf.notes as forward_notes
    FROM maintenance_assignments ma
    INNER JOIN reports r ON ma.report_id = r.id
    INNER JOIN users u_assigned_by ON ma.assigned_by = u_assigned_by.id
    INNER JOIN users u_assigned_to ON ma.assigned_to = u_assigned_to.id
    LEFT JOIN report_forwards rf ON ma.forward_id = rf.id
    WHERE ma.team_type = 'traffic_management'
";

// Add status filter
if ($status_filter !== 'all') {
    $query .= " AND ma.status = :status";
}

// Add search filter
if (!empty($search)) {
    $query .= " AND (
        r.address LIKE :search 
        OR r.description LIKE :search 
        OR u_assigned_to.fullname LIKE :search
        OR ma.id LIKE :search
        OR r.id LIKE :search
    )";
}

$query .= " ORDER BY 
    CASE 
        WHEN ma.status = 'assigned' THEN 1
        WHEN ma.status = 'in_progress' THEN 2
        WHEN ma.status = 'completed' THEN 3
        ELSE 4
    END,
    ma.completion_deadline ASC
";

$stmt = $pdo->prepare($query);

if ($status_filter !== 'all') {
    $stmt->bindValue(':status', $status_filter);
}
if (!empty($search)) {
    $stmt->bindValue(':search', '%' . $search . '%');
}

$stmt->execute();
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_tasks = count($assignments);
$assigned_count = count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'assigned'));
$in_progress_count = count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'in_progress'));
$completed_count = count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'completed'));
$overdue_count = count(array_filter($assignments, fn($a) => 
    $a['assignment_status'] !== 'completed' && strtotime($a['completion_deadline']) < time()
));

// Calculate completion rate
$completion_rate = $total_tasks > 0 ? round(($completed_count / $total_tasks) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Traffic Management Progress - RTIM Admin</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <!-- Poppins Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <!-- Leaflet JS -->
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
    * { font-family: 'Poppins', sans-serif; }
    html, body { width: 100%; height: 100%; overflow-x: hidden; }
    .sidebar-link { color:#9CA3AF; }
    .sidebar-link:hover { color:#FFF; background:#00332c; }
    .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
    .task-card { transition: all .2s ease; }
    .task-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,.1); }
    
    /* Status indicators */
    .status-assigned { border-left: 4px solid #6b7280 !important; }
    .status-in-progress { border-left: 4px solid #faae2b !important; }
    .status-completed { border-left: 4px solid #10b981 !important; }
    .status-overdue { border-left: 4px solid #ef4444 !important; }
    
    /* Progress bar animation */
    .progress-bar {
      transition: width 0.5s ease;
    }
    
    /* Timeline styles */
    .timeline-item {
      position: relative;
      padding-left: 2rem;
    }
    .timeline-item::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0.5rem;
      width: 0.75rem;
      height: 0.75rem;
      border-radius: 50%;
      background: #10b981;
    }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <!-- Include admin sidebar -->
  <?php include 'sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- Header - STICKY -->
    <header class="sticky top-0 z-40 bg-white shadow-sm border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <!-- Left Section -->
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Traffic Management Progress</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Monitor all traffic management assignments</p>
          </div>
        </div>

        <!-- Right Section -->
        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
          <!-- Profile -->
          <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
              <i class="fa fa-user-shield text-lgu-button-text font-semibold text-sm sm:text-base"></i>
            </div>
            <div class="hidden md:block">
              <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Admin', 0, 15)); ?></p>
              <p class="text-xs text-lgu-paragraph">Administrator</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <!-- Stats Overview -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-lgu-stroke">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Tasks</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-stroke mt-2"><?= $total_tasks ?></p>
            </div>
            <div class="w-12 h-12 bg-lgu-bg rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-traffic-light text-lg sm:text-xl text-lgu-headline"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-gray-400">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Assigned</p>
              <p class="text-2xl sm:text-3xl font-bold text-gray-600 mt-2"><?= $assigned_count ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-clock text-lg sm:text-xl text-gray-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-lgu-button">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">In Progress</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-button-text mt-2"><?= $in_progress_count ?></p>
            </div>
            <div class="w-12 h-12 bg-amber-50 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-tools text-lg sm:text-xl text-lgu-button"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Completed</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?= $completed_count ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-red-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Overdue</p>
              <p class="text-2xl sm:text-3xl font-bold text-red-600 mt-2"><?= $overdue_count ?></p>
            </div>
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-exclamation-triangle text-lg sm:text-xl text-red-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Completion Rate Card -->
      <div class="bg-white rounded-lg shadow-sm p-6 mb-6 border border-gray-200">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-lg font-semibold text-lgu-headline">Overall Completion Rate</h3>
          <span class="text-2xl font-bold text-lgu-button-text"><?= $completion_rate ?>%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
          <div class="progress-bar bg-gradient-to-r from-lgu-button to-green-500 h-4 rounded-full" style="width: <?= $completion_rate ?>%"></div>
        </div>
        <p class="text-sm text-lgu-paragraph mt-2"><?= $completed_count ?> out of <?= $total_tasks ?> tasks completed</p>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-lg shadow-sm p-4 mb-6 border border-gray-200">
        <form method="GET" class="flex flex-col md:flex-row gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-lgu-headline mb-1">Search</label>
            <div class="relative">
              <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                     placeholder="Search by address, description, worker name, or ID..." 
                     class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-lgu-button focus:border-lgu-button">
              <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            </div>
          </div>
          
          <div class="w-full md:w-48">
            <label class="block text-sm font-medium text-lgu-headline mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-lgu-button focus:border-lgu-button">
              <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
              <option value="assigned" <?= $status_filter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
              <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
              <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
          </div>
          
          <div class="flex items-end gap-2">
            <button type="submit" class="bg-lgu-button hover:bg-amber-500 text-lgu-button-text font-semibold py-2 px-6 rounded-lg transition">
              <i class="fas fa-filter mr-2"></i>Filter
            </button>
            <a href="progress_traffic_maintenance.php" class="bg-gray-200 hover:bg-gray-300 text-lgu-button-text font-semibold py-2 px-4 rounded-lg transition">
              <i class="fas fa-redo"></i>
            </a>
          </div>
        </form>
      </div>

      <!-- Tasks List -->
      <div class="bg-white rounded-lg shadow-sm overflow-hidden border border-gray-200">
        <div class="bg-lgu-headline text-white px-4 py-3 sm:px-6 flex items-center justify-between">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-list-check mr-2"></i>
            Traffic Management Tasks
          </h2>
          <span class="bg-lgu-button text-lgu-button-text font-bold py-1 px-3 rounded-full text-sm">
            <?= count($assignments) ?> Tasks
          </span>
        </div>

        <div class="p-4 sm:p-6">
          <?php if (empty($assignments)): ?>
            <div class="text-center py-8">
              <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
              <h3 class="text-lg font-semibold text-lgu-paragraph">No traffic management tasks found</h3>
              <p class="text-gray-400 mt-2">
                <?php if (!empty($search) || $status_filter !== 'all'): ?>
                  Try adjusting your filters or search criteria.
                <?php else: ?>
                  Traffic management tasks will appear here once assigned.
                <?php endif; ?>
              </p>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($assignments as $assignment): 
                $is_overdue = strtotime($assignment['completion_deadline']) < time() && $assignment['assignment_status'] !== 'completed';
                
                // Determine status class
                if ($is_overdue) {
                  $status_class = 'status-overdue';
                } else {
                  $status_class = 'status-' . str_replace('_', '-', $assignment['assignment_status']);
                }
              ?>
                <div class="task-card bg-white border border-gray-200 rounded-lg shadow-sm p-4 <?= $status_class ?>">
                  <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <!-- Task Info -->
                    <div class="flex-1">
                      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-3">
                        <div>
                          <h3 class="text-lg font-semibold text-lgu-headline">
                            Task #<?= htmlspecialchars($assignment['assignment_id']) ?> 
                            - Report #<?= htmlspecialchars($assignment['report_id']) ?>
                          </h3>
                          <div class="flex flex-wrap items-center gap-2 mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $assignment['hazard_type']))) ?>
                            </span>
                            
                            <?php 
                            $ai_data = !empty($assignment['ai_analysis_result']) ? json_decode($assignment['ai_analysis_result'], true) : null;
                            if ($ai_data && isset($ai_data['hazardLevel'])): 
                              $hazard_level = strtolower($ai_data['hazardLevel']);
                              $level_colors = [
                                'low' => 'bg-green-100 text-green-800 border-green-300',
                                'medium' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                'high' => 'bg-red-100 text-red-800 border-red-300'
                              ];
                              $color_class = $level_colors[$hazard_level] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                            ?>
                              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $color_class ?>">
                                <?= htmlspecialchars($ai_data['hazardLevel']) ?>
                              </span>
                            <?php endif; ?>
                            
                            <!-- Status Badge -->
                            <?php if ($assignment['assignment_status'] === 'assigned'): ?>
                              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-300">
                                <span class="w-2 h-2 bg-gray-500 rounded-full mr-1"></span>
                                Not Started
                              </span>
                            <?php elseif ($assignment['assignment_status'] === 'in_progress'): ?>
                              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
                                <span class="w-2 h-2 bg-lgu-button rounded-full mr-1 animate-pulse"></span>
                                In Progress
                              </span>
                            <?php elseif ($assignment['assignment_status'] === 'completed'): ?>
                              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-300">
                                <span class="w-2 h-2 bg-green-500 rounded-full mr-1"></span>
                                Completed
                              </span>
                            <?php endif; ?>
                            
                            <?php if ($is_overdue): ?>
                              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-300">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                OVERDUE
                              </span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                      
                      <p class="text-lgu-paragraph mb-3"><?= htmlspecialchars($assignment['description']) ?></p>
                      
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-lgu-paragraph">
                        <div class="flex items-start">
                          <i class="fas fa-map-marker-alt mr-2 text-lgu-headline mt-0.5"></i>
                          <span><?= htmlspecialchars($assignment['address']) ?></span>
                        </div>
                        <?php if (!empty($assignment['landmark'])): ?>
                        <div class="flex items-start">
                          <i class="fas fa-landmark mr-2 text-lgu-headline mt-0.5"></i>
                          <span>Near: <?= htmlspecialchars($assignment['landmark']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex items-center">
                          <i class="fas fa-user-hard-hat mr-2 text-lgu-headline"></i>
                          <span>Worker: <?= htmlspecialchars($assignment['assigned_to_name']) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-calendar-alt mr-2 text-lgu-headline"></i>
                          <span>Deadline: <?= date('M j, Y g:i A', strtotime($assignment['completion_deadline'])) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-user-shield mr-2 text-lgu-headline"></i>
                          <span>Assigned by: <?= htmlspecialchars($assignment['assigned_by_name']) ?></span>
                        </div>
                      </div>
                      
                      <!-- Timeline Progress -->
                      <div class="mt-4 p-3 bg-lgu-bg rounded-lg border border-gray-200">
                        <h4 class="text-sm font-semibold text-lgu-headline mb-2">Progress Timeline</h4>
                        <div class="space-y-2">
                          <div class="timeline-item text-sm">
                            <span class="text-lgu-paragraph">Assigned: <?= date('M j, Y g:i A', strtotime($assignment['assigned_date'])) ?></span>
                          </div>
                          
                          <?php if ($assignment['started_at']): ?>
                            <div class="timeline-item text-sm" style="--tw-text-opacity: 1; color: rgb(250 174 43 / var(--tw-text-opacity));">
                              <span>Started: <?= date('M j, Y g:i A', strtotime($assignment['started_at'])) ?></span>
                            </div>
                          <?php else: ?>
                            <div class="timeline-item text-sm opacity-50">
                              <span class="text-gray-400">Not started yet</span>
                            </div>
                          <?php endif; ?>
                          
                          <?php if ($assignment['completed_at']): ?>
                            <div class="timeline-item text-sm" style="--tw-text-opacity: 1; color: rgb(16 185 129 / var(--tw-text-opacity));">
                              <span>Completed: <?= date('M j, Y g:i A', strtotime($assignment['completed_at'])) ?></span>
                            </div>
                          <?php else: ?>
                            <div class="timeline-item text-sm opacity-50">
                              <span class="text-gray-400">Not completed yet</span>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Images & Actions -->
                    <div class="flex flex-col items-center gap-3 lg:w-64">
                      <?php if ($assignment['image_path']): ?>
                        <div class="text-center">
                          <img src="../uploads/hazard_reports/<?= htmlspecialchars($assignment['image_path']) ?>" 
                               alt="Traffic Hazard" 
                               class="w-full max-w-xs rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition border border-gray-200"
                               onclick="openImageModal(this.src, 'Original Report')">
                          <p class="text-xs text-lgu-paragraph mt-1">Original Report</p>
                        </div>
                      <?php endif; ?>
                      
                      <?php if ($assignment['completion_image_path']): ?>
                        <div class="text-center">
                          <img src="../uploads/<?= htmlspecialchars($assignment['completion_image_path']) ?>" 
                               alt="Completion Photo" 
                               class="w-full max-w-xs rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition border-2 border-green-500"
                               onclick="openImageModal(this.src, 'Completion Photo')">
                          <p class="text-xs text-green-600 font-semibold mt-1">
                            <i class="fas fa-check-circle mr-1"></i>Completion Photo
                          </p>
                        </div>
                      <?php endif; ?>
                      
                      <div class="flex flex-col gap-2 w-full">
                        <button class="w-full bg-lgu-headline hover:bg-lgu-stroke text-white font-semibold py-2 px-4 rounded-lg transition view-details"
                                data-assignment='<?= htmlspecialchars(json_encode($assignment), ENT_QUOTES, 'UTF-8') ?>'>
                          <i class="fas fa-info-circle mr-2"></i>View Full Details
                        </button>
                        
                        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition view-map"
                                data-address="<?= htmlspecialchars($assignment['address']) ?>"
                                data-landmark="<?= htmlspecialchars($assignment['landmark'] ?? '') ?>">
                          <i class="fas fa-map mr-2"></i>View Map
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <footer class="bg-lgu-headline text-white py-6 sm:py-8 mt-8 sm:mt-12 flex-shrink-0">
      <div class="container mx-auto px-4 text-center">
        <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM- Road and Transportation Infrastructure Monitoring</p>
      </div>
    </footer>
  </div>

  <!-- Task Details Modal -->
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="detailsModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-3xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-lg rounded-lg border border-gray-200">
        <div class="bg-lgu-headline text-white px-6 py-4 rounded-t-lg">
          <h3 class="text-lg font-semibold flex items-center">
            <i class="fas fa-info-circle mr-2"></i> Complete Task Details
          </h3>
        </div>
        
        <div class="mt-4 space-y-4 max-h-[70vh] overflow-y-auto" id="task-details-content">
          <!-- Details will be populated by JavaScript -->
        </div>
        
        <div class="flex justify-end mt-6">
          <button type="button" class="px-4 py-2 text-sm font-medium text-white bg-lgu-headline hover:bg-lgu-stroke rounded-lg transition close-details">
            <i class="fas fa-times mr-1"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Image Modal -->
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="imageModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-black bg-opacity-90" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-4xl p-4 my-8 text-center align-middle transition-all transform">
        <button type="button" class="absolute top-4 right-4 text-white hover:text-gray-300 text-2xl z-10" onclick="closeImageModal()">
          <i class="fas fa-times"></i>
        </button>
        <div class="text-center">
          <h3 id="imageModalTitle" class="text-white text-lg font-semibold mb-3"></h3>
          <img id="modalImage" src="" alt="Image" class="max-w-full max-h-[80vh] rounded-lg shadow-lg mx-auto border-2 border-white">
        </div>
      </div>
    </div>
  </div>

  <!-- Map Modal -->
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="mapModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-5xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-lg rounded-lg border border-gray-200">
        <div class="bg-lgu-headline text-white px-6 py-4 rounded-t-lg flex items-center justify-between">
          <h3 class="text-lg font-semibold flex items-center">
            <i class="fas fa-map mr-2"></i> Traffic Location Map
          </h3>
          <button type="button" class="text-white hover:text-gray-300" onclick="closeMapModal()">
            <i class="fas fa-times text-xl"></i>
          </button>
        </div>
        
        <div class="mt-4">
          <div id="map-info" class="mb-4 p-3 bg-lgu-bg rounded-lg border border-gray-200">
            <div class="flex items-start">
              <i class="fas fa-map-marker-alt text-lgu-headline mt-1 mr-2"></i>
              <div>
                <p class="text-sm font-semibold text-lgu-headline">Address:</p>
                <p id="map-address" class="text-sm text-lgu-paragraph"></p>
              </div>
            </div>
            <div id="map-landmark-info" class="flex items-start mt-2" style="display: none;">
              <i class="fas fa-landmark text-lgu-headline mt-1 mr-2"></i>
              <div>
                <p class="text-sm font-semibold text-lgu-headline">Landmark:</p>
                <p id="map-landmark" class="text-sm text-lgu-paragraph"></p>
              </div>
            </div>
          </div>
          
          <div id="trafficMap" class="w-full h-96 rounded-lg border-2 border-gray-300"></div>
          <p class="text-xs text-lgu-paragraph mt-2">Red marker shows the traffic management location</p>
          
          <div class="flex justify-between mt-4">
            <a id="openInGoogleMaps" href="#" target="_blank" 
               class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition flex items-center">
              <i class="fab fa-google mr-2"></i>Open in Google Maps
            </a>
            <button type="button" class="px-4 py-2 text-sm font-medium text-white bg-lgu-headline hover:bg-lgu-stroke rounded-lg transition" onclick="closeMapModal()">
              <i class="fas fa-times mr-1"></i> Close
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Mobile sidebar toggle
  const sidebar = document.getElementById('admin-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  // Task details modal
  const detailsModal = document.getElementById('detailsModal');
  const viewDetailsButtons = document.querySelectorAll('.view-details');
  
  viewDetailsButtons.forEach(button => {
    button.addEventListener('click', function() {
      const assignmentData = JSON.parse(this.getAttribute('data-assignment'));
      populateDetailsModal(assignmentData);
      detailsModal.style.display = 'block';
    });
  });
  
  // Close details modal
  document.querySelector('.close-details').addEventListener('click', function() {
    detailsModal.style.display = 'none';
  });
  
  // Map modal functionality
  const mapModal = document.getElementById('mapModal');
  const viewMapButtons = document.querySelectorAll('.view-map');
  
  viewMapButtons.forEach(button => {
    button.addEventListener('click', function() {
      const address = this.getAttribute('data-address');
      const landmark = this.getAttribute('data-landmark');
      openMapModal(address, landmark);
    });
  });
  
  // Close modals when clicking outside
  window.addEventListener('click', function(event) {
    if (event.target === detailsModal) {
      detailsModal.style.display = 'none';
    }
    if (event.target === document.getElementById('imageModal')) {
      closeImageModal();
    }
    if (event.target === mapModal) {
      closeMapModal();
    }
  });
  
  // Function to populate details modal
  function populateDetailsModal(assignment) {
    const content = document.getElementById('task-details-content');
    
    // Format dates
    const deadline = new Date(assignment.completion_deadline).toLocaleString();
    const assignedDate = new Date(assignment.assigned_date).toLocaleString();
    const startedAt = assignment.started_at ? new Date(assignment.started_at).toLocaleString() : '<span class="text-gray-400">Not started</span>';
    const completedAt = assignment.completed_at ? new Date(assignment.completed_at).toLocaleString() : '<span class="text-gray-400">Not completed</span>';
    
    // Calculate time elapsed
    let timeElapsed = '';
    if (assignment.started_at && assignment.completed_at) {
      const start = new Date(assignment.started_at);
      const end = new Date(assignment.completed_at);
      const diffMs = end - start;
      const diffHrs = Math.floor((diffMs % 86400000) / 3600000);
      const diffMins = Math.round(((diffMs % 86400000) % 3600000) / 60000);
      const diffDays = Math.floor(diffMs / 86400000);
      
      if (diffDays > 0) {
        timeElapsed = `${diffDays} day${diffDays > 1 ? 's' : ''}, ${diffHrs} hour${diffHrs > 1 ? 's' : ''}`;
      } else if (diffHrs > 0) {
        timeElapsed = `${diffHrs} hour${diffHrs > 1 ? 's' : ''}, ${diffMins} minute${diffMins > 1 ? 's' : ''}`;
      } else {
        timeElapsed = `${diffMins} minute${diffMins > 1 ? 's' : ''}`;
      }
    }
    
    // Determine status info
    let statusBadge = '';
    let statusDescription = '';
    if (assignment.assignment_status === 'assigned') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800 border border-gray-300"><span class="w-2 h-2 bg-gray-500 rounded-full mr-2"></span>Not Started</span>';
      statusDescription = 'This traffic management task has been assigned but the worker has not started working on it yet.';
    } else if (assignment.assignment_status === 'in_progress') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-50 text-amber-800 border border-amber-200"><span class="w-2 h-2 bg-amber-500 rounded-full mr-2 animate-pulse"></span>In Progress</span>';
      statusDescription = 'The worker has started working on this traffic management task.';
    } else if (assignment.assignment_status === 'completed') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 border border-green-300"><span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>Completed</span>';
      statusDescription = 'This traffic management task has been successfully completed.';
    }
    
    // Check if overdue
    const isOverdue = new Date(assignment.completion_deadline) < new Date() && assignment.assignment_status !== 'completed';
    
    content.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <div class="bg-lgu-bg p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between mb-2">
              <h4 class="font-semibold text-lgu-headline">Task Status</h4>
              ${statusBadge}
            </div>
            <p class="text-sm text-lgu-paragraph">${statusDescription}</p>
            ${isOverdue ? '<p class="text-sm text-red-600 font-semibold mt-2"><i class="fas fa-exclamation-triangle mr-1"></i>This traffic management task is overdue!</p>' : ''}
          </div>
        </div>
        
        <div>
          <h4 class="font-semibold text-lgu-headline mb-2">Basic Information</h4>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Task ID:</span>
              <span class="font-medium text-lgu-headline">#${assignment.assignment_id}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Report ID:</span>
              <span class="font-medium text-lgu-headline">#${assignment.report_id}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Hazard Type:</span>
              <span class="font-medium text-lgu-headline">${assignment.hazard_type ? assignment.hazard_type.replace(/_/g, ' ').toUpperCase() : 'N/A'}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Report Status:</span>
              <span class="font-medium text-lgu-headline">${assignment.report_status ? assignment.report_status.toUpperCase() : 'N/A'}</span>
            </div>
          </div>
        </div>
        
        <div>
          <h4 class="font-semibold text-lgu-headline mb-2">Timeline</h4>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Assigned:</span>
              <span class="font-medium text-lgu-headline">${assignedDate}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Deadline:</span>
              <span class="font-medium ${isOverdue ? 'text-red-600' : 'text-lgu-headline'}">${deadline}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Started:</span>
              <span class="font-medium text-lgu-headline">${startedAt}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Completed:</span>
              <span class="font-medium text-lgu-headline">${completedAt}</span>
            </div>
            ${timeElapsed ? `
            <div class="flex justify-between py-1 border-b border-gray-200">
              <span class="text-lgu-paragraph">Time Taken:</span>
              <span class="font-medium text-green-600">${timeElapsed}</span>
            </div>
            ` : ''}
          </div>
        </div>
        
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2">Location</h4>
          <div class="space-y-2">
            <div class="flex items-start p-3 bg-lgu-bg rounded-lg border border-gray-200">
              <i class="fas fa-map-marker-alt text-lgu-headline mt-1 mr-2"></i>
              <span class="text-sm text-lgu-paragraph">${assignment.address || 'N/A'}</span>
            </div>
            ${assignment.landmark ? `
            <div class="flex items-start p-3 bg-lgu-bg rounded-lg border border-gray-200">
              <i class="fas fa-landmark text-lgu-headline mt-1 mr-2"></i>
              <span class="text-sm text-lgu-paragraph">Near: ${assignment.landmark}</span>
            </div>
            ` : ''}
          </div>
        </div>
        
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2">Description</h4>
          <div class="p-3 bg-lgu-bg rounded-lg border border-gray-200">
            <p class="text-sm text-lgu-paragraph">${assignment.description || 'No description provided'}</p>
          </div>
        </div>
        
        ${assignment.ai_analysis_result ? `
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2 flex items-center">
            <i class="fas fa-robot mr-2 text-lgu-button"></i>
            Beripikado AI Analysis
          </h4>
          <div class="p-4 bg-blue-50 rounded-lg border border-blue-200" id="ai-content-${assignment.assignment_id}"></div>
        </div>
        ` : ''}
        
        <div>
          <h4 class="font-semibold text-lgu-headline mb-2">Personnel</h4>
          <div class="space-y-2 text-sm">
            <div class="flex items-center p-2 bg-lgu-bg rounded border border-gray-200">
              <i class="fas fa-user-hard-hat text-lgu-headline mr-2"></i>
              <div>
                <p class="text-xs text-lgu-paragraph">Assigned To</p>
                <p class="font-medium text-lgu-headline">${assignment.assigned_to_name}</p>
                ${assignment.assigned_to_email ? `<p class="text-xs text-gray-500">${assignment.assigned_to_email}</p>` : ''}
              </div>
            </div>
            <div class="flex items-center p-2 bg-lgu-bg rounded border border-gray-200">
              <i class="fas fa-user-shield text-lgu-headline mr-2"></i>
              <div>
                <p class="text-xs text-lgu-paragraph">Assigned By</p>
                <p class="font-medium text-lgu-headline">${assignment.assigned_by_name}</p>
              </div>
            </div>
          </div>
        </div>
        
        <div>
          <h4 class="font-semibold text-lgu-headline mb-2">Contact Information</h4>
          <div class="space-y-2 text-sm">
            ${assignment.contact_number ? `
            <div class="flex items-center p-3 bg-lgu-bg rounded border border-gray-200">
              <i class="fas fa-phone text-lgu-headline mr-2"></i>
              <div>
                <p class="text-xs text-lgu-paragraph">Reporter Contact Number</p>
                <p class="font-medium text-lgu-headline">${assignment.contact_number}</p>
              </div>
            </div>
            ` : '<p class="text-sm text-gray-400">No contact number provided</p>'}
          </div>
        </div>
        
        ${assignment.assignment_notes ? `
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2">Assignment Notes</h4>
          <div class="p-3 bg-amber-50 rounded-lg border border-amber-200">
            <p class="text-sm text-lgu-paragraph">${assignment.assignment_notes}</p>
          </div>
        </div>
        ` : ''}
        
        ${assignment.forward_notes ? `
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2">Forward Notes</h4>
          <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
            <p class="text-sm text-lgu-paragraph">${assignment.forward_notes}</p>
          </div>
        </div>
        ` : ''}
        
        ${assignment.image_path || assignment.completion_image_path ? `
        <div class="md:col-span-2">
          <h4 class="font-semibold text-lgu-headline mb-2">Images</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            ${assignment.image_path ? `
            <div class="text-center">
              <img src="../uploads/hazard_reports/${assignment.image_path}" 
                   alt="Original Report" 
                   class="w-full h-48 object-cover rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition border border-gray-200"
                   onclick="openImageModal(this.src, 'Original Traffic Report Image')">
              <p class="text-xs text-lgu-paragraph mt-2">Original Traffic Report Image</p>
            </div>
            ` : ''}
            ${assignment.completion_image_path ? `
            <div class="text-center">
              <img src="../uploads/${assignment.completion_image_path}" 
                   alt="Completion Photo" 
                   class="w-full h-48 object-cover rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition border-2 border-green-500"
                   onclick="openImageModal(this.src, 'Traffic Completion Photo')">
              <p class="text-xs text-green-600 font-semibold mt-2">
                <i class="fas fa-check-circle mr-1"></i>Traffic Completion Photo
              </p>
            </div>
            ` : ''}
          </div>
          <div class="mt-4">
            <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition flex items-center justify-center"
                    onclick="openMapModal('${assignment.address || ''}', '${assignment.landmark || ''}')">
              <i class="fas fa-map mr-2"></i>View Traffic Location on Map
            </button>
          </div>
        </div>
        ` : ''}
      </div>
    `;
  }
  
  function renderAIAnalysis(assignmentId, aiResultStr) {
    const container = document.getElementById(`ai-content-${assignmentId}`);
    if (!container) return;
    
    try {
      const aiData = JSON.parse(aiResultStr);
      let html = '';
      
      if (aiData.topPrediction) {
        html += `
          <div class="mb-4 p-3 bg-blue-100 rounded-lg border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
              <span class="font-semibold text-blue-800">${aiData.topPrediction.className}</span>
              <span class="text-sm font-bold text-blue-700">${(aiData.topPrediction.probability * 100).toFixed(1)}%</span>
            </div>
            <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
          </div>
        `;
      }
      
      if (aiData.hazardLevel) {
        const hazardLevel = aiData.hazardLevel.toLowerCase();
        const levelColors = {
          'low': 'bg-green-100 border-green-500 text-green-800',
          'medium': 'bg-yellow-100 border-yellow-500 text-yellow-800',
          'high': 'bg-red-100 border-red-500 text-red-800'
        };
        const colorClass = levelColors[hazardLevel] || 'bg-gray-100 border-gray-500 text-gray-800';
        html += `
          <div class="mb-4 p-3 rounded-lg border-l-4 ${colorClass}">
            <div class="flex items-center justify-between">
              <span class="font-semibold">Hazard Level:</span>
              <span class="font-bold uppercase">${aiData.hazardLevel}</span>
            </div>
          </div>
        `;
      }
      
      if (aiData.topPrediction) {
        const confidence = aiData.topPrediction.probability;
        if (confidence > 0.6) {
          html += `
            <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
              <div class="flex items-center text-green-800 text-sm">
                <i class="fas fa-check-circle mr-2"></i>
                <span class="font-medium">AI Recommendation: Image appears to show ${aiData.topPrediction.className}</span>
              </div>
            </div>
          `;
        } else {
          html += `
            <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
              <div class="flex items-center text-yellow-800 text-sm">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span class="font-medium">AI Recommendation: Hazard classification requires manual verification</span>
              </div>
            </div>
          `;
        }
      }
      
      container.innerHTML = html || '<p class="text-sm text-blue-700">AI analysis data available</p>';
    } catch (e) {
      container.innerHTML = `<p class="text-sm text-blue-700">${aiResultStr.substring(0, 100)}...</p>`;
    }
  }
  
  // Render AI analysis if present
  if (assignment.ai_analysis_result) {
    setTimeout(() => {
      renderAIAnalysis(assignment.assignment_id, assignment.ai_analysis_result);
    }, 50);
  }
});

// Image modal functions
function openImageModal(src, title) {
  document.getElementById('modalImage').src = src;
  document.getElementById('imageModalTitle').textContent = title || 'Image';
  document.getElementById('imageModal').style.display = 'block';
}

function closeImageModal() {
  document.getElementById('imageModal').style.display = 'none';
}

// Map modal functions
let trafficMap = null;
const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';

function openMapModal(address, landmark) {
  const mapModal = document.getElementById('mapModal');
  const mapAddress = document.getElementById('map-address');
  const mapLandmark = document.getElementById('map-landmark');
  const mapLandmarkInfo = document.getElementById('map-landmark-info');
  const openInGoogleMaps = document.getElementById('openInGoogleMaps');
  
  // Set address
  mapAddress.textContent = address || 'No address provided';
  
  // Set landmark if available
  if (landmark && landmark.trim()) {
    mapLandmark.textContent = landmark;
    mapLandmarkInfo.style.display = 'flex';
  } else {
    mapLandmarkInfo.style.display = 'none';
  }
  
  // Create search query (address + landmark if available)
  let searchQuery = address;
  if (landmark && landmark.trim()) {
    searchQuery += ', ' + landmark;
  }
  
  // Set Google Maps link
  const encodedQuery = encodeURIComponent(searchQuery);
  openInGoogleMaps.href = `https://maps.google.com/?q=${encodedQuery}`;
  
  // Show modal
  mapModal.style.display = 'block';
  
  // Initialize map after modal is shown
  setTimeout(() => {
    initTrafficMap(address, landmark);
  }, 100);
}

function closeMapModal() {
  const mapModal = document.getElementById('mapModal');
  
  // Destroy existing map
  if (trafficMap) {
    trafficMap.remove();
    trafficMap = null;
  }
  
  // Hide modal
  mapModal.style.display = 'none';
}

function initTrafficMap(address, landmark) {
  // Initialize map centered on Philippines
  trafficMap = L.map('trafficMap').setView([14.5995, 120.9842], 12);
  
  // Add TomTom tile layer
  L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
    attribution: '© TomTom, © OpenStreetMap contributors'
  }).addTo(trafficMap);
  
  // Geocode the address and add marker
  if (address) {
    geocodeTrafficLocation(address, landmark);
  }
}

async function geocodeTrafficLocation(address, landmark) {
  try {
    let searchAddress = address;
    if (landmark && landmark.trim()) {
      searchAddress += ', ' + landmark;
    }
    
    const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(searchAddress)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
    const data = await response.json();
    
    if (data.results && data.results.length > 0) {
      const result = data.results[0];
      const lat = result.position.lat;
      const lng = result.position.lon;
      
      // Add traffic management marker
      const marker = L.marker([lat, lng], {
        icon: L.divIcon({
          className: 'custom-marker',
          html: '<div style="background: #ef4444; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-center: center;"><i class="fas fa-traffic-light text-white text-sm"></i></div>',
          iconSize: [30, 30],
          iconAnchor: [15, 15]
        })
      }).addTo(trafficMap);
      
      // Create popup content
      const popupContent = `
        <div class="p-3 min-w-[200px]">
          <h4 class="font-bold text-red-600 mb-2">Traffic Management Location</h4>
          <p class="text-sm mb-2"><strong>Address:</strong> ${result.address.freeformAddress}</p>
          ${landmark ? `<p class="text-sm mb-2"><strong>Landmark:</strong> ${landmark}</p>` : ''}
        </div>
      `;
      
      marker.bindPopup(popupContent);
      
      // Center map on location
      trafficMap.setView([lat, lng], 16);
    } else {
      console.log('No geocoding results found for address:', searchAddress);
    }
  } catch (error) {
    console.error('Geocoding error:', error);
  }
}
</script>
</body>
</html>