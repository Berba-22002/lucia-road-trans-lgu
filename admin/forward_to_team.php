<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin and inspector
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'inspector'])) {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $report_id = intval($_POST['report_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        
        if ($action === 'forward_maintenance') {
            $team_type = $_POST['team_type'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            // Create report_forwards table if it doesn't exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS report_forwards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                team_type VARCHAR(100) NOT NULL,
                notes TEXT,
                forwarded_by INT NOT NULL,
                forwarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
                FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
                FOREIGN KEY (forwarded_by) REFERENCES users(id) ON DELETE CASCADE
            )");
            
            // Insert forwarding record
            $forward_stmt = $pdo->prepare("INSERT INTO report_forwards (report_id, team_type, notes, forwarded_by) VALUES (?, ?, ?, ?)");
            $forward_stmt->execute([$report_id, $team_type, $notes, $_SESSION['user_id']]);
            
            // Update report status to in_progress for minor issues
            $stmt = $pdo->prepare("UPDATE reports SET status = 'in_progress' WHERE id = ?");
            $stmt->execute([$report_id]);
            
            $_SESSION['success_message'] = "Report successfully forwarded to {$team_type} maintenance team!";
            header("Location: forward_to_team.php");
            exit();
            
        } elseif ($action === 'escalate_project') {
            $uploadDir = __DIR__ . '/../uploads/project-requests/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $data = [
                'requesting_office' => $_POST['requesting_office'] ?? '',
                'contact_person' => $_POST['contact_person'] ?? '',
                'position' => $_POST['position'] ?? '',
                'contact_number' => $_POST['contact_number'] ?? '',
                'contact_email' => $_POST['contact_email'] ?? '',
                'project_title' => $_POST['project_title'] ?? '',
                'project_category' => $_POST['project_category'] ?? '',
                'project_location' => $_POST['project_location'] ?? '',
                'latitude' => $_POST['latitude'] ?? '',
                'longitude' => $_POST['longitude'] ?? '',
                'problem_identified' => $_POST['problem_identified'] ?? '',
                'scope_item1' => $_POST['scope_item1'] ?? '',
                'scope_item2' => $_POST['scope_item2'] ?? '',
                'scope_item3' => $_POST['scope_item3'] ?? '',
                'estimated_budget' => !empty($_POST['estimated_budget']) ? floatval($_POST['estimated_budget']) : null,
                'priority_level' => $_POST['priority_level'] ?? 'medium',
                'requested_start_date' => $_POST['requested_start_date'] ?? '',
                'prepared_by' => $_POST['prepared_by'] ?? '',
                'prepared_position' => $_POST['prepared_position'] ?? ''
            ];
            
            $attachments = [];
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['name'] as $key => $name) {
                    if ($_FILES['photos']['error'][$key] === 0) {
                        $filename = time() . '_' . $key . '_' . basename($name);
                        if (move_uploaded_file($_FILES['photos']['tmp_name'][$key], $uploadDir . $filename)) {
                            $attachments['photos'][] = 'uploads/project-requests/' . $filename;
                        }
                    }
                }
            }
            if (!empty($_FILES['map']['name']) && $_FILES['map']['error'] === 0) {
                $filename = time() . '_map_' . basename($_FILES['map']['name']);
                if (move_uploaded_file($_FILES['map']['tmp_name'], $uploadDir . $filename)) {
                    $attachments['map'] = 'uploads/project-requests/' . $filename;
                }
            }
            if (!empty($_FILES['resolution']['name']) && $_FILES['resolution']['error'] === 0) {
                $filename = time() . '_resolution_' . basename($_FILES['resolution']['name']);
                if (move_uploaded_file($_FILES['resolution']['tmp_name'], $uploadDir . $filename)) {
                    $attachments['resolution'] = 'uploads/project-requests/' . $filename;
                }
            }
            if (!empty($_FILES['others']['name'][0])) {
                foreach ($_FILES['others']['name'] as $key => $name) {
                    if ($_FILES['others']['error'][$key] === 0) {
                        $filename = time() . '_' . $key . '_other_' . basename($name);
                        if (move_uploaded_file($_FILES['others']['tmp_name'][$key], $uploadDir . $filename)) {
                            $attachments['others'][] = 'uploads/project-requests/' . $filename;
                        }
                    }
                }
            }
            
            // Send to Infrastructure PM API
            $apiData = [
                'requesting_office' => $data['requesting_office'],
                'contact_person' => $data['contact_person'],
                'position' => $data['position'],
                'contact_number' => $data['contact_number'],
                'contact_email' => $data['contact_email'],
                'project_title' => $data['project_title'],
                'project_category' => $data['project_category'],
                'project_location' => $data['project_location'],
                'latitude' => !empty($data['latitude']) ? floatval($data['latitude']) : 0,
                'longitude' => !empty($data['longitude']) ? floatval($data['longitude']) : 0,
                'problem_identified' => $data['problem_identified'],
                'scope_item1' => $data['scope_item1'],
                'scope_item2' => $data['scope_item2'],
                'scope_item3' => $data['scope_item3'],
                'estimated_budget' => $data['estimated_budget'] ?? 0,
                'priority_level' => $data['priority_level'],
                'requested_start_date' => $data['requested_start_date'],
                'prepared_by' => $data['prepared_by'],
                'prepared_position' => $data['prepared_position']
            ];
            
            $ch = curl_init('https://infra-pm.local-government-unit-1-ph.com/api/integrations/ProjectRequest.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT,
                project_title VARCHAR(255) NOT NULL,
                estimated_budget DECIMAL(15,2),
                status ENUM('approved', 'under_construction', 'completed', 'on_hold') DEFAULT 'approved',
                actual_cost DECIMAL(15,2),
                progress INT DEFAULT 0,
                start_date DATE,
                expected_completion DATE,
                actual_completion DATE,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )");
            
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS requesting_office VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS contact_person VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS position VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS contact_email VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_category VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_location VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS latitude VARCHAR(50)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS longitude VARCHAR(50)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS problem_identified TEXT");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS scope_item1 TEXT");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS scope_item2 TEXT");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS scope_item3 TEXT");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS priority_level VARCHAR(50)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS requested_start_date DATE");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS prepared_position VARCHAR(255)");
            $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS attachments JSON");
            $pdo->exec("ALTER TABLE projects MODIFY COLUMN project_location VARCHAR(255) DEFAULT ''");
            
            $stmt = $pdo->prepare("INSERT INTO projects (report_id, requesting_office, contact_person, position, contact_number, contact_email, project_title, project_category, project_location, latitude, longitude, problem_identified, scope_item1, scope_item2, scope_item3, estimated_budget, priority_level, requested_start_date, prepared_by, prepared_position, attachments, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $report_id,
                $data['requesting_office'],
                $data['contact_person'],
                $data['position'],
                $data['contact_number'],
                $data['contact_email'],
                $data['project_title'],
                $data['project_category'],
                $data['project_location'],
                $data['latitude'],
                $data['longitude'],
                $data['problem_identified'],
                $data['scope_item1'],
                $data['scope_item2'],
                $data['scope_item3'],
                $data['estimated_budget'],
                $data['priority_level'],
                $data['requested_start_date'],
                $data['prepared_by'],
                $data['prepared_position'],
                json_encode($attachments),
                $_SESSION['user_id']
            ]);
            
            $update_stmt = $pdo->prepare("UPDATE reports SET status = 'escalated' WHERE id = ?");
            $update_stmt->execute([$report_id]);
            
            $_SESSION['success_message'] = "Report successfully escalated to Road Project!";
            header("Location: forward_to_team.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Forward to team error: " . $e->getMessage());
        $message = 'Error processing request: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Fetch reports with inspection findings
try {
    $query = "SELECT DISTINCT r.*, 
              u.fullname as reporter_name,
              u.contact_number as reporter_contact,
              (SELECT COUNT(*) FROM inspection_findings WHERE report_id = r.id) as findings_count,
              (SELECT GROUP_CONCAT(DISTINCT severity) FROM inspection_findings WHERE report_id = r.id) as severities,
              (SELECT COUNT(*) FROM projects WHERE report_id = r.id) as is_escalated
              FROM reports r
              INNER JOIN users u ON r.user_id = u.id
              WHERE EXISTS (SELECT 1 FROM inspection_findings WHERE report_id = r.id)
              AND r.status IN ('inspection_ended', 'pending', 'in_progress')
              AND NOT EXISTS (SELECT 1 FROM report_forwards WHERE report_id = r.id)
              ORDER BY r.created_at DESC";
    
    $stmt = $pdo->query($query);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Fetch reports error: " . $e->getMessage());
    $reports = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forward to Team - RTIM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="tomtom-geocode.js"></script>
    
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
                        'lgu-stroke': '#00332c'
                    }
                }
            }
        }
    </script>
    
    <style>
        * { font-family: 'Poppins', sans-serif; }
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        
        .report-card {
            transition: all 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .badge {
            transition: transform 0.2s ease;
        }
        .badge:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Include Sidebar -->
    <?php include __DIR__ . '/../admin/sidebar.php'; ?>

    <div class="lg:ml-64 min-h-screen">
        <!-- Header - STICKY -->
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-3 sm:px-4 py-3 sm:py-4">
                <div class="flex items-center justify-between gap-3 sm:gap-4">
                    <div class="flex items-center gap-3 sm:gap-4 flex-1 min-w-0">
                        <button id="sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0 p-2 rounded-lg hover:bg-gray-100">
                            <i class="fa fa-bars text-xl"></i>
                        </button>
                        <div class="min-w-0">
                            <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Forward to Team</h1>
                            <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Forward inspected reports to maintenance or escalate to projects</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                        <div class="stat-badge bg-gradient-to-br from-lgu-button to-yellow-500 text-lgu-button-text px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg">
                            <div class="text-xs">Reports</div>
                            <div class="text-base sm:text-lg"><?php echo count($reports); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto p-3 sm:p-4 lg:p-6">
            
            <?php if ($message && $message_type === 'error'): ?>
            <div class="bg-red-100 border-red-400 text-red-700 border px-4 py-3 rounded-lg mb-4 sm:mb-6 shadow-sm">
                <div class="flex items-start gap-3">
                    <i class="fa fa-exclamation-circle text-lg mt-0.5"></i>
                    <div>
                        <p class="font-semibold text-sm">Error</p>
                        <p class="text-xs sm:text-sm"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fa fa-tools text-xl text-blue-600"></i>
                        </div>
                        <div>
                            <p class="text-xs text-lgu-paragraph">Minor Issues</p>
                            <p class="text-xl font-bold text-lgu-headline">→ Maintenance</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-red-500">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fa fa-exclamation-triangle text-xl text-red-600"></i>
                        </div>
                        <div>
                            <p class="text-xs text-lgu-paragraph">Major Issues</p>
                            <p class="text-xl font-bold text-lgu-headline">→ Road Project</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-green-500">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fa fa-clipboard-check text-xl text-green-600"></i>
                        </div>
                        <div>
                            <p class="text-xs text-lgu-paragraph">Ready to Forward</p>
                            <p class="text-xl font-bold text-lgu-headline"><?php echo count($reports); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-lgu-button to-yellow-500 rounded-lg flex items-center justify-center shadow-md">
                            <i class="fa fa-arrow-right text-lg sm:text-xl text-lgu-button-text"></i>
                        </div>
                        <div>
                            <h3 class="text-lg sm:text-xl font-bold text-lgu-headline">Inspected Reports</h3>
                            <p class="text-xs sm:text-sm text-lgu-paragraph">Reports with completed inspection findings</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($reports)): ?>
                <div class="p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fa fa-inbox text-4xl text-gray-300"></i>
                    </div>
                    <h4 class="text-lg font-semibold text-lgu-headline mb-2">No Reports Available</h4>
                    <p class="text-sm text-lgu-paragraph">No inspected reports ready for forwarding</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider">Report ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider hidden md:table-cell">Reporter</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider">Severity</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider hidden lg:table-cell">Findings</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-lgu-headline uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($reports as $report): 
                                $has_major = strpos($report['severities'] ?? '', 'major') !== false;
                                $severity_class = $has_major ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700';
                                $severity_text = $has_major ? 'Major' : 'Minor';
                                $is_escalated = $report['is_escalated'] > 0;
                            ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-4 py-4">
                                    <span class="font-bold text-lgu-headline">#<?php echo $report['id']; ?></span>
                                </td>
                                <td class="px-4 py-4 hidden md:table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-xs">
                                            <?php echo strtoupper(substr($report['reporter_name'], 0, 1)); ?>
                                        </div>
                                        <span class="text-sm text-lgu-paragraph truncate max-w-[150px]"><?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded-full text-xs font-semibold">
                                        <?php echo ucfirst($report['hazard_type'] ?? 'General'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="<?php echo $severity_class; ?> px-3 py-1 rounded-full text-xs font-bold">
                                        <i class="fa fa-<?php echo $has_major ? 'exclamation-triangle' : 'info-circle'; ?> mr-1"></i>
                                        <?php echo $severity_text; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 hidden lg:table-cell">
                                    <span class="text-sm font-semibold text-lgu-headline"><?php echo $report['findings_count']; ?> findings</span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex gap-2">
                                        <a href="inspection_findings.php?report_id=<?php echo $report['id']; ?>" 
                                           class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition">
                                            <i class="fa fa-eye mr-1"></i>View
                                        </a>
                                        
                                        <?php if ($has_major): ?>
                                        <?php if ($is_escalated): ?>
                                        <span class="bg-gray-400 text-white px-3 py-1 rounded-lg text-xs font-semibold cursor-not-allowed">
                                            <i class="fa fa-check mr-1"></i>Escalated
                                        </span>
                                        <?php else: ?>
                                        <button onclick="openEscalateModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars(addslashes($report['description'])); ?>', '<?php echo htmlspecialchars(addslashes($report['address'])); ?>')" 
                                                class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition">
                                            <i class="fa fa-arrow-up mr-1"></i>Escalate
                                        </button>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <button onclick="openMaintenanceModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars(addslashes($report['hazard_type'])); ?>')" 
                                                class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition">
                                            <i class="fa fa-tools mr-1"></i>Forward
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Maintenance Modal -->
    <div id="maintenanceModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-green-600 to-green-700 text-white px-6 py-4 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa fa-tools text-2xl"></i>
                        <h3 class="text-xl font-bold">Forward to Maintenance</h3>
                    </div>
                    <button onclick="closeMaintenanceModal()" class="text-white hover:text-gray-200 transition">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="report_id" id="maintenance_report_id">
                <input type="hidden" name="action" value="forward_maintenance">
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">
                        <i class="fa fa-users mr-2"></i>Select Maintenance Team
                    </label>
                    <select name="team_type" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">-- Select Team --</option>
                        <option value="road_maintenance">Road Maintenance Team</option>
                        <option value="bridge_maintenance">Bridge Maintenance Team</option>
                        <option value="traffic_management">Traffic Management Team</option>
                    </select>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">
                        <i class="fa fa-comment mr-2"></i>Additional Notes (Optional)
                    </label>
                    <textarea name="notes" rows="4" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Add any special instructions or notes..."></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-bold transition">
                        <i class="fa fa-paper-plane mr-2"></i>Forward to Team
                    </button>
                    <button type="button" onclick="closeMaintenanceModal()" class="px-6 py-3 border border-gray-300 rounded-lg font-bold text-lgu-headline hover:bg-gray-50 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Escalate to Project Modal -->
    <div id="escalateModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-red-600 to-red-700 text-white px-6 py-4 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa fa-exclamation-triangle text-2xl"></i>
                        <h3 class="text-xl font-bold">Escalate to Road Project</h3>
                    </div>
                    <button onclick="closeEscalateModal()" class="text-white hover:text-gray-200 transition">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6 max-h-[calc(90vh-120px)] overflow-y-auto">
                <input type="hidden" name="report_id" id="escalate_report_id">
                <input type="hidden" name="action" value="escalate_project">
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Requesting Entity Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Requesting Office / Barangay *</label>
                            <input type="text" name="requesting_office" placeholder="Barangay San Jose" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Contact Person *</label>
                            <input type="text" name="contact_person" placeholder="Juan Dela Cruz" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Position</label>
                            <input type="text" name="position" placeholder="Barangay Captain" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Contact Number</label>
                            <input type="tel" name="contact_number" placeholder="09123456789" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Email</label>
                            <input type="email" name="contact_email" placeholder="user@email.com" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Project Details</h4>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Project Title *</label>
                            <input type="text" name="project_title" placeholder="Road Concreting of Main Street" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Project Category *</label>
                            <select name="project_category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                                <option value="">Select Category</option>
                                <option value="Road and Transportation">Road and Transportation</option>
                                <option value="Housing and Resettlement">Housing and Resettlement</option>
                                <option value="Public Facilities and Reservation">Public Facilities and Reservation</option>
                                <option value="Utility Billing">Utility Billing</option>
                                <option value="Community infrastructure">Community infrastructure</option>
                                <option value="Renewable Energy">Renewable Energy</option>
                                <option value="Energy Efficiency and Conservation">Energy Efficiency and Conservation</option>
                                <option value="Urban Planning and Development">Urban Planning and Development</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Project Location *</label>
                            <div class="flex gap-2">
                                <input type="text" name="project_location" id="escalate_location" placeholder="Brgy. San Jose, Main Street" required class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                                <button type="button" onclick="fetchTomTomLocation()" class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-xs font-semibold">📍 Fetch</button>
                            </div>
                            <input type="hidden" id="escalate_latitude" name="latitude">
                            <input type="hidden" id="escalate_longitude" name="longitude">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Problem Identified / Reason for Request *</label>
                            <textarea name="problem_identified" id="escalate_description" placeholder="Describe the problem or need..." required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm"></textarea>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Proposed Scope of Work</h4>
                    <div class="space-y-2">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Item 1</label>
                            <input type="text" name="scope_item1" placeholder="Excavation and grading" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Item 2</label>
                            <input type="text" name="scope_item2" placeholder="Concrete pouring" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Item 3</label>
                            <input type="text" name="scope_item3" placeholder="Installation of drainage system" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Additional Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Estimated Budget</label>
                            <input type="number" name="estimated_budget" step="0.01" placeholder="500000" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Priority Level</label>
                            <select name="priority_level" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Requested Start Date</label>
                            <input type="date" name="requested_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Attachments</h4>
                    <div class="space-y-2">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Photos of current condition</label>
                            <input type="file" name="photos[]" multiple accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Map / Sketch of location</label>
                            <input type="file" name="map" accept="image/*,.pdf" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Resolution / Endorsement</label>
                            <input type="file" name="resolution" accept=".pdf,.doc,.docx,image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Other attachments</label>
                            <input type="file" name="others[]" multiple class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-semibold text-lgu-headline mb-3 border-b pb-2">Authorization</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Prepared By (Name)</label>
                            <input type="text" name="prepared_by" placeholder="Maria Clara" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-lgu-headline mb-1">Position</label>
                            <input type="text" name="prepared_position" placeholder="Project Coordinator" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-3 sticky bottom-0 bg-white pt-4 border-t">
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-bold transition">
                        <i class="fa fa-arrow-up mr-2"></i>Escalate to Project
                    </button>
                    <button type="button" onclick="closeEscalateModal()" class="px-6 py-3 border border-gray-300 rounded-lg font-bold text-lgu-headline hover:bg-gray-50 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            const overlay = document.getElementById('sidebar-overlay');
            
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    if (overlay) {
                        overlay.classList.toggle('hidden');
                    }
                });
            }
            
            if (overlay) {
                overlay.addEventListener('click', () => {
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.add('hidden');
                });
            }
            
            // Show success message with SweetAlert
            <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo addslashes($_SESSION['success_message']); ?>',
                confirmButtonColor: '#10b981',
                timer: 3000,
                timerProgressBar: true
            });
            <?php unset($_SESSION['success_message']); endif; ?>
        });

        // Modal functions
        function openMaintenanceModal(reportId, hazardType) {
            document.getElementById('maintenance_report_id').value = reportId;
            document.getElementById('maintenanceModal').classList.add('show');
        }

        function closeMaintenanceModal() {
            document.getElementById('maintenanceModal').classList.remove('show');
        }

        function openEscalateModal(reportId, description, location) {
            document.getElementById('escalate_report_id').value = reportId;
            document.getElementById('escalate_description').value = description;
            document.getElementById('escalate_location').value = location;
            document.getElementById('escalateModal').classList.add('show');
        }

        function closeEscalateModal() {
            document.getElementById('escalateModal').classList.remove('show');
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMaintenanceModal();
                closeEscalateModal();
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const maintenanceModal = document.getElementById('maintenanceModal');
            const escalateModal = document.getElementById('escalateModal');
            
            if (event.target === maintenanceModal) {
                closeMaintenanceModal();
            }
            if (event.target === escalateModal) {
                closeEscalateModal();
            }
        }
const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
function fetchTomTomLocation() {
    const location = document.getElementById('escalate_location').value;
    if (!location) {
        Swal.fire({icon: 'warning', title: 'Location Required', text: 'Please enter a location first', confirmButtonColor: '#faae2b'});
        return;
    }
    fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(location)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`)
        .then(response => response.json())
        .then(data => {
            if (data.results && data.results.length > 0) {
                const result = data.results[0];
                document.getElementById('escalate_latitude').value = result.position.lat;
                document.getElementById('escalate_longitude').value = result.position.lon;
                document.getElementById('escalate_location').value = result.address.freeformAddress;
                Swal.fire({icon: 'success', title: 'Success', text: 'Location fetched successfully!', confirmButtonColor: '#10b981', timer: 2000});
            } else {
                Swal.fire({icon: 'error', title: 'Not Found', text: 'Location not found', confirmButtonColor: '#fa5246'});
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({icon: 'error', title: 'Error', text: 'Error fetching location', confirmButtonColor: '#fa5246'});
        });
}

    </script>
</body>
</html>