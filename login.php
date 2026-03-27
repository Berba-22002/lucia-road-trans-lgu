<?php
ini_set('session.gc_maxlifetime', 180);
session_set_cookie_params(['lifetime' => 180]);
session_start();
require_once 'config/database.php';
require_once 'includes/face_recognition.php';
require_once 'includes/login_attempt_handler.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($email) || empty($password)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Email and password are required!'
        ]);
        exit();
    }

    // Check login attempts
    $attempt_check = $login_handler->checkAttempts($email);
    if ($attempt_check['status'] === 'cooldown') {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'locked' => true,
            'message' => $attempt_check['message']
        ]);
        exit();
    }

    try {
        // Use prepared statement to prevent SQL injection
        $stmt = $pdo->prepare("SELECT id, email, password, role, fullname FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            if (!isset($_SESSION['login_otp_verified']) || $_SESSION['login_otp_verified'] !== $email) {
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'require_otp' => true, 'message' => 'OTP verification required']);
                exit();
            }
            
            if (!isset($_SESSION['face_verified']) || $_SESSION['face_verified'] !== $email) {
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'require_face' => true, 'user_id' => $user['id'], 'message' => 'Face verification required']);
                exit();
            }
            
            unset($_SESSION['login_otp_verified']);
            unset($_SESSION['face_verified']);
            $_SESSION['last_activity'] = time();
            
            // Clear failed attempts on successful login
            $login_handler->clearAttempts($email);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['fullname'];
            $_SESSION['last_activity'] = time();
            
            // Return JSON response for AJAX handling
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'role' => $user['role'],
                'message' => 'Login successful!'
            ]);
            exit();
        } else {
            // Record failed attempt
            $attempt_result = $login_handler->recordFailedAttempt($email);
            
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'locked' => $attempt_result['status'] === 'locked',
                'message' => $attempt_result['message']
            ]);
            exit();
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred during login. Please try again later.'
        ]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f2f7f5;
            --headline: #00473e;
            --paragraph: #475d5b;
            --button: #faae2b;
            --button-text: #00473e;
            --stroke: #00332c;
            --highlight: #faae2b;
            --secondary: #ffa8ba;
            --tertiary: #fa5246;
        }
        .background-radial-gradient {
            background-color: var(--bg-color);
            background-image: radial-gradient(650px circle at 0% 0%,
                rgba(0, 71, 62, 0.3) 15%,
                rgba(0, 71, 62, 0.2) 35%,
                rgba(242, 247, 245, 0.8) 75%,
                rgba(242, 247, 245, 0.9) 80%,
                transparent 100%),
              radial-gradient(1250px circle at 100% 100%,
                rgba(250, 174, 43, 0.2) 15%,
                rgba(255, 168, 186, 0.15) 35%,
                rgba(242, 247, 245, 0.8) 75%,
                rgba(242, 247, 245, 0.9) 80%,
                transparent 100%);
            min-height: 100vh;
            font-family: 'Poppins', Arial, sans-serif;
            overflow: hidden;
        }
        #radius-shape-1 {
            height: 220px;
            width: 220px;
            top: -60px;
            left: -130px;
            background: radial-gradient(var(--highlight), var(--secondary));
            overflow: hidden;
        }
        #radius-shape-2 {
            border-radius: 38% 62% 63% 37% / 70% 33% 67% 30%;
            bottom: -60px;
            right: -110px;
            width: 300px;
            height: 300px;
            background: radial-gradient(var(--highlight), var(--secondary));
            overflow: hidden;
        }
        .bg-glass {
            background-color: hsla(0, 0%, 100%, 0.9) !important;
            backdrop-filter: saturate(200%) blur(25px);
        }
        .hero-text {
            color: var(--headline);
        }
        .hero-text span {
            color: var(--highlight);
        }
        .hero-description {
            color: var(--paragraph);
            opacity: 0.8;
        }
        .form-label {
            color: var(--headline);
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .form-control {
            transition: all 0.3s ease;
            border: 2px solid #e9ecef;
        }
        .form-control:focus {
            border-color: var(--highlight);
            box-shadow: 0 0 0 0.2rem var(--highlight)33, 0 0 20px rgba(250, 174, 43, 0.1);
            transform: translateY(-2px);
        }
        .input-group-text {
            background: var(--bg-color);
            color: var(--headline);
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--button) 0%, var(--highlight) 100%);
            color: var(--button-text);
            border: none;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(250, 174, 43, 0.3);
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            position: relative;
            overflow: hidden;
        }
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .btn-primary:hover::before {
            left: 100%;
        }
        .btn-primary:hover, .btn-primary:focus {
            background: linear-gradient(135deg, var(--highlight) 0%, var(--secondary) 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(250, 174, 43, 0.4);
            transform: translateY(-2px);
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0 0.5rem 0;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1.5px solid #e0e0e0;
        }
        .divider:not(:empty)::before {
            margin-right: .75em;
        }
        .divider:not(:empty)::after {
            margin-left: .75em;
        }
        .register-link {
            color: var(--headline);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .register-link:hover {
            color: var(--tertiary);
            text-decoration: underline;
        }
        /* Checkbox styling */
        .form-check-input:checked {
            background-color: var(--highlight);
            border-color: var(--highlight);
        }
        .form-check-input:focus {
            border-color: var(--highlight);
            box-shadow: 0 0 0 0.25rem rgba(250, 174, 43, 0.25);
        }
        /* OTP Modal Styling */
        .swal2-popup {
            font-family: 'Poppins', sans-serif !important;
            border-radius: 20px !important;
            box-shadow: 0 20px 60px rgba(0, 71, 62, 0.2) !important;
        }
        .swal2-title {
            color: var(--headline) !important;
            font-weight: 700 !important;
            font-size: 1.5rem !important;
        }
        .swal2-html-container {
            color: var(--paragraph) !important;
            font-weight: 400 !important;
        }
        .swal2-input {
            font-family: 'Poppins', sans-serif !important;
            border: 2px solid #e9ecef !important;
            border-radius: 10px !important;
            padding: 15px !important;
            font-size: 1.2rem !important;
            font-weight: 600 !important;
            color: var(--headline) !important;
            transition: all 0.3s ease !important;
        }
        .swal2-input:focus {
            border-color: var(--highlight) !important;
            box-shadow: 0 0 0 0.2rem rgba(250, 174, 43, 0.25) !important;
            outline: none !important;
        }
        .swal2-confirm {
            background: linear-gradient(135deg, var(--button) 0%, var(--highlight) 100%) !important;
            color: var(--button-text) !important;
            border: none !important;
            font-weight: 700 !important;
            font-family: 'Poppins', sans-serif !important;
            border-radius: 10px !important;
            padding: 12px 30px !important;
            box-shadow: 0 4px 15px rgba(250, 174, 43, 0.3) !important;
            transition: all 0.3s ease !important;
        }
        .swal2-confirm:hover {
            background: linear-gradient(135deg, var(--highlight) 0%, var(--secondary) 100%) !important;
            color: white !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(250, 174, 43, 0.4) !important;
        }
        .swal2-cancel {
            background: var(--tertiary) !important;
            color: white !important;
            border: none !important;
            font-weight: 600 !important;
            font-family: 'Poppins', sans-serif !important;
            border-radius: 10px !important;
            padding: 12px 30px !important;
            transition: all 0.3s ease !important;
        }
        .swal2-cancel:hover {
            background: #e04739 !important;
            transform: translateY(-2px) !important;
        }
        .swal2-icon {
            border: none !important;
        }
        .swal2-icon.swal2-success {
            color: var(--highlight) !important;
        }
        .swal2-icon.swal2-error {
            color: var(--tertiary) !important;
        }
        .otp-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 2px solid var(--highlight);
            margin: 0 auto 15px;
            display: block;
            object-fit: cover;
        }
        #loginOtpInput {
            text-align: center !important;
            font-size: 1.5em !important;
            letter-spacing: 0.5em !important;
            font-weight: 600 !important;
            font-family: 'Courier New', monospace !important;
            display: block !important;
            margin: 0 auto !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 480px) {
            .swal2-popup {
                width: 90% !important;
                padding: 1.5rem !important;
                max-height: 85vh !important;
            }
            .swal2-title {
                font-size: 1.25rem !important;
                margin-bottom: 0.5rem !important;
            }
            .swal2-html-container {
                font-size: 0.9rem !important;
                padding: 0 0.5rem !important;
            }
            .swal2-input {
                font-size: 1rem !important;
                padding: 12px !important;
            }
            #loginOtpInput {
                font-size: 1.3em !important;
                letter-spacing: 0.3em !important;
                padding: 14px 8px !important;
            }
            .swal2-confirm, .swal2-cancel {
                padding: 10px 15px !important;
                font-size: 0.85rem !important;
                margin: 5px 3px !important;
            }
            .otp-logo {
                width: 50px !important;
                height: 50px !important;
                margin-bottom: 10px !important;
            }
        }
        @media (max-width: 360px) {
            .swal2-popup {
                width: 95% !important;
                padding: 1rem !important;
            }
            .swal2-title {
                font-size: 1.1rem !important;
            }
            .swal2-html-container {
                font-size: 0.85rem !important;
            }
            #loginOtpInput {
                font-size: 1.1em !important;
                letter-spacing: 0.2em !important;
                padding: 12px 6px !important;
            }
            .swal2-confirm, .swal2-cancel {
                padding: 8px 12px !important;
                font-size: 0.75rem !important;
                margin: 4px 2px !important;
            }
            .otp-logo {
                width: 45px !important;
                height: 45px !important;
            }
        }
        @media (max-width: 768px) {
            .hero-text {
                font-size: 2rem !important;
                text-align: center;
            }
            .hero-description {
                text-align: center;
            }
            #radius-shape-1, #radius-shape-2 {
                display: none;
            }
        }
    </style>
</head>
<body>
    <section class="background-radial-gradient overflow-hidden">
        <div class="container px-4 py-5 px-md-5 text-center text-lg-start my-5">
            <div class="row gx-lg-5 align-items-center mb-5">
                <div class="col-lg-6 mb-5 mb-lg-0" style="z-index: 10">
                    <h1 class="my-5 display-5 fw-bold ls-tight hero-text">
                        Road and Transportation <br />
                        <span>Infrastructure Monitoring</span>
                    </h1>
                    <p class="mb-4 hero-description">
                        Secure access to RTIM services and systems. Your gateway to efficient infrastructure management and monitoring.
                    </p>
                </div>

                <div class="col-lg-6 mb-5 mb-lg-0 position-relative">
                    <div id="radius-shape-1" class="position-absolute rounded-circle shadow-5-strong"></div>
                    <div id="radius-shape-2" class="position-absolute shadow-5-strong"></div>

                    <div class="card bg-glass">
                        <div class="card-body px-4 py-5 px-md-5">
                            <div class="text-center mb-4">
                                <div style="width: 100px; height: 100px; margin: 0 auto 1rem; border-radius: 50%; overflow: hidden; border: 3px solid var(--highlight); box-shadow: 0 4px 15px rgba(250, 174, 43, 0.3);">
                                    <img src="admin/logo.jpg" alt="LGU Logo" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <h2 style="color: var(--headline); font-family: 'Poppins', sans-serif; font-weight: 700;">Sign In</h2>
                                <p class="text-muted"><i class="bi bi-shield-check-fill"></i> Secure Login</p>
                            </div>
            
                            <form method="POST" id="loginForm">
                                <div class="mb-3">
                                    <label for="email" class="form-label"><i class="bi bi-person-badge"></i> Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" 
                                               class="form-control" 
                                               id="email" 
                                               name="email" 
                                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                               required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="password" class="form-label"><i class="bi bi-lock"></i> Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                        <input type="password" 
                                               class="form-control" 
                                               id="password" 
                                               name="password" 
                                               autocomplete="current-password"
                                               required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="bi bi-eye" id="passwordIcon"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Terms and Conditions Checkbox -->
                                <div class="form-check mb-3 mt-3">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                                    <label class="form-check-label" for="termsCheck" style="color: var(--paragraph);">
                                        I agree to the 
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal" 
                                           style="color: var(--highlight); text-decoration: none; font-weight: 600;">
                                            Terms and Conditions
                                        </a>
                                        and 
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal"
                                           style="color: var(--highlight); text-decoration: none; font-weight: 600;">
                                            Privacy Policy
                                        </a>
                                    </label>
                                    <div class="invalid-feedback" id="termsError">
                                        You must agree to the terms and conditions before signing in.
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100" id="loginBtn" disabled>
                                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                                </button>
                            </form>

                            <div class="divider">or</div>
                            <div class="text-center">
                                <a href="register.php" class="register-link"><i class="bi bi-person-plus"></i> Create New Account</a>
                            </div>
                         
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--headline) 0%, #00332c 100%); color: white;">
                    <h5 class="modal-title" id="termsModalLabel"><i class="bi bi-file-text"></i> Terms and Conditions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                    <div class="terms-content">
                        <h5 class="text-primary">Last Updated: <?php echo date('F d, Y'); ?></h5>
                        
                        <h6 class="mt-4" style="color: var(--headline);">1. Acceptance of Terms</h6>
                        <p>By accessing and using the Road and Transportation Infrastructure Monitoring (RTIM) System, you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions.</p>
                        
                        <h6 style="color: var(--headline);">2. User Responsibilities</h6>
                        <p>You agree to:</p>
                        <ul>
                            <li>Provide accurate and complete information during registration</li>
                            <li>Maintain the confidentiality of your login credentials</li>
                            <li>Not share your account with others</li>
                            <li>Use the system only for its intended purposes</li>
                            <li>Report any security breaches or unauthorized access immediately</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">3. System Usage</h6>
                        <p>The RTIM system is designed for:</p>
                        <ul>
                            <li>Monitoring road and transportation infrastructure</li>
                            <li>Reporting maintenance issues</li>
                            <li>Tracking repair progress</li>
                            <li>Communication between residents, inspectors, and maintenance teams</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">4. Data Privacy and Security</h6>
                        <p>We implement appropriate security measures to protect your personal information. However, no system is completely secure, and we cannot guarantee absolute security.</p>
                        
                        <h6 style="color: var(--headline);">5. Prohibited Activities</h6>
                        <p>You must not:</p>
                        <ul>
                            <li>Attempt to hack or compromise system security</li>
                            <li>Upload malicious files or scripts</li>
                            <li>Use the system for illegal activities</li>
                            <li>Submit false reports or misinformation</li>
                            <li>Impersonate other users or officials</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">6. Account Termination</h6>
                        <p>We reserve the right to suspend or terminate accounts that violate these terms or engage in suspicious activities.</p>
                        
                        <h6 style="color: var(--headline);">7. Limitation of Liability</h6>
                        <p>The LGU shall not be liable for any indirect, incidental, or consequential damages arising from system use or inability to use the system.</p>
                        
                        <h6 style="color: var(--headline);">8. Changes to Terms</h6>
                        <p>We may modify these terms at any time. Continued use of the system constitutes acceptance of the modified terms.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="checkTermsAccepted()">
                        I Understand
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--headline) 0%, #00332c 100%); color: white;">
                    <h5 class="modal-title" id="privacyModalLabel"><i class="bi bi-shield-check"></i> Privacy Policy</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                    <div class="privacy-content">
                        <h5 class="text-primary">Effective Date: <?php echo date('F d, Y'); ?></h5>
                        
                        <h6 class="mt-4" style="color: var(--headline);">1. Information We Collect</h6>
                        <p>We collect:</p>
                        <ul>
                            <li>Personal identification information (name, email, contact details)</li>
                            <li>Account credentials</li>
                            <li>Facial recognition data for authentication</li>
                            <li>Reports and submissions made through the system</li>
                            <li>System usage data and logs</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">2. How We Use Your Information</h6>
                        <p>Your information is used to:</p>
                        <ul>
                            <li>Provide and maintain the RTIM system</li>
                            <li>Authenticate users securely</li>
                            <li>Process reports and requests</li>
                            <li>Communicate system updates and important notices</li>
                            <li>Improve system performance and user experience</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">3. Data Protection</h6>
                        <p>We implement:</p>
                        <ul>
                            <li>Encryption for sensitive data</li>
                            <li>Secure authentication protocols</li>
                            <li>Regular security audits</li>
                            <li>Access controls and permissions</li>
                            <li>Data backup and recovery procedures</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">4. Data Sharing</h6>
                        <p>We do not sell your personal information. Data may be shared with:</p>
                        <ul>
                            <li>Authorized LGU personnel for system operation</li>
                            <li>Maintenance and inspection teams as needed</li>
                            <li>Law enforcement when required by law</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">5. Your Rights</h6>
                        <p>You have the right to:</p>
                        <ul>
                            <li>Access your personal data</li>
                            <li>Request correction of inaccurate data</li>
                            <li>Request deletion of your data</li>
                            <li>Withdraw consent for data processing</li>
                        </ul>
                        
                        <h6 style="color: var(--headline);">6. Data Retention</h6>
                        <p>We retain your data only as long as necessary for system operation or as required by law.</p>
                        
                        <h6 style="color: var(--headline);">7. Contact Us</h6>
                        <p>For privacy concerns, contact our Data Protection Officer at: <strong>dpo@lgusystem.gov.ph</strong></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="checkTermsAccepted()">
                        I Understand
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password toggle functionality
        const togglePassword = document.getElementById('togglePassword');
        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                const passwordField = document.getElementById('password');
                const passwordIcon = document.getElementById('passwordIcon');
                
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    passwordIcon.className = 'bi bi-eye-slash';
                } else {
                    passwordField.type = 'password';
                    passwordIcon.className = 'bi bi-eye';
                }
            });
        }
        
        // Terms and Conditions functionality
        const termsCheck = document.getElementById('termsCheck');
        const loginBtn = document.getElementById('loginBtn');
        const termsError = document.getElementById('termsError');
        
        if (termsCheck) {
            termsCheck.addEventListener('change', function() {
                if (this.checked) {
                    loginBtn.disabled = false;
                    termsError.style.display = 'none';
                    this.classList.remove('is-invalid');
                } else {
                    loginBtn.disabled = true;
                }
            });
        }
        
        // Function to auto-check terms when user closes modal with "I Understand"
        function checkTermsAccepted() {
            if (termsCheck) {
                termsCheck.checked = true;
                loginBtn.disabled = false;
                termsError.style.display = 'none';
                termsCheck.classList.remove('is-invalid');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Check if terms are accepted
                if (!termsCheck.checked) {
                    termsError.style.display = 'block';
                    termsCheck.classList.add('is-invalid');
                    Swal.fire({
                        icon: 'error',
                        title: 'Terms Required',
                        text: 'Please agree to the Terms and Conditions before signing in.',
                        confirmButtonColor: '#fa5246'
                    });
                    return;
                }
                
                // Get form data
                const formData = new FormData(loginForm);
                const email = formData.get('email');
                const password = formData.get('password');
                
                // Simple validation
                if (!email || !password) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Missing Fields',
                        text: 'Please enter both email and password',
                        confirmButtonColor: '#fa5246'
                    });
                    return;
                }
                
                // Show loading state
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Signing In...';
                
                // Create AJAX request to submit form data
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo $_SERVER["PHP_SELF"]; ?>', true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                
                xhr.onload = function() {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Show success message
                            Swal.fire({
                                icon: 'success',
                                title: 'Login Successful!',
                                text: 'Redirecting to your dashboard...',
                                showConfirmButton: false,
                                timer: 2000,
                                timerProgressBar: true,
                                willOpen: () => {
                                    Swal.showLoading();
                                },
                                willClose: () => {
                                    // Redirect based on role from server response
                                    switch(response.role) {
                                        case 'admin':
                                            window.location.href = 'admin/admin-dashboard.php';
                                            break;
                                        case 'inspector':
                                            window.location.href = 'inspector/inspector-dashboard.php';
                                            break;
                                        case 'resident':
                                            window.location.href = 'resident/resident-dashboard.php';
                                            break;
                                        case 'maintenance':
                                            window.location.href = 'maintenance/maintenance-dashboard.php';
                                            break;
                                        case 'treasurer':
                                            window.location.href = 'treasurer/treasurer-dashboard.php';
                                            break;
                                        case 'engineer':
                                            window.location.href = 'engineer/engineer-dashboard.php';
                                            break;
                                        default:
                                            window.location.href = 'index.php';
                                    }
                                }
                            });
                        } else if (response.require_otp) {
                            loginBtn.disabled = false;
                            loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                            sendLoginOTP(email, password);
                        } else if (response.require_face) {
                            loginBtn.disabled = false;
                            loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                            showFaceVerification(email, password, response.user_id);
                        } else if (response.locked) {
                            showCooldownError(response.message);
                        } else {
                            showWarning(response.message);
                        }
                    } catch (e) {
                        showError('Invalid response from server');
                    }
                };
                
                xhr.onerror = function() {
                    showError('Network error. Please check your connection.');
                };
                
                xhr.send(new URLSearchParams(formData));
            });
            
            function showError(message) {
                // Reset button state
                loginBtn.disabled = false;
                loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                
                Swal.fire({
                    icon: 'error',
                    title: 'Login Failed',
                    text: message,
                    confirmButtonColor: '#fa5246'
                });
            }
            
            function showWarning(message) {
                // Reset button state
                loginBtn.disabled = false;
                loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Credentials',
                    text: message,
                    confirmButtonColor: '#faae2b',
                    confirmButtonText: 'Try Again'
                });
            }
            
            function showCooldownError(message) {
                // Reset button state
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                
                Swal.fire({
                    icon: 'error',
                    title: 'Account Locked',
                    text: message,
                    confirmButtonColor: '#fa5246',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(() => {
                    // Disable login button for 3 minutes
                    let cooldownTime = 180;
                    const cooldownInterval = setInterval(() => {
                        cooldownTime--;
                        const mins = Math.floor(cooldownTime / 60);
                        const secs = cooldownTime % 60;
                        loginBtn.innerHTML = `<i class="bi bi-hourglass-split"></i> Wait ${mins}:${secs.toString().padStart(2, '0')}`;
                        
                        if (cooldownTime <= 0) {
                            clearInterval(cooldownInterval);
                            loginBtn.disabled = false;
                            loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
                        }
                    }, 1000);
                });
            }
            
            // Check if there are any PHP session errors to display
            <?php if (isset($_SESSION['error'])): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Login Error',
                    text: '<?php echo $_SESSION['error']; unset($_SESSION['error']); ?>',
                    confirmButtonColor: '#fa5246'
                });
            <?php endif; ?>
        });
        
        // Send OTP for login verification
        function sendLoginOTP(email, password) {
            Swal.fire({
                title: 'Sending OTP',
                text: 'Please wait while we send the verification code to your email...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('send_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'email=' + encodeURIComponent(email)
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    showLoginOTPModal(email, password);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Send OTP',
                        text: data.message,
                        confirmButtonColor: '#fa5246'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please check your connection and try again',
                    confirmButtonColor: '#fa5246'
                });
            });
        }

        // Show OTP verification modal for login
        function showLoginOTPModal(email, password) {
            let timeLeft = 120;
            Swal.fire({
                title: 'Login Verification',
                html: `
                    <img src="admin/logo.jpg" alt="LGU Logo" class="otp-logo">
                    <p style="margin-bottom: 10px;">We've sent a 6-digit OTP to <strong>${email}</strong></p>
                    <div style="background: #fff3cd; border: 2px solid #faae2b; border-radius: 8px; padding: 12px; margin-bottom: 15px;">
                        <p style="margin: 0; font-size: 14px; color: #856404;"><strong>Time remaining:</strong> <span id="loginCountdown" style="font-size: 18px; font-weight: bold; color: #fa5246;">2:00</span></p>
                    </div>
                    <input type="text" id="loginOtpInput" class="swal2-input" placeholder="Enter 6-digit OTP" maxlength="6" style="text-align: center; font-size: 1.5em; letter-spacing: 0.5em;">
                `,
                showCancelButton: true,
                confirmButtonText: 'Verify & Login',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#00473e',
                cancelButtonColor: '#fa5246',
                allowOutsideClick: false,
                didOpen: () => {
                    const countdownInterval = setInterval(() => {
                        timeLeft--;
                        const mins = Math.floor(timeLeft / 60);
                        const secs = timeLeft % 60;
                        document.getElementById('loginCountdown').textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                        if (timeLeft <= 0) {
                            clearInterval(countdownInterval);
                            Swal.close();
                            Swal.fire({
                                icon: 'error',
                                title: 'OTP Expired',
                                text: 'Your OTP has expired. Please request a new one.',
                                confirmButtonColor: '#fa5246'
                            });
                        }
                    }, 1000);
                    Swal.getPopup().countdownInterval = countdownInterval;
                },
                willClose: () => {
                    const popup = Swal.getPopup();
                    if (popup && popup.countdownInterval) {
                        clearInterval(popup.countdownInterval);
                    }
                },
                preConfirm: () => {
                    const otp = document.getElementById('loginOtpInput').value;
                    if (!otp || otp.length !== 6) {
                        Swal.showValidationMessage('Please enter a valid 6-digit OTP');
                        return false;
                    }
                    return otp;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    verifyLoginOTP(result.value, email, password);
                }
            });
        }

        // Verify OTP and complete login
        function verifyLoginOTP(otp, email, password) {
            Swal.fire({
                title: 'Verifying OTP',
                text: 'Please wait...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('verify_login_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'otp=' + encodeURIComponent(otp) + '&email=' + encodeURIComponent(email)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetch('<?php echo $_SERVER["PHP_SELF"]; ?>', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new URLSearchParams({ email, password })
                    }).then(r => r.json()).then(loginData => {
                        if (loginData.require_face) showFaceVerification(email, password, loginData.user_id);
                        else if (loginData.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Login Successful!',
                                text: 'Redirecting to your dashboard...',
                                showConfirmButton: false,
                                timer: 2000,
                                timerProgressBar: true,
                                willClose: () => {
                                    switch(loginData.role) {
                                        case 'admin':
                                            window.location.href = 'admin/admin-dashboard.php';
                                            break;
                                        case 'inspector':
                                            window.location.href = 'inspector/inspector-dashboard.php';
                                            break;
                                        case 'resident':
                                            window.location.href = 'resident/resident-dashboard.php';
                                            break;
                                        case 'maintenance':
                                            window.location.href = 'maintenance/maintenance-dashboard.php';
                                            break;
                                        case 'treasurer':
                                            window.location.href = 'treasurer/treasurer-dashboard.php';
                                            break;
                                        case 'engineer':
                                            window.location.href = 'engineer/engineer-dashboard.php';
                                            break;
                                        default:
                                            window.location.href = 'index.php';
                                    }
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Login Failed',
                                text: loginData.message,
                                confirmButtonColor: '#fa5246'
                            });
                        }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Verification Failed',
                        text: data.message,
                        confirmButtonColor: '#fa5246'
                    }).then(() => {
                        showLoginOTPModal(email, password);
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please check your connection and try again',
                    confirmButtonColor: '#fa5246'
                });
            });
        }
    </script>
    <script>
        function showFaceVerification(email, password, userId) {
            Swal.fire({
                title: 'Face Verification',
                html: '<div style="position:relative;display:inline-block;"><video id="faceVideo" width="320" height="240" autoplay style="border-radius:10px;"></video><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:250px;border:3px dashed #faae2b;border-radius:50%;pointer-events:none;"></div></div><canvas id="faceCanvas" style="display:none;"></canvas><p style="margin-top:10px;color:#856404;"><i class="bi bi-info-circle"></i> Position your face within the oval guide</p>',
                showCancelButton: true,
                confirmButtonText: 'Capture',
                didOpen: () => {
                    navigator.mediaDevices.getUserMedia({ video: true }).then(s => {
                        document.getElementById('faceVideo').srcObject = s;
                        Swal.getPopup().stream = s;
                    }).catch(() => Swal.showValidationMessage('Camera denied'));
                },
                willClose: () => { const s = Swal.getPopup().stream; if(s) s.getTracks().forEach(t => t.stop()); },
                preConfirm: () => {
                    const v = document.getElementById('faceVideo'), c = document.getElementById('faceCanvas');
                    c.width = v.videoWidth; c.height = v.videoHeight;
                    c.getContext('2d').drawImage(v, 0, 0);
                    return c.toDataURL('image/jpeg').split(',')[1];
                }
            }).then(r => { if(r.isConfirmed) verifyFace(r.value, email, password, userId); });
        }
        
        function verifyFace(img, email, password, userId) {
            Swal.fire({ title: 'Verifying', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            fetch('verify_face.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user_id=${userId}&captured_image=${encodeURIComponent(img)}&email=${encodeURIComponent(email)}`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    fetch('<?php echo $_SERVER["PHP_SELF"]; ?>', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new URLSearchParams({ email, password })
                    }).then(r => r.json()).then(l => {
                        if (l.success) {
                            Swal.fire({ icon: 'success', title: 'Success!', timer: 2000, showConfirmButton: false, willClose: () => {
                                const urls = { admin: 'admin/admin-dashboard.php', inspector: 'inspector/inspector-dashboard.php', resident: 'resident/resident-dashboard.php', maintenance: 'maintenance/maintenance-dashboard.php', treasurer: 'treasurer/treasurer-dashboard.php', engineer: 'engineer/engineer-dashboard.php' };
                                window.location.href = urls[l.role] || 'index.php';
                            }});
                        }
                    });
                } else Swal.fire({ icon: 'error', title: 'Failed', text: d.message }).then(() => showFaceVerification(email, password, userId));
            });
        }
    </script>
</body>
</html>