<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$users = [];
$total_users = 0;

try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_users = count($users);
} catch (PDOException $e) {
    $error = 'Failed to fetch users: ' . $e->getMessage();
}

$role_counts = ['admin' => 0, 'inspector' => 0, 'maintenance' => 0, 'traffic' => 0, 'treasurer' => 0, 'resident' => 0];
foreach ($users as $user) {
    $role = $user['role'] ?? 'resident';
    if (isset($role_counts[$role])) {
        $role_counts[$role]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="lg:ml-64 flex flex-col min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-lgu-headline">User Management</h1>
                        <p class="text-sm text-lgu-paragraph">View all system users</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-y-auto">
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-lgu-button">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Total Users</p>
                    <p class="text-2xl font-bold text-lgu-headline mt-2"><?php echo $total_users; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Admins</p>
                    <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo $role_counts['admin']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Inspectors</p>
                    <p class="text-2xl font-bold text-purple-600 mt-2"><?php echo $role_counts['inspector']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Maintenance</p>
                    <p class="text-2xl font-bold text-orange-600 mt-2"><?php echo $role_counts['maintenance']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Traffic</p>
                    <p class="text-2xl font-bold text-red-600 mt-2"><?php echo $role_counts['traffic']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                    <p class="text-xs font-semibold text-lgu-paragraph uppercase">Residents</p>
                    <p class="text-2xl font-bold text-green-600 mt-2"><?php echo $role_counts['resident']; ?></p>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Name</th>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Email</th>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Phone</th>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Role</th>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Status</th>
                                <th class="px-6 py-3 font-semibold text-lgu-paragraph">Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-lgu-paragraph">
                                    <i class="fa fa-inbox text-3xl text-gray-300 mb-2 block"></i>
                                    No users found
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-semibold text-lgu-headline">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 bg-lgu-button rounded-full flex items-center justify-center text-lgu-button-text font-bold text-xs">
                                                <?php echo strtoupper(substr($user['fullname'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <?php echo htmlspecialchars($user['fullname'] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-lgu-paragraph"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-lgu-paragraph"><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
                                            <?php
                                            $role = $user['role'] ?? 'resident';
                                            $role_colors = [
                                                'admin' => 'bg-blue-100 text-blue-700',
                                                'inspector' => 'bg-purple-100 text-purple-700',
                                                'maintenance' => 'bg-orange-100 text-orange-700',
                                                'traffic' => 'bg-red-100 text-red-700',
                                                'treasurer' => 'bg-indigo-100 text-indigo-700',
                                                'resident' => 'bg-green-100 text-green-700'
                                            ];
                                            echo $role_colors[$role] ?? 'bg-gray-100 text-gray-700';
                                            ?>">
                                            <?php echo ucfirst($role); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php
                                        $status = $user['status'] ?? 'active';
                                        $status_class = $status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $status_class; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-lgu-paragraph text-xs">
                                        <?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8">
            <div class="container mx-auto px-4 text-center">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> Road and Traffic Infrastructure Management System</p>
            </div>
        </footer>
    </div>

    <script>
        document.getElementById('mobile-sidebar-toggle').addEventListener('click', () => {
            const sidebar = document.getElementById('admin-sidebar');
            sidebar.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
