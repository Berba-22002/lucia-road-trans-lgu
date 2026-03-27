<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Support - Resident Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    <?php include 'sidebar.php';
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    
    require '../vendor/autoload.php';
    $mailConfig = include '../config/email.php';
    if (!is_array($mailConfig)) {
        $mailConfig = array('smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_user' => 'lgu1.infrastructureutilities@gmail.com', 'smtp_password' => '');
    }
    
    $message_sent = false;
    $error_message = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = htmlspecialchars($_POST['name']);
        $email = htmlspecialchars($_POST['email']);
        $phone = htmlspecialchars($_POST['phone']);
        $category = htmlspecialchars($_POST['category']);
        $subject = htmlspecialchars($_POST['subject']);
        $message = htmlspecialchars($_POST['message']);
        $priority = htmlspecialchars($_POST['priority']);
        
        $ticket_id = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $admin_email = $mailConfig['smtp_user'];
        
        // Handle file attachment
        $attachment_path = '';
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            $filename = $_FILES['attachment']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed) && $_FILES['attachment']['size'] <= 5242880) {
                $upload_dir = 'uploads/support_tickets/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $new_filename = $ticket_id . '_' . time() . '.' . $ext;
                $attachment_path = $upload_dir . $new_filename;
                move_uploaded_file($_FILES['attachment']['tmp_name'], $attachment_path);
            }
        }
        
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $mailConfig['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mailConfig['smtp_user'];
            $mail->Password = $mailConfig['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $mailConfig['smtp_port'];
            $mail->SMTPDebug = 0;
            
            // Email to admin
            $mail->setFrom($email, $name);
            $mail->addAddress($admin_email);
            $mail->Subject = "New Support Ticket: $ticket_id - $subject";
            
            $admin_body = "New Support Ticket Received\n\n";
            $admin_body .= "Ticket ID: $ticket_id\n";
            $admin_body .= "Priority: " . strtoupper($priority) . "\n";
            $admin_body .= "Category: $category\n\n";
            $admin_body .= "Customer Information:\n";
            $admin_body .= "Name: $name\n";
            $admin_body .= "Email: $email\n";
            $admin_body .= "Phone: $phone\n\n";
            $admin_body .= "Subject: $subject\n\n";
            $admin_body .= "Message:\n$message\n";
            
            $mail->Body = $admin_body;
            $mail->isHTML(false);
            
            if ($attachment_path && file_exists($attachment_path)) {
                $mail->addAttachment($attachment_path);
            }
            
            $mail->send();
            
            // Email to user
            $mail->clearAddresses();
            $mail->addAddress($email);
            $mail->Subject = "Support Ticket Confirmation - $ticket_id";
            
            $user_body = "Dear $name,\n\n";
            $user_body .= "Thank you for contacting LGU Infrastructure Support.\n\n";
            $user_body .= "Your support ticket has been received and assigned the following ID:\n";
            $user_body .= "Ticket ID: $ticket_id\n\n";
            $user_body .= "We will review your request and get back to you within 24 hours.\n\n";
            $user_body .= "Subject: $subject\n";
            $user_body .= "Priority: " . strtoupper($priority) . "\n\n";
            $user_body .= "Best regards,\n";
            $user_body .= "LGU Infrastructure Support Team\n";
            $user_body .= $mailConfig['smtp_user'] . "\n";
            $user_body .= "0919-075-5101";
            
            $mail->Body = $user_body;
            $mail->send();
            
            $message_sent = true;
        } catch (Exception $e) {
            $error_message = "Failed to send email. Please check your credentials in config/email.php";
        }
    }
    ?>

    <div class="ml-0 lg:ml-64 p-6">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-4xl font-bold text-lgu-headline mb-2">
                    <i class="fas fa-headset mr-3"></i>Contact Support
                </h1>
                <p class="text-lgu-paragraph">We're here to help. Get in touch with our support team.</p>
            </div>

            <!-- Success Message -->
            <?php if ($message_sent): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Ticket Submitted Successfully!',
                    text: 'Your support ticket has been sent. Check your email for confirmation and ticket ID.',
                    confirmButtonColor: '#faae2b'
                });
            </script>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error_message): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '<?php echo $error_message; ?>',
                    confirmButtonColor: '#faae2b'
                });
            </script>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <!-- Phone -->
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-lgu-highlight text-3xl mb-2">
                        <i class="fas fa-phone"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-lgu-headline mb-1">Call Us</h3>
                    <p class="text-xs text-gray-600">0919-075-5101</p>
                </div>

                <!-- Email -->
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-lgu-highlight text-3xl mb-2">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-lgu-headline mb-1">Email Us</h3>
                    <p class="text-xs text-gray-600">lgu1.infrastructureutilities@gmail.com</p>
                </div>

                <!-- Emergency -->
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-lgu-tertiary text-3xl mb-2">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-lgu-headline mb-1">Emergency</h3>
                    <p class="text-xs text-gray-600">911</p>
                </div>
            </div>

            <!-- Support Form -->
            <div class="bg-white rounded-lg shadow-md p-6 max-w-2xl mx-auto">
                <h2 class="text-xl font-bold text-lgu-headline mb-4">
                    <i class="fas fa-paper-plane mr-2"></i>Submit a Support Ticket
                </h2>

                <form id="supportForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Name -->
                        <div>
                            <label class="block text-xs font-semibold text-lgu-headline mb-1">Full Name *</label>
                            <input type="text" name="name" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-xs font-semibold text-lgu-headline mb-1">Email Address *</label>
                            <input type="email" name="email" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Phone -->
                        <div>
                            <label class="block text-xs font-semibold text-lgu-headline mb-1">Phone Number *</label>
                            <input type="tel" name="phone" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block text-xs font-semibold text-lgu-headline mb-1">Issue Category *</label>
                            <select name="category" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                                <option value="">Select a category</option>
                                <option value="account">Account & Login</option>
                                <option value="report">Report Issue</option>
                                <option value="status">Report Status</option>
                                <option value="notification">Notifications</option>
                                <option value="technical">Technical Issue</option>
                                <option value="feedback">Feedback</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div>
                        <label class="block text-xs font-semibold text-lgu-headline mb-1">Subject *</label>
                        <input type="text" name="subject" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                    </div>

                    <!-- Message -->
                    <div>
                        <label class="block text-xs font-semibold text-lgu-headline mb-1">Message *</label>
                        <textarea name="message" rows="4" required class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight resize-none text-sm"></textarea>
                    </div>

                    <!-- Attachment -->
                    <div>
                        <label class="block text-xs font-semibold text-lgu-headline mb-1">Attach Photo</label>
                        <input type="file" name="attachment" accept="image/*,.pdf" class="w-full px-3 py-1.5 border-2 border-lgu-stroke rounded-lg focus:outline-none focus:border-lgu-highlight text-sm">
                        <p class="text-xs text-gray-500 mt-0.5">Max file size: 5MB (JPG, PNG, GIF, PDF)</p>
                    </div>

                    <!-- Priority -->
                    <div>
                        <label class="block text-xs font-semibold text-lgu-headline mb-1">Priority Level</label>
                        <div class="flex gap-3 text-sm">
                            <label class="flex items-center">
                                <input type="radio" name="priority" value="low" checked class="mr-1">
                                <span class="text-lgu-paragraph">Low</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="priority" value="medium" class="mr-1">
                                <span class="text-lgu-paragraph">Medium</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="priority" value="high" class="mr-1">
                                <span class="text-lgu-paragraph">High</span>
                            </label>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-lgu-button text-lgu-button-text px-4 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition text-sm">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Ticket
                        </button>
                        <button type="reset" class="flex-1 bg-lgu-stroke text-white px-4 py-2 rounded-lg font-semibold hover:bg-lgu-headline transition text-sm">
                            <i class="fas fa-redo mr-2"></i>Clear Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- FAQ Link -->
            <div class="mt-8 bg-lgu-headline text-white rounded-lg p-6 text-center">
                <h3 class="text-xl font-bold mb-2">Looking for quick answers?</h3>
                <p class="mb-4">Check our FAQ section for common questions and solutions.</p>
                <a href="faq.php" class="inline-block bg-lgu-button text-lgu-button-text px-6 py-2 rounded-lg font-semibold hover:bg-yellow-500 transition">
                    <i class="fas fa-question-circle mr-2"></i>View FAQ
                </a>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
</html>
