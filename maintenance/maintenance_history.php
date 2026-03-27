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

// Fetch completed maintenance assignments for this user
$query = "
    SELECT 
        ma.id as assignment_id,
        ma.report_id,
        ma.completion_deadline,
        ma.notes as assignment_notes,
        ma.started_at,
        ma.completed_at,
        ma.created_at as assigned_date,
        ma.team_type,
        r.hazard_type,
        r.description,
        r.address,
        r.image_path,
        u_assigned_by.fullname as assigned_by_name,
        rf.notes as forward_notes
    FROM maintenance_assignments ma
    INNER JOIN reports r ON ma.report_id = r.id
    INNER JOIN users u_assigned_by ON ma.assigned_by = u_assigned_by.id
    LEFT JOIN report_forwards rf ON ma.forward_id = rf.id
    WHERE ma.assigned_to = ? 
      AND ma.status = 'completed'
    ORDER BY ma.completed_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$completed_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$total_completed = count($completed_tasks);
$this_month = count(array_filter($completed_tasks, fn($task) => date('Y-m', strtotime($task['completed_at'])) === date('Y-m')));
$this_week = count(array_filter($completed_tasks, fn($task) => date('Y-W', strtotime($task['completed_at'])) === date('Y-W')));

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Maintenance History - RTIM</title>

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
    .history-card { transition: transform .15s ease; }
    .history-card:hover { transform: translateY(-2px); }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <!-- Include sidebar -->
  <?php include 'sidebar.php'; ?>

  <div class="lg:ml-64 flex flex-col min-h-screen">
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 gap-4">
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
            <i class="fa fa-bars text-xl"></i>
          </button>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Maintenance History</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">View your completed maintenance tasks</p>
          </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
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
      <!-- Statistics -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Completed</p>
              <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-2"><?= $total_completed ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-check-circle text-lg sm:text-xl text-green-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">This Month</p>
              <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-2"><?= $this_month ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-calendar-alt text-lg sm:text-xl text-blue-500"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">This Week</p>
              <p class="text-2xl sm:text-3xl font-bold text-purple-600 mt-2"><?= $this_week ?></p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-clock text-lg sm:text-xl text-purple-500"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- History List -->
      <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-4 py-3 sm:px-6 flex items-center justify-between">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-history mr-2"></i>
            Completed Tasks
          </h2>
          <span class="bg-lgu-button text-lgu-button-text font-bold py-1 px-3 rounded-full text-sm">
            <?= $total_completed ?> Tasks
          </span>
        </div>

        <div class="p-4 sm:p-6">
          <?php if (empty($completed_tasks)): ?>
            <div class="text-center py-8">
              <i class="fas fa-history fa-3x text-gray-300 mb-3"></i>
              <h3 class="text-lg font-semibold text-gray-500">No completed tasks yet.</h3>
              <p class="text-gray-400 mt-2">Your completed maintenance tasks will appear here.</p>
            </div>
          <?php else: ?>
            <div class="space-y-4">
              <?php foreach ($completed_tasks as $task): ?>
                <div class="history-card bg-white border border-gray-200 rounded-lg shadow-sm p-4 border-l-4 border-green-500">
                  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div class="flex-1">
                      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-2">
                        <div>
                          <h3 class="text-lg font-semibold text-lgu-headline">
                            Task #<?= htmlspecialchars($task['assignment_id']) ?> 
                            - Report #<?= htmlspecialchars($task['report_id']) ?>
                          </h3>
                          <div class="flex flex-wrap items-center gap-2 mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $task['team_type']))) ?>
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $task['hazard_type']))) ?>
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                              <i class="fas fa-check mr-1"></i>
                              Completed
                            </span>
                          </div>
                        </div>
                      </div>
                      
                      <p class="text-gray-600 mb-3"><?= htmlspecialchars($task['description']) ?></p>
                      
                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm text-gray-500">
                        <div class="flex items-center">
                          <i class="fas fa-map-marker-alt mr-2 text-gray-400"></i>
                          <span class="truncate"><?= htmlspecialchars(substr($task['address'], 0, 50)) ?>...</span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-user mr-2 text-gray-400"></i>
                          <span>Assigned by: <?= htmlspecialchars($task['assigned_by_name']) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-calendar-check mr-2 text-gray-400"></i>
                          <span>Completed: <?= date('M j, Y g:i A', strtotime($task['completed_at'])) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-clock mr-2 text-gray-400"></i>
                          <span>Duration: 
                            <?php 
                            if ($task['started_at']) {
                              $start = new DateTime($task['started_at']);
                              $end = new DateTime($task['completed_at']);
                              $diff = $start->diff($end);
                              echo $diff->days . 'd ' . $diff->h . 'h ' . $diff->i . 'm';
                            } else {
                              echo 'N/A';
                            }
                            ?>
                          </span>
                        </div>
                      </div>
                    </div>
                    
                    <div class="flex flex-col gap-2 justify-center items-start">
                      <button class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition view-details-btn"
                              data-task='<?= htmlspecialchars(json_encode($task), ENT_QUOTES, 'UTF-8') ?>'>
                        <i class="fas fa-eye mr-2"></i> View Details
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
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="detailsModal" tabindex="-1" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"></div>
      
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
      const taskData = JSON.parse(this.getAttribute('data-task'));
      populateDetailsModal(taskData);
      detailsModal.style.display = 'block';
    });
  });
  
  // Close details modal
  document.querySelector('.close-details').addEventListener('click', function() {
    detailsModal.style.display = 'none';
  });
  
  // Close modal when clicking outside
  window.addEventListener('click', function(event) {
    if (event.target === detailsModal) {
      detailsModal.style.display = 'none';
    }
  });
  
  // Function to populate details modal
  function populateDetailsModal(task) {
    const content = document.getElementById('task-details-content');
    
    const assignedDate = new Date(task.assigned_date).toLocaleDateString();
    const completedAt = new Date(task.completed_at).toLocaleString();
    const startedAt = task.started_at ? new Date(task.started_at).toLocaleString() : 'Not recorded';
    const deadline = new Date(task.completion_deadline).toLocaleString();
    
    content.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <h4 class="font-semibold text-gray-700">Task Information</h4>
          <div class="mt-2 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500">Task ID:</span>
              <span class="font-medium">#${task.assignment_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Report ID:</span>
              <span class="font-medium">#${task.report_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Team Type:</span>
              <span class="font-medium">${task.team_type ? task.team_type.replace(/_/g, ' ') : 'N/A'}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">Hazard Type:</span>
              <span class="font-medium">${task.hazard_type ? task.hazard_type.replace(/_/g, ' ') : 'N/A'}</span>
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
              <span class="font-medium text-green-600">${completedAt}</span>
            </div>
          </div>
        </div>
      </div>
      
      <div>
        <h4 class="font-semibold text-gray-700">Location</h4>
        <p class="mt-1 text-sm">${task.address || 'N/A'}</p>
      </div>
      
      <div>
        <h4 class="font-semibold text-gray-700">Description</h4>
        <p class="mt-1 text-sm">${task.description || 'No description provided'}</p>
      </div>
      
      ${task.assignment_notes ? `
      <div>
        <h4 class="font-semibold text-gray-700">Assignment Notes</h4>
        <p class="mt-1 text-sm">${task.assignment_notes}</p>
      </div>
      ` : ''}
      
      ${task.forward_notes ? `
      <div>
        <h4 class="font-semibold text-gray-700">Forward Notes</h4>
        <p class="mt-1 text-sm">${task.forward_notes}</p>
      </div>
      ` : ''}
      
      <div>
        <h4 class="font-semibold text-gray-700">Assigned By</h4>
        <p class="mt-1 text-sm">${task.assigned_by_name || 'N/A'}</p>
      </div>
    `;
  }
});
</script>
</body>
</html>