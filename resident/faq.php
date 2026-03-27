<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ - Resident Dashboard</title>
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
    <?php include 'sidebar.php'; ?>

    <div class="ml-0 lg:ml-64 p-6">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-4xl font-bold text-lgu-headline mb-2">
                    <i class="fas fa-question-circle mr-3"></i>Frequently Asked Questions
                </h1>
                <p class="text-lgu-paragraph">Find answers to common questions about our infrastructure reporting system.</p>
            </div>

            <!-- Search Bar -->
            <div class="mb-8">
                <input type="text" id="faqSearch" placeholder="Search FAQ..." class="w-full px-4 py-3 rounded-lg border-2 border-lgu-stroke focus:outline-none focus:border-lgu-highlight">
            </div>

            <!-- FAQ Categories -->
            <div class="space-y-6">
                <!-- Getting Started -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-rocket mr-2"></i>Getting Started
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I create an account?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                To create an account, click on the "Sign Up" button on the homepage. Fill in your personal information, verify your email, and you're ready to start reporting infrastructure issues.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">What information do I need to provide?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                You'll need to provide your full name, email address, phone number, and residential address. This helps us verify your identity and locate the infrastructure issues you report.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">Is my personal information secure?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Yes, we use industry-standard encryption and security measures to protect your personal information. Your data is never shared with third parties without your consent.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reporting Issues -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Reporting Issues
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">What types of issues can I report?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                You can report various infrastructure issues including potholes, damaged roads, broken streetlights, traffic signal malfunctions, bridge damage, and other public infrastructure problems.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I submit a hazard report?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Navigate to "Submit Hazard Report" in the Infrastructure Services menu. Fill in the details, attach photos if possible, and submit. You'll receive a confirmation with a reference number.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">Can I attach photos to my report?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Yes, we strongly encourage you to attach clear photos of the issue. Photos help our inspectors better understand the problem and prioritize repairs.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How long does it take to process a report?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Reports are typically reviewed within 24-48 hours. Critical safety issues are prioritized and may be addressed sooner. You'll receive updates via email and SMS.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tracking & Status -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-clipboard-check mr-2"></i>Tracking & Status
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I check the status of my report?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Go to "Report Status" in the Infrastructure Services menu. Enter your reference number or search by location to view the current status of your report.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">What do the different status labels mean?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                <ul class="list-disc pl-5 space-y-1">
                                    <li><strong>Submitted:</strong> Report received and queued for review</li>
                                    <li><strong>Under Review:</strong> Inspector assigned and investigating</li>
                                    <li><strong>Approved:</strong> Issue confirmed and scheduled for repair</li>
                                    <li><strong>In Progress:</strong> Maintenance team is working on it</li>
                                    <li><strong>Completed:</strong> Issue has been resolved</li>
                                </ul>
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">Will I be notified of updates?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Yes, you'll receive notifications via email and SMS whenever your report status changes. You can manage notification preferences in your account settings.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts & Notifications -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-bell mr-2"></i>Alerts & Notifications
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">What are traffic alerts?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Traffic alerts notify you about road closures, maintenance work, and traffic disruptions in your area. Subscribe to receive real-time updates about infrastructure work.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I manage my notification preferences?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Go to "Notification Settings" in the Settings & Privacy menu. You can choose to receive notifications via email, SMS, or both, and select which types of alerts you want.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account & Privacy -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-shield-alt mr-2"></i>Account & Privacy
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I reset my password?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Click "Forgot Password" on the login page. Enter your email address and follow the instructions sent to your inbox to reset your password.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">Can I delete my account?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Yes, you can request account deletion from your account settings. Note that this will remove your personal information but your submitted reports will remain for record-keeping purposes.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">Who can see my reports?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Your reports are visible to LGU staff and authorized personnel only. You can adjust privacy settings to control whether your name appears on public reports.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Support -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-lgu-headline text-white p-4">
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-headset mr-2"></i>Support
                        </h2>
                    </div>
                    <div class="divide-y">
                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">How do I contact support?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Visit the "Contact Support" page to submit a support ticket. You can also call our hotline at 0919-075-5101 or email support@lgu-infrastructure.gov.ph.
                            </div>
                        </div>

                        <div class="faq-item">
                            <button class="faq-toggle w-full p-4 flex items-center justify-between hover:bg-lgu-bg transition">
                                <span class="text-left font-semibold text-lgu-headline">What are your support hours?</span>
                                <i class="fas fa-chevron-down text-lgu-highlight"></i>
                            </button>
                            <div class="faq-answer hidden px-4 pb-4 text-lgu-paragraph">
                                Our support team is available Monday to Friday, 8:00 AM to 5:00 PM. Emergency reports can be submitted 24/7 through the system.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Section -->
            <div class="mt-12 bg-lgu-headline text-white rounded-lg p-8 text-center">
                <h3 class="text-2xl font-bold mb-4">Didn't find your answer?</h3>
                <p class="mb-6">Contact our support team for additional assistance.</p>
                <a href="contact_support.php" class="inline-block bg-lgu-button text-lgu-button-text px-6 py-3 rounded-lg font-semibold hover:bg-yellow-500 transition">
                    <i class="fas fa-envelope mr-2"></i>Contact Support
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggles = document.querySelectorAll('.faq-toggle');
            const searchInput = document.getElementById('faqSearch');

            toggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const answer = this.nextElementSibling;
                    const icon = this.querySelector('i');
                    
                    answer.classList.toggle('hidden');
                    icon.classList.toggle('rotate-180');
                });
            });

            searchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const items = document.querySelectorAll('.faq-item');

                items.forEach(item => {
                    const question = item.querySelector('.faq-toggle span').textContent.toLowerCase();
                    const answer = item.querySelector('.faq-answer').textContent.toLowerCase();
                    
                    if (question.includes(searchTerm) || answer.includes(searchTerm)) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    </script>

    <style>
        .faq-toggle i {
            transition: transform 0.3s ease;
        }

        .faq-toggle i.rotate-180 {
            transform: rotate(180deg);
        }
    </style>
</body>
</html>
