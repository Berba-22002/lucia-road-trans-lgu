<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$requests = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM utility_billing_requests WHERE user_id = :user_id ORDER BY created_at DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requests: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $system_name = 'Utility';
    $event_type = $_POST['event_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $location = $_POST['location'] ?? '';
    $landmark = $_POST['landmark'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (empty($event_type) || empty($start_date) || empty($end_date) || empty($location) || empty($description)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'All fields are required!']);
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO utility_billing_requests (user_id, system_name, event_type, start_date, end_date, location, landmark, description, status) 
                              VALUES (:user_id, :system_name, :event_type, :start_date, :end_date, :location, :landmark, :description, 'pending')");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':system_name' => $system_name,
            ':event_type' => $event_type,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':location' => $location,
            ':landmark' => $landmark,
            ':description' => $description
        ]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Request submitted successfully!']);
        exit();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error submitting request']);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utility Billing Request</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
        
        .form-input {
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        .form-input:focus {
            background: white;
            box-shadow: 0 0 0 3px rgba(250, 174, 43, 0.1);
            transform: translateY(-1px);
        }
        
        .request-row:hover {
            background: linear-gradient(90deg, rgba(250, 174, 43, 0.05), transparent);
            transform: translateX(4px);
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #faae2b 0%, #f59e0b 100%);
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(250, 174, 43, 0.3);
        }
        
        .header-gradient {
            background: linear-gradient(135deg, #00473e 0%, #00332c 100%);
        }
        
        .icon-box {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #faae2b 0%, #f59e0b 100%);
            border-radius: 0.75rem;
            color: #00473e;
            font-size: 1.25rem;
        }
        
        .view-remarks-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .view-remarks-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
    </style>
</head>
<body class="bg-lgu-bg min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="header-gradient rounded-2xl shadow-lg p-8 mb-8 text-white flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="icon-box">
                    <i class="fas fa-water"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold">Utility Billing Request</h1>
                    <p class="text-blue-100">Submit requests for utility services</p>
                </div>
            </div>
            <button onclick="openForm()" class="submit-btn text-lgu-button-text font-bold py-3 px-6 rounded-lg text-sm shadow-lg">
                <i class="fas fa-plus mr-2"></i> New Request
            </button>
        </div>

        <!-- Single Modal Form -->
        <div id="formModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-lgu-headline">Service Details</h2>
                    <button onclick="closeForm()" class="text-gray-500 hover:text-gray-700 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form id="billingForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">System Name</label>
                        <input type="text" value="Utility" readonly class="w-full border-2 border-gray-300 rounded-lg p-3 text-sm bg-gray-100 cursor-not-allowed">
                        <input type="hidden" name="system_name" value="Utility">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">Service Type <span class="text-lgu-tertiary">*</span></label>
                        <input type="text" name="event_type" placeholder="e.g., Water Supply, Electricity" required class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">Landmark</label>
                        <input type="text" name="landmark" placeholder="Nearby landmark (optional)" class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-lgu-headline mb-2">Start Date & Time <span class="text-lgu-tertiary">*</span></label>
                            <input type="datetime-local" name="start_date" required class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-lgu-headline mb-2">End Date & Time <span class="text-lgu-tertiary">*</span></label>
                            <input type="datetime-local" name="end_date" required class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">Location <span class="text-lgu-tertiary">*</span></label>
                        <input type="text" name="location" id="location" placeholder="Enter location address" required class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button mb-2">
                        <button type="button" id="getCurrentLocationBtn" class="w-full bg-blue-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-blue-700 transition font-semibold">
                            <i class="fas fa-location-arrow"></i> <span id="btnText">Current Location</span>
                        </button>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">Map Preview</label>
                        <div id="billingMap" class="w-full h-48 rounded-lg border-2 border-gray-300"></div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-lgu-headline mb-2">Description <span class="text-lgu-tertiary">*</span></label>
                        <textarea name="description" placeholder="Describe your request..." required rows="3" class="form-input w-full border-2 border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-lgu-button resize-none"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="submit-btn flex-1 text-lgu-button-text font-bold py-3 rounded-lg text-sm shadow-lg">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                        <button type="button" onclick="closeForm()" class="flex-1 bg-gray-300 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-400 transition">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Requests Table -->
        <div class="bg-white rounded-2xl shadow-lg p-6">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b-2 border-lgu-button">
                <i class="fas fa-list text-lgu-button text-2xl"></i>
                <h2 class="text-lg font-bold text-lgu-headline">Your Requests</h2>
                <span class="ml-auto bg-lgu-button text-lgu-button-text px-3 py-1 rounded-full text-sm font-bold">
                    <?php echo count($requests); ?>
                </span>
            </div>
            
            <?php if (empty($requests)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-6xl text-gray-200 mb-4"></i>
                    <p class="text-gray-500 text-lg font-medium">No requests submitted yet</p>
                    <p class="text-gray-400 text-sm">Click "New Request" button to submit your first request</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-300">
                                <th class="text-left py-3 px-4 font-bold text-lgu-headline">Service Type</th>
                                <th class="text-left py-3 px-4 font-bold text-lgu-headline">Location</th>
                                <th class="text-left py-3 px-4 font-bold text-lgu-headline">Start Date</th>
                                <th class="text-left py-3 px-4 font-bold text-lgu-headline">Status</th>
                                <th class="text-left py-3 px-4 font-bold text-lgu-headline">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr class="request-row border-b border-gray-200 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-gray-700"><?php echo ucfirst($req['event_type']); ?></td>
                                    <td class="py-3 px-4 text-gray-700 truncate" title="<?php echo htmlspecialchars($req['location']); ?>"><?php echo htmlspecialchars(substr($req['location'], 0, 30)); ?></td>
                                    <td class="py-3 px-4 text-gray-700"><?php echo date('M d, H:i', strtotime($req['start_date'])); ?></td>
                                    <td class="py-3 px-4">
                                        <span class="px-3 py-1 rounded text-xs font-bold <?php 
                                            echo $req['status'] == 'approved' ? 'bg-green-100 text-green-700' : 
                                                 ($req['status'] == 'rejected' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); 
                                        ?>">
                                            <?php echo ucfirst($req['status']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-700 text-xs"><?php echo $req['remarks'] ? '<button onclick="showRemarks(\'' . htmlspecialchars(addslashes($req['remarks'])) . '\')" class="view-remarks-btn"><i class="fas fa-eye"></i>View Remarks</button>' : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
        let map = null;
        let marker = null;

        function openForm() {
            document.getElementById('formModal').classList.remove('hidden');
            setTimeout(() => {
                if (map) map.invalidateSize();
                else initMap();
            }, 100);
        }

        function closeForm() {
            document.getElementById('formModal').classList.add('hidden');
            document.getElementById('billingForm').reset();
        }

        function initMap() {
            const mapElement = document.getElementById('billingMap');
            if (!mapElement) return;
            
            if (map) map.remove();
            map = L.map('billingMap').setView([14.6760, 120.9626], 11);
            L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {
                attribution: '© TomTom'
            }).addTo(map);
        }

        async function geocodeAddress(address) {
            if (!address.trim() || !map) return;
            try {
                const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
                const data = await response.json();
                
                if (data.results && data.results.length > 0) {
                    const result = data.results[0];
                    const lat = result.position.lat;
                    const lng = result.position.lon;
                    
                    if (marker) map.removeLayer(marker);
                    marker = L.marker([lat, lng]).addTo(map).bindPopup(result.address.freeformAddress);
                    map.setView([lat, lng], 16);
                }
            } catch (error) {
                console.error('Geocoding error:', error);
            }
        }

        document.getElementById('location').addEventListener('change', function() {
            geocodeAddress(this.value);
        });

        document.getElementById('getCurrentLocationBtn').addEventListener('click', function(e) {
            e.preventDefault();
            const btn = this;
            const btnText = document.getElementById('btnText');
            btn.disabled = true;
            btnText.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Loading...';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                        .then(r => r.json())
                        .then(data => {
                            document.getElementById('location').value = data.display_name || `${lat}, ${lng}`;
                            geocodeAddress(document.getElementById('location').value);
                            if (map) map.flyTo([lat, lng], 16, {duration: 1.5});
                            btn.disabled = false;
                            btnText.textContent = 'Current Location';
                        });
                });
            }
        });

        document.getElementById('billingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        confirmButtonColor: '#00473e'
                    }).then(() => {
                        closeForm();
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#00473e'
                    });
                }
            });
        });

        function showRemarks(remarks) {
            Swal.fire({
                title: 'Remarks',
                text: remarks,
                icon: 'info',
                confirmButtonColor: '#00473e'
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeForm();
        });
    </script>
</body>
</html>
