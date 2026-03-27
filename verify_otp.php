<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = $_POST['otp'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (empty($otp) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'OTP and email are required']);
        exit();
    }
    
    // Check if OTP exists and is valid
    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_expiry'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit();
    }
    
    // Check if OTP expired
    if (time() > $_SESSION['otp_expiry']) {
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit();
    }
    
    // Check if email matches
    if ($_SESSION['otp_email'] !== $email) {
        echo json_encode(['success' => false, 'message' => 'Email mismatch']);
        exit();
    }
    
    // Verify OTP
    if ($_SESSION['otp'] === $otp) {
        $_SESSION['email_verified'] = true;
        $_SESSION['verified_email'] = $email;
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry']);
        echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
    }
}
?>