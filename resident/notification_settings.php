<?php 
session_start();
include 'sidebar.php';

$settings_updated = false;
$settings = [
    'ticket_updates' => 1,
    'infrastructure_alerts' => 1,
    'service_disruptions' => 1,
    'maintenance_notices' => 1,
    'billing_notifications' => 0,
    'weekly_digest' => 0
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['ticket_updates'] = isset($_POST['ticket_updates']) ? 1 : 0;
    $settings['infrastructure_alerts'] = isset($_POST['infrastructure_alerts']) ? 1 : 0;
    $settings['service_disruptions'] = isset($_POST['service_disruptions']) ? 1 : 0;
    $settings['maintenance_notices'] = isset($_POST['maintenance_notices']) ? 1 : 0;
    $settings['billing_notifications'] = isset($_POST['billing_notifications']) ? 1 : 0;
    $settings['weekly_digest'] = isset($_POST['weekly_digest']) ? 1 : 0;
    
    $_SESSION['notification_settings'] = $settings;
    $settings_updated = true;
} elseif (isset($_SESSION['notification_settings'])) {
    $settings = $_SESSION['notification_settings'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Settings - Resident Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                        'lgu-highlight': '#faae2b',
                        'lgu-secondary': '#ffa8ba',
                        'lgu-tertiary': '#fa5246'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-lgu-bg font-poppins">
    <div class="ml-0 lg:ml-64 p-6">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-4xl font-bold text-lgu-headline mb-2">
                    <i class="fas fa-bell mr-3"></i>Notification Settings
                </h1>
                <p class="text-lgu-paragraph">Manage how you receive notifications and updates.</p>
            </div>

            <?php if ($settings_updated): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Settings Updated',
                    text: 'Your notification preferences have been saved.',
                    confirmButtonColor: '#faae2b'
                });
            </script>
            <?php endif; ?>

            <!-- Notification Settings Form -->
            <form method="POST" class="space-y-6">
                <!-- Notification Types -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-cog mr-2 text-lgu-highlight"></i>Notification Types
                    </h2>
                    <div class="space-y-4">
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="ticket_updates" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['ticket_updates'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Support Ticket Updates</p>
                                <p class="text-sm text-lgu-paragraph">Get notified when your support tickets are updated</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="infrastructure_alerts" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['infrastructure_alerts'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Infrastructure Alerts</p>
                                <p class="text-sm text-lgu-paragraph">Be informed about infrastructure issues and updates</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="service_disruptions" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['service_disruptions'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Service Disruptions</p>
                                <p class="text-sm text-lgu-paragraph">Urgent notifications about service outages</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="maintenance_notices" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['maintenance_notices'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Maintenance Notices</p>
                                <p class="text-sm text-lgu-paragraph">Scheduled maintenance and system updates</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="billing_notifications" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['billing_notifications'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Billing Notifications</p>
                                <p class="text-sm text-lgu-paragraph">Invoices and payment reminders</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg hover:border-lgu-highlight cursor-pointer">
                            <input type="checkbox" name="weekly_digest" class="w-5 h-5 text-lgu-highlight rounded" <?php echo $settings['weekly_digest'] ? 'checked' : ''; ?>>
                            <div class="ml-3">
                                <p class="font-semibold text-lgu-headline">Weekly Digest</p>
                                <p class="text-sm text-lgu-paragraph">Weekly summary of activities and updates</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-lgu-button text-lgu-button-text px-6 py-3 rounded-lg font-semibold hover:bg-yellow-500 transition">
                        <i class="fas fa-save mr-2"></i>Save Settings
                    </button>
                    <button type="reset" class="flex-1 bg-lgu-stroke text-white px-6 py-3 rounded-lg font-semibold hover:bg-lgu-headline transition">
                        <i class="fas fa-redo mr-2"></i>Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
