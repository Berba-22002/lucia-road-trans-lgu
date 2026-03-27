<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: ../../login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $status = $_POST['status'] ?? 'published';
    $media_name = null;

    if (empty($title) || empty($content)) {
        $error = 'Title and content are required!';
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM advisories WHERE title = :title AND content = :content");
            $check->execute([':title' => $title, ':content' => $content]);
            if ($check->fetch()) {
                $error = 'This advisory already exists!';
            } else {
                if (isset($_FILES['advisory_image']) && $_FILES['advisory_image']['error'] == UPLOAD_ERR_OK) {
                    $file = $_FILES['advisory_image'];
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                    if (!in_array($file_ext, $allowed_ext)) {
                        $error = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP';
                    } elseif ($file['size'] > 5 * 1024 * 1024) {
                        $error = 'File size exceeds 5MB limit';
                    } else {
                        $upload_dir = __DIR__ . '/../uploads/advisories/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $media_name = 'advisory_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_ext;
                        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $media_name)) {
                            $error = 'Failed to upload image';
                        }
                    }
                }

                if (empty($error)) {
                    $sql = "INSERT INTO advisories (admin_id, title, content, image_path, status) 
                            VALUES (:admin_id, :title, :content, :image_path, :status)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':admin_id' => $_SESSION['user_id'],
                        ':title' => $title,
                        ':content' => $content,
                        ':image_path' => $media_name,
                        ':status' => $status
                    ]);
                    $message = 'Advisory posted successfully!';
                    $_POST = [];
                }
            }
        } catch (PDOException $e) {
            $error = 'Error posting advisory: ' . $e->getMessage();
        }
    }
}

try {
    $sql = "SELECT a.*, u.fullname FROM advisories a
            JOIN users u ON a.admin_id = u.id
            ORDER BY a.created_at DESC";
    $advisories = $pdo->query($sql)->fetchAll();
} catch (PDOException $e) {
    $error = 'Error fetching advisories: ' . $e->getMessage();
    $advisories = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Advisories - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f2f7f5; }
        .main-content { margin-left: 256px; padding: 32px; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; padding: 24px; } }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #00473e 0%, #005a52 100%); padding: 40px 35px; border-radius: 12px; margin-bottom: 35px; box-shadow: 0 4px 15px rgba(0,71,62,0.15); display: flex; justify-content: space-between; align-items: center; }
        .header-content h1 { color: #faae2b; margin-bottom: 8px; font-size: 32px; font-weight: 700; }
        .header-content p { color: #e0e0e0; font-size: 15px; }
        .btn-create { background: #faae2b; color: #00473e; padding: 14px 32px; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 700; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(250,174,43,0.3); }
        .btn-create:hover { background: #f5a217; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(250,174,43,0.4); }
        .alert { padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 15px; border-left: 4px solid; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left-color: #dc3545; }
        .advisories-section h2 { color: #00473e; margin: 40px 0 28px; font-size: 24px; font-weight: 700; }
        .advisory-card { background: white; border-radius: 12px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; transition: all 0.3s ease; border: 1px solid #f0f0f0; }
        .advisory-card:hover { box-shadow: 0 8px 24px rgba(0,71,62,0.12); transform: translateY(-2px); }
        .advisory-header { padding: 28px 32px; border-bottom: 1px solid #f0f0f0; background: #fafafa; }
        .advisory-title { font-size: 20px; font-weight: 700; color: #00473e; margin-bottom: 12px; line-height: 1.4; }
        .advisory-meta { font-size: 14px; color: #666; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .status-badge { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; }
        .status-published { background: #d4edda; color: #155724; }
        .status-draft { background: #fff3cd; color: #856404; }
        .advisory-image-container { width: 100%; height: 500px; overflow: hidden; background: #000; position: relative; }
        .advisory-image { width: 100%; height: 100%; object-fit: contain; cursor: pointer; transition: transform 0.5s ease; }
        .advisory-image:hover { transform: scale(1.05); }
        .image-overlay { position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(0,0,0,0.7), transparent); padding: 20px; color: white; font-size: 14px; opacity: 0; transition: opacity 0.3s; }
        .advisory-image-container:hover .image-overlay { opacity: 1; }
        .advisory-content { padding: 32px; color: #475d5b; line-height: 1.8; font-size: 15px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-dialog { background: white; border-radius: 12px; max-width: 650px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { padding: 28px 32px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .modal-header h2 { color: #00473e; font-size: 22px; font-weight: 700; }
        .modal-close-btn { background: none; border: none; font-size: 28px; color: #999; cursor: pointer; transition: color 0.2s; }
        .modal-close-btn:hover { color: #00473e; }
        .modal-body { padding: 32px; }
        .form-group { margin-bottom: 24px; }
        label { display: block; margin-bottom: 10px; color: #00473e; font-weight: 700; font-size: 15px; }
        input[type="text"], textarea, select { width: 100%; padding: 14px 16px; border: 1.5px solid #ddd; border-radius: 8px; font-family: inherit; font-size: 15px; color: #00473e; transition: all 0.3s ease; }
        input[type="text"]:focus, textarea:focus, select:focus { outline: none; border-color: #faae2b; box-shadow: 0 0 0 4px rgba(250, 174, 43, 0.15); background: #fffbf5; }
        textarea { resize: vertical; min-height: 140px; }
        input[type="file"] { padding: 10px; }
        small { color: #666; font-size: 13px; display: block; margin-top: 8px; }
        .modal-footer { padding: 24px 32px; border-top: 1px solid #f0f0f0; display: flex; gap: 12px; justify-content: flex-end; background: #fafafa; }
        .btn-submit { background: #faae2b; color: #00473e; padding: 14px 36px; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 700; transition: all 0.3s ease; }
        .btn-submit:hover { background: #f5a217; transform: translateY(-2px); }
        .btn-cancel { background: #e8e8e8; color: #00473e; padding: 14px 36px; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 700; transition: all 0.3s ease; }
        .btn-cancel:hover { background: #d8d8d8; }
        .image-modal { background: rgba(0,0,0,0.95); }
        .image-modal-content { max-width: 90%; max-height: 90vh; display: flex; align-items: center; justify-content: center; }
        .image-modal-content img { max-width: 100%; max-height: 90vh; object-fit: contain; border-radius: 8px; }
        .image-modal-close { position: absolute; top: 25px; right: 35px; color: white; font-size: 40px; cursor: pointer; transition: transform 0.2s; z-index: 1001; }
        .image-modal-close:hover { transform: scale(1.2); }
        @media (max-width: 768px) {
            .header { flex-direction: column; gap: 20px; text-align: center; padding: 30px 20px; }
            .header-content h1 { font-size: 26px; }
            .btn-create { width: 100%; }
            .advisory-header { padding: 20px; }
            .advisory-title { font-size: 18px; }
            .advisory-content { padding: 20px; font-size: 14px; }
            .advisory-image-container { height: 300px; }
            .modal-dialog { width: 95%; max-width: 100%; }
            .modal-header, .modal-body, .modal-footer { padding: 20px; }
            .modal-footer { flex-direction: column; }
            .btn-submit, .btn-cancel { width: 100%; }
        }: pointer; font-size: 15px; font-weight: 700; transition: all 0.3s ease; }
        .btn-cancel:hover { background: #d8d8d8; }
        .image-modal { background: rgba(0,0,0,0.95); }
        .image-modal-content { max-width: 90%; max-height: 90%; }
        .image-modal-content img { width: 100%; height: auto; }
        .image-modal-close { position: absolute; top: 25px; right: 35px; color: white; font-size: 32px; cursor: pointer; transition: transform 0.2s; }
        .image-modal-close:hover { transform: scale(1.2); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="container">
            <div class="header">
                <div class="header-content">
                    <h1>📢 Public Advisories</h1>
                    <p>Post important announcements for residents</p>
                </div>
                <button class="btn-create" onclick="openFormModal()">+ Create Advisory</button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="advisories-section">
                <h2>Recent Advisories</h2>
                <?php if (empty($advisories)): ?>
                    <p style="color: #475d5b; text-align: center; padding: 40px;">No advisories yet</p>
                <?php else: ?>
                    <?php foreach ($advisories as $advisory): ?>
                        <div class="advisory-card">
                            <div class="advisory-header">
                                <div class="advisory-title"><?php echo htmlspecialchars($advisory['title']); ?></div>
                                <div class="advisory-meta">
                                    By <?php echo htmlspecialchars($advisory['fullname']); ?> • 
                                    <?php echo date('M d, Y H:i', strtotime($advisory['created_at'])); ?>
                                    <span class="status-badge status-<?php echo $advisory['status']; ?>">
                                        <?php echo ucfirst($advisory['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($advisory['image_path']): ?>
                                <div class="advisory-image-container">
                                    <img src="../uploads/advisories/<?php echo htmlspecialchars($advisory['image_path']); ?>" alt="Advisory image" class="advisory-image" onclick="openImageModal(this)">
                                    <div class="image-overlay"><i class="fas fa-search-plus"></i> Click to view full size</div>
                                </div>
                            <?php endif; ?>

                            <div class="advisory-content"><?php echo nl2br(htmlspecialchars($advisory['content'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Form Modal -->
    <div id="formModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Create New Advisory</h2>
                <button class="modal-close-btn" onclick="closeFormModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required placeholder="Enter advisory title">
                    </div>

                    <div class="form-group">
                        <label for="content">Content *</label>
                        <textarea id="content" name="content" required placeholder="Enter advisory content"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="advisory_image">Image (Optional)</label>
                        <input type="file" id="advisory_image" name="advisory_image" accept="image/*">
                        <small>Max 5MB. Formats: JPG, PNG, GIF, WebP</small>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeFormModal()">Cancel</button>
                        <button type="submit" class="btn-submit">Post Advisory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="modal image-modal" onclick="closeImageModal()">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <div class="image-modal-content" onclick="event.stopPropagation()">
            <img id="modalImage" src="" alt="Full view">
        </div>
    </div>

    <script>
        function openFormModal() {
            document.getElementById('formModal').classList.add('active');
        }
        function closeFormModal() {
            document.getElementById('formModal').classList.remove('active');
        }
        function openImageModal(img) {
            document.getElementById('modalImage').src = img.src;
            document.getElementById('imageModal').classList.add('active');
        }
        function closeImageModal() {
            document.getElementById('imageModal').classList.remove('active');
        }
    </script>
</body>
</html>
