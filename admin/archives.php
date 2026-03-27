<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Only allow admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Check if access is already verified and still valid (30 minutes)
if (isset($_SESSION['archive_access_verified']) && isset($_SESSION['archive_access_time']) && 
    (time() - $_SESSION['archive_access_time']) < 1800) {
    // Access still valid, skip OTP
} else {
    // Clear expired access
    unset($_SESSION['archive_access_verified'], $_SESSION['archive_access_time']);
}

// Require OTP verification if not already verified
if (!isset($_SESSION['archive_access_verified'])) {
    
    // Get admin email
    $database = new Database();
    $pdo = $database->getConnection();
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin_email = $stmt->fetchColumn();
    
    // Only send OTP if not exists or expired (don't send on refresh)
    $send_otp = false;
    if (!isset($_SESSION['archive_otp']) || !isset($_SESSION['otp_generated_time'])) {
        $send_otp = true;
    } elseif (time() - $_SESSION['otp_generated_time'] > 300) {
        $send_otp = true;
    }
    
    if ($send_otp) {
        $_SESSION['archive_otp'] = sprintf('%06d', mt_rand(100000, 999999));
        $_SESSION['otp_generated_time'] = time();
        
        // Send OTP via PHPMailer
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'lgu1.infrastructureutilities@gmail.com';
            $mail->Password = 'kpyv rwvp tmxw zvoq';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Recipients
            $mail->setFrom('lgu1.infrastructureutilities@gmail.com', 'RTIM System');
            $mail->addAddress($admin_email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Archive Access OTP - RTIM Admin';
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: white;'>
                <div style='background: #00473e; padding: 30px; text-align: center;'>
                    <div style='width: 80px; height: 80px; background: #faae2b; border-radius: 50%; margin: 0 auto 15px; line-height: 80px; text-align: center;'>
                        <span style='color: #00473e; font-size: 24px; font-weight: bold;'>LGU</span>
                    </div>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>Archive Access</h1>
                </div>
                <div style='padding: 40px 30px;'>
                    <h2 style='color: #00473e; margin: 0 0 20px 0; font-size: 22px; text-align: center;'>Archive Verification</h2>
                    <p style='color: #475d5b; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0; text-align: center;'>Your OTP code for archive access:</p>
                    <div style='background: #f2f7f5; border: 3px solid #faae2b; border-radius: 10px; padding: 30px; text-align: center; margin: 30px 0;'>
                        <h1 style='color: #faae2b; font-size: 36px; font-weight: bold; margin: 0; letter-spacing: 5px;'>{$_SESSION['archive_otp']}</h1>
                    </div>
                    <div style='background: #fff3cd; border: 1px solid #faae2b; border-radius: 5px; padding: 15px; margin: 20px 0; text-align: center;'>
                        <p style='color: #856404; margin: 0; font-size: 14px;'><strong>Code expires in 5 minutes</strong></p>
                    </div>
                </div>
            </div>";
            
            $mail->send();
        } catch (Exception $e) {
            error_log('Failed to send archive OTP: ' . $e->getMessage());
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
        $entered_otp = $_POST['otp_code'];
        
        if ($entered_otp === $_SESSION['archive_otp']) {
            $_SESSION['archive_access_verified'] = true;
            $_SESSION['archive_access_time'] = time();
            unset($_SESSION['archive_otp'], $_SESSION['otp_generated_time']);
            header('Location: view_archives.php');
            exit();
        } else {
            $otp_error = 'Invalid OTP code. Please try again.';
        }
    }
    
    if (!isset($_SESSION['archive_access_verified'])) {
        // Show enhanced OTP modal
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Archive Access - RTIM Admin</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <style>
                :root {
                    --lgu-bg: #f2f7f5;
                    --lgu-headline: #00473e;
                    --lgu-paragraph: #475d5b;
                    --lgu-button: #faae2b;
                    --lgu-button-text: #00473e;
                    --lgu-stroke: #00332c;
                }
                .swal2-popup {
                    font-family: 'Poppins', sans-serif !important;
                    border-radius: 20px !important;
                    box-shadow: 0 20px 60px rgba(0, 71, 62, 0.2) !important;
                }
                .swal2-title {
                    color: var(--lgu-headline) !important;
                    font-weight: 700 !important;
                }
                .swal2-html-container {
                    color: var(--lgu-paragraph) !important;
                }
                .swal2-input {
                    font-family: 'Poppins', sans-serif !important;
                    border: 2px solid #e9ecef !important;
                    border-radius: 10px !important;
                    padding: 15px !important;
                    font-size: 1.5rem !important;
                    font-weight: 600 !important;
                    color: var(--lgu-headline) !important;
                    text-align: center !important;
                    letter-spacing: 0.5em !important;
                }
                .swal2-input:focus {
                    border-color: var(--lgu-button) !important;
                    box-shadow: 0 0 0 0.2rem rgba(250, 174, 43, 0.25) !important;
                }
                .swal2-confirm {
                    background: linear-gradient(135deg, var(--lgu-button) 0%, var(--lgu-button) 100%) !important;
                    color: var(--lgu-button-text) !important;
                    border: none !important;
                    font-weight: 700 !important;
                    font-family: 'Poppins', sans-serif !important;
                    border-radius: 10px !important;
                    padding: 12px 30px !important;
                }
                .otp-logo {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    border: 2px solid var(--lgu-button);
                    margin: 0 auto 15px;
                    display: block;
                    object-fit: cover;
                }
            </style>
        </head>
        <body class="bg-gray-100 font-poppins">
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        title: 'Archive Access Verification',
                        html: `
                            <img src="logo.jpg" alt="LGU Logo" class="otp-logo">
                            <p>We've sent a 6-digit OTP to <strong><?php echo htmlspecialchars($admin_email); ?></strong></p>
                            <p style="color: var(--lgu-paragraph); font-size: 0.9em; margin-top: 10px;">Code expires in 5 minutes</p>
                            <input type="text" id="otpInput" class="swal2-input" placeholder="Enter 6-digit OTP" maxlength="6">
                        `,
                        showCancelButton: true,
                        confirmButtonText: '<i class="fa fa-unlock"></i> Access Archives',
                        cancelButtonText: '<i class="fa fa-arrow-left"></i> Back to Reports',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        preConfirm: () => {
                            const otp = document.getElementById('otpInput').value;
                            if (!otp || otp.length !== 6) {
                                Swal.showValidationMessage('Please enter a valid 6-digit OTP');
                                return false;
                            }
                            return otp;
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Submit OTP
                            const form = document.createElement('form');
                            form.method = 'POST';
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'otp_code';
                            input.value = result.value;
                            form.appendChild(input);
                            document.body.appendChild(form);
                            form.submit();
                        } else {
                            window.location.href = 'incoming_reports.php';
                        }
                    });
                    
                    <?php if (isset($otp_error)): ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid OTP',
                        text: '<?php echo htmlspecialchars($otp_error); ?>',
                        confirmButtonColor: '#fa5246'
                    }).then(() => {
                        location.reload();
                    });
                    <?php endif; ?>
                });
            </script>
        </body>
        </html>
        <?php
        exit();
    }
}

$database = new Database();
$pdo = $database->getConnection();

$archived_reports = [];
$total_archived = 0;

try {
    // Get all archived reports with reporter info
    $stmt = $pdo->prepare("
        SELECT 
            r.id AS report_id,
            r.user_id,
            r.hazard_type,
            r.address,
            r.description,
            r.status,
            r.validation_status,
            r.created_at,
            r.created_at as archived_at,
            r.image_path,
            r.contact_number AS phone,
            u.fullname AS reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status = 'archived'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $archived_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_archived = count($archived_reports);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Error fetching archived reports: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Reports - RTIM Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
        * { font-family: 'Poppins', sans-serif; }
        html, body { width: 100%; height: 100%; overflow-x: hidden; }
        .table-row-hover:hover { background-color: #f9fafb; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">

    <!-- Include Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Archived Reports</h1>
                        <p class="text-xs sm:text-sm text-lgu-paragraph truncate">Previously validated and archived hazard reports</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-500 to-gray-700 text-white px-3 sm:px-4 py-2 rounded-lg font-bold text-center shadow-lg flex-shrink-0">
                    <div class="text-xl sm:text-2xl"><?php echo $total_archived; ?></div>
                    <div class="text-xs">Archived</div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-4 sm:p-6 overflow-y-auto">
            
            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fa fa-exclamation-circle mr-3"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Filter & Search Section -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                    <div class="flex-1 flex gap-2 w-full sm:w-auto">
                        <input type="text" id="searchInput" placeholder="Search archived reports..." 
                               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-lgu-button text-sm">
                        <button class="bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition flex-shrink-0">
                            <i class="fa fa-search"></i>
                        </button>
                    </div>
                    <a href="incoming_reports.php" class="text-lgu-paragraph hover:text-lgu-headline transition whitespace-nowrap">
                        <i class="fa fa-arrow-left mr-1"></i> Back to Reports
                    </a>
                </div>
            </div>

            <!-- Archived Reports Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <?php if ($total_archived === 0): ?>
                    <div class="p-12 text-center">
                        <i class="fa fa-archive text-6xl text-gray-300 mb-4 block"></i>
                        <p class="text-gray-500 text-xl font-semibold mb-2">No Archived Reports</p>
                        <p class="text-gray-400 text-sm">No reports have been archived yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-gray-600 to-gray-800 text-white">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">ID</th>
                                    <th class="px-4 py-3 text-left font-semibold">Reporter</th>
                                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                                    <th class="px-4 py-3 text-left font-semibold">Location</th>
                                    <th class="px-4 py-3 text-left font-semibold">Archived Date</th>
                                    <th class="px-4 py-3 text-center font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($archived_reports as $report): ?>
                                    <tr class="table-row-hover transition">
                                        <td class="px-4 py-3 font-bold text-lgu-headline">
                                            #<?php echo htmlspecialchars($report['report_id']); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>
                                                <p class="font-medium text-lgu-headline"><?php echo htmlspecialchars($report['reporter_name'] ?? 'Unknown'); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($report['phone'] ?? 'N/A'); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-block bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <?php echo htmlspecialchars(ucfirst($report['hazard_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="text-xs text-lgu-paragraph"><i class="fa fa-map-marker-alt mr-1 text-lgu-tertiary"></i><?php echo htmlspecialchars(substr($report['address'], 0, 40)) . (strlen($report['address']) > 40 ? '...' : ''); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            <i class="fa fa-calendar mr-1"></i>
                                            <?php echo date('M d, Y', strtotime($report['archived_at'])); ?>
                                            <br>
                                            <i class="fa fa-clock mr-1"></i>
                                            <?php echo date('h:i A', strtotime($report['archived_at'])); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-2 justify-center">
                                                <button onclick="unarchiveReport(<?php echo (int)$report['report_id']; ?>)" 
                                                        class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1.5 rounded text-xs font-bold transition flex items-center gap-1"
                                                        title="Unarchive Report">
                                                    <i class="fa fa-undo text-xs"></i>
                                                    <span class="hidden lg:inline">Unarchive</span>
                                                </button>
                                                <a href="view_archives.php?id=<?php echo (int)$report['report_id']; ?>" 
                                                   class="bg-lgu-headline hover:bg-lgu-stroke text-white px-2 py-1.5 rounded text-xs font-bold transition flex items-center gap-1"
                                                   title="View Details">
                                                    <i class="fa fa-eye text-xs"></i>
                                                    <span class="hidden lg:inline">View</span>
                                                </a>
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

        <!-- Footer -->
        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> RTIM - Road and Transportation Infrastructure Monitoring</p>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.getElementById('mobile-sidebar-toggle');
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
                });
            }

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const isVisible = text.includes(searchTerm);
                        row.style.display = isVisible ? '' : 'none';
                    });
                });

                // Clear search on escape key
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        this.dispatchEvent(new Event('keyup'));
                    }
                });
            }
        });

        // Unarchive function
        async function unarchiveReport(reportId) {
            const result = await Swal.fire({
                title: 'Unarchive Report #' + reportId,
                text: 'This will restore the report to active status.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fa fa-undo"></i> Unarchive',
                cancelButtonText: '<i class="fa fa-times"></i> Cancel',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280'
            });

            if (result.isConfirmed) {
                try {
                    const response = await fetch('process_unarchive.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `report_id=${reportId}`
                    });

                    const result = await response.json();

                    if (result.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Report Unarchived!',
                            text: 'The report has been restored to active status.',
                            confirmButtonColor: '#10b981'
                        });
                        const row = document.querySelector(`tr[data-report-id="${reportId}"]`);
                        if (row) {
                            row.remove();
                        }
                        location.reload();
                    } else {
                        throw new Error(result.message || 'Failed to unarchive report');
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'An error occurred while unarchiving the report',
                        confirmButtonColor: '#ef4444'
                    });
                }
            }
        }
    </script>

</body>
</html>