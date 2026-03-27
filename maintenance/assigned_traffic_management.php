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

// Fetch assigned TRAFFIC maintenance tasks for this user
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
        r.landmark,
        r.image_path,
        r.contact_number,
        u_assigned_by.fullname as assigned_by_name,
        rf.notes as forward_notes
    FROM maintenance_assignments ma
    INNER JOIN reports r ON ma.report_id = r.id
    INNER JOIN users u_assigned_by ON ma.assigned_by = u_assigned_by.id
    LEFT JOIN report_forwards rf ON ma.forward_id = rf.id
    WHERE ma.assigned_to = ? AND ma.team_type = 'traffic_management'
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

// Handle status updates for TRAFFIC maintenance
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['start_work'])) {
        $assignment_id = $_POST['assignment_id'];
        
        try {
            $update_query = "
                UPDATE maintenance_assignments 
                SET status = 'in_progress', started_at = NOW() 
                WHERE id = ? AND assigned_to = ? AND team_type = 'traffic_management'
            ";
            $stmt = $pdo->prepare($update_query);
            $stmt->execute([$assignment_id, $user_id]);
            
            $_SESSION['success'] = "Traffic management work started successfully!";
            header('Location: assigned_traffic_management.php');
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error starting work: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['complete_work'])) {
        $assignment_id = $_POST['assignment_id'];
        $completion_notes = $_POST['completion_notes'];
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Handle image upload
            $completion_image_path = null;
            if (isset($_FILES['completion_image']) && $_FILES['completion_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/completion/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['completion_image']['name'], PATHINFO_EXTENSION);
                $filename = 'completion_traffic_' . $assignment_id . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $filename;
                
                // Validate file type
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array(strtolower($file_extension), $allowed_types)) {
                    if (move_uploaded_file($_FILES['completion_image']['tmp_name'], $target_path)) {
                        $completion_image_path = 'completion/' . $filename;
                    } else {
                        throw new Exception("Failed to upload completion image.");
                    }
                } else {
                    throw new Exception("Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.");
                }
            }
            
            // Update assignment status with completion image
            $update_assignment = "
                UPDATE maintenance_assignments 
                SET status = 'completed', 
                    completed_at = NOW(), 
                    notes = CONCAT(COALESCE(notes, ''), '\nTraffic Work Completion: ', ?),
                    completion_image_path = ?
                WHERE id = ? AND assigned_to = ? AND team_type = 'traffic_management'
            ";
            $stmt = $pdo->prepare($update_assignment);
            $stmt->execute([$completion_notes, $completion_image_path, $assignment_id, $user_id]);
            
            // Get report_id from assignment
            $report_query = "SELECT report_id FROM maintenance_assignments WHERE id = ?";
            $stmt = $pdo->prepare($report_query);
            $stmt->execute([$assignment_id]);
            $assignment_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($assignment_data) {
                // Update report status to done
                $update_report = "UPDATE reports SET status = 'done' WHERE id = ?";
                $stmt = $pdo->prepare($update_report);
                $stmt->execute([$assignment_data['report_id']]);
                
                // Update report forward status to completed
                $update_forward = "
                    UPDATE report_forwards 
                    SET status = 'completed' 
                    WHERE id = (SELECT forward_id FROM maintenance_assignments WHERE id = ?)
                ";
                $stmt = $pdo->prepare($update_forward);
                $stmt->execute([$assignment_id]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Traffic management work completed successfully!" . ($completion_image_path ? " Completion image uploaded." : "");
            header('Location: assigned_traffic_management.php');
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error completing work: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Traffic Management Tasks - RTIM</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <!-- Poppins Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Leaflet -->
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
    * { font-family: 'Poppins', sans-serif; }
    html, body { width: 100%; height: 100%; overflow-x: hidden; }
    .sidebar-link { color:#9CA3AF; }
    .sidebar-link:hover { color:#FFF; background:#00332c; }
    .sidebar-link.active { color:#faae2b; background:#00332c; border-left:3px solid #faae2b; }
    .task-card { transition: transform .15s ease; }
    .task-card:hover { transform: translateY(-2px); }
    
    /* Priority indicators using LGU colors */
    .urgent-task { border-left: 4px solid #ef4444 !important; }
    .warning-task { border-left: 4px solid #faae2b !important; }
    .normal-task { border-left: 4px solid #00473e !important; }
    
    /* Image preview styles */
    .image-preview-container {
      display: none;
      margin-top: 1rem;
    }
    .image-preview {
      max-width: 100%;
      max-height: 200px;
      border-radius: 8px;
      border: 2px solid #e5e7eb;
    }
    .camera-btn {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .upload-btn {
      background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }
    
    /* Map styles */
    .leaflet-container {
      height: 100%;
      width: 100%;
      border-radius: 0.5rem;
      z-index: 1 !important;
    }
    
    [id^="taskMap_"] {
      height: 200px !important;
      min-height: 200px;
      width: 100%;
    }
    
    .custom-hazard-marker {
      background: transparent !important;
      border: none !important;
    }
  </style>
</head>
<body class="bg-lgu-bg min-h-screen font-poppins">

  <!-- Include sidebar -->
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
            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Traffic Management Tasks</h1>
            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Manage your assigned traffic management tasks</p>
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
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative" role="alert">
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
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative" role="alert">
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
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="stat-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-gray-400">
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

        <div class="stat-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-lgu-button">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">In Progress</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-button-text mt-2"><?= count(array_filter($assignments, fn($a) => $a['assignment_status'] === 'in_progress')) ?></p>
            </div>
            <div class="w-12 h-12 bg-amber-50 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-traffic-light text-lg sm:text-xl text-lgu-button"></i>
            </div>
          </div>
        </div>

        <div class="stat-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-green-500">
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

        <div class="stat-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-lgu-stroke">
          <div class="flex justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="text-xs font-semibold text-lgu-paragraph uppercase truncate">Total Tasks</p>
              <p class="text-2xl sm:text-3xl font-bold text-lgu-stroke mt-2"><?= count($assignments) ?></p>
            </div>
            <div class="w-12 h-12 bg-lgu-bg rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa fa-traffic-light text-lg sm:text-xl text-lgu-headline"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Traffic Management Tasks List -->
      <div class="bg-white rounded-lg shadow-sm overflow-hidden border border-gray-200">
        <div class="bg-lgu-headline text-white px-4 py-3 sm:px-6 flex items-center justify-between">
          <h2 class="text-lg sm:text-xl font-bold flex items-center">
            <i class="fas fa-traffic-light mr-2"></i>
            Traffic Management Tasks
          </h2>
          <span class="bg-lgu-button text-lgu-button-text font-bold py-1 px-3 rounded-full text-sm">
            Total: <?= count($assignments) ?>
          </span>
        </div>

        <div class="p-4 sm:p-6">
          <?php if (empty($assignments)): ?>
            <div class="text-center py-8">
              <i class="fas fa-traffic-light fa-3x text-gray-300 mb-3"></i>
              <h3 class="text-lg font-semibold text-lgu-paragraph">No traffic management tasks assigned to you yet.</h3>
              <p class="text-gray-400 mt-2">You will see assigned traffic management tasks here once they are assigned to you by an administrator.</p>
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
                  <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <!-- Task Details -->
                    <div class="flex-1">
                      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-3">
                        <div>
                          <h3 class="text-lg font-semibold text-lgu-headline">
                            Task #<?= htmlspecialchars($assignment['assignment_id']) ?> 
                            - Report #<?= htmlspecialchars($assignment['report_id']) ?>
                          </h3>
                          <div class="flex flex-wrap items-center gap-2 mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-lgu-bg text-lgu-headline border border-lgu-stroke">
                              <i class="fas fa-traffic-light mr-1"></i>
                              Traffic Management
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                              <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $assignment['hazard_type']))) ?>
                            </span>
                          </div>
                        </div>
                        
                        <div class="mt-2 sm:mt-0 flex items-center gap-2">
                          <?php if ($assignment['assignment_status'] === 'assigned'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800 border border-gray-300">
                              <span class="w-2 h-2 bg-gray-500 rounded-full mr-2"></span>
                              Assigned
                            </span>
                          <?php elseif ($assignment['assignment_status'] === 'in_progress'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-50 text-amber-800 border border-amber-200">
                              <span class="w-2 h-2 bg-lgu-button rounded-full mr-2"></span>
                              In Progress
                            </span>
                          <?php elseif ($assignment['assignment_status'] === 'completed'): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 border border-green-300">
                              <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                              Completed
                            </span>
                          <?php endif; ?>
                          
                          <!-- Priority indicators -->
                          <?php if ($is_urgent && $assignment['assignment_status'] !== 'completed'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-300">
                              <i class="fas fa-exclamation-triangle mr-1"></i>
                              URGENT
                            </span>
                          <?php elseif ($is_warning && $assignment['assignment_status'] !== 'completed'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 border border-amber-300">
                              <i class="fas fa-clock mr-1"></i>
                              SOON
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>
                      
                      <p class="text-lgu-paragraph mb-3"><?= htmlspecialchars($assignment['description']) ?></p>
                      
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-lgu-paragraph">
                        <div class="flex items-start">
                          <i class="fas fa-map-marker-alt mr-2 text-lgu-headline mt-0.5"></i>
                          <div>
                            <span class="truncate"><?= htmlspecialchars($assignment['address']) ?></span>
                            <?php if (!empty($assignment['landmark'])): ?>
                            <p class="text-xs text-lgu-paragraph mt-1 flex items-center">
                              <i class="fas fa-landmark mr-1"></i> <?= htmlspecialchars($assignment['landmark']) ?>
                            </p>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-user mr-2 text-lgu-headline"></i>
                          <span>Assigned by: <?= htmlspecialchars($assignment['assigned_by_name']) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-calendar-alt mr-2 text-lgu-headline"></i>
                          <span>Deadline: <?= date('M j, Y g:i A', strtotime($assignment['completion_deadline'])) ?></span>
                        </div>
                        <div class="flex items-center">
                          <i class="fas fa-clock mr-2 text-lgu-headline"></i>
                          <span>Assigned: <?= date('M j, Y', strtotime($assignment['assigned_date'])) ?></span>
                        </div>
                      </div>
                      
                      <?php if ($assignment['assignment_notes']): ?>
                        <div class="mt-3 p-3 bg-lgu-bg rounded-lg border border-gray-200">
                          <h4 class="text-sm font-semibold text-lgu-headline mb-1">Assignment Notes:</h4>
                          <p class="text-sm text-lgu-paragraph"><?= htmlspecialchars($assignment['assignment_notes']) ?></p>
                        </div>
                      <?php endif; ?>
                      
                      <?php if ($assignment['contact_number']): ?>
                        <div class="mt-2 flex items-center text-sm text-lgu-paragraph">
                          <i class="fas fa-phone mr-2 text-lgu-headline"></i>
                          <span>Contact: <?= htmlspecialchars($assignment['contact_number']) ?></span>
                        </div>
                      <?php endif; ?>
                      
                      <!-- Timeline -->
                      <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-lgu-paragraph">
                        <?php if ($assignment['started_at']): ?>
                          <div class="flex items-center">
                            <i class="fas fa-play-circle mr-2 text-green-500"></i>
                            <span>Started: <?= date('M j, Y g:i A', strtotime($assignment['started_at'])) ?></span>
                          </div>
                        <?php endif; ?>
                        
                        <?php if ($assignment['completed_at']): ?>
                          <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2 text-green-500"></i>
                            <span>Completed: <?= date('M j, Y g:i A', strtotime($assignment['completed_at'])) ?></span>
                          </div>
                        <?php endif; ?>
                      </div>
                      
                      <!-- Location Map -->
                      <div class="mt-4 bg-gray-50 rounded-lg p-3 border border-gray-200">
                        <div class="flex items-center gap-2 mb-2">
                          <i class="fas fa-map text-lgu-headline"></i>
                          <span class="font-medium text-lgu-headline text-sm">Traffic Location</span>
                        </div>
                        <div id="taskMap_<?= $assignment['assignment_id'] ?>" class="w-full rounded border border-gray-300"></div>
                        <p class="text-xs text-lgu-paragraph mt-1">Red marker shows the traffic management location</p>
                      </div>
                    </div>
                    
                    <!-- Action Buttons & Image -->
                    <div class="flex flex-col items-center gap-4 lg:w-64">
                      <?php if ($assignment['image_path']): ?>
                        <div class="text-center">
                          <?php
                            $image_src = $assignment['image_path'];
                            if (substr($image_src, 0, 4) !== 'http' && substr($image_src, 0, 1) !== '/') {
                                if (substr($image_src, 0, 8) !== 'uploads/') {
                                    $image_src = 'uploads/hazard_reports/' . $image_src;
                                }
                                $image_src = '../' . $image_src;
                            }
                          ?>
                          <img src="<?= htmlspecialchars($image_src) ?>" 
                               alt="Traffic Hazard Image" 
                               class="w-full max-w-xs rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition border border-gray-200"
                               onclick="openImageModal(this.src)"
                               onerror="this.style.display='none'; this.nextElementSibling.innerHTML='Image not found'">
                          <p class="text-xs text-lgu-paragraph mt-1">Original Report Image</p>
                        </div>
                      <?php endif; ?>
                      
                      <div class="flex flex-col gap-2 w-full">
                        <?php if ($assignment['assignment_status'] === 'assigned'): ?>
                          <form method="POST" class="w-full">
                            <input type="hidden" name="assignment_id" value="<?= $assignment['assignment_id'] ?>">
                            <button type="submit" name="start_work" class="w-full bg-lgu-button hover:bg-amber-500 text-lgu-button-text font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition shadow-sm">
                              <i class="fas fa-play-circle mr-2"></i> Start Traffic Work
                            </button>
                          </form>
                        <?php elseif ($assignment['assignment_status'] === 'in_progress'): ?>
                          <button class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition shadow-sm complete-traffic-work" 
                                  data-assignment-id="<?= $assignment['assignment_id'] ?>">
                            <i class="fas fa-check-circle mr-2"></i> Complete Traffic Work
                          </button>
                        <?php elseif ($assignment['assignment_status'] === 'completed'): ?>
                          <div class="text-center w-full py-2 px-4 border border-green-300 rounded-lg bg-green-50">
                            <span class="text-green-700 font-semibold flex items-center justify-center">
                              <i class="fas fa-check-double mr-2"></i> Traffic Work Completed
                            </span>
                            <?php if ($assignment['completed_at']): ?>
                              <p class="text-xs text-green-600 mt-1">
                                <?= date('M j, Y', strtotime($assignment['completed_at'])) ?>
                              </p>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                        
                        <!-- View Location Button -->
                        <a href="https://maps.google.com/?q=<?= urlencode($assignment['address']) ?>" 
                           target="_blank" 
                           class="w-full bg-lgu-headline hover:bg-lgu-stroke text-white font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition shadow-sm">
                          <i class="fas fa-map-marker-alt mr-2"></i> View Location
                        </a>
                        
                        <!-- View Details Button -->
                        <button class="w-full bg-gray-200 hover:bg-gray-300 text-lgu-button-text font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition shadow-sm view-traffic-details"
                                data-assignment='<?= htmlspecialchars(json_encode($assignment), ENT_QUOTES, 'UTF-8') ?>'>
                          <i class="fas fa-info-circle mr-2"></i> Task Details
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

  <!-- Complete Traffic Work Modal with Image Upload -->
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="completeTrafficModal" tabindex="-1" aria-labelledby="completeTrafficModalLabel" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-md p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-lg rounded-lg border border-gray-200">
        <div class="bg-lgu-headline text-white px-6 py-4 rounded-t-lg">
          <h3 class="text-lg font-semibold flex items-center">
            <i class="fas fa-check-circle mr-2"></i> Complete Traffic Management
          </h3>
        </div>
        
        <form method="POST" action="" enctype="multipart/form-data" class="mt-4">
          <input type="hidden" name="assignment_id" id="complete_traffic_assignment_id">
          
          <div class="mb-4">
            <label for="completion_notes" class="block text-sm font-medium text-lgu-headline mb-2">
              <i class="fas fa-sticky-note mr-1"></i> Traffic Work Completion Notes
            </label>
            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-lgu-button focus:border-lgu-button" 
                      id="completion_notes" name="completion_notes" rows="4" 
                      placeholder="Describe the traffic management work completed (signage installation, signal repair, road marking, etc.) and any follow-up required..." 
                      required></textarea>
            <div class="text-xs text-lgu-paragraph mt-1">Please provide details about the traffic management work you completed.</div>
          </div>
          
          <div class="mb-4">
            <label class="block text-sm font-medium text-lgu-headline mb-2">
              <i class="fas fa-camera mr-1"></i> Upload Completion Photo
            </label>
            <div class="flex gap-2 mb-2">
              <input type="file" id="completion_image" name="completion_image" accept="image/*" capture="environment" class="hidden">
              <button type="button" onclick="document.getElementById('completion_image').click()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition camera-btn">
                <i class="fas fa-camera mr-2"></i> Take Photo
              </button>
              <button type="button" onclick="document.getElementById('completion_image').click()" class="flex-1 bg-pink-600 hover:bg-pink-700 text-white font-semibold py-2 px-4 rounded-lg flex items-center justify-center transition upload-btn">
                <i class="fas fa-upload mr-2"></i> Upload Photo
              </button>
            </div>
            <div class="text-xs text-lgu-paragraph">Take a photo of the completed work or upload an existing photo</div>
            
            <!-- Image Preview -->
            <div id="imagePreviewContainer" class="image-preview-container">
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-lgu-headline">Preview:</span>
                <button type="button" onclick="clearImage()" class="text-red-600 hover:text-red-800 text-sm">
                  <i class="fas fa-times mr-1"></i> Remove
                </button>
              </div>
              <img id="imagePreview" class="image-preview" alt="Completion photo preview">
            </div>
          </div>
          
          <div class="flex justify-end space-x-3 mt-6">
            <button type="button" class="px-4 py-2 text-sm font-medium text-lgu-paragraph bg-gray-200 hover:bg-gray-300 rounded-lg transition cancel-traffic-complete">
              <i class="fas fa-times mr-1"></i> Cancel
            </button>
            <button type="submit" name="complete_work" class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition">
              <i class="fas fa-check mr-1"></i> Complete Traffic Work
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Traffic Task Details Modal -->
  <div class="modal fade fixed inset-0 z-50 overflow-y-auto" id="trafficDetailsModal" tabindex="-1" aria-labelledby="trafficDetailsModalLabel" aria-hidden="true" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>
      
      <div class="relative inline-block w-full max-w-2xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-lg rounded-lg border border-gray-200">
        <div class="bg-lgu-headline text-white px-6 py-4 rounded-t-lg">
          <h3 class="text-lg font-semibold flex items-center">
            <i class="fas fa-info-circle mr-2"></i> Traffic Task Details
          </h3>
        </div>
        
        <div class="mt-4 space-y-4" id="traffic-task-details-content">
          <!-- Details will be populated by JavaScript -->
        </div>
        
        <div class="flex justify-end mt-6">
          <button type="button" class="px-4 py-2 text-sm font-medium text-lgu-paragraph bg-gray-200 hover:bg-gray-300 rounded-lg transition close-traffic-details">
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
        <img id="modalImage" src="" alt="Traffic Hazard Image" class="max-w-full max-h-[80vh] rounded-lg shadow-lg mx-auto border-2 border-white">
      </div>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Initialize maps
  initializeMaps();
  
  // Mobile sidebar toggle
  const sidebar = document.getElementById('maintenance-sidebar');
  const mobileToggle = document.getElementById('mobile-sidebar-toggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => {
      sidebar.classList.toggle('-translate-x-full');
      document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
    });
  }

  // Complete traffic work modal functionality
  const completeTrafficModal = document.getElementById('completeTrafficModal');
  const completeTrafficButtons = document.querySelectorAll('.complete-traffic-work');
  
  completeTrafficButtons.forEach(button => {
    button.addEventListener('click', function() {
      const assignmentId = this.getAttribute('data-assignment-id');
      document.getElementById('complete_traffic_assignment_id').value = assignmentId;
      completeTrafficModal.style.display = 'block';
    });
  });
  
  // Close complete traffic modal
  document.querySelector('.cancel-traffic-complete').addEventListener('click', function() {
    completeTrafficModal.style.display = 'none';
    clearImage();
  });
  
  // Traffic task details modal
  const trafficDetailsModal = document.getElementById('trafficDetailsModal');
  const viewTrafficDetailsButtons = document.querySelectorAll('.view-traffic-details');
  
  viewTrafficDetailsButtons.forEach(button => {
    button.addEventListener('click', function() {
      const assignmentData = JSON.parse(this.getAttribute('data-assignment'));
      populateTrafficDetailsModal(assignmentData);
      trafficDetailsModal.style.display = 'block';
    });
  });
  
  // Close traffic details modal
  document.querySelector('.close-traffic-details').addEventListener('click', function() {
    trafficDetailsModal.style.display = 'none';
  });
  
  // Close modals when clicking outside
  window.addEventListener('click', function(event) {
    if (event.target === completeTrafficModal) {
      completeTrafficModal.style.display = 'none';
      clearImage();
    }
    if (event.target === trafficDetailsModal) {
      trafficDetailsModal.style.display = 'none';
    }
    if (event.target === document.getElementById('imageModal')) {
      closeImageModal();
    }
  });
  
  // Image upload preview functionality
  const completionImageInput = document.getElementById('completion_image');
  const imagePreview = document.getElementById('imagePreview');
  const imagePreviewContainer = document.getElementById('imagePreviewContainer');
  
  if (completionImageInput) {
    completionImageInput.addEventListener('change', function(event) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          imagePreview.src = e.target.result;
          imagePreviewContainer.style.display = 'block';
        };
        reader.readAsDataURL(file);
      }
    });
  }
  
  // Function to clear image preview
  window.clearImage = function() {
    if (completionImageInput) {
      completionImageInput.value = '';
    }
    imagePreviewContainer.style.display = 'none';
    imagePreview.src = '';
  };
  
  // Function to populate traffic details modal
  function populateTrafficDetailsModal(assignment) {
    const content = document.getElementById('traffic-task-details-content');
    
    // Format dates
    const deadline = new Date(assignment.completion_deadline).toLocaleString();
    const assignedDate = new Date(assignment.assigned_date).toLocaleString();
    const startedAt = assignment.started_at ? new Date(assignment.started_at).toLocaleString() : 'Not started';
    const completedAt = assignment.completed_at ? new Date(assignment.completed_at).toLocaleString() : 'Not completed';
    
    // Determine status badge
    let statusBadge = '';
    if (assignment.assignment_status === 'assigned') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800 border border-gray-300">Assigned</span>';
    } else if (assignment.assignment_status === 'in_progress') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-50 text-amber-800 border border-amber-200">In Progress</span>';
    } else if (assignment.assignment_status === 'completed') {
      statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 border border-green-300">Completed</span>';
    }
    
    content.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <h4 class="font-semibold text-lgu-headline">Traffic Task Information</h4>
          <div class="mt-2 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Task ID:</span>
              <span class="font-medium text-lgu-headline">#${assignment.assignment_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Report ID:</span>
              <span class="font-medium text-lgu-headline">#${assignment.report_id}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Team Type:</span>
              <span class="font-medium text-lgu-headline">${assignment.team_type ? assignment.team_type.replace(/_/g, ' ') : 'N/A'}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Hazard Type:</span>
              <span class="font-medium text-lgu-headline">${assignment.hazard_type ? assignment.hazard_type.replace(/_/g, ' ') : 'N/A'}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Status:</span>
              <span>${statusBadge}</span>
            </div>
          </div>
        </div>
        
        <div>
          <h4 class="font-semibold text-lgu-headline">Timeline</h4>
          <div class="mt-2 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Assigned:</span>
              <span class="font-medium text-lgu-headline">${assignedDate}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Deadline:</span>
              <span class="font-medium text-lgu-headline">${deadline}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Started:</span>
              <span class="font-medium text-lgu-headline">${startedAt}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-lgu-paragraph">Completed:</span>
              <span class="font-medium text-lgu-headline">${completedAt}</span>
            </div>
          </div>
        </div>
      </div>
      
      <div>
        <h4 class="font-semibold text-lgu-headline">Location</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.address || 'N/A'}</p>
      </div>
      
      <div>
        <h4 class="font-semibold text-lgu-headline">Description</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.description || 'No description provided'}</p>
      </div>
      
      ${assignment.contact_number ? `
      <div>
        <h4 class="font-semibold text-lgu-headline">Contact Number</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.contact_number}</p>
      </div>
      ` : ''}
      
      ${assignment.assignment_notes ? `
      <div>
        <h4 class="font-semibold text-lgu-headline">Assignment Notes</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.assignment_notes}</p>
      </div>
      ` : ''}
      
      ${assignment.forward_notes ? `
      <div>
        <h4 class="font-semibold text-lgu-headline">Forward Notes</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.forward_notes}</p>
      </div>
      ` : ''}
      
      <div>
        <h4 class="font-semibold text-lgu-headline">Assigned By</h4>
        <p class="mt-1 text-sm text-lgu-paragraph">${assignment.assigned_by_name || 'N/A'}</p>
      </div>
    `;
  }
});

// Image modal functions
function openImageModal(src) {
  document.getElementById('modalImage').src = src;
  document.getElementById('imageModal').style.display = 'block';
}

function closeImageModal() {
  document.getElementById('imageModal').style.display = 'none';
}

// Map initialization functions
const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';

function initializeMaps() {
  setTimeout(() => {
    <?php foreach ($assignments as $assignment): ?>
    initTaskMap(<?= $assignment['assignment_id'] ?>, <?= json_encode($assignment['address']) ?>, <?= json_encode($assignment['hazard_type']) ?>, <?= json_encode($assignment['landmark'] ?? '') ?>);
    <?php endforeach; ?>
  }, 100);
}

function initTaskMap(taskId, address, hazardType, landmark) {
  const mapId = 'taskMap_' + taskId;
  const mapElement = document.getElementById(mapId);
  
  if (!mapElement || !address) return;
  
  try {
    const map = L.map(mapId, {
      zoomControl: true,
      scrollWheelZoom: false
    }).setView([14.5995, 120.9842], 12);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(map);
    
    setTimeout(() => {
      map.invalidateSize();
      geocodeAndMarkLocation(map, address, hazardType, landmark);
    }, 200);
    
  } catch (error) {
    console.error(`Error initializing map for task ${taskId}:`, error);
    mapElement.innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 text-gray-500 text-xs"><i class="fa fa-map-marker-alt mr-1"></i>Map unavailable</div>';
  }
}

async function geocodeAndMarkLocation(map, address, hazardType, landmark) {
  try {
    let response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
    let data = await response.json();
    
    if (!data.results || data.results.length === 0) {
      response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address + ', Philippines')}&limit=1`);
      const nominatimData = await response.json();
      
      if (nominatimData && nominatimData.length > 0) {
        data = {
          results: [{
            position: {
              lat: parseFloat(nominatimData[0].lat),
              lon: parseFloat(nominatimData[0].lon)
            }
          }]
        };
      }
    }
    
    if (data.results && data.results.length > 0) {
      const result = data.results[0];
      const lat = result.position.lat;
      const lng = result.position.lon;
      
      const marker = L.marker([lat, lng], {
        icon: L.divIcon({
          className: 'custom-hazard-marker',
          html: '<div style="background: #ef4444; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-traffic-light text-white text-xs"></i></div>',
          iconSize: [20, 20],
          iconAnchor: [10, 10]
        })
      }).addTo(map);
      
      let popupContent = `
        <div class="p-2 text-xs">
          <h4 class="font-bold text-red-600 mb-1">Traffic Management</h4>
          <p><strong>Type:</strong> ${hazardType}</p>
          <p><strong>Address:</strong> ${address}</p>
      `;
      
      if (landmark && landmark.trim()) {
        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
      }
      
      popupContent += `</div>`;
      
      marker.bindPopup(popupContent);
      map.setView([lat, lng], 15);
      
    } else {
      const mapContainer = map.getContainer();
      const overlay = document.createElement('div');
      overlay.className = 'absolute inset-0 bg-gray-100 bg-opacity-90 flex items-center justify-center text-center p-2';
      overlay.innerHTML = `
        <div>
          <i class="fa fa-map-marker-alt text-lg text-gray-400 mb-1"></i>
          <p class="text-xs font-semibold text-gray-600">${address}</p>
          <p class="text-xs text-gray-500">Unable to show on map</p>
        </div>
      `;
      mapContainer.style.position = 'relative';
      mapContainer.appendChild(overlay);
    }
    
  } catch (error) {
    console.error('Geocoding error:', error);
  }
}
</script>
</body>
</html>