<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is a resident
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
    header("Location: ../../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Contacts - LGU Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    <style>
    </style>
    <script>
        function callNumber(phoneNumber) {
            window.location.href = 'tel:' + phoneNumber;
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
                        <h1 class="text-lg lg:text-xl font-bold text-lgu-headline truncate">Emergency Contacts</h1>
                        <p class="text-xs lg:text-sm text-lgu-paragraph truncate">Important contact information</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 lg:p-6">
            <!-- Emergency Alert -->
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3 icon-3d"></i>
                    <div>
                        <h3 class="text-red-800 font-semibold">Emergency Notice</h3>
                        <p class="text-red-700 text-sm">For life-threatening emergencies, call 911 immediately</p>
                    </div>
                </div>
            </div>

            <!-- Primary Emergency Contacts -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-phone-alt text-red-600 text-xl icon-3d"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-red-800">Emergency Hotline</h3>
                            <p class="text-red-600 text-sm">24/7 Emergency Response</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-red-800 font-semibold text-xl">911</span>
                            <button onclick="callNumber('911')" class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700">
                                <i class="fas fa-phone mr-1 icon-3d"></i> Call
                            </button>
                        </div>
                        <p class="text-xs text-red-600">National Emergency Hotline</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-lgu-button">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-road text-lgu-button text-xl icon-3d"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-lgu-headline">LGU Infrastructure</h3>
                            <p class="text-lgu-paragraph text-sm">Road & Traffic Emergencies</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-lgu-headline font-semibold text-xl">0919-075-5101</span>
                            <button onclick="callNumber('09190755101')" class="bg-lgu-button text-lgu-button-text px-3 py-1 rounded text-sm hover:bg-yellow-500">
                                <i class="fas fa-phone mr-1 icon-3d"></i> Call
                            </button>
                        </div>
                        <p class="text-xs text-lgu-paragraph">Available 24/7</p>
                    </div>
                </div>
            </div>

            <!-- Road & Infrastructure Emergency Services -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-xl font-bold text-lgu-headline mb-6">🚨 Road & Infrastructure Monitoring Hotlines</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    
                    <!-- MMDA Metrobase -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-traffic-light text-orange-500 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">MMDA Metrobase</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">(02) 882-4151</p>
                        <p class="text-xs text-gray-500 mb-2">Traffic incidents & flood updates</p>
                        <button onclick="callNumber('0288824151')" class="text-orange-600 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- QC Helpline -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-shield-alt text-blue-600 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">QC Helpline</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">122</p>
                        <p class="text-xs text-gray-500 mb-2">General emergency response</p>
                        <button onclick="callNumber('122')" class="text-blue-600 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- QC Traffic & Transport -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-car text-green-600 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">QC Traffic & Transport</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">(02) 8703-8906</p>
                        <p class="text-xs text-gray-500 mb-2">Traffic Management Department</p>
                        <button onclick="callNumber('0287038906')" class="text-green-600 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- Caloocan Emergency -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-life-ring text-red-600 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">Caloocan Emergency</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">(02) 888-25664</p>
                        <p class="text-xs text-gray-500 mb-2">"888-ALONG" Emergency Line</p>
                        <button onclick="callNumber('0288825664')" class="text-red-600 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- Valenzuela DRRMO -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-ambulance text-red-500 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">Valenzuela DRRMO</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">(02) 8352-5000</p>
                        <p class="text-xs text-gray-500 mb-2">Disaster Risk Reduction Office</p>
                        <button onclick="callNumber('0283525000')" class="text-red-500 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- Meralco -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-bolt text-yellow-500 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">Meralco</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">16211</p>
                        <p class="text-xs text-gray-500 mb-2">Power line hazards</p>
                        <button onclick="callNumber('16211')" class="text-yellow-600 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- Maynilad -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-tint text-blue-500 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">Maynilad</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">1626</p>
                        <p class="text-xs text-gray-500 mb-2">Water pipe leaks (West Zone)</p>
                        <button onclick="callNumber('1626')" class="text-blue-500 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                    <!-- Manila Water -->
                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                        <div class="flex items-center mb-3">
                            <div class="w-10 h-10 bg-cyan-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-tint text-cyan-500 text-lg icon-3d"></i>
                            </div>
                            <h4 class="font-semibold text-lgu-headline">Manila Water</h4>
                        </div>
                        <p class="text-lgu-paragraph text-sm mb-2">1627</p>
                        <p class="text-xs text-gray-500 mb-2">Water leaks (East Zone)</p>
                        <button onclick="callNumber('1627')" class="text-cyan-500 text-sm hover:underline">
                            <i class="fas fa-phone mr-1 icon-3d"></i> Call Now
                        </button>
                    </div>

                </div>
            </div>

            <!-- Verified Sources Notice -->
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 text-xl mr-3 icon-3d"></i>
                    <div>
                        <h3 class="text-green-800 font-semibold">Verified Official Numbers</h3>
                        <p class="text-green-700 text-sm">Numbers verified from official LGU websites and government sources</p>
                    </div>
                </div>
            </div>

            <!-- Coverage Areas -->
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <div class="flex items-center">
                    <i class="fas fa-map-marker-alt text-blue-500 text-xl mr-3 icon-3d"></i>
                    <div>
                        <h3 class="text-blue-800 font-semibold">Coverage Areas</h3>
                        <p class="text-blue-700 text-sm">Quezon City • Novaliches • Caloocan • Valenzuela</p>
                    </div>
                </div>
            </div>

           
        </main>
    </div>

    <script>
        function callNumber(number) {
            window.location.href = `tel:${number}`;
        }

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
