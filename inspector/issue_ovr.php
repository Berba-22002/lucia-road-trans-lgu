<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    header("Location: ../login.php");
    exit();
}

try {
    $pdo = (new Database())->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$inspector_id = $_SESSION['user_id'];

function generateTicketNumber() {
    $month = date('m');
    $year = date('y');
    $random = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $checksum = rand(0, 9);
    return "MM{$month}{$year}-{$random}-{$checksum}";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_number = generateTicketNumber();
    $violation_date = $_POST['violation_date'] ?? '';
    $violation_time = $_POST['violation_time'] ?? '';
    $location = $_POST['location'] ?? '';
    $offender_name = $_POST['offender_name'] ?? '';
    $offender_contact = $_POST['offender_contact'] ?? '';
    $violation_description = $_POST['violation_description'] ?? '';
    $penalty_amount = $_POST['penalty_amount'] ?? '';
    $violation_category = $_POST['violation_category'] ?? '';
    $violation_id = $_POST['violation_id'] ?? '';
    $offense_count = $_POST['offense_count'] ?? 1;
    $ordinance_code = $_POST['ordinance_code'] ?? '';
    $plate_number = $_POST['plate_number'] ?? '';
    $evidence_media = null;
    $resident_id = $_POST['resident_id'] ?? null; // Get resident_id if selected

    // Validate required fields
    $required_fields = [
        'violation_date', 'violation_time', 'location', 
        'offender_name', 'violation_description', 'penalty_amount',
        'violation_category', 'violation_id', 'offense_count'
    ];
    
    $is_valid = true;
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $is_valid = false;
            break;
        }
    }

    if ($is_valid) {
        try {
            // Ensure evidence_media column exists
            $pdo->exec("ALTER TABLE ovr_tickets ADD COLUMN IF NOT EXISTS evidence_media VARCHAR(255) AFTER plate_number");
            
            // Handle file upload
            if (isset($_FILES['evidence_media']) && $_FILES['evidence_media']['error'] == UPLOAD_ERR_OK) {
                $file = $_FILES['evidence_media'];
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'webm', 'mp4', 'mov', 'avi'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($file_ext, $allowed_extensions)) {
                    $upload_dir = __DIR__ . '/../uploads/ovr_evidence/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $evidence_media = 'ovr_' . $ticket_number . '_' . time() . '.' . $file_ext;
                    $upload_path = $upload_dir . $evidence_media;
                    move_uploaded_file($file['tmp_name'], $upload_path);
                }
            }
            
            // Prepare SQL statement
            $stmt = $pdo->prepare("INSERT INTO ovr_tickets (ticket_number, violation_date, violation_time, location, offender_name, offender_contact, violation_description, penalty_amount, violation_category, violation_id, offense_count, ordinance_code, plate_number, evidence_media, inspector_id, resident_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . implode(" ", $pdo->errorInfo()));
            }
            
            $result = $stmt->execute([
                $ticket_number, $violation_date, $violation_time, $location,
                $offender_name, $offender_contact, $violation_description,
                $penalty_amount, $violation_category, $violation_id,
                $offense_count, $ordinance_code, $plate_number,
                $evidence_media, $inspector_id, $resident_id
            ]);
            
            if (!$result) {
                throw new Exception("Execute failed: " . implode(" ", $stmt->errorInfo()));
            }
            
            echo json_encode(['success' => true]);
            exit();
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            error_log("OVR Ticket Insert Error: " . $e->getMessage());
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
            error_log("OVR Ticket Error: " . $e->getMessage());
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Please fill in all required fields']);
        exit();
    }
}

// Check for success message
$success_message = null;
if (isset($_GET['success'])) {
    $success_message = "OVR Ticket issued successfully!";
}

try {
    $stmt = $pdo->prepare("SELECT ot.*, v.violation_name FROM ovr_tickets ot LEFT JOIN violations v ON ot.violation_id = v.id WHERE ot.inspector_id = ? ORDER BY ot.created_at DESC");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . implode(" ", $pdo->errorInfo()));
    }
    $result = $stmt->execute([$inspector_id]);
    if (!$result) {
        throw new Exception("Execute failed: " . implode(" ", $stmt->errorInfo()));
    }
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("OVR Tickets Fetch Error: " . $e->getMessage());
    $tickets = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue OVR - RTIM Inspector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        #ovrMap { height: 300px; border-radius: 8px; }
        .upload-tab-btn.active { background: white; color: #00473e; }
        .upload-tab-content { display: none; }
        .upload-tab-content.active { display: block; }
        .resident-option:hover { background-color: #f0f9ff; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-gradient-to-r from-lgu-headline to-lgu-stroke shadow-lg border-b-4 border-lgu-button">
            <div class="flex items-center justify-between px-4 py-4 gap-4">
                <div class="flex items-center gap-4 flex-1">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-white">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Issue OVR Ticket</h1>
                        <p class="text-sm text-gray-200">Create and issue violation tickets</p>
                    </div>
                </div>
                <button id="openModalBtn" class="bg-lgu-button text-lgu-button-text px-6 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition flex items-center gap-2 shadow-md">
                    <i class="fas fa-plus"></i>New Ticket
                </button>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <?php if ($success_message): ?>
                <script>
                    Swal.fire({
                        title: 'Success!',
                        text: '<?php echo htmlspecialchars($success_message); ?>',
                        icon: 'success',
                        confirmButtonColor: '#faae2b',
                        timer: 3000
                    });
                </script>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <script>
                    Swal.fire({
                        title: 'Error!',
                        text: '<?php echo htmlspecialchars($error); ?>',
                        icon: 'error',
                        confirmButtonColor: '#faae2b'
                    });
                </script>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow-lg overflow-hidden border-t-4 border-lgu-button">
                <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-6 py-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-list mr-3"></i>Issued Tickets
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100 border-b">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Ticket #</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Violation</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Offender</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Fine (₱)</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Date</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-lgu-headline">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr class="border-b hover:bg-gray-50 transition">
                                <td class="px-6 py-4 text-sm font-semibold text-lgu-headline"><?php echo htmlspecialchars($ticket['ticket_number']); ?></td>
                                <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo htmlspecialchars($ticket['violation_name'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo htmlspecialchars($ticket['offender_name']); ?></td>
                                <td class="px-6 py-4 text-sm font-semibold text-lgu-button">₱<?php echo number_format($ticket['penalty_amount'], 2); ?></td>
                                <td class="px-6 py-4 text-sm text-lgu-paragraph"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                <td class="px-6 py-4 text-sm">
                                    <button class="text-blue-600 hover:text-blue-800 font-semibold transition" onclick="viewTicket(<?php echo htmlspecialchars(json_encode($ticket)); ?>)">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (empty($tickets)): ?>
                    <div class="text-center py-12 text-lgu-paragraph">
                        <i class="fas fa-inbox text-5xl text-gray-300 mb-3"></i>
                        <p class="text-lg">No tickets issued yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 border-t-4 border-lgu-button">
            <div class="px-6 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM</p>
            </div>
        </footer>
    </div>

    <div id="ovrModal" class="modal">
        <div class="bg-white rounded-lg shadow-2xl max-w-3xl w-full mx-4 max-h-[95vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke text-white px-6 py-4 flex items-center justify-between sticky top-0 z-10">
                <h2 class="text-xl font-bold flex items-center">
                    <i class="fas fa-ticket-alt mr-3"></i>New OVR Ticket
                </h2>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6" id="ticketForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Category</label>
                        <select name="violation_category" id="violation_category" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight">
                            <option value="">Select Category</option>
                            <option value="Vehicle-Related">🚗 Vehicle-Related</option>
                            <option value="Non-Vehicle">🚶 Non-Vehicle</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Violation</label>
                        <select name="violation_id" id="violation_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" disabled>
                            <option value="">Select Violation</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Offense Level</label>
                        <select name="offense_count" id="offense_count" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight">
                            <option value="1">1st Offense</option>
                            <option value="2">2nd Offense</option>
                            <option value="3">3rd Offense</option>
                            <option value="4">4th Offense</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Fine (₱)</label>
                        <input type="number" name="penalty_amount" id="penalty_amount" required step="0.01" min="0" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50" readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Ordinance Code</label>
                        <input type="text" name="ordinance_code" id="ordinance_code" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50" readonly>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Date</label>
                        <input type="date" name="violation_date" id="violation_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Time</label>
                        <input type="time" name="violation_time" id="violation_time" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" value="<?php echo date('H:i'); ?>">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Location</label>
                    <input type="text" name="location" id="location" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight mb-2" placeholder="Type address">
                    <button type="button" id="getCurrentLocationBtn" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition mb-3">
                        <i class="fas fa-location-arrow mr-1"></i>Use Current Location
                    </button>
                    <div id="ovrMap" class="border border-gray-300 rounded-lg"></div>
                </div>

                <!-- UPDATED OFFENDER NAME SECTION -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Offender Name *</label>
                        <div class="space-y-3">
                            <!-- Mode Selection -->
                            <div class="flex gap-2">
                                <button type="button" id="residentSelectBtn" class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg text-sm font-medium hover:bg-yellow-500 transition flex items-center">
                                    <i class="fas fa-list mr-2"></i>Select Resident
                                </button>
                                <button type="button" id="customNameBtn" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-300 transition">
                                    Enter Custom Name
                                </button>
                            </div>
                            
                            <!-- Resident Search Area -->
                            <div id="residentSelectArea" class="space-y-2 hidden">
                                <select id="residentDropdown" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight">
                                    <option value="">Loading residents...</option>
                                </select>
                                <div id="selectedResidentInfo" class="hidden bg-blue-50 p-3 rounded-lg border border-blue-200">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-lgu-headline"><span id="selectedResidentName"></span></p>
                                            <p class="text-xs text-lgu-paragraph">ID: <span id="selectedResidentId"></span></p>
                                        </div>
                                        <button type="button" id="clearResidentBtn" class="text-red-600 hover:text-red-800 font-semibold">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <p class="text-xs text-lgu-paragraph italic">Don't see the resident? <button type="button" class="text-blue-600 hover:underline" onclick="switchToCustomMode()">Enter custom name</button></p>
                            </div>
                            
                            <!-- Custom Name Input -->
                            <div id="customNameArea" class="space-y-2 hidden">
                                <input type="text" name="offender_name" id="offender_name_custom" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" 
                                       placeholder="Enter offender's name">
                                <p class="text-xs text-lgu-paragraph italic">Entering name manually for non-registered offenders</p>
                            </div>
                            
                            <!-- Hidden input for resident ID -->
                            <input type="hidden" name="resident_id" id="resident_id" value="">
                            <!-- Hidden input for offender name (populated based on mode) -->
                            <input type="hidden" name="offender_name" id="offender_name" value="">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-lgu-headline mb-2">Contact (Optional)</label>
                        <input type="tel" name="offender_contact" id="offender_contact" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" placeholder="Phone number">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Plate Number (Optional)</label>
                    <input type="text" name="plate_number" id="plate_number" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" placeholder="ABC-1234">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Description</label>
                    <textarea name="violation_description" id="violation_description" required rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-lgu-highlight" placeholder="Detailed description"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-lgu-headline mb-2">Evidence Media (Optional)</label>
                    <div class="flex gap-1 mb-4 bg-gray-100 p-1 rounded-xl w-fit">
                        <button type="button" class="upload-tab-btn active px-6 py-2 font-medium text-lgu-headline rounded-lg" data-tab="video">
                            <i class="fas fa-video mr-2"></i>Record Video
                        </button>
                        <button type="button" class="upload-tab-btn px-6 py-2 font-medium text-lgu-paragraph rounded-lg hover:text-lgu-headline" data-tab="camera">
                            <i class="fas fa-camera mr-2"></i>Capture Photo
                        </button>
                        <button type="button" class="upload-tab-btn px-6 py-2 font-medium text-lgu-paragraph rounded-lg hover:text-lgu-headline" data-tab="upload">
                            <i class="fas fa-upload mr-2"></i>Upload File
                        </button>
                    </div>

                    <!-- Video Tab -->
                    <div id="video-tab" class="upload-tab-content active bg-gray-50 rounded-2xl p-6 mb-4">
                        <div class="text-center">
                            <video id="videoPreview" class="w-full max-w-sm mx-auto mb-4 bg-black rounded-lg border-2 border-lgu-button" playsinline autoplay muted></video>
                            <div class="flex gap-3 justify-center mb-4 flex-wrap">
                                <button type="button" id="startVideoBtn" class="bg-lgu-button text-lgu-button-text font-bold py-2 px-6 rounded-lg hover:bg-yellow-500 transition">
                                    <i class="fas fa-play mr-2"></i>Start Camera
                                </button>
                                <button type="button" id="recordVideoBtn" class="bg-red-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-red-700 transition hidden">
                                    <i class="fas fa-record-vinyl mr-2"></i>Record
                                </button>
                                <button type="button" id="stopVideoBtn" class="bg-gray-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-gray-700 transition hidden">
                                    <i class="fas fa-stop mr-2"></i>Stop
                                </button>
                            </div>
                            <div id="recordingTimer" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg hidden">
                                <div class="flex items-center justify-center text-red-800">
                                    <i class="fas fa-circle text-red-600 mr-2 animate-pulse"></i>
                                    <span class="font-bold text-lg">Recording: <span id="timerDisplay">00:30</span></span>
                                </div>
                            </div>
                            <video id="recordedVideo" class="w-full max-w-sm mx-auto mt-4 rounded-lg border-2 border-lgu-button hidden" controls playsinline></video>
                            <div id="videoTimestamp" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg hidden">
                                <div class="flex items-center justify-center text-green-800">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span class="font-medium">Video recorded on: <span id="videoTimestampText"></span></span>
                                </div>
                            </div>
                            <p class="text-sm text-lgu-paragraph mt-4">Record a short video showing the violation (max 30 seconds)</p>
                        </div>
                    </div>

                    <!-- Camera Tab -->
                    <div id="camera-tab" class="upload-tab-content hidden bg-gray-50 rounded-2xl p-6 mb-4">
                        <div class="text-center">
                            <video id="cameraVideo" class="w-full max-w-sm mx-auto mb-4 bg-black rounded-lg border-2 border-lgu-button" playsinline autoplay></video>
                            <div class="flex gap-3 justify-center mb-4 flex-wrap">
                                <button type="button" id="startCameraBtn" class="bg-lgu-button text-lgu-button-text font-bold py-2 px-6 rounded-lg hover:bg-yellow-500 transition">
                                    <i class="fas fa-play mr-2"></i>Start Camera
                                </button>
                                <button type="button" id="stopCameraBtn" class="bg-red-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-red-700 transition hidden">
                                    <i class="fas fa-stop mr-2"></i>Stop
                                </button>
                                <button type="button" id="captureBtn" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-green-700 transition hidden">
                                    <i class="fas fa-camera mr-2"></i>Capture
                                </button>
                            </div>
                            <canvas id="captureCanvas" style="display: none;"></canvas>
                            <img id="capturedImage" class="w-full max-w-sm mx-auto mt-4 rounded-lg border-2 border-lgu-button hidden" alt="Captured">
                            <div id="captureTimestamp" class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg hidden">
                                <div class="flex items-center justify-center text-green-800">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span class="font-medium">Photo captured on: <span id="timestampText"></span></span>
                                </div>
                            </div>
                            <p class="text-sm text-lgu-paragraph mt-4">Make sure the violation is clearly visible</p>
                        </div>
                    </div>

                    <!-- Upload Tab -->
                    <div id="upload-tab" class="upload-tab-content hidden bg-gray-50 rounded-2xl p-6 mb-4">
                        <div class="text-center">
                            <button type="button" id="uploadBtn" class="bg-lgu-button text-lgu-button-text font-bold py-3 px-8 rounded-lg hover:bg-yellow-500 transition mb-4">
                                <i class="fas fa-upload mr-2"></i>Choose File
                            </button>
                            <p class="text-sm text-lgu-paragraph">Select an image or video file from your device</p>
                        </div>
                    </div>

                    <input type="file" name="evidence_media" id="mediaInput" accept="image/*,video/*" style="display:none;">
                    <div id="mediaPreview" class="mt-4"></div>
                    <div id="mediaStatus" class="text-sm text-green-600 hidden"><i class="fas fa-check mr-1"></i>Media selected</div>
                </div>

                <div class="flex gap-3 pt-4 border-t">
                    <button type="submit" id="submitBtn" class="flex-1 bg-lgu-highlight text-lgu-button-text font-semibold py-3 rounded-lg hover:bg-yellow-600 transition flex items-center justify-center">
                        <i class="fas fa-check mr-2"></i>Issue Ticket
                    </button>
                    <button type="button" onclick="closeModal()" class="flex-1 bg-gray-300 text-gray-700 font-semibold py-3 rounded-lg hover:bg-gray-400 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
let map = null, marker = null, cameraStream = null, videoStream = null, mediaRecorder = null, recordedChunks = [];
let livePreviewActive = false, recordingTimer = null, recordingStartTime = null;

// Initialize map
function initMap() {
    if (!map) {
        map = L.map('ovrMap').setView([14.6760, 120.9626], 11);
        L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
            attribution: '© TomTom'
        }).addTo(map);
    }
}

// Geocode address
async function geocodeAddress(address) {
    if (!address.trim()) {
        if (marker) map.removeLayer(marker);
        marker = null;
        return;
    }
    try {
        const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
        const data = await response.json();
        if (data.results && data.results.length > 0) {
            const result = data.results[0];
            if (marker) map.removeLayer(marker);
            marker = L.marker([result.position.lat, result.position.lon]).addTo(map);
            map.setView([result.position.lat, result.position.lon], 16);
        }
    } catch (error) {
        console.error('Geocoding error:', error);
    }
}

// Modal functions
document.getElementById('openModalBtn').addEventListener('click', () => {
    document.getElementById('ovrModal').classList.add('active');
    setTimeout(() => initMap(), 100);
});

function closeModal() {
    document.getElementById('ovrModal').classList.remove('active');
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
    livePreviewActive = false;
    
    // Reset form
    document.getElementById('ticketForm').reset();
    document.getElementById('penalty_amount').value = '';
    document.getElementById('ordinance_code').value = '';
    document.getElementById('violation_id').disabled = true;
    document.getElementById('violation_id').innerHTML = '<option value="">Select Violation</option>';
    document.getElementById('mediaPreview').innerHTML = '';
    document.getElementById('mediaStatus').classList.add('hidden');
    
    // Reset offender name section
    resetOffenderNameSection();
}

// Location input handler
document.getElementById('location').addEventListener('input', function() {
    clearTimeout(window.geocodeTimeout);
    window.geocodeTimeout = setTimeout(() => geocodeAddress(this.value), 1000);
});

// Get current location
document.getElementById('getCurrentLocationBtn').addEventListener('click', function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                const data = await response.json();
                if (data && data.address) {
                    const addr = data.address;
                    const parts = [];
                    if (addr.house_number) parts.push(addr.house_number);
                    if (addr.road) parts.push(addr.road);
                    if (addr.suburb) parts.push(addr.suburb);
                    if (addr.city) parts.push(addr.city);
                    if (addr.province) parts.push(addr.province);
                    
                    if (parts.length > 0) {
                        document.getElementById('location').value = parts.join(', ');
                        geocodeAddress(document.getElementById('location').value);
                    }
                }
            } catch (error) {
                console.error('Error:', error);
            }
        });
    }
});

// Violation category change
document.getElementById('violation_category').addEventListener('change', async function() {
    const category = this.value;
    const select = document.getElementById('violation_id');
    select.innerHTML = '<option value="">Select Violation</option>';
    select.disabled = !category;
    document.getElementById('penalty_amount').value = '';
    document.getElementById('ordinance_code').value = '';
    
    if (category) {
        try {
            const response = await fetch(`./api/get_violations.php?action=get_violations_by_category&category=${encodeURIComponent(category)}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const violations = await response.json();
            
            if (Array.isArray(violations)) {
                violations.forEach(v => {
                    const option = document.createElement('option');
                    option.value = v.id;
                    option.textContent = v.violation_name;
                    select.appendChild(option);
                });
                select.disabled = false;
            }
        } catch (error) {
            console.error('Error loading violations:', error);
            Swal.fire({
                title: 'Error',
                text: 'Failed to load violations: ' + error.message,
                icon: 'error',
                confirmButtonColor: '#faae2b'
            });
        }
    }
});

// Calculate fine
async function calculateFine() {
    const violationId = document.getElementById('violation_id').value;
    const offenseCount = document.getElementById('offense_count').value;
    
    if (violationId && offenseCount) {
        try {
            const response = await fetch(`./api/get_violations.php?action=get_fine&violation_id=${violationId}&offense_count=${offenseCount}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();
            
            if (data.fine !== undefined) {
                document.getElementById('penalty_amount').value = data.fine;
                document.getElementById('ordinance_code').value = data.ordinance_code || '';
            }
        } catch (error) {
            console.error('Error calculating fine:', error);
        }
    }
}

document.getElementById('violation_id').addEventListener('change', calculateFine);
document.getElementById('offense_count').addEventListener('change', calculateFine);

// UPDATED: Offender Name Selection System
let currentMode = 'resident'; // 'resident' or 'custom'

function resetOffenderNameSection() {
    document.getElementById('residentSelectArea').classList.add('hidden');
    document.getElementById('customNameArea').classList.add('hidden');
    document.getElementById('residentDropdown').value = '';
    document.getElementById('offender_name_custom').value = '';
    document.getElementById('resident_id').value = '';
    document.getElementById('offender_name').value = '';
    document.getElementById('offender_contact').value = '';
    currentMode = 'resident';
    
    document.getElementById('residentSelectBtn').classList.remove('bg-gray-200', 'text-gray-700');
    document.getElementById('residentSelectBtn').classList.add('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('customNameBtn').classList.remove('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('customNameBtn').classList.add('bg-gray-200', 'text-gray-700');
}

function switchToResidentMode() {
    currentMode = 'resident';
    document.getElementById('residentSelectArea').classList.remove('hidden');
    document.getElementById('customNameArea').classList.add('hidden');
    
    document.getElementById('residentSelectBtn').classList.remove('bg-gray-200', 'text-gray-700');
    document.getElementById('residentSelectBtn').classList.add('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('customNameBtn').classList.remove('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('customNameBtn').classList.add('bg-gray-200', 'text-gray-700');
    
    document.getElementById('offender_name_custom').value = '';
    updateOffenderNameField();
    
    loadResidents();
}

function switchToCustomMode() {
    currentMode = 'custom';
    document.getElementById('residentSelectArea').classList.add('hidden');
    document.getElementById('customNameArea').classList.remove('hidden');
    
    document.getElementById('customNameBtn').classList.remove('bg-gray-200', 'text-gray-700');
    document.getElementById('customNameBtn').classList.add('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('residentSelectBtn').classList.remove('bg-lgu-button', 'text-lgu-button-text');
    document.getElementById('residentSelectBtn').classList.add('bg-gray-200', 'text-gray-700');
    
    document.getElementById('resident_id').value = '';
    document.getElementById('residentDropdown').value = '';
    document.getElementById('offender_contact').value = '';
    
    setTimeout(() => document.getElementById('offender_name_custom').focus(), 100);
    updateOffenderNameField();
}

function updateOffenderNameField() {
    if (currentMode === 'resident') {
        const dropdown = document.getElementById('residentDropdown');
        const selectedOption = dropdown.options[dropdown.selectedIndex];
        if (selectedOption && selectedOption.value) {
            document.getElementById('offender_name').value = selectedOption.text;
        } else {
            document.getElementById('offender_name').value = '';
        }
    } else {
        document.getElementById('offender_name').value = document.getElementById('offender_name_custom').value;
    }
}

// Event listeners for mode switching
document.getElementById('residentSelectBtn').addEventListener('click', switchToResidentMode);
document.getElementById('customNameBtn').addEventListener('click', switchToCustomMode);



async function loadResidents() {
    try {
        const response = await fetch('../api/get_all_residents.php');
        const residents = await response.json();
        const dropdown = document.getElementById('residentDropdown');
        dropdown.innerHTML = '<option value="">Select a resident...</option>';
        if (Array.isArray(residents)) {
            residents.forEach(resident => {
                const option = document.createElement('option');
                option.value = resident.id;
                option.textContent = resident.fullname + ' (ID: ' + resident.id + ')';
                option.dataset.contact = resident.contact || '';
                dropdown.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading residents:', error);
        document.getElementById('residentDropdown').innerHTML = '<option value="">Error loading residents</option>';
    }
}





// Clear selected resident
if (document.getElementById('clearResidentBtn')) {
    document.getElementById('clearResidentBtn').addEventListener('click', function() {
        document.getElementById('selectedResidentInfo').classList.add('hidden');
        document.getElementById('resident_id').value = '';
        document.getElementById('offender_name').value = '';
        document.getElementById('offender_contact').value = '';
    });
}

// Update hidden field when custom name changes
if (document.getElementById('offender_name_custom')) {
    document.getElementById('offender_name_custom').addEventListener('input', function() {
        updateOffenderNameField();
    });
}

// Initialize offender name section
document.addEventListener('DOMContentLoaded', function() {
    resetOffenderNameSection();
});

// Upload tab switching
document.querySelectorAll('.upload-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.getAttribute('data-tab');
        
        // Update active button
        document.querySelectorAll('.upload-tab-btn').forEach(b => {
            b.classList.remove('active');
            b.classList.add('text-lgu-paragraph', 'hover:text-lgu-headline');
        });
        this.classList.add('active');
        this.classList.remove('text-lgu-paragraph', 'hover:text-lgu-headline');
        
        // Show active tab content
        document.querySelectorAll('.upload-tab-content').forEach(content => {
            content.classList.remove('active');
            content.classList.add('hidden');
        });
        document.getElementById(tabId + '-tab').classList.add('active');
        document.getElementById(tabId + '-tab').classList.remove('hidden');
    });
});

// Video recording functionality
let videoRecorder = null;
let videoChunks = [];

document.getElementById('startVideoBtn').addEventListener('click', async function() {
    try {
        videoStream = await navigator.mediaDevices.getUserMedia({ 
            video: { facingMode: 'environment' },
            audio: true 
        });
        
        const videoPreview = document.getElementById('videoPreview');
        videoPreview.srcObject = videoStream;
        videoPreview.play();
        
        document.getElementById('startVideoBtn').classList.add('hidden');
        document.getElementById('recordVideoBtn').classList.remove('hidden');
        document.getElementById('stopVideoBtn').classList.remove('hidden');
        
    } catch (err) {
        Swal.fire({ 
            title: 'Camera Error', 
            text: 'Unable to access camera: ' + err.message, 
            icon: 'error',
            confirmButtonColor: '#faae2b' 
        });
    }
});

document.getElementById('recordVideoBtn').addEventListener('click', function() {
    if (!videoStream) return;
    
    videoChunks = [];
    const options = { mimeType: 'video/webm;codecs=vp9,opus' };
    
    try {
        videoRecorder = new MediaRecorder(videoStream, options);
        
        videoRecorder.ondataavailable = event => {
            if (event.data.size > 0) {
                videoChunks.push(event.data);
            }
        };
        
        videoRecorder.onstop = () => {
            const videoBlob = new Blob(videoChunks, { type: 'video/webm' });
            const videoURL = URL.createObjectURL(videoBlob);
            
            const recordedVideo = document.getElementById('recordedVideo');
            recordedVideo.src = videoURL;
            recordedVideo.classList.remove('hidden');
            
            // Create file for form submission
            const file = new File([videoBlob], 'evidence_' + Date.now() + '.webm', { type: 'video/webm' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('mediaInput').files = dataTransfer.files;
            
            // Show preview
            document.getElementById('mediaPreview').innerHTML = `
                <video src="${videoURL}" controls class="max-w-sm rounded-lg border-2 border-lgu-button"></video>
            `;
            document.getElementById('mediaStatus').classList.remove('hidden');
            
            // Update timestamp
            document.getElementById('videoTimestampText').textContent = new Date().toLocaleString();
            document.getElementById('videoTimestamp').classList.remove('hidden');
        };
        
        videoRecorder.start();
        
        // Start timer
        let seconds = 30;
        const timerDisplay = document.getElementById('timerDisplay');
        const recordingTimerDiv = document.getElementById('recordingTimer');
        recordingTimerDiv.classList.remove('hidden');
        
        const timer = setInterval(() => {
            seconds--;
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            timerDisplay.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            
            if (seconds <= 0) {
                clearInterval(timer);
                if (videoRecorder && videoRecorder.state === 'recording') {
                    videoRecorder.stop();
                }
                recordingTimerDiv.classList.add('hidden');
            }
        }, 1000);
        
        // Store timer reference
        window.recordingTimer = timer;
        
    } catch (err) {
        console.error('Recording error:', err);
    }
});

document.getElementById('stopVideoBtn').addEventListener('click', function() {
    if (videoRecorder && videoRecorder.state === 'recording') {
        videoRecorder.stop();
    }
    
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
    
    document.getElementById('startVideoBtn').classList.remove('hidden');
    document.getElementById('recordVideoBtn').classList.add('hidden');
    document.getElementById('stopVideoBtn').classList.add('hidden');
    document.getElementById('recordingTimer').classList.add('hidden');
    
    if (window.recordingTimer) {
        clearInterval(window.recordingTimer);
        window.recordingTimer = null;
    }
});

// Camera capture functionality
document.getElementById('startCameraBtn').addEventListener('click', async function() {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ 
            video: { facingMode: 'environment' } 
        });
        
        const cameraVideo = document.getElementById('cameraVideo');
        cameraVideo.srcObject = cameraStream;
        cameraVideo.play();
        
        document.getElementById('startCameraBtn').classList.add('hidden');
        document.getElementById('stopCameraBtn').classList.remove('hidden');
        document.getElementById('captureBtn').classList.remove('hidden');
        
    } catch (err) {
        Swal.fire({ 
            title: 'Camera Error', 
            text: 'Unable to access camera: ' + err.message, 
            icon: 'error',
            confirmButtonColor: '#faae2b' 
        });
    }
});

document.getElementById('captureBtn').addEventListener('click', function() {
    const cameraVideo = document.getElementById('cameraVideo');
    const canvas = document.getElementById('captureCanvas');
    const capturedImage = document.getElementById('capturedImage');
    
    canvas.width = cameraVideo.videoWidth;
    canvas.height = cameraVideo.videoHeight;
    
    const ctx = canvas.getContext('2d');
    ctx.drawImage(cameraVideo, 0, 0, canvas.width, canvas.height);
    
    canvas.toBlob(blob => {
        const imageURL = URL.createObjectURL(blob);
        capturedImage.src = imageURL;
        capturedImage.classList.remove('hidden');
        
        // Create file for form submission
        const file = new File([blob], 'evidence_' + Date.now() + '.jpg', { type: 'image/jpeg' });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        document.getElementById('mediaInput').files = dataTransfer.files;
        
        // Show preview
        document.getElementById('mediaPreview').innerHTML = `
            <img src="${imageURL}" class="max-w-sm rounded-lg border-2 border-lgu-button">
        `;
        document.getElementById('mediaStatus').classList.remove('hidden');
        
        // Update timestamp
        document.getElementById('timestampText').textContent = new Date().toLocaleString();
        document.getElementById('captureTimestamp').classList.remove('hidden');
        
    }, 'image/jpeg', 0.9);
});

document.getElementById('stopCameraBtn').addEventListener('click', function() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    
    document.getElementById('startCameraBtn').classList.remove('hidden');
    document.getElementById('stopCameraBtn').classList.add('hidden');
    document.getElementById('captureBtn').classList.add('hidden');
});

// File upload
document.getElementById('uploadBtn').addEventListener('click', function() {
    document.getElementById('mediaInput').click();
});

document.getElementById('mediaInput').addEventListener('change', function() {
    if (this.files.length > 0) {
        const file = this.files[0];
        const fileURL = URL.createObjectURL(file);
        
        if (file.type.startsWith('image/')) {
            document.getElementById('mediaPreview').innerHTML = `
                <img src="${fileURL}" class="max-w-sm rounded-lg border-2 border-lgu-button">
            `;
        } else if (file.type.startsWith('video/')) {
            document.getElementById('mediaPreview').innerHTML = `
                <video src="${fileURL}" controls class="max-w-sm rounded-lg border-2 border-lgu-button"></video>
            `;
        }
        
        document.getElementById('mediaStatus').classList.remove('hidden');
    }
});

// Form validation and submission
document.getElementById('ticketForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate offender name
    updateOffenderNameField();
    const offenderName = document.getElementById('offender_name').value.trim();
    if (!offenderName) {
        Swal.fire({
            title: 'Missing Offender Name',
            text: 'Please select a resident or enter a custom name',
            icon: 'warning',
            confirmButtonColor: '#faae2b'
        });
        return false;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const originalHtml = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
    
    const requiredFields = [
        'violation_date', 'violation_time', 'location',
        'violation_description', 'penalty_amount',
        'violation_category', 'violation_id'
    ];
    
    let isValid = true;
    for (const fieldName of requiredFields) {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (!field || !field.value.trim()) {
            isValid = false;
            Swal.fire({
                title: 'Missing Information',
                text: `Please fill in ${fieldName.replace(/_/g, ' ')}`,
                icon: 'warning',
                confirmButtonColor: '#faae2b'
            }).then(() => {
                field?.focus();
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHtml;
            });
            return false;
        }
    }
    
    const penaltyAmount = document.getElementById('penalty_amount');
    if (!penaltyAmount.value || parseFloat(penaltyAmount.value) <= 0) {
        Swal.fire({
            title: 'Invalid Fine Amount',
            text: 'Please select a violation first',
            icon: 'warning',
            confirmButtonColor: '#faae2b'
        }).then(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        });
        return false;
    }
    
    const formData = new FormData(this);
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: 'OVR Ticket issued successfully!',
                icon: 'success',
                confirmButtonColor: '#faae2b',
                timer: 2000
            }).then(() => {
                window.location.href = 'issue_ovr.php?success=1';
            });
        } else {
            Swal.fire({
                title: 'Error!',
                text: data.error || 'Failed to submit ticket',
                icon: 'error',
                confirmButtonColor: '#faae2b'
            });
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        Swal.fire({
            title: 'Error!',
            text: 'Failed to submit ticket: ' + error.message,
            icon: 'error',
            confirmButtonColor: '#faae2b'
        });
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    });
    
    return false;
});

// View ticket details
function viewTicket(ticket) {
    Swal.fire({
        title: 'Ticket Details',
        html: `
            <div class="text-left space-y-2">
                <p><strong>Ticket #:</strong> ${ticket.ticket_number}</p>
                <p><strong>Violation:</strong> ${ticket.violation_name || 'N/A'}</p>
                <p><strong>Offender:</strong> ${ticket.offender_name}</p>
                <p><strong>Contact:</strong> ${ticket.offender_contact || 'N/A'}</p>
                <p><strong>Fine:</strong> ₱${parseFloat(ticket.penalty_amount).toFixed(2)}</p>
                <p><strong>Date/Time:</strong> ${ticket.violation_date} ${ticket.violation_time}</p>
                <p><strong>Location:</strong> ${ticket.location}</p>
                <p><strong>Description:</strong> ${ticket.violation_description}</p>
                <p><strong>Plate Number:</strong> ${ticket.plate_number || 'N/A'}</p>
            </div>
        `,
        width: 600,
        confirmButtonColor: '#faae2b'
    });
}

// Mobile sidebar toggle
document.getElementById('mobile-sidebar-toggle').addEventListener('click', function() {
    document.querySelector('.lg\\:ml-64').classList.toggle('ml-0');
    document.querySelector('.lg\\:ml-64').classList.toggle('ml-64');
});

// Set today's date and time by default
window.addEventListener('load', function() {
    const today = new Date();
    const dateInput = document.getElementById('violation_date');
    const timeInput = document.getElementById('violation_time');
    
    if (dateInput && !dateInput.value) {
        dateInput.value = today.toISOString().split('T')[0];
    }
    
    if (timeInput && !timeInput.value) {
        const hours = today.getHours().toString().padStart(2, '0');
        const minutes = today.getMinutes().toString().padStart(2, '0');
        timeInput.value = `${hours}:${minutes}`;
    }
});

// Load residents data from PHP
async function loadResidents() {
    const residents = <?php 
        try {
            $database = new Database();
            $pdo = $database->getConnection();
            $stmt = $pdo->prepare("SELECT id, fullname, contact_number as contact FROM users WHERE role = 'resident' ORDER BY fullname ASC");
            $stmt->execute();
            $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($residents);
        } catch (Exception $e) {
            echo json_encode([]);
        }
    ?>;
    
    const dropdown = document.getElementById('residentDropdown');
    dropdown.innerHTML = '<option value="">Select a resident...</option>';
    
    if (Array.isArray(residents) && residents.length > 0) {
        residents.forEach(resident => {
            const option = document.createElement('option');
            option.value = resident.id;
            option.textContent = resident.fullname + ' (ID: ' + resident.id + ')';
            option.dataset.contact = resident.contact || '';
            dropdown.appendChild(option);
        });
    } else {
        dropdown.innerHTML = '<option value="">No residents found</option>';
    }
}

if (document.getElementById('residentDropdown')) {
    document.getElementById('residentDropdown').addEventListener('change', function() {
        if (this.value) {
            const selectedOption = this.options[this.selectedIndex];
            document.getElementById('resident_id').value = this.value;
            document.getElementById('selectedResidentName').textContent = selectedOption.text.split(' (ID:')[0];
            document.getElementById('selectedResidentId').textContent = this.value;
            document.getElementById('selectedResidentInfo').classList.remove('hidden');
            updateOffenderNameField();
            if (selectedOption.dataset.contact) {
                document.getElementById('offender_contact').value = selectedOption.dataset.contact;
            }
        } else {
            document.getElementById('selectedResidentInfo').classList.add('hidden');
            document.getElementById('resident_id').value = '';
            document.getElementById('offender_contact').value = '';
            updateOffenderNameField();
        }
    });
}
    </script>

</body>
</html>