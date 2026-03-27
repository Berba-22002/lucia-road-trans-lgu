<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    header("Location: ../login.php");
    exit();
}

// Fetch traffic management assignments
$query = "
    SELECT 
        ma.id,
        ma.report_id,
        ma.assigned_to,
        ma.completion_deadline,
        ma.notes,
        ma.status,
        ma.created_at,
        u.fullname as assigned_to_name,
        r.address,
        r.hazard_type
    FROM maintenance_assignments ma
    LEFT JOIN users u ON ma.assigned_to = u.id
    LEFT JOIN reports r ON ma.report_id = r.id
    WHERE ma.team_type = 'traffic_management'
    ORDER BY ma.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$traffic_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minor Traffic Issues</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                'lgu-highlight': '#faae2b'
              }
            }
          }
        }
    </script>
    <style>
        .font-poppins { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="bg-lgu-bg font-poppins">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main content -->
    <div class="lg:ml-64 flex flex-col h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-lgu-stroke">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-lgu-highlight rounded-lg">
                        <i class="fas fa-traffic-light text-lgu-button-text text-lg"></i>
                    </div>
                    <h1 class="text-xl font-semibold text-lgu-headline">Minor Traffic Issues</h1>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-lgu-stroke">
                <div class="bg-lgu-headline px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-white text-lg font-semibold flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Traffic Management Assignments
                        </h2>
                        <span class="bg-lgu-highlight text-lgu-button-text px-3 py-1 rounded-full text-sm font-medium">
                            <?= count($traffic_assignments) ?>
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <?php if (empty($traffic_assignments)): ?>
                        <div class="text-center py-8">
                            <div class="bg-lgu-bg rounded-xl p-6 max-w-md mx-auto border border-lgu-stroke">
                                <i class="fas fa-info-circle text-lgu-paragraph text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-lgu-headline mb-2">No assignments found</h3>
                                <p class="text-lgu-paragraph">No traffic management assignments have been created yet.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($traffic_assignments as $assignment): ?>
                                <div class="border border-lgu-stroke rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4">
                                            <div class="bg-lgu-highlight bg-opacity-20 p-3 rounded-lg">
                                                <i class="fas fa-traffic-light text-lgu-headline"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-lgu-headline">
                                                    Assignment #<?= htmlspecialchars($assignment['id']) ?>
                                                </h3>
                                                <div class="flex items-center space-x-4 text-sm text-lgu-paragraph mt-1">
                                                    <span class="flex items-center">
                                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                                        <?= htmlspecialchars($assignment['address'] ?? 'N/A') ?>
                                                    </span>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-user mr-1"></i>
                                                        <?= htmlspecialchars($assignment['assigned_to_name'] ?? 'Unassigned') ?>
                                                    </span>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-calendar mr-1"></i>
                                                        <?= date('M j, Y', strtotime($assignment['completion_deadline'])) ?>
                                                    </span>
                                                </div>
                                                <?php if ($assignment['notes']): ?>
                                                    <p class="text-sm text-lgu-paragraph mt-2">
                                                        <i class="fas fa-sticky-note mr-1"></i>
                                                        <?= htmlspecialchars($assignment['notes']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <span class="px-3 py-1 rounded-full text-xs font-medium
                                                <?= $assignment['status'] === 'assigned' ? 'bg-blue-100 text-blue-800' : 
                                                    ($assignment['status'] === 'in_progress' ? 'bg-yellow-100 text-yellow-800' : 
                                                    ($assignment['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                                                <?= ucfirst(str_replace('_', ' ', $assignment['status'])) ?>
                                            </span>
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
</body>
</html>