<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is a resident
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Handle consent updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $marketing_consent = isset($_POST['marketing_consent']) ? 1 : 0;
    
    try {
        // Update user consent
        $stmt = $pdo->prepare("UPDATE users SET marketing_consent = ?, last_privacy_update = NOW() WHERE id = ?");
        $stmt->execute([$marketing_consent, $user_id]);
        
        // Log consent change
        $consent_stmt = $pdo->prepare("INSERT INTO consent_logs (user_id, consent_type, consent_given, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $consent_stmt->execute([
            $user_id, 
            'marketing', 
            $marketing_consent, 
            $_SERVER['REMOTE_ADDR'] ?? '', 
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $success_message = "Privacy settings updated successfully!";
    } catch (PDOException $e) {
        $error_message = "Error updating settings. Please try again.";
    }
}

// Get current user consent data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error loading user data.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Settings - LGU Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                        'lgu-highlight': '#faae2b',
                        'lgu-tertiary': '#fa5246'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-lgu-bg min-h-screen">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <?php include 'sidebar.php'; ?>

    <div class="lg:ml-64 min-h-screen">
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center space-x-3 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline hover:text-lgu-highlight flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-lg lg:text-xl font-bold text-lgu-headline truncate">Privacy Settings</h1>
                        <p class="text-xs lg:text-sm text-lgu-paragraph truncate">Manage your data privacy</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <?php if (isset($error_message)): ?>

            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3"></i>
                    <p class="text-red-700"><?php echo $error_message; ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Current Consent Status -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-bold text-lgu-headline mb-4">
                    <i class="fas fa-shield-check mr-2"></i>Current Privacy Consent Status
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="border rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold text-lgu-headline">Data Processing</h4>
                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">Required</span>
                        </div>
                        <p class="text-sm text-lgu-paragraph">Consent given on: <?php echo date('M d, Y', strtotime($user['privacy_consent_date'])); ?></p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold text-lgu-headline">Data Retention</h4>
                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">Required</span>
                        </div>
                        <p class="text-sm text-lgu-paragraph">Automatically consented during registration</p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold text-lgu-headline">Marketing Communications</h4>
                            <span class="<?php echo $user['marketing_consent'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?> px-2 py-1 rounded text-sm">
                                <?php echo $user['marketing_consent'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                        <p class="text-sm text-lgu-paragraph">Optional service updates and notifications</p>
                    </div>
                </div>
            </div>

            <!-- Update Consent Form -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-bold text-lgu-headline mb-4">
                    <i class="fas fa-cog mr-2"></i>Update Privacy Preferences
                </h3>
                <form method="POST">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 border rounded-lg">
                            <div>
                                <h4 class="font-semibold text-lgu-headline">Marketing Communications</h4>
                                <p class="text-sm text-lgu-paragraph">Receive service updates, maintenance notifications, and system announcements</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="marketing_consent" class="sr-only peer" <?php echo $user['marketing_consent'] ? 'checked' : ''; ?>>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-yellow-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-lgu-button"></div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <button type="submit" class="bg-lgu-button text-lgu-button-text px-6 py-2 rounded hover:bg-yellow-500 font-semibold">
                            <i class="fas fa-save mr-2"></i>Update Preferences
                        </button>
                    </div>
                </form>
            </div>

            <!-- Data Rights Information -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold text-lgu-headline mb-4">
                    <i class="fas fa-info-circle mr-2"></i>Your Data Rights
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border rounded-lg p-4">
                        <h4 class="font-semibold text-lgu-headline mb-2">
                            <i class="fas fa-eye text-blue-500 mr-2"></i>Right to Access
                        </h4>
                        <p class="text-sm text-lgu-paragraph">You can request a copy of all personal data we hold about you.</p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <h4 class="font-semibold text-lgu-headline mb-2">
                            <i class="fas fa-edit text-green-500 mr-2"></i>Right to Rectification
                        </h4>
                        <p class="text-sm text-lgu-paragraph">You can request correction of inaccurate personal data.</p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <h4 class="font-semibold text-lgu-headline mb-2">
                            <i class="fas fa-trash text-red-500 mr-2"></i>Right to Erasure
                        </h4>
                        <p class="text-sm text-lgu-paragraph">You can request deletion of your personal data under certain conditions.</p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <h4 class="font-semibold text-lgu-headline mb-2">
                            <i class="fas fa-download text-purple-500 mr-2"></i>Right to Portability
                        </h4>
                        <p class="text-sm text-lgu-paragraph">You can request your data in a structured, machine-readable format.</p>
                    </div>
                </div>
                
                <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <h4 class="font-semibold text-lgu-headline mb-2">
                        <i class="fas fa-envelope text-lgu-button mr-2"></i>Contact Data Protection Officer
                    </h4>
                    <p class="text-sm text-lgu-paragraph">
                        For any privacy-related concerns or to exercise your rights, contact our DPO at:
                        <strong>dpo@lgu-infrastructure.gov.ph</strong> or call <strong>0919-075-5101</strong>
                    </p>
                </div>
            </div>
        </main>
    </div>

    <script>
        <?php if (isset($success_message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo $success_message; ?>',
            confirmButtonColor: '#faae2b',
            confirmButtonText: 'OK'
        });
        <?php endif; ?>

        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.getElementById('mobile-sidebar-toggle');
            const sidebarClose = document.getElementById('sidebar-close');
            const overlay = document.getElementById('sidebar-overlay');

            function toggleSidebar() {
                if (sidebar) {
                    sidebar.classList.toggle('-translate-x-full');
                }
                if (overlay) {
                    overlay.classList.toggle('hidden');
                }
            }

            function closeSidebar() {
                if (sidebar) {
                    sidebar.classList.add('-translate-x-full');
                }
                if (overlay) {
                    overlay.classList.add('hidden');
                }
            }

            if (toggle) {
                toggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarClose) {
                sidebarClose.addEventListener('click', closeSidebar);
            }
            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }
        });
    </script>
</body>
</html>
