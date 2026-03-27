<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    $where = "WHERE a.status = 'published'";
    $params = [];
    
    if ($search) {
        $where .= " AND (a.title LIKE ? OR a.content LIKE ?)";
        $params = ["%$search%", "%$search%"];
    }
    
    if ($priority && in_array($priority, ['low', 'medium', 'high'])) {
        $where .= " AND a.priority = ?";
        $params[] = $priority;
    }
    
    $count_sql = "SELECT COUNT(*) as total FROM advisories a $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch()['total'];
    $total_pages = ceil($total / $per_page);
    
    $sql = "SELECT a.*, u.fullname FROM advisories a
            JOIN users u ON a.admin_id = u.id
            $where
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $advisories = $stmt->fetchAll();
} catch (PDOException $e) {
    $advisories = [];
    $total_pages = 1;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Advisories</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        poppins: ['Poppins', 'sans-serif']
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
        body { font-family: 'Poppins', sans-serif; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { max-width: 90%; max-height: 90%; }
        .modal-content img { width: 100%; height: auto; object-fit: contain; }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 28px; cursor: pointer; }
        .priority-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .priority-high { background-color: #fee2e2; color: #991b1b; }
        .priority-medium { background-color: #fef3c7; color: #92400e; }
        .priority-low { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <div id="sidebar-overlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"></div>
    <?php include 'sidebar.php'; ?>
    <button id="mobile-sidebar-toggle" class="hidden lg:hidden fixed top-4 left-4 z-50 bg-lgu-headline text-white p-2 rounded">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
    </button>
    <div class="lg:ml-64 min-h-screen">
        <div class="max-w-6xl mx-auto px-4 py-4">
            <div class="bg-white rounded-lg shadow-sm p-3 md:p-4 mb-4">
                <h1 class="text-xl md:text-2xl font-bold text-lgu-headline mb-3">📢 Public Advisories</h1>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3">
                    <input type="text" id="searchInput" placeholder="Search advisories..." class="col-span-1 md:col-span-2 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-lgu-button">
                    <select id="priorityFilter" class="px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-lgu-button">
                        <option value="">All Priorities</option>
                        <option value="high">High Priority</option>
                        <option value="medium">Medium Priority</option>
                        <option value="low">Low Priority</option>
                    </select>
                </div>
            </div>

            <div id="advisories-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2">
                <?php if (empty($advisories)): ?>
                    <div class="col-span-full text-center py-12 bg-white rounded-lg">
                        <p class="text-lgu-paragraph">No advisories found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($advisories as $advisory): ?>
                        <div class="bg-white rounded shadow-sm hover:shadow-md transition-shadow overflow-hidden cursor-pointer" onclick="openModal(document.querySelector('[data-id=<?php echo $advisory['id']; ?>]'))">
                            <?php if ($advisory['image_path']): ?>
                                <img src="../uploads/advisories/<?php echo htmlspecialchars($advisory['image_path']); ?>" alt="Advisory image" data-id="<?php echo $advisory['id']; ?>" class="w-full h-24 object-cover">
                            <?php else: ?>
                                <div class="w-full h-24 bg-gray-200 flex items-center justify-center">
                                    <i class="fas fa-image text-gray-400 text-xl"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="p-2">
                                <div class="font-bold text-lgu-headline text-xs line-clamp-2 mb-1"><?php echo htmlspecialchars($advisory['title']); ?></div>
                                <div class="flex items-center gap-1 mb-1">
                                    <div class="w-5 h-5 rounded-full bg-lgu-headline text-white flex items-center justify-center font-bold text-xs flex-shrink-0">
                                        <?php echo strtoupper(substr($advisory['fullname'], 0, 1)); ?>
                                    </div>
                                    <div class="text-xs text-lgu-paragraph flex-1 truncate"><?php echo htmlspecialchars($advisory['fullname']); ?></div>
                                </div>
                                <div class="text-xs text-lgu-paragraph mb-1"><?php echo date('M d, Y', strtotime($advisory['created_at'])); ?></div>
                                <?php if (isset($advisory['priority'])): ?>
                                    <span class="priority-badge priority-<?php echo htmlspecialchars($advisory['priority']); ?>"><?php echo ucfirst($advisory['priority']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center items-center gap-1 mt-4 mb-4">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50">First</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50">Prev</a>
                    <?php endif; ?>
                    
                    <span class="text-xs text-lgu-paragraph">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50">Next</a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50">Last</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="imageModal" class="modal" onclick="closeModal()">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <div class="modal-content" onclick="event.stopPropagation()">
            <img id="modalImage" src="" alt="Full view">
        </div>
    </div>

    <script>
        function openModal(img) {
            const src = img.src || img.getAttribute('data-src');
            if (src) {
                document.getElementById('modalImage').src = src;
                document.getElementById('imageModal').classList.add('active');
            }
        }
        function closeModal() {
            document.getElementById('imageModal').classList.remove('active');
        }
        
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = this.value;
                const priority = document.getElementById('priorityFilter').value;
                const params = new URLSearchParams();
                if (search) params.append('search', search);
                if (priority) params.append('priority', priority);
                window.location.href = '?' + params.toString();
            }, 500);
        });
        
        document.getElementById('priorityFilter').addEventListener('change', function() {
            const search = document.getElementById('searchInput').value;
            const priority = this.value;
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (priority) params.append('priority', priority);
            window.location.href = '?' + params.toString();
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('mobile-sidebar-toggle');
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    overlay.classList.toggle('hidden');
                });
                overlay.addEventListener('click', () => {
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.add('hidden');
                });
            }
            
            const searchInput = document.getElementById('searchInput');
            const priorityFilter = document.getElementById('priorityFilter');
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('search')) searchInput.value = urlParams.get('search');
            if (urlParams.has('priority')) priorityFilter.value = urlParams.get('priority');
        });
    </script>
</body>
</html>
