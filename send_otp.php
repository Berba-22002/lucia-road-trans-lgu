<?php
session_start();
require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }
    
    // Check if email exists in database
    try {
        $database = new Database();
        $pdo = $database->getConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Email address not registered in the system']);
            exit();
        }
    } catch (PDOException $e) {
        error_log("Database error in send_otp.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
        exit();
    }
    
    // Generate 6-digit OTP
    $otp = sprintf("%06d", mt_rand(1, 999999));
    
    // Store OTP in session with expiry
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_expiry'] = time() + 120; // 2 minutes
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'lgu1.infrastructureutilities@gmail.com';
        $mail->Password = 'kpyvrwvptmxwzvoq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('lgu1.infrastructureutilities@gmail.com', 'RTIM System');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Login Verification Code - RTIM System';
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: white;'>
            <!-- Header -->
            <div style='background: #00473e; padding: 30px; text-align: center;'>
                <div style='width: 80px; height: 80px; background: #faae2b; border-radius: 50%; margin: 0 auto 15px; line-height: 80px; text-align: center;'>
                    <span style='color: #00473e; font-size: 24px; font-weight: bold;'>LGU</span>
                </div>
                <h1 style='color: white; margin: 0; font-size: 24px;'>RTIM System</h1>
                <p style='color: #faae2b; margin: 5px 0 0 0; font-size: 14px;'>Road & Transportation Infrastructure Monitoring</p>
            </div>
            
            <!-- Content -->
            <div style='padding: 40px 30px;'>
                <h2 style='color: #00473e; margin: 0 0 20px 0; font-size: 22px; text-align: center;'>Email Verification</h2>
                <p style='color: #475d5b; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0; text-align: center;'>We've received a login request for your account. Please use the verification code below:</p>
                
                <!-- OTP Box -->
                <div style='background: #f2f7f5; border: 3px solid #faae2b; border-radius: 10px; padding: 30px; text-align: center; margin: 30px 0;'>
                    <p style='color: #475d5b; margin: 0 0 10px 0; font-size: 14px;'>Your Verification Code</p>
                    <h1 style='color: #faae2b; font-size: 36px; font-weight: bold; margin: 0; letter-spacing: 5px;'>{$otp}</h1>
                </div>
                
                <div style='background: #fff3cd; border: 1px solid #faae2b; border-radius: 5px; padding: 15px; margin: 20px 0; text-align: center;'>
                    <p style='color: #856404; margin: 0; font-size: 14px;'>
                        <strong>Important:</strong> This code expires in <strong>2 minutes</strong>
                    </p>
                </div>
                
                <p style='color: #475d5b; font-size: 14px; line-height: 1.6; margin: 20px 0 0 0; text-align: center;'>If you didn't request this, please ignore this email.</p>
            </div>
            
            <!-- Footer -->
            <div style='background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #e9ecef;'>
                <p style='color: #6c757d; font-size: 12px; margin: 0;'>© 2024 LGU - RTIM System. All rights reserved.</p>
            </div>
        </div>";
        
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP: ' . $mail->ErrorInfo]);
    }
}
?>