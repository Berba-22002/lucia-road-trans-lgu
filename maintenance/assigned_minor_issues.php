<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow maintenance users
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'maintenance') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch assigned tasks for this maintenance user
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
        r.hazard_type,
        r.description,
        r.address,
        r.image_path,
        r.contact_number,
        u_assigned_by.fullname as assigned_by_name,
        rf.notes as forward_notes
    FROM maintenance_assignments ma
    INNER JOIN reports r ON ma.report_id = r.id
    INNER JOIN users u_assigned_by ON ma.assigned_by = u_assigned_by.id
    LEFT JOIN report_forwards rf ON ma.forward_id = rf.id
    WHERE ma.assigned_to = ?
    ORDER BY 
        CASE 
            WHEN ma.status = 'assigned' THEN 1
            WHEN ma.status = 'in_progress' THEN 2
            WHEN ma.status = 'completed' THEN 3
            ELSE 4
        END,
        ma.completion_deadline ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>My Assigned Tasks - RTIM</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <!-- Poppins Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
    .task-card { transition: transform .15s ease; }
    .task-card:hover { transform: translateY(-4px); }
    
    /* Status badges */
    .status-badge-assigned { background-color: #6b7280; color: white; }
    .status-badge-in_progress { background-color: #f59e0b; color: white; }
    .status-badge-completed { background-color: #10b981; color: white; }
    
    /* Priority indicators */
    .urgent-task { border-left: 4px solid #ef4444 !important; }
    .warning-task { border-left: 4px solid #f59e0b !important; }
    .normal-task { border-left: 4px solid #3b82f6 !important; }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <!-- Include sidebar -->
  <?php include 'sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- Header - STICKY -->
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <!-- Left Section -->
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">My Assigned Tasks</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage your assigned maintenance tasks</p>
          </div>
        </div>

        <!-- Right Section -->
        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
          <!-- Profile -->
          <div class="flex items-center gap-2 sm:gap-3 pl-2 sm:pl-4 border-l border-gray-300 flex-shrink-0">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-lgu-highlight rounded-full flex items-center justify-center shadow flex-shrink-0">
              <i class="fa fa-tools text-lgu-button-text font-semibold text-sm sm:text-base"></i>
            </div>
            <div class="hidden md:block">
              <p class="text-xs sm:text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'Maintenance', 0, 15)); ?></p>
              <p class="text-xs text-lgu-paragraph">Maintenance</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 p-3 sm:p-4 lg:p-6 overflow-y-auto">
      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
          <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
          </div>
          <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove();">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <?php unset($_SESSION['success']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
          <div class="flex items-center">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
          </div>
          <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove();">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <!-- Stats Summary -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-gray-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Assigned</p>
              <p class="text-2xl sm:text-3xl font-bold text-gray-600 mt-2"><?= count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'assigned')) ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-clock text-lg sm:text-xl text-gray-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">In Progress</p>
              <p class="text-2xl sm:text-3xl font-bold text-yellow-600 mt-2"><?= count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'in_progress')) ?></p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-spinner text-lg sm:text-xl text-yellow-500"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Completed</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?= count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'completed')) ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Task List -->
      <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6 flex items-center justify-between">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-tasks mr-2"></i>
            My Tasks
          </h2>
          <span class="bg-lgu-button text-lgu-button-text font-bold py-1 px-3 rounded-full text-sm">
            Total: <?= count($assignments) ?>
          </span>
        </div>

        <div class="p-4 sm:p-6">
          <?php if (empty($assignments)): ?>
            <div class="text-center py-8">
              <i class="fas fa-info-circle fa-3x text-gray-300 mb-3"></i>
              <h3 class="text-lg font-semibold text-gray-500">No tasks assigned to you yet.</h3>
              <p class="text-gray-400 mt-2">You will see assigned maintenance tasks here once they are assigned to you by an administrator.</p>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($assignments as $assignment): 
                $is_urgent = strtotime($assignment['completion_deadline']) < strtotime('+1 day');
                $is_warning = strtotime($assignment['completion_deadline']) < strtotime('+3 days');
                
                // Determine priority class
                if ($assignment['assignment_status'] === 'completed') {
                  $priority_class = 'normal-task';
                } else if ($is_urgent) {
                  $priority_class = 'urgent-task';
                } else if ($is_warning) {
                  $priority_class = 'warning-task';
                } else {
                  $priority_class = 'normal-task';
                }
              ?>
                <div class="task-card bg-white border border-gray-200 rounded-lg shadow-sm p-4 <?= $priority_class ?>">
                  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <!-- Task Details -->
                    <div class="flex-1">
                      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-2">
                        <div>
                          <h3 class="text-lg font-semibold text-lgu-headline">
                            Task #<?= htmlspecialchars($assignment['assignment_id']) ?> 
                            - Report #<?= htmlspecialchars($assignment['report_id']) ?>
                          </h3>
                          <div class="flex flex-wrap items-center gap-2 mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $assignment['team_type']))) ?>
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $assignment['hazard_type']))) ?>
                            </span>
                          </div>
                        </div>
                        
                        <div class="mt-2 sm:mt-0">
                          <?php if ($assignment['assignment_status'] === 'assigned'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                              <span class="w-2 h-2 bg-gray-500 rounded-full mr-2"></span>
                              Assigned
                            </span>
                          <?php elseif ($assignment['assignment_status'] === 'in_progress'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                              <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                              In Progress
                            </span>
                          <?php elseif ($assignment['assignment_status'] === 'completed'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                              <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                              Completed
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>
                      
                      <p class="text-gray-600 mb-3"><?= htmlspecialchars($assignment['description']) ?></p>
                      
                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm text-gray-500">
                        <div class="flex items-center">
                          <i class="fas fa-map-marker-alt mr-2 text-gray-400"></i>
                          <span class="truncate"><?= htmlspecialchars(substr($assignment['address'], 0, 50)) ?>...</span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-user mr-2 text-gray-400"></i>
                          <span>Assigned by: <?= htmlspecialchars($assignment['assigned_by_name']) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-calendar-alt mr-2 text-gray-400"></i>
                          <span>Deadline: <?= date('M j, Y g:i A', strtotime($assignment['completion_deadline'])) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-clock mr-2 text-gray-400"></i>
                          <span>Assigned: <?= date('M j, Y', strtotime($assignment['assigned_date'])) ?></span>
                        </div>
                      </div>
                      
                      <!-- Priority indicators -->
                      <?php if ($is_urgent && $assignment['assignment_status'] !== 'completed'): ?>
                        <div class="mt-3">
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            URGENT - Due soon!
                          </span>
                        </div>
                      <?php elseif ($is_warning && $assignment['assignment_status'] !== 'completed'): ?>
                        <div class="mt-3">
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                            <i class="fas fa-clock mr-1"></i>
                            Due in less than 3 days
                          </span>
                        </div>
                      <?php endif; ?>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row md:flex-col gap-2 justify-center items-start">
                      <?php if ($assignment['assignment_status'] === 'completed'): ?>
                        <div class="text-center w-full py-2 px-4">
                          <span class="text-green-600 font-semibold flex items-center justify-center">
                            <i class="fas fa-check-double mr-2"></i> Completed
                          </span>
                          <?php if ($assignment['completed_at']): ?>
                            <p class="text-xs text-gray-500 mt-1">
                              <?= date('M j, Y', strtotime($assignment['completed_at'])) ?>
                            </p>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      
                      <!-- View Details Button -->
                      <button class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition view-details-btn"
                              data-assignment='<?= htmlspecialchars(json_encode($assignment), ENT_QUOTES, 'UTF-8') ?>'>
                        <i class="fas fa-eye mr-2"></i> Details
                      </button>
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
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-2xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
        <div class="bg-lgu-headline text-white px-6 py-4 rounded-t-lg">
          <h3 class="text-lg font-semibold flex items-center">
            <i class="fas fa-info-circle mr-2"></i> Task Details
          </h3>
        </div>
        
        <div class="mt-4 space-y-4" id="task-details-content">
          <!-- Details will be populated by JavaScript -->
        </div>
        
        <div class="flex justify-end mt-6">
          <button type="button" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md transition close-details">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Mobile sidebar toggle
  const sidebar = document.getElementById('maintenance-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }


  
  // Task details modal
  const detailsModal = document.getElementById('detailsModal');
  const viewDetailsButtons = document.querySelectorAll('.view-details-btn');
  
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
  
  // Close modals when clicking outside
  window.addEventListener('click', function(event) {
    if (event.target === detailsModal) {
      detailsModal.style.display = 'none';
    }
  });
  
  // Function to populate details modal
  function populateDetailsModal(assignment) {
    const content = document.getElementById('task-details-content');
    
    // Format dates
    const deadline = new Date(assignment.completion_deadline).toLocaleString();
    const assignedDate = new Date(assignment.assigned_date).toLocaleDateString();
    const startedAt = assignment.started_at ? new Date(assignment.started_at).toLocaleString() : 'Not started';
    const completedAt = assignment.completed_at ? new Date(assignment.completed_at).toLocaleString() : 'Not completed';
    
    // Determine status badge
    let statusBadge = '';
    if (assignment.assignment_status === 'assigned') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">Assigned</span>';
    } else if (assignment.assignment_status === 'in_progress') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">In Progress</span>';
    } else if (assignment.assignment_status === 'completed') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Completed</span>';
    }
    
    content.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <h4 class="font-semibold text-gray-700">Task Information</h4>
          <div class="mt-2 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500">Task ID:</span>
              <span class="font-medium">#${assignment.assignment_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Report ID:</span>
              <span class="font-medium">#${assignment.report_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Team Type:</span>
              <span class="font-medium">${assignment.team_type ? assignment.team_type.replace(/_/g, ' ') : 'N/A'}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Hazard Type:</span>
              <span class="font-medium">${assignment.hazard_type ? assignment.hazard_type.replace(/_/g, ' ') : 'N/A'}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Status:</span>
              <span>${statusBadge}</span>
            </div>
          </div>
        </div>
        
        <div>
          <h4 class="font-semibold text-gray-700">Timeline</h4>
          <div class="mt-2 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500">Assigned:</span>
              <span class="font-medium">${assignedDate}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Deadline:</span>
              <span class="font-medium">${deadline}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Started:</span>
              <span class="font-medium">${startedAt}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Completed:</span>
              <span class="font-medium">${completedAt}</span>
            </div>
          </div>
        </div>
      </div>
      
      <div>
        <h4 class="font-semibold text-gray-700">Location</h4>
        <p class="mt-1 text-sm">${assignment.address || 'N/A'}</p>
      </div>
      
      <div>
        <h4 class="font-semibold text-gray-700">Description</h4>
        <p class="mt-1 text-sm">${assignment.description || 'No description provided'}</p>
      </div>
      
      ${assignment.assignment_notes ? `
      <div>
        <h4 class="font-semibold text-gray-700">Assignment Notes</h4>
        <p class="mt-1 text-sm">${assignment.assignment_notes}</p>
      </div>
      ` : ''}
      
      ${assignment.forward_notes ? `
      <div>
        <h4 class="font-semibold text-gray-700">Forward Notes</h4>
        <p class="mt-1 text-sm">${assignment.forward_notes}</p>
      </div>
      ` : ''}
      
      <div>
        <h4 class="font-semibold text-gray-700">Assigned By</h4>
        <p class="mt-1 text-sm">${assignment.assigned_by_name || 'N/A'}</p>
      </div>
    `;
  }
});
</script>
</body>
</html>