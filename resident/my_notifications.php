<?php 
session_start();
include 'sidebar.php';

$resident_id = $_SESSION['resident_id'] ?? null;

$notifications = [];

if ($resident_id) {
    $notifications = [
        [
            'id' => 1,
            'type' => 'ticket',
            'title' => 'Support Ticket Updated',
            'message' => 'Your ticket TKT-20260208-001 has been updated by support team.',
            'timestamp' => time() - 3600,
            'read' => 0,
            'icon' => 'fa-ticket-alt',
            'color' => 'text-lgu-highlight'
        ],
        [
            'id' => 2,
            'type' => 'infrastructure',
            'title' => 'Infrastructure Alert',
            'message' => 'Road maintenance scheduled on Lucia Road from 2-5 PM today.',
            'timestamp' => time() - 7200,
            'read' => 0,
            'icon' => 'fa-exclamation-circle',
            'color' => 'text-lgu-secondary'
        ],
        [
            'id' => 3,
            'type' => 'maintenance',
            'title' => 'System Maintenance',
            'message' => 'Scheduled maintenance on February 10, 2025 from 11 PM to 1 AM.',
            'timestamp' => time() - 86400,
            'read' => 1,
            'icon' => 'fa-wrench',
            'color' => 'text-yellow-500'
        ],
        [
            'id' => 4,
            'type' => 'service',
            'title' => 'Service Disruption',
            'message' => 'Water service disruption in your area due to pipe repair.',
            'timestamp' => time() - 172800,
            'read' => 1,
            'icon' => 'fa-water',
            'color' => 'text-lgu-tertiary'
        ]
    ];
}

usort($notifications, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

$filter = $_GET['filter'] ?? 'all';
$filtered_notifications = $notifications;

if ($filter !== 'all') {
    $filtered_notifications = array_filter($notifications, function($n) use ($filter) {
        return $n['type'] === $filter;
    });
}

function getTimeAgo($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    
    return date('M d, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications - Resident Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-4xl font-bold text-lgu-headline mb-2">
                    <i class="fas fa-bell mr-3"></i>My Notifications
                </h1>
                <p class="text-lgu-paragraph">View all your notifications and updates.</p>
            </div>

            <!-- Filter Tabs -->
            <div class="flex gap-2 mb-6 flex-wrap">
                <a href="?filter=all" class="px-4 py-2 rounded-lg font-semibold transition <?php echo $filter === 'all' ? 'bg-lgu-button text-lgu-button-text' : 'bg-white text-lgu-headline border-2 border-gray-200 hover:border-lgu-highlight'; ?>">
                    <i class="fas fa-inbox mr-2"></i>All
                </a>
                <a href="?filter=ticket" class="px-4 py-2 rounded-lg font-semibold transition <?php echo $filter === 'ticket' ? 'bg-lgu-button text-lgu-button-text' : 'bg-white text-lgu-headline border-2 border-gray-200 hover:border-lgu-highlight'; ?>">
                    <i class="fas fa-ticket-alt mr-2"></i>Tickets
                </a>
                <a href="?filter=infrastructure" class="px-4 py-2 rounded-lg font-semibold transition <?php echo $filter === 'infrastructure' ? 'bg-lgu-button text-lgu-button-text' : 'bg-white text-lgu-headline border-2 border-gray-200 hover:border-lgu-highlight'; ?>">
                    <i class="fas fa-exclamation-circle mr-2"></i>Infrastructure
                </a>
                <a href="?filter=service" class="px-4 py-2 rounded-lg font-semibold transition <?php echo $filter === 'service' ? 'bg-lgu-button text-lgu-button-text' : 'bg-white text-lgu-headline border-2 border-gray-200 hover:border-lgu-highlight'; ?>">
                    <i class="fas fa-water mr-2"></i>Service
                </a>
                <a href="?filter=maintenance" class="px-4 py-2 rounded-lg font-semibold transition <?php echo $filter === 'maintenance' ? 'bg-lgu-button text-lgu-button-text' : 'bg-white text-lgu-headline border-2 border-gray-200 hover:border-lgu-highlight'; ?>">
                    <i class="fas fa-wrench mr-2"></i>Maintenance
                </a>
            </div>

            <!-- Notifications List -->
            <div class="space-y-3">
                <?php if (empty($filtered_notifications)): ?>
                <div class="bg-white rounded-lg shadow-md p-8 text-center">
                    <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                    <p class="text-lgu-paragraph">No notifications found.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($filtered_notifications as $notif): ?>
                    <div class="bg-white rounded-lg shadow-md p-4 border-l-4 <?php echo $notif['read'] ? 'border-gray-300 opacity-75' : 'border-lgu-highlight'; ?>">
                        <div class="flex items-start gap-4">
                            <div class="<?php echo $notif['color']; ?> text-2xl mt-1">
                                <i class="fas <?php echo $notif['icon']; ?>"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <h3 class="font-semibold text-lgu-headline"><?php echo $notif['title']; ?></h3>
                                    <?php if (!$notif['read']): ?>
                                    <span class="inline-block bg-lgu-highlight text-lgu-button-text text-xs px-2 py-1 rounded-full">New</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-lgu-paragraph mb-2"><?php echo $notif['message']; ?></p>
                                <p class="text-xs text-gray-500 time-ago" data-timestamp="<?php echo $notif['timestamp']; ?>"><?php echo getTimeAgo($notif['timestamp']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Clear All Button -->
            <?php if (!empty($filtered_notifications)): ?>
            <div class="mt-6 text-center">
                <button class="bg-lgu-stroke text-white px-6 py-2 rounded-lg font-semibold hover:bg-lgu-headline transition">
                    <i class="fas fa-trash mr-2"></i>Clear All
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateTimestamps() {
            document.querySelectorAll('.time-ago').forEach(el => {
                const timestamp = parseInt(el.dataset.timestamp);
                const diff = Math.floor((Date.now() / 1000) - timestamp);
                
                let text;
                if (diff < 60) text = 'Just now';
                else if (diff < 3600) text = Math.floor(diff / 60) + ' min ago';
                else if (diff < 86400) text = Math.floor(diff / 3600) + ' hours ago';
                else if (diff < 604800) text = Math.floor(diff / 86400) + ' days ago';
                else text = new Date(timestamp * 1000).toLocaleDateString();
                
                el.textContent = text;
            });
        }
        
        updateTimestamps();
        setInterval(updateTimestamps, 60000);
    </script>
</body>
</html>
