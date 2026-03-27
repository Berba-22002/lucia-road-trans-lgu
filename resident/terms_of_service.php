<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service - Resident Dashboard</title>
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
                    <i class="fas fa-file-contract mr-3"></i>Terms of Service
                </h1>
                <p class="text-lgu-paragraph">Last updated: <span id="lastUpdated"></span></p>
            </div>

            <!-- Terms Content -->
            <div class="bg-white rounded-lg shadow-md p-8 space-y-8">
                <!-- 1. Acceptance of Terms -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">1. Acceptance of Terms</h2>
                    <p class="text-lgu-paragraph mb-3">By accessing and using this Resident Dashboard, you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by the above, please do not use this service.</p>
                </section>

                <!-- 2. Use License -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">2. Use License</h2>
                    <p class="text-lgu-paragraph mb-3">Permission is granted to temporarily download one copy of the materials (information or software) on the Resident Dashboard for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
                    <ul class="list-disc list-inside space-y-2 text-lgu-paragraph ml-2">
                        <li>Modify or copy the materials</li>
                        <li>Use the materials for any commercial purpose or for any public display</li>
                        <li>Attempt to decompile or reverse engineer any software contained on the dashboard</li>
                        <li>Remove any copyright or other proprietary notations from the materials</li>
                        <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
                    </ul>
                </section>

                <!-- 3. Disclaimer -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">3. Disclaimer</h2>
                    <p class="text-lgu-paragraph mb-3">The materials on the Resident Dashboard are provided on an 'as is' basis. The LGU makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.</p>
                </section>

                <!-- 4. Limitations -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">4. Limitations of Liability</h2>
                    <p class="text-lgu-paragraph mb-3">In no event shall the LGU or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on the Resident Dashboard.</p>
                </section>

                <!-- 5. Accuracy of Materials -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">5. Accuracy of Materials</h2>
                    <p class="text-lgu-paragraph mb-3">The materials appearing on the Resident Dashboard could include technical, typographical, or photographic errors. The LGU does not warrant that any of the materials on the dashboard are accurate, complete, or current. The LGU may make changes to the materials contained on the dashboard at any time without notice.</p>
                </section>

                <!-- 6. Materials and Content -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">6. Materials and Content</h2>
                    <p class="text-lgu-paragraph mb-3">The LGU has not reviewed all of the sites linked to its website and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by the LGU of the site. Use of any such linked website is at the user's own risk.</p>
                </section>

                <!-- 7. Modifications -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">7. Modifications</h2>
                    <p class="text-lgu-paragraph mb-3">The LGU may revise these terms of service for the dashboard at any time without notice. By using this dashboard, you are agreeing to be bound by the then current version of these terms of service.</p>
                </section>

                <!-- 8. Governing Law -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">8. Governing Law</h2>
                    <p class="text-lgu-paragraph mb-3">These terms and conditions are governed by and construed in accordance with the laws of the Philippines, and you irrevocably submit to the exclusive jurisdiction of the courts in that location.</p>
                </section>

                <!-- 9. User Responsibilities -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">9. User Responsibilities</h2>
                    <p class="text-lgu-paragraph mb-3">Users are responsible for:</p>
                    <ul class="list-disc list-inside space-y-2 text-lgu-paragraph ml-2">
                        <li>Maintaining the confidentiality of their account credentials</li>
                        <li>Providing accurate and complete information</li>
                        <li>Complying with all applicable laws and regulations</li>
                        <li>Not engaging in any unlawful or prohibited activities</li>
                    </ul>
                </section>

                <!-- 10. Compliance with Laws -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">10. Compliance with Laws</h2>
                    <p class="text-lgu-paragraph mb-3">This Resident Dashboard operates in compliance with the following Philippine laws and regulations:</p>
                    <ul class="list-disc list-inside space-y-2 text-lgu-paragraph ml-2">
                        <li><strong>Data Privacy Act of 2012 (RA 10173):</strong> All personal data collected is protected and processed in accordance with this law</li>
                        <li><strong>Cybercrime Prevention Act of 2012 (RA 10175):</strong> Users must not engage in unauthorized access, data theft, or malicious activities</li>
                        <li><strong>Local Government Code (RA 7160):</strong> The LGU operates under the authority and regulations of the Local Government Code</li>
                        <li><strong>National Building Code:</strong> Infrastructure reports must comply with building and safety standards</li>
                        <li><strong>Environmental Code:</strong> Environmental concerns must be reported in accordance with environmental regulations</li>
                    </ul>
                </section>

                <!-- 11. Data Protection and Privacy -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">11. Data Protection and Privacy</h2>
                    <p class="text-lgu-paragraph mb-3">Your personal information is protected under the Data Privacy Act of 2012. The LGU commits to:</p>
                    <ul class="list-disc list-inside space-y-2 text-lgu-paragraph ml-2">
                        <li>Collecting only necessary personal data</li>
                        <li>Using data solely for the purposes stated in this agreement</li>
                        <li>Implementing security measures to protect your data</li>
                        <li>Not sharing data with third parties without your consent, except as required by law</li>
                        <li>Allowing you to access and correct your personal information</li>
                    </ul>
                </section>

                <!-- 12. Contact Information -->
                <section>
                    <h2 class="text-2xl font-bold text-lgu-headline mb-3">12. Contact Information</h2>
                    <p class="text-lgu-paragraph mb-3">If you have any questions about these Terms of Service or concerns regarding data privacy, please contact us at:</p>
                    <div class="bg-lgu-bg p-4 rounded-lg mt-3">
                        <p class="text-lgu-paragraph"><strong>Email:</strong> lgu1.infrastructureutilities@gmail.com</p>
                        <p class="text-lgu-paragraph"><strong>Phone:</strong> 0919-075-5101</p>
                        <p class="text-lgu-paragraph"><strong>Data Protection Officer:</strong> Available upon request</p>
                    </div>
                </section>
            </div>

            <!-- Acceptance Button -->
            <div class="mt-8 bg-lgu-headline text-white rounded-lg p-6 text-center">
                <p class="mb-4">By using this dashboard, you acknowledge that you have read and agree to these Terms of Service.</p>
                <a href="dashboard.php" class="inline-block bg-lgu-button text-lgu-button-text px-6 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('lastUpdated').textContent = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    </script>
</body>
</html>
