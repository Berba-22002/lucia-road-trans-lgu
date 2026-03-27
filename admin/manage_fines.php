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

$search = $_GET['search'] ?? '';

// Fetch unique categories for dropdown
try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM violations ORDER BY category");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Categories query error: " . $e->getMessage());
    $categories = [];
}

// Handle fine update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_fine') {
    $violation_id = intval($_POST['violation_id'] ?? 0);
    $offense_count = intval($_POST['offense_count'] ?? 0);
    $fine_amount = floatval($_POST['fine_amount'] ?? 0);
    
    if ($violation_id > 0 && $offense_count > 0) {
        try {
            $columns = ['first_offense', 'second_offense', 'third_offense'];
            $column = $columns[min($offense_count - 1, 2)];
            
            $sql = "UPDATE violations SET " . $column . " = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fine_amount, $violation_id]);
            
            $_SESSION['success_message'] = 'Fine amount updated successfully!';
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Error updating fine: ' . $e->getMessage();
            error_log("Update error: " . $e->getMessage());
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . ($search ? '?search=' . urlencode($search) : ''));
    exit();
}

// Fetch violations with their fines
try {
    $where = $search ? "WHERE category = ?" : "";
    $params = $search ? [$search] : [];
    
    $stmt = $pdo->prepare("
        SELECT id, violation_name, category, first_offense, second_offense, third_offense
        FROM violations
        $where
        ORDER BY category, violation_name
    ");
    $stmt->execute($params);
    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Manage fines query error: " . $e->getMessage());
    $violations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage Fines - RTIM Admin</title>
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
<body class="bg-lgu-bg min-h-screen font-poppins">
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="lg:ml-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-40 bg-white shadow-md border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 gap-4">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <button id="mobile-sidebar-toggle" class="lg:hidden text-lgu-headline flex-shrink-0">
                        <i class="fa fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-bold text-lgu-headline truncate">Manage Fines</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
            <?php if (isset($_SESSION['success_message'])): ?>
                <script>
                    Swal.fire({
                        title: 'Success!',
                        text: '<?php echo htmlspecialchars($_SESSION['success_message']); ?>',
                        icon: 'success',
                        confirmButtonColor: '#faae2b',
                        timer: 3000
                    });
                </script>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <script>
                    Swal.fire({
                        title: 'Error!',
                        text: '<?php echo htmlspecialchars($_SESSION['error_message']); ?>',
                        icon: 'error',
                        confirmButtonColor: '#faae2b'
                    });
                </script>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <form method="GET" class="flex gap-2">
                    <select name="search" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $search === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-4 py-2 bg-lgu-button text-lgu-button-text rounded-lg font-semibold hover:bg-yellow-500 transition">
                        <i class="fa fa-filter"></i> Filter
                    </button>
                    <a href="manage_fines.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-400 transition">
                        Reset
                    </a>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline">Violation Fines Management</h2>
                    <p class="text-sm text-lgu-paragraph mt-1">Edit fine amounts for different offense levels</p>
                </div>

                <?php if (empty($violations)): ?>
                    <div class="text-center py-12 text-lgu-paragraph">
                        <i class="fa fa-search text-5xl text-gray-300 mb-3 block"></i>
                        <p class="text-lg"><?php echo $search ? 'No violations found in category "' . htmlspecialchars($search) . '"' : 'No violations found'; ?></p>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($violations as $violation): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 class="text-lg font-bold text-lgu-headline"><?php echo htmlspecialchars($violation['violation_name']); ?></h3>
                                        <p class="text-xs text-lgu-paragraph uppercase tracking-wide"><?php echo htmlspecialchars($violation['category']); ?></p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <?php 
                                    $offenses = [
                                        1 => ['label' => '1st Offense', 'value' => $violation['first_offense']],
                                        2 => ['label' => '2nd Offense', 'value' => $violation['second_offense']],
                                        3 => ['label' => '3rd Offense', 'value' => $violation['third_offense']],
                                        4 => ['label' => '4th Offense', 'value' => $violation['third_offense']]
                                    ];
                                    foreach ($offenses as $count => $offense): 
                                    ?>
                                        <form method="POST" class="flex flex-col gap-2">
                                            <input type="hidden" name="action" value="update_fine">
                                            <input type="hidden" name="violation_id" value="<?php echo $violation['id']; ?>">
                                            <input type="hidden" name="offense_count" value="<?php echo $count; ?>">
                                            
                                            <label class="text-xs font-semibold text-lgu-headline uppercase">
                                                <?php echo $offense['label']; ?>
                                            </label>
                                            <div class="flex gap-2">
                                                <div class="flex-1 relative">
                                                    <span class="absolute left-3 top-2 text-lgu-button font-bold">₱</span>
                                                    <input type="number" name="fine_amount" value="<?php echo $offense['value']; ?>" step="0.01" min="0" required 
                                                           class="w-full pl-7 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-lgu-button">
                                                </div>
                                                <button type="submit" class="bg-lgu-button text-lgu-button-text px-3 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition text-sm">
                                                    <i class="fa fa-save"></i>
                                                </button>
                                            </div>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="bg-lgu-headline text-white py-6 mt-8 flex-shrink-0">
            <div class="container mx-auto px-4 text-center">
                <p class="text-xs sm:text-sm">&copy; <?php echo date('Y'); ?> RTIM</p>
            </div>
        </footer>
    </div>

    <script>
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('admin-sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay')?.classList.toggle('hidden');
        });
    </script>
</body>
</html>
