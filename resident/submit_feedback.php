<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is a resident
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}

// Initialize database connection
try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create or modify report_feedback table
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
        feedback_text TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add user_id column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE report_feedback ADD COLUMN user_id INT NOT NULL AFTER report_id");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add unique constraint if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE report_feedback ADD UNIQUE KEY unique_user_report (user_id, report_id)");
    } catch (PDOException $e) {
        // Constraint already exists, ignore error
    }
    
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

$user_id = $_SESSION['user_id'];

// Fetch user's submitted reports with media type detection
try {
    $reports_query = "
        SELECT r.*, 
               ri.notes as inspector_notes,
               ri.completed_at as inspection_completed_at,
               u.fullname as inspector_name
        FROM reports r 
        LEFT JOIN report_inspectors ri ON r.id = ri.report_id AND ri.status = 'completed'
        LEFT JOIN users u ON ri.inspector_id = u.id
        WHERE r.user_id = :user_id 
        ORDER BY r.created_at DESC
    ";
    $stmt = $pdo->prepare($reports_query);
    $stmt->execute([':user_id' => $user_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Detect media type for each report
    $video_extensions = ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv'];
    foreach ($reports as &$report) {
        if (!empty($report['image_path'])) {
            $ext = strtolower(pathinfo($report['image_path'], PATHINFO_EXTENSION));
            $report['media_type'] = in_array($ext, $video_extensions) ? 'video' : 'image';
        } else {
            $report['media_type'] = null;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching reports: " . $e->getMessage());
    $reports = [];
}

// Handle feedback submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    $report_id = $_POST['report_id'];
    $rating = $_POST['rating'];
    $feedback_text = trim($_POST['feedback_text']);
    
    // Validate input
    if (empty($report_id) || empty($rating) || empty($feedback_text)) {
        $_SESSION['feedback_error'] = 'All fields are required!';
    } else {
        try {
            // Check if feedback already exists for this report by this user
            $check_feedback = $pdo->prepare("SELECT id FROM report_feedback WHERE report_id = :report_id AND user_id = :user_id");
            $check_feedback->execute([':report_id' => $report_id, ':user_id' => $user_id]);
            
            if ($check_feedback->rowCount() > 0) {
                $_SESSION['feedback_error'] = 'You have already submitted feedback for this report!';
            } else {
                // Insert feedback
                $insert_feedback = $pdo->prepare("
                    INSERT INTO report_feedback (report_id, user_id, rating, feedback_text, created_at) 
                    VALUES (:report_id, :user_id, :rating, :feedback_text, NOW())
                ");
                
                $insert_feedback->execute([
                    ':report_id' => $report_id,
                    ':user_id' => $user_id,
                    ':rating' => $rating,
                    ':feedback_text' => $feedback_text
                ]);
                
                $_SESSION['feedback_success'] = 'Feedback submitted successfully!';
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error submitting feedback: " . $e->getMessage());
            $_SESSION['feedback_error'] = 'Error submitting feedback. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Feedback - LGU Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'lgu-bg': '#f2f7f5',
                        'lgu-headline': '#00473e',
                        'lgu-paragraph': '#475d5b',
                        'lgu-button': '#faae2b',
                        'lgu-button-text': '#00473e',
                        'lgu-stroke': '#00332c',
                        'lgu-main': '#f2f7f5',
                        'lgu-highlight': '#faae2b',
                        'lgu-secondary': '#ffa8ba',
                        'lgu-tertiary': '#fa5246'
                    }
                }
            }
        }
    </script>
    <style>
        .status-badge {
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .status-pending { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; box-shadow: 0 2px 4px rgba(146, 64, 14, 0.1); }
        .status-in_progress { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; box-shadow: 0 2px 4px rgba(30, 64, 175, 0.1); }
        .status-inspection_ended { background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); color: #be185d; box-shadow: 0 2px 4px rgba(190, 24, 93, 0.1); }
        .status-done { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; box-shadow: 0 2px 4px rgba(6, 95, 70, 0.1); }
        .status-escalated { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; box-shadow: 0 2px 4px rgba(153, 27, 27, 0.1); }
        
        .validation-pending { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; }
        .validation-validated { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; }
        .validation-rejected { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; }
        
        .star-rating {
            display: flex;
            gap: 0.5rem;
        }
        
        .star {
            cursor: pointer;
            color: #d1d5db;
            transition: all 0.2s ease;
            font-size: 1.75rem;
        }
        
        .star:hover {
            transform: scale(1.15);
        }
        
        .star.active {
            color: #fbbf24;
            text-shadow: 0 0 8px rgba(251, 191, 36, 0.5);
            transform: scale(1.1);
        }
        
        .report-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
        }
        
        .report-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: translateY(-4px);
            border-color: #faae2b;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #faae2b 0%, #f59e0b 100%);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(250, 174, 43, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(250, 174, 43, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .modal-backdrop {
            backdrop-filter: blur(4px);
        }
        
        .modal-content {
            animation: slideUp 0.3s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-input {
            transition: all 0.3s ease;
            border-color: #e5e7eb;
        }
        
        .form-input:focus {
            border-color: #faae2b;
            box-shadow: 0 0 0 3px rgba(250, 174, 43, 0.1);
        }
        
        .empty-state {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-gradient-to-r from-lgu-headline to-lgu-stroke shadow-lg border-b-4 border-lgu-button sticky top-0 z-30">
            <div class="flex items-center px-4 py-4">
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="lg:hidden mr-4 p-2 text-white hover:bg-white hover:bg-opacity-20 rounded-lg transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div>
                    <h1 class="text-2xl font-bold text-white flex items-center">
                        <i class="fas fa-comment-dots mr-3"></i>Submit Feedback
                    </h1>
                    <p class="text-sm text-gray-200 mt-1">Share your experience and help us improve</p>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['feedback_success'])): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 text-green-800 rounded-lg shadow-md">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3 text-green-600 text-lg"></i>
                        <span class="font-medium"><?php echo $_SESSION['feedback_success']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['feedback_success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['feedback_error'])): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 text-red-800 rounded-lg shadow-md">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-3 text-red-600 text-lg"></i>
                        <span class="font-medium"><?php echo $_SESSION['feedback_error']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['feedback_error']); ?>
            <?php endif; ?>

            <div class="max-w-6xl mx-auto">
                <!-- Reports List -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6 border border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold text-lgu-headline flex items-center">
                            <i class="fas fa-list-check mr-3 text-lgu-button"></i>
                            Your Submitted Reports
                        </h2>
                        <div class="hidden sm:block text-sm text-lgu-paragraph">
                            <i class="fas fa-info-circle mr-2"></i>Click "Give Feedback" on completed reports
                        </div>
                    </div>

                    <?php if (empty($reports)): ?>
                        <div class="text-center py-12 empty-state">
                            <div class="mb-4">
                                <i class="fas fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                            </div>
                            <p class="text-lgu-paragraph text-lg font-medium mb-2">No reports submitted yet</p>
                            <p class="text-gray-500 text-sm mb-6">Start by submitting your first hazard report to help improve our community</p>
                            <a href="report_hazard.php" class="inline-block btn-primary text-lgu-button-text font-bold py-3 px-6 rounded-lg transition">
                                <i class="fas fa-plus mr-2"></i>Submit Your First Report
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($reports as $report): ?>
                                <div class="report-card rounded-xl p-5 border-l-4 border-lgu-button">
                                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                        <div class="flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                                <h3 class="font-bold text-lg text-lgu-headline">
                                                    <i class="fas fa-exclamation-triangle text-lgu-button mr-2"></i>
                                                    <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?> Hazard
                                                </h3>
                                                <span class="status-badge status-<?php echo $report['status']; ?>">
                                                    <?php echo str_replace('_', ' ', $report['status']); ?>
                                                </span>
                                                <span class="status-badge validation-<?php echo $report['validation_status']; ?>">
                                                    <?php echo $report['validation_status']; ?>
                                                </span>
                                            </div>
                                            
                                            <div class="space-y-2 text-sm">
                                                <p class="text-lgu-paragraph">
                                                    <i class="fas fa-map-marker-alt mr-2 text-lgu-button"></i>
                                                    <?php echo htmlspecialchars($report['address']); ?>
                                                </p>
                                                <?php if (!empty($report['landmark'])): ?>
                                                <p class="text-gray-600">
                                                    <i class="fas fa-landmark mr-2 text-lgu-button"></i>
                                                    <?php echo htmlspecialchars($report['landmark']); ?>
                                                </p>
                                                <?php endif; ?>
                                                
                                                <p class="text-gray-500 text-xs">
                                                    <i class="fas fa-calendar-alt mr-2"></i>
                                                    Submitted: <?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?>
                                                </p>
                                                
                                                <?php if ($report['inspector_name']): ?>
                                                    <p class="text-lgu-paragraph text-xs bg-blue-50 p-2 rounded">
                                                        <i class="fas fa-user-check mr-1 text-blue-600"></i>
                                                        Inspected by: <strong><?php echo htmlspecialchars($report['inspector_name']); ?></strong>
                                                        on <?php echo date('M j, Y', strtotime($report['inspection_completed_at'])); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="flex flex-col sm:flex-row gap-2 lg:flex-col lg:items-end">
                                            <button type="button" 
                                                    class="view-details-btn btn-primary text-lgu-button-text font-bold py-2 px-4 rounded-lg transition text-sm whitespace-nowrap"
                                                    data-report-id="<?php echo $report['id']; ?>">
                                                <i class="fas fa-eye mr-1"></i>View Details
                                            </button>
                                            
                                            <?php if ($report['status'] == 'done'): ?>
                                                <?php 
                                                $has_feedback = false;
                                                try {
                                                    $feedback_check = $pdo->prepare("SELECT id FROM report_feedback WHERE report_id = ? AND user_id = ?");
                                                    $feedback_check->execute([$report['id'], $user_id]);
                                                    $has_feedback = $feedback_check->rowCount() > 0;
                                                } catch (PDOException $e) {
                                                    $has_feedback = false;
                                                }
                                                ?>
                                                
                                                <?php if ($has_feedback): ?>
                                                    <span class="bg-gradient-to-r from-gray-400 to-gray-500 text-white font-bold py-2 px-4 rounded-lg text-sm cursor-not-allowed shadow-md whitespace-nowrap">
                                                        <i class="fas fa-check mr-1"></i>Feedback Submitted
                                                    </span>
                                                <?php else: ?>
                                                    <button type="button" 
                                                            class="give-feedback-btn btn-success text-white font-bold py-2 px-4 rounded-lg transition text-sm whitespace-nowrap"
                                                            data-report-id="<?php echo $report['id']; ?>">
                                                        <i class="fas fa-comment mr-1"></i>Give Feedback
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4 modal-backdrop">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto modal-content border border-gray-100">
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke p-6 rounded-t-2xl">
                <h3 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-star mr-3 text-lgu-button"></i>
                    Share Your Feedback
                </h3>
                <p class="text-gray-200 text-sm mt-1">Help us improve our services</p>
            </div>
            <div class="p-6">
                
                <form id="feedbackForm" method="POST">
                    <input type="hidden" name="report_id" id="modal_report_id">
                    <input type="hidden" name="submit_feedback" value="1">
                    
                    <div class="mb-6">
                        <label class="block text-lgu-headline text-sm font-bold mb-3">
                            How would you rate this service? <span class="text-lgu-tertiary">*</span>
                        </label>
                        <div class="star-rating mb-3" id="starRating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="star" data-rating="<?php echo $i; ?>">
                                    <i class="fas fa-star"></i>
                                </span>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="ratingInput" required>
                        <p class="text-xs text-gray-500"><i class="fas fa-info-circle mr-1"></i>Click on the stars to rate</p>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-lgu-headline text-sm font-bold mb-3">
                            Your Feedback <span class="text-lgu-tertiary">*</span>
                        </label>
                        <textarea
                            name="feedback_text"
                            id="feedback_text"
                            rows="4"
                            placeholder="Please share your experience and any suggestions for improvement..."
                            class="w-full border-2 border-gray-300 rounded-lg p-3 text-lgu-paragraph form-input resize-none"
                            required></textarea>
                        <p class="text-xs text-gray-500 mt-2"><i class="fas fa-lightbulb mr-1"></i>Your feedback helps us serve you better</p>
                    </div>
                    
                    <div class="flex gap-3">
                        <button
                            type="submit"
                            class="btn-primary text-lgu-button-text font-bold py-2 px-4 rounded-lg transition flex-1">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Feedback
                        </button>
                        <button
                            type="button"
                            id="closeModal"
                            class="bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4 modal-backdrop">
        <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto modal-content border border-gray-100">
            <div class="bg-gradient-to-r from-lgu-headline to-lgu-stroke p-6 rounded-t-2xl flex items-center justify-between sticky top-0 z-10">
                <h3 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-file-alt mr-3 text-lgu-button"></i>
                    Report Details
                </h3>
                <button id="closeDetailsModal" class="text-white hover:bg-white hover:bg-opacity-20 p-2 rounded-lg transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                
                <div id="modalContent" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    overlay.classList.toggle('hidden');
                });
            }
        });
        
        // View details modal
        const detailsModal = document.getElementById('detailsModal');
        const modalContent = document.getElementById('modalContent');
        
        document.querySelectorAll('.view-details-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const reportId = this.getAttribute('data-report-id');
                const reportData = <?php echo json_encode($reports); ?>.find(r => r.id == parseInt(reportId));
                
                if (reportData) {
                    modalContent.innerHTML = `
                        <div>
                            <h4 class="font-semibold text-lgu-headline mb-3">Description</h4>
                            <p class="text-sm text-lgu-paragraph bg-gray-50 p-4 rounded-lg mb-4">
                                ${reportData.description}
                            </p>
                            
                            <h4 class="font-semibold text-lgu-headline mb-3">Location Map</h4>
                            <div id="reportMap_${reportData.id}" class="w-full h-48 rounded-lg border border-gray-300 mb-4"></div>
                            
                            ${reportData.image_path ? `
                                <h4 class="font-semibold text-lgu-headline mb-3">Attached Media</h4>
                                ${reportData.media_type === 'video' ? `
                                    <div class="mb-4">
                                        <video controls class="w-full rounded-lg border" style="max-height: 400px;">
                                            <source src="../uploads/hazard_reports/${reportData.image_path}" type="video/mp4">
                                            <source src="../uploads/hazard_reports/${reportData.image_path}" type="video/webm">
                                            Your browser does not support the video tag.
                                        </video>
                                        <p class="text-xs text-gray-500 mt-2"><i class="fas fa-video mr-1"></i>Video Evidence</p>
                                    </div>
                                ` : `
                                    <img src="../uploads/hazard_reports/${reportData.image_path}" 
                                         alt="Report image" 
                                         class="w-full rounded-lg border mb-4">
                                `}
                            ` : ''}
                        </div>
                        
                        <div>
                            <h4 class="font-semibold text-lgu-headline mb-3">Report Information</h4>
                            <div class="space-y-3 text-sm mb-4">
                                <div class="flex justify-between">
                                    <span class="font-medium">Contact:</span>
                                    <span>${reportData.contact_number || 'Not provided'}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-medium">Type:</span>
                                    <span>${reportData.hazard_type.charAt(0).toUpperCase() + reportData.hazard_type.slice(1)}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-medium">Status:</span>
                                    <span class="status-badge status-${reportData.status}">
                                        ${reportData.status.replace('_', ' ')}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-medium">Validation:</span>
                                    <span class="status-badge validation-${reportData.validation_status}">
                                        ${reportData.validation_status}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-medium">Submitted:</span>
                                    <span>${new Date(reportData.created_at).toLocaleDateString()}</span>
                                </div>
                                ${reportData.media_type ? `
                                <div class="flex justify-between">
                                    <span class="font-medium">Media Type:</span>
                                    <span class="capitalize">${reportData.media_type}</span>
                                </div>
                                ${reportData.ai_analysis_result ? `
                                <div class="flex justify-between">
                                    <span class="font-medium">Hazard Level:</span>
                                    <span id="hazard-level-badge"></span>
                                </div>
                                ` : ''}
                                ` : ''}
                            </div>
                            
                            ${reportData.inspector_notes ? `
                                <h4 class="font-semibold text-lgu-headline mb-2">Inspector Notes</h4>
                                <p class="text-sm text-lgu-paragraph bg-blue-50 p-3 rounded-lg mb-4">
                                    ${reportData.inspector_notes}
                                </p>
                            ` : ''}
                            
                            ${reportData.ai_analysis_result ? `
                                <h4 class="font-semibold text-lgu-headline mb-3 flex items-center">
                                    <i class="fa fa-robot mr-2 text-lgu-button"></i>
                                    Beripikado AI Analysis
                                </h4>
                                <div id="ai-analysis-${reportData.id}"></div>
                            ` : ''}
                        </div>
                    `;
                    
                    // Process AI analysis if exists
                    if (reportData.ai_analysis_result) {
                        const aiContainer = document.getElementById(`ai-analysis-${reportData.id}`);
                        let aiData;
                        try {
                            aiData = JSON.parse(reportData.ai_analysis_result);
                        } catch (e) {
                            aiData = null;
                        }
                        
                        // Set hazard level badge
                        const hazardLevelBadge = document.getElementById('hazard-level-badge');
                        if (aiData && aiData.hazardLevel) {
                            const levelColors = {
                                high: { bg: 'bg-red-100', text: 'text-red-700', label: 'HIGH' },
                                medium: { bg: 'bg-yellow-100', text: 'text-yellow-700', label: 'MEDIUM' },
                                low: { bg: 'bg-blue-100', text: 'text-blue-700', label: 'LOW' }
                            };
                            const colors = levelColors[aiData.hazardLevel] || levelColors.low;
                            hazardLevelBadge.innerHTML = `<span class="px-3 py-1 rounded-full text-sm font-bold ${colors.bg} ${colors.text}">${colors.label}</span>`;
                        }
                        
                        if (aiData && aiData.predictions && aiData.topPrediction) {
                            const topPrediction = aiData.topPrediction;
                            const confidence = (topPrediction.probability * 100).toFixed(1);
                            
                            aiContainer.innerHTML = `
                                <div class="bg-blue-50 rounded-lg p-4">
                                    <div class="mb-4">
                                        <div class="bg-blue-100 p-3 rounded-lg border-l-4 border-blue-500">
                                            <div class="flex justify-between items-center">
                                                <span class="font-semibold text-blue-800">${topPrediction.className}</span>
                                                <span class="text-sm font-bold text-blue-700">${confidence}% Confidence</span>
                                            </div>
                                            <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 p-3 ${topPrediction.probability > 0.6 ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200'} border rounded-lg">
                                        <div class="flex items-start ${topPrediction.probability > 0.6 ? 'text-green-800' : 'text-yellow-800'}">
                                            <i class="fa ${topPrediction.probability > 0.6 ? 'fa-check-circle' : 'fa-exclamation-triangle'} mr-2 mt-0.5 flex-shrink-0"></i>
                                            <span class="font-medium text-sm">
                                                ${topPrediction.probability > 0.6 
                                                    ? `AI Recommendation: Image appears to show ${topPrediction.className}` 
                                                    : 'AI Recommendation: Hazard classification requires manual verification'
                                                }
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        } else {
                            aiContainer.innerHTML = `
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <p class="text-lgu-paragraph leading-relaxed text-sm">${reportData.ai_analysis_result.replace(/\n/g, '<br>')}</p>
                                </div>
                            `;
                        }
                    }
                    
                    detailsModal.classList.remove('hidden');
                    
                    // Initialize map for this report
                    setTimeout(() => {
                        initReportMap(reportData.id, reportData.address, reportData.hazard_type, reportData.landmark || '');
                    }, 100);
                }
            });
        });
        
        // Close details modal
        document.getElementById('closeDetailsModal').addEventListener('click', function() {
            detailsModal.classList.add('hidden');
        });
        
        // Close modal when clicking outside
        detailsModal.addEventListener('click', function(e) {
            if (e.target === detailsModal) {
                detailsModal.classList.add('hidden');
            }
        });

        // Feedback modal functionality
        const feedbackModal = document.getElementById('feedbackModal');
        const ratingInput = document.getElementById('ratingInput');
        let currentRating = 0;

        // Star rating functionality
        document.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                currentRating = rating;
                ratingInput.value = rating;
                
                // Update star display
                document.querySelectorAll('.star').forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
            
            // Hover effect
            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                document.querySelectorAll('.star').forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
        });

        // Reset stars when mouse leaves
        document.getElementById('starRating').addEventListener('mouseleave', function() {
            document.querySelectorAll('.star').forEach((s, index) => {
                if (index < currentRating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });

        // Open feedback modal
        document.querySelectorAll('.give-feedback-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const reportId = this.getAttribute('data-report-id');
                document.getElementById('modal_report_id').value = reportId;
                feedbackModal.classList.remove('hidden');
            });
        });

        // Close modal
        document.getElementById('closeModal').addEventListener('click', function() {
            feedbackModal.classList.add('hidden');
            // Reset form
            document.getElementById('feedbackForm').reset();
            currentRating = 0;
            ratingInput.value = '';
            document.querySelectorAll('.star').forEach(star => {
                star.classList.remove('active');
            });
        });

        // Close modal when clicking outside
        feedbackModal.addEventListener('click', function(e) {
            if (e.target === feedbackModal) {
                feedbackModal.classList.add('hidden');
                // Reset form
                document.getElementById('feedbackForm').reset();
                currentRating = 0;
                ratingInput.value = '';
                document.querySelectorAll('.star').forEach(star => {
                    star.classList.remove('active');
                });
            }
        });

        // Form validation
        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            if (!ratingInput.value) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Rating Required',
                    text: 'Please provide a rating by clicking on the stars.',
                    confirmButtonColor: '#faae2b'
                });
            }
        });

        // Map functionality
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        const reportMaps = {};
        
        function initReportMap(reportId, address, hazardType, landmark) {
            const mapId = 'reportMap_' + reportId;
            const mapElement = document.getElementById(mapId);
            
            if (!mapElement || !address) return;
            
            // Initialize map
            const map = L.map(mapId).setView([14.5995, 120.9842], 12);
            
            // Add TomTom tile layer
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom, © OpenStreetMap contributors'
            }).addTo(map);
            
            reportMaps[reportId] = map;
            
            // Geocode and add marker
            geocodeAndMarkLocation(map, address, hazardType, landmark);
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
                            html: '<div style="background: #ef4444; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation text-white text-xs"></i></div>',
                            iconSize: [20, 20],
                            iconAnchor: [10, 10]
                        })
                    }).addTo(map);
                    
                    // Create popup content
                    let popupContent = `
                        <div class="p-2 text-xs">
                            <h4 class="font-bold text-red-600 mb-1">My Report</h4>
                            <p><strong>Type:</strong> ${hazardType}</p>
                            <p><strong>Address:</strong> ${result.address.freeformAddress}</p>
                    `;
                    
                    if (landmark) {
                        popupContent += `<p><strong>Landmark:</strong> ${landmark}</p>`;
                    }
                    
                    popupContent += `</div>`;
                    
                    marker.bindPopup(popupContent);
                    
                    // Center map on location
                    map.setView([lat, lng], 15);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }

        // Show success message if there's a success parameter in URL
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            Swal.fire({
                icon: 'success',
                title: 'Feedback Submitted!',
                text: 'Thank you for your feedback.',
                confirmButtonColor: '#00473e',
                timer: 3000
            });
        <?php endif; ?>
    </script>
</body>
</html>