<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporting Guide - Resident Dashboard</title>
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
                    <i class="fas fa-book mr-3"></i>Reporting Guide
                </h1>
                <p class="text-lgu-paragraph">Learn how to report issues and submit requests effectively.</p>
            </div>

            <!-- Guide Sections -->
            <div class="space-y-6">
                <!-- Getting Started -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-play-circle mr-2 text-lgu-highlight"></i>Getting Started
                    </h2>
                    <div class="space-y-3 text-lgu-paragraph">
                        <p>Follow these simple steps to report an issue:</p>
                        <ol class="list-decimal list-inside space-y-2 ml-2">
                            <li>Navigate to the Contact Support page</li>
                            <li>Fill in your personal information (name, email, phone)</li>
                            <li>Select the appropriate issue category</li>
                            <li>Provide a clear subject and detailed message</li>
                            <li>Attach supporting documents if needed</li>
                            <li>Set the priority level</li>
                            <li>Submit your ticket</li>
                        </ol>
                    </div>
                </div>

                <!-- Issue Categories -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-list mr-2 text-lgu-highlight"></i>Issue Categories
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Account & Login</h3>
                            <p class="text-sm text-lgu-paragraph">Issues with account access or login problems</p>
                        </div>
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Report Issue</h3>
                            <p class="text-sm text-lgu-paragraph">Report infrastructure or service issues</p>
                        </div>
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Report Status</h3>
                            <p class="text-sm text-lgu-paragraph">Check or update status of previous reports</p>
                        </div>
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Notifications</h3>
                            <p class="text-sm text-lgu-paragraph">Issues with notification settings or delivery</p>
                        </div>
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Technical Issue</h3>
                            <p class="text-sm text-lgu-paragraph">Technical problems with the platform</p>
                        </div>
                        <div class="border-l-4 border-lgu-highlight pl-4">
                            <h3 class="font-semibold text-lgu-headline mb-1">Feedback</h3>
                            <p class="text-sm text-lgu-paragraph">Suggestions and general feedback</p>
                        </div>
                    </div>
                </div>

                <!-- Priority Levels -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-exclamation-triangle mr-2 text-lgu-highlight"></i>Priority Levels
                    </h2>
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <span class="inline-block bg-green-500 text-white px-3 py-1 rounded text-sm font-semibold mr-3">Low</span>
                            <p class="text-lgu-paragraph">General inquiries and non-urgent matters. Response within 3-5 business days.</p>
                        </div>
                        <div class="flex items-start">
                            <span class="inline-block bg-yellow-500 text-white px-3 py-1 rounded text-sm font-semibold mr-3">Medium</span>
                            <p class="text-lgu-paragraph">Important issues affecting service. Response within 24-48 hours.</p>
                        </div>
                        <div class="flex items-start">
                            <span class="inline-block bg-red-500 text-white px-3 py-1 rounded text-sm font-semibold mr-3">High</span>
                            <p class="text-lgu-paragraph">Urgent issues requiring immediate attention. Response within 2-4 hours.</p>
                        </div>
                    </div>
                </div>

                <!-- Tips for Better Reports -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-lightbulb mr-2 text-lgu-highlight"></i>Tips for Better Reports
                    </h2>
                    <ul class="space-y-2 text-lgu-paragraph">
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Be specific and clear about the issue</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Include relevant dates and times when the issue occurred</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Attach screenshots or documents that support your report</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Provide step-by-step instructions to reproduce the issue</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Use proper grammar and professional language</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-lgu-highlight mr-3 mt-1"></i>
                            <span>Keep your contact information up to date</span>
                        </li>
                    </ul>
                </div>

                <!-- What to Expect -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-lgu-headline mb-4">
                        <i class="fas fa-clock mr-2 text-lgu-highlight"></i>What to Expect
                    </h2>
                    <div class="space-y-3 text-lgu-paragraph">
                        <p><strong>Ticket Confirmation:</strong> You'll receive an email confirmation with your ticket ID immediately after submission.</p>
                        <p><strong>Initial Response:</strong> Our support team will review your ticket and provide an initial response within the timeframe based on priority.</p>
                        <p><strong>Updates:</strong> You'll receive email updates on the progress of your ticket.</p>
                        <p><strong>Resolution:</strong> Once resolved, you'll be notified with details and any recommended actions.</p>
                    </div>
                </div>

                <!-- Contact Support Button -->
                <div class="bg-lgu-headline text-white rounded-lg p-6 text-center">
                    <h3 class="text-xl font-bold mb-3">Ready to Report?</h3>
                    <a href="contact_support.php" class="inline-block bg-lgu-button text-lgu-button-text px-6 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition">
                        <i class="fas fa-arrow-right mr-2"></i>Go to Contact Support
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
