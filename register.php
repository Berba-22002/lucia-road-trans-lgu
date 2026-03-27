<?php
session_start();
require_once 'config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = $_POST['fullname'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $role = 'resident';
    $address = $_POST['address'] ?? '';
    $contact = $_POST['contact'] ?? '';
    $privacy_consent = isset($_POST['privacy_consent']) ? 1 : 0;
    $data_retention_consent = isset($_POST['data_retention_consent']) ? 1 : 0;
    $marketing_consent = isset($_POST['marketing_consent']) ? 1 : 0;

    // Validate inputs
    if (empty($fullname) || empty($email) || empty($password) || empty($confirmPassword) || empty($address) || empty($contact)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'All fields are required!'
        ]);
        exit();
    }

    // Validate password match
    if ($password !== $confirmPassword) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Passwords do not match!'
        ]);
        exit();
    }

    // Validate password strength
    if (strlen($password) < 8) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password must be at least 8 characters long!'
        ]);
        exit();
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password must contain at least one uppercase letter!'
        ]);
        exit();
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password must contain at least one lowercase letter!'
        ]);
        exit();
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password must contain at least one number!'
        ]);
        exit();
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password must contain at least one special character!'
        ]);
        exit();
    }

    // Validate contact number
    if (strlen($contact) !== 11 || !preg_match('/^09[0-9]{9}$/', $contact)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Contact number must be 11 digits starting with 09!'
        ]);
        exit();
    }

    // Check if email is verified
    if (!isset($_SESSION['email_verified']) || !isset($_SESSION['verified_email']) || $_SESSION['verified_email'] !== $email) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Please verify your email first!'
        ]);
        exit();
    }

    try {
        // Check if email already exists
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $check_stmt->execute([':email' => $email]);
        
        if ($check_stmt->rowCount() > 0) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Email already exists!'
            ]);
            exit();
        }

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user with consent data
        $insert_stmt = $pdo->prepare(
            "INSERT INTO users (fullname, email, password, role, address, contact_number, privacy_consent, privacy_consent_date, data_retention_consent, marketing_consent, consent_ip_address) 
             VALUES (:fullname, :email, :password, :role, :address, :contact_number, :privacy_consent, NOW(), :data_retention_consent, :marketing_consent, :ip_address)"
        );
        
        $insert_stmt->execute([
            ':fullname' => $fullname,
            ':email' => $email,
            ':password' => $hashed_password,
            ':role' => $role,
            ':address' => $address,
            ':contact_number' => $contact,
            ':privacy_consent' => $privacy_consent,
            ':data_retention_consent' => $data_retention_consent,
            ':marketing_consent' => $marketing_consent,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        $user_id = $pdo->lastInsertId();

        // Log consent for audit trail
        $consent_stmt = $pdo->prepare(
            "INSERT INTO consent_logs (user_id, consent_type, consent_given, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)"
        );
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $consent_stmt->execute([$user_id, 'privacy', $privacy_consent, $ip_address, $user_agent]);
        $consent_stmt->execute([$user_id, 'data_retention', $data_retention_consent, $ip_address, $user_agent]);
        $consent_stmt->execute([$user_id, 'marketing', $marketing_consent, $ip_address, $user_agent]);

        unset($_SESSION['email_verified'], $_SESSION['verified_email']);

        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'user_id' => $user_id,
            'message' => 'Registration successful! Please register your face.'
        ]);
        exit();

    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred during registration. Please try again later.'
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
    <title>Register - RTIM System</title>
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
        .password-strength {
            margin-top: 8px;
        }
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e9ecef;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .strength-bar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
            width: 0%;
        }
        .strength-weak::before {
            background: #dc3545;
            width: 33%;
        }
        .strength-medium::before {
            background: #fd7e14;
            width: 66%;
        }
        .strength-strong::before {
            background: #198754;
            width: 100%;
        }
        .password-requirements {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 5px;
        }
        .req-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.75rem;
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
        .login-link {
            color: var(--headline);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .login-link:hover {
            color: var(--tertiary);
            text-decoration: underline;
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
        .terms-modal .modal-body {
            max-height: 400px;
            overflow-y: auto;
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
        #registerOtpInput {
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
            #registerOtpInput {
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
            #registerOtpInput {
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
    </style>
</head>
<body>
    <section class="background-radial-gradient overflow-hidden">
        <div class="container px-4 py-5 px-md-5 text-center text-lg-start my-5">
            <div class="row gx-lg-5 align-items-center mb-5">
                <div class="col-lg-6 mb-5 mb-lg-0" style="z-index: 10">
                    <h1 class="my-5 display-5 fw-bold ls-tight hero-text">
                        Road and Transportation <br />
                        <span>Infrastructaure Monitoring</span>
                    </h1>
                    <p class="mb-4 hero-description">
                        Create your account to access RTIM services and systems. Join our digital infrastructure management platform.
                    </p>
                </div>

                <div class="col-lg-6 mb-5 mb-lg-0 position-relative">
                    <div id="radius-shape-1" class="position-absolute rounded-circle shadow-5-strong"></div>
                    <div id="radius-shape-2" class="position-absolute shadow-5-strong"></div>

                    <div class="card bg-glass">
                        <div class="card-body px-4 py-5 px-md-5">
                            <div class="text-center mb-4">
                                <div style="width: 80px; height: 80px; background: var(--headline); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                                    <i class="bi bi-person-plus" style="font-size: 2rem; color: white;"></i>
                                </div>
                                <h2 style="color: var(--headline); font-family: 'Poppins', sans-serif; font-weight: 700;">Create Account</h2>
                                <p class="text-muted"><i class="bi bi-people-fill"></i> Join RTIM System</p>
                            </div>
            
                            <form method="POST" id="registrationForm">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="fullname" class="form-label"><i class="bi bi-person-vcard"></i> Full Name</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                                            <input type="text" class="form-control" id="fullname" name="fullname" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="email" class="form-label"><i class="bi bi-envelope"></i> Email Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                            <input type="email" class="form-control" id="email" name="email" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="password" class="form-label"><i class="bi bi-lock"></i> Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                            <input type="password" class="form-control" id="password" name="password" required>
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="bi bi-eye" id="passwordIcon"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength mt-2">
                                            <div class="strength-bar" id="strengthBar"></div>
                                            <small id="strengthText" class="text-muted">Password strength</small>
                                            <div class="password-requirements mt-1">
                                                <small class="req-item" id="req-length"><i class="bi bi-x-circle text-danger"></i> At least 8 characters</small>
                                                <small class="req-item" id="req-upper"><i class="bi bi-x-circle text-danger"></i> Uppercase letter</small>
                                                <small class="req-item" id="req-lower"><i class="bi bi-x-circle text-danger"></i> Lowercase letter</small>
                                                <small class="req-item" id="req-number"><i class="bi bi-x-circle text-danger"></i> Number</small>
                                                <small class="req-item" id="req-special"><i class="bi bi-x-circle text-danger"></i> Special character</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="confirmPassword" class="form-label"><i class="bi bi-lock-fill"></i> Confirm Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                            <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" required>
                                            <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                                <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="contact" class="form-label"><i class="bi bi-phone"></i> Contact Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                            <input type="tel" class="form-control" id="contact" name="contact" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="address" class="form-label"><i class="bi bi-geo-alt"></i> Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-house"></i></span>
                                        <textarea class="form-control" id="address" name="address" rows="3" placeholder="Enter your complete address" required></textarea>
                                        <button class="btn btn-outline-secondary" type="button" id="getCurrentLocation" title="Get Current Location">
                                            <i class="bi bi-geo-alt-fill"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Click the location icon to auto-fill your current address</small>
                                </div>

                                <!-- Privacy Consent Section -->
                                <div class="mb-3">
                                    <div class="card border-warning">
                                        <div class="card-header bg-warning bg-opacity-10">
                                            <h6 class="mb-0"><i class="bi bi-shield-check"></i> Privacy & Data Consent</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="privacyConsent" name="privacy_consent">
                                                <label class="form-check-label" for="privacyConsent">
                                                    I agree to the collection and processing of my personal data as outlined in the 
                                                    <a href="#" class="text-primary" data-bs-toggle="modal" data-bs-target="#privacyPolicyModal">Privacy Policy</a>
                                                    <span class="text-danger">*</span>
                                                </label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="dataRetentionConsent" name="data_retention_consent">
                                                <label class="form-check-label" for="dataRetentionConsent">
                                                    I understand and agree to the data retention policies
                                                    <span class="text-danger">*</span>
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="marketingConsent" name="marketing_consent">
                                                <label class="form-check-label" for="marketingConsent">
                                                    I consent to receive service updates and notifications 
                                                </label>
                                            </div>
                                            <small class="text-muted d-block mt-2">
                                                <i class="bi bi-info-circle"></i> Your data will be handled in compliance with the Data Privacy Act of 2012
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100" id="registerButton">
                                    <i class="bi bi-person-plus"></i> Register Account
                                </button>
                            </form>

                            <div class="divider">or</div>
                            <div class="text-center">
                                <a href="login.php" class="login-link"><i class="bi bi-box-arrow-in-right"></i> Already have an account? Login</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Privacy Policy Modal -->
    <div class="modal fade" id="privacyPolicyModal" tabindex="-1" aria-labelledby="privacyPolicyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--headline); color: white;">
                    <h5 class="modal-title" id="privacyPolicyModalLabel">
                        <i class="bi bi-shield-check"></i> Privacy Policy
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">1. Information We Collect</h6>
                        <p class="mb-2">We collect the following personal information:</p>
                        <ul class="list-unstyled ps-3">
                            <li><i class="bi bi-check-circle text-success me-2"></i>Full name and contact information</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i>Address and location data for service delivery</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i>Photos and descriptions of infrastructure issues</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i>Communication records and feedback</li>
                        </ul>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">2. How We Use Your Information</h6>
                        <ul class="list-unstyled ps-3">
                            <li><i class="bi bi-arrow-right text-warning me-2"></i>Process and respond to infrastructure reports</li>
                            <li><i class="bi bi-arrow-right text-warning me-2"></i>Coordinate maintenance and repair activities</li>
                            <li><i class="bi bi-arrow-right text-warning me-2"></i>Send notifications about service updates</li>
                            <li><i class="bi bi-arrow-right text-warning me-2"></i>Improve our services and system functionality</li>
                        </ul>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">3. Data Retention</h6>
                        <p class="mb-2">We retain your data as follows:</p>
                        <ul class="list-unstyled ps-3">
                            <li><i class="bi bi-clock text-info me-2"></i>Active reports: Until resolution + 2 years</li>
                            <li><i class="bi bi-clock text-info me-2"></i>User accounts: Until account deletion requested</li>
                            <li><i class="bi bi-clock text-info me-2"></i>System logs: 1 year for security purposes</li>
                            <li><i class="bi bi-clock text-info me-2"></i>Archived reports: 5 years for historical records</li>
                        </ul>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">4. Your Rights</h6>
                        <p class="mb-2">Under the Data Privacy Act of 2012, you have the right to:</p>
                        <ul class="list-unstyled ps-3">
                            <li><i class="bi bi-person-check text-success me-2"></i>Access your personal data</li>
                            <li><i class="bi bi-pencil text-success me-2"></i>Correct inaccurate information</li>
                            <li><i class="bi bi-trash text-success me-2"></i>Request deletion of your data</li>
                            <li><i class="bi bi-x-circle text-success me-2"></i>Withdraw consent (where applicable)</li>
                            <li><i class="bi bi-file-earmark-text text-success me-2"></i>File complaints with the National Privacy Commission</li>
                        </ul>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">5. Data Security</h6>
                        <p>We implement appropriate security measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction.</p>
                    </div>

                    <div class="alert alert-info">
                        <h6 class="fw-bold"><i class="bi bi-envelope"></i> Contact Information</h6>
                        <p class="mb-1">For privacy concerns, contact our Data Protection Officer at:</p>
                        <p class="mb-1"><strong>Email:</strong> dpo@lgu-infrastructure.gov.ph</p>
                        <p class="mb-0"><strong>Phone:</strong> 0919-075-5101</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
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
        
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        if (toggleConfirmPassword) {
            toggleConfirmPassword.addEventListener('click', function() {
                const confirmPasswordField = document.getElementById('confirmPassword');
                const confirmPasswordIcon = document.getElementById('confirmPasswordIcon');
                
                if (confirmPasswordField.type === 'password') {
                    confirmPasswordField.type = 'text';
                    confirmPasswordIcon.className = 'bi bi-eye-slash';
                } else {
                    confirmPasswordField.type = 'password';
                    confirmPasswordIcon.className = 'bi bi-eye';
                }
            });
        }

        // Form validation and submission
        document.addEventListener('DOMContentLoaded', function() {
            // Load saved form data from localStorage
            loadFormData();

            // Get current location functionality
            document.getElementById('getCurrentLocation').addEventListener('click', function() {
                const button = this;
                const addressField = document.getElementById('address');
                
                button.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                button.disabled = true;
                
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data && data.address) {
                                        const addr = data.address;
                                        const parts = [];
                                        if (addr.house_number) parts.push(addr.house_number);
                                        if (addr.road) parts.push(addr.road);
                                        if (addr.suburb) parts.push(addr.suburb);
                                        if (addr.city) parts.push(addr.city);
                                        if (addr.province) parts.push(addr.province);
                                        if (addr.postcode) parts.push(addr.postcode);
                                        if (addr.country) parts.push(addr.country);
                                        
                                        if (parts.length > 0) {
                                            addressField.value = parts.join(', ');
                                        } else if (data.display_name) {
                                            addressField.value = data.display_name;
                                        } else {
                                            throw new Error('No address found');
                                        }
                                    } else {
                                        throw new Error('No address found');
                                    }
                                })
                                .catch(() => {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'Address Not Found',
                                        text: 'Could not determine address from your location. Please enter manually.',
                                        confirmButtonColor: '#faae2b'
                                    });
                                })
                                .finally(() => {
                                    button.innerHTML = '<i class="bi bi-geo-alt-fill"></i>';
                                    button.disabled = false;
                                });
                        },
                        function(error) {
                            let errorMsg = 'Unable to get location';
                            switch(error.code) {
                                case error.PERMISSION_DENIED:
                                    errorMsg = 'Location access denied. Please enable location permissions in your browser settings.';
                                    break;
                                case error.POSITION_UNAVAILABLE:
                                    errorMsg = 'Location information unavailable. Please try again or enter manually.';
                                    break;
                                case error.TIMEOUT:
                                    errorMsg = 'Location request timed out. Please try again.';
                                    break;
                            }
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Location Error',
                                text: errorMsg,
                                confirmButtonColor: '#fa5246'
                            });
                            
                            button.innerHTML = '<i class="bi bi-geo-alt-fill"></i>';
                            button.disabled = false;
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 15000,
                            maximumAge: 0
                        }
                    );
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Not Supported',
                        text: 'Geolocation is not supported by this browser',
                        confirmButtonColor: '#fa5246'
                    });
                    
                    button.innerHTML = '<i class="bi bi-geo-alt-fill"></i>';
                    button.disabled = false;
                }
            });
            const form = document.getElementById('registrationForm');
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const fullname = document.getElementById('fullname').value.trim();
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                const role = 'resident';
                const contact = document.getElementById('contact').value.trim();
                const address = document.getElementById('address').value.trim();

                // Validate required fields
                if (!fullname || !email || !password || !confirmPassword || !contact || !address) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Missing Information',
                        text: 'Please fill in all required fields',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                // Check if passwords match
                if (password !== confirmPassword) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Password Mismatch',
                        text: 'Passwords do not match. Please try again.',
                        confirmButtonColor: '#fa5246'
                    });
                    return;
                }
                
                // Check password strength
                if (password.length < 8) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must be at least 8 characters long.',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                if (!/[A-Z]/.test(password)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must contain at least one uppercase letter.',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                if (!/[a-z]/.test(password)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must contain at least one lowercase letter.',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                if (!/[0-9]/.test(password)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must contain at least one number.',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                if (!/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must contain at least one special character.',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                // Validate contact number
                if (contact.length !== 11 || !contact.match(/^09[0-9]{9}$/)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Contact Number',
                        text: 'Contact number must be 11 digits starting with 09',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                // Validate email format
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Email',
                        text: 'Please enter a valid email address',
                        confirmButtonColor: '#faae2b'
                    });
                    return;
                }
                
                // Send OTP and show verification modal
                sendOTPAndVerify(fullname, email, password, confirmPassword, role, contact, address);
            });

            // Contact number input restriction
            document.getElementById('contact').addEventListener('input', function(e) {
                let value = e.target.value.replace(/[^0-9]/g, '');
                if (value.length > 11) {
                    value = value.slice(0, 11);
                }
                e.target.value = value;
                saveFormData();
            });

            // Save form data on input changes
            ['fullname', 'email', 'contact', 'address'].forEach(id => {
                document.getElementById(id).addEventListener('input', saveFormData);
            });

            // Password strength checker
            document.getElementById('password').addEventListener('input', function() {
                checkPasswordStrength(this.value);
            });


        });

        function submitRegistration(fullname, email, password, confirmPassword, role, contact, address) {
            const registerButton = document.getElementById('registerButton');
            registerButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            registerButton.disabled = true;

            const formData = new FormData();
            formData.append('fullname', fullname);
            formData.append('email', email);
            formData.append('password', password);
            formData.append('confirmPassword', confirmPassword);
            formData.append('role', role);
            formData.append('contact', contact);
            formData.append('address', address);
            formData.append('privacy_consent', document.getElementById('privacyConsent').checked ? '1' : '0');
            formData.append('data_retention_consent', document.getElementById('dataRetentionConsent').checked ? '1' : '0');
            formData.append('marketing_consent', document.getElementById('marketingConsent').checked ? '1' : '0');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo $_SERVER["PHP_SELF"]; ?>', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onload = function() {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        if (response.user_id) {
                            showFaceCapture(response.user_id);
                        } else {
                            Swal.fire({
                                icon: 'success',
                                title: 'Registration Successful!',
                                text: response.message,
                                confirmButtonColor: '#00473e',
                                confirmButtonText: 'Continue to Login'
                            }).then(() => {
                                clearFormData();
                                window.location.href = 'login.php';
                            });
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Registration Failed',
                            text: response.message,
                            confirmButtonColor: '#fa5246'
                        });
                        resetButton();
                    }
                } catch (e) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Invalid response from server',
                        confirmButtonColor: '#fa5246'
                    });
                    resetButton();
                }
            };

            xhr.onerror = function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please check your connection and try again',
                    confirmButtonColor: '#fa5246'
                });
                resetButton();
            };

            xhr.send(formData);
        }
        
        function resetButton() {
            const registerButton = document.getElementById('registerButton');
            registerButton.innerHTML = '<i class="bi bi-person-plus"></i> Register Account';
            registerButton.disabled = false;
        }

        // Save form data to localStorage
        function saveFormData() {
            const formData = {
                fullname: document.getElementById('fullname').value,
                email: document.getElementById('email').value,
                contact: document.getElementById('contact').value,
                address: document.getElementById('address').value
            };
            localStorage.setItem('rtim_registration_data', JSON.stringify(formData));
        }

        // Load form data from localStorage
        function loadFormData() {
            const savedData = localStorage.getItem('rtim_registration_data');
            if (savedData) {
                const formData = JSON.parse(savedData);
                const hasData = formData.fullname || formData.email || formData.contact || formData.address;
                
                if (hasData) {
                    document.getElementById('fullname').value = formData.fullname || '';
                    document.getElementById('email').value = formData.email || '';
                    document.getElementById('contact').value = formData.contact || '';
                    document.getElementById('address').value = formData.address || '';
                    
                    Swal.fire({
                        icon: 'info',
                        title: 'Form Data Restored',
                        text: 'Your previous form data has been restored',
                        confirmButtonColor: '#faae2b',
                        timer: 3000,
                        timerProgressBar: true
                    });
                }
            }
        }

        // Clear form data from localStorage
        function clearFormData() {
            localStorage.removeItem('rtim_registration_data');
        }

        // Send OTP and show verification modal
        function sendOTPAndVerify(fullname, email, password, confirmPassword, role, contact, address) {
            // Show loading alert
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

            fetch('send_registration_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'email=' + encodeURIComponent(email)
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    showOTPModal(fullname, email, password, confirmPassword, role, contact, address);
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

        // Show OTP verification modal
        function showOTPModal(fullname, email, password, confirmPassword, role, contact, address) {
            let timeLeft = 120;
            Swal.fire({
                title: 'Email Verification',
                html: `
                    <img src="admin/logo.jpg" alt="LGU Logo" class="otp-logo">
                    <p style="margin-bottom: 10px;">We've sent a 6-digit OTP to <strong>${email}</strong></p>
                    <div style="background: #fff3cd; border: 2px solid #faae2b; border-radius: 8px; padding: 12px; margin-bottom: 15px;">
                        <p style="margin: 0; font-size: 14px; color: #856404;"><strong>Time remaining:</strong> <span id="registerCountdown" style="font-size: 18px; font-weight: bold; color: #fa5246;">2:00</span></p>
                    </div>
                    <input type="text" id="registerOtpInput" class="swal2-input" placeholder="Enter 6-digit OTP" maxlength="6">
                `,
                showCancelButton: true,
                confirmButtonText: 'Verify & Register',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#00473e',
                cancelButtonColor: '#fa5246',
                allowOutsideClick: false,
                didOpen: () => {
                    const countdownInterval = setInterval(() => {
                        timeLeft--;
                        const mins = Math.floor(timeLeft / 60);
                        const secs = timeLeft % 60;
                        document.getElementById('registerCountdown').textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
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
                    const otp = document.getElementById('registerOtpInput').value;
                    if (!otp || otp.length !== 6) {
                        Swal.showValidationMessage('Please enter a valid 6-digit OTP');
                        return false;
                    }
                    return otp;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    verifyOTPAndRegister(result.value, fullname, email, password, confirmPassword, role, contact, address);
                }
            });
        }

        // Check password strength
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            let score = 0;
            
            // Check length
            const hasLength = password.length >= 8;
            updateRequirement('req-length', hasLength);
            if (hasLength) score++;
            
            // Check uppercase
            const hasUpper = /[A-Z]/.test(password);
            updateRequirement('req-upper', hasUpper);
            if (hasUpper) score++;
            
            // Check lowercase
            const hasLower = /[a-z]/.test(password);
            updateRequirement('req-lower', hasLower);
            if (hasLower) score++;
            
            // Check number
            const hasNumber = /[0-9]/.test(password);
            updateRequirement('req-number', hasNumber);
            if (hasNumber) score++;
            
            // Check special character
            const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
            updateRequirement('req-special', hasSpecial);
            if (hasSpecial) score++;
            
            strengthBar.className = 'strength-bar';
            
            if (score < 3) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-danger';
            } else if (score < 5) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Medium password';
                strengthText.className = 'text-warning';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-success';
            }
        }
        
        function updateRequirement(id, met) {
            const element = document.getElementById(id);
            const icon = element.querySelector('i');
            if (met) {
                icon.className = 'bi bi-check-circle text-success';
            } else {
                icon.className = 'bi bi-x-circle text-danger';
            }
        }

        // Verify OTP and complete registration
        function verifyOTPAndRegister(otp, fullname, email, password, confirmPassword, role, contact, address) {
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

            fetch('verify_registration_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'otp=' + encodeURIComponent(otp) + '&email=' + encodeURIComponent(email)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    submitRegistration(fullname, email, password, confirmPassword, role, contact, address);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Verification Failed',
                        text: data.message,
                        confirmButtonColor: '#fa5246'
                    }).then(() => {
                        showOTPModal(fullname, email, password, confirmPassword, role, contact, address);
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
        
        function showFaceCapture(userId) {
            Swal.fire({
                title: 'Register Your Face',
                html: '<div style="position:relative;display:inline-block;"><video id="regVideo" width="320" height="240" autoplay style="border-radius:10px;"></video><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:250px;border:3px dashed #faae2b;border-radius:50%;pointer-events:none;"></div></div><canvas id="regCanvas" style="display:none;"></canvas><p style="margin-top:10px;color:#856404;"><i class="bi bi-info-circle"></i> Position your face within the oval guide</p>',
                showCancelButton: true,
                confirmButtonText: 'Capture Face',
                didOpen: () => {
                    navigator.mediaDevices.getUserMedia({ video: true }).then(s => {
                        document.getElementById('regVideo').srcObject = s;
                        Swal.getPopup().stream = s;
                    }).catch(() => Swal.showValidationMessage('Camera denied'));
                },
                willClose: () => { const s = Swal.getPopup().stream; if(s) s.getTracks().forEach(t => t.stop()); },
                preConfirm: () => {
                    const v = document.getElementById('regVideo'), c = document.getElementById('regCanvas');
                    c.width = v.videoWidth; c.height = v.videoHeight;
                    c.getContext('2d').drawImage(v, 0, 0);
                    return c.toDataURL('image/jpeg').split(',')[1];
                }
            }).then(r => {
                if(r.isConfirmed) {
                    saveFaceImage(userId, r.value);
                } else {
                    window.location.href = 'login.php';
                }
            });
        }
        
        function saveFaceImage(userId, img) {
            Swal.fire({ title: 'Saving Face', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            fetch('save_face.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user_id=${userId}&face_image=${encodeURIComponent(img)}`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    Swal.fire({ icon: 'success', title: 'Face Registered!', text: 'You can now login', confirmButtonColor: '#00473e' }).then(() => {
                        clearFormData();
                        window.location.href = 'login.php';
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Failed', text: d.message }).then(() => showFaceCapture(userId));
                }
            });
        }
    </script>
</body>
</html>