<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = $_POST['otp'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (empty($otp) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'OTP and email are required']);
        exit();
    }
    
    // Check if OTP exists and is valid
    if (!isset($_SESSION['registration_otp']) || !isset($_SESSION['registration_email']) || !isset($_SESSION['otp_expiry'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit();
    }
    
    // Check if OTP expired
    if (time() > $_SESSION['otp_expiry']) {
        unset($_SESSION['registration_otp'], $_SESSION['registration_email'], $_SESSION['otp_expiry']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit();
    }
    
    // Check if email matches
    if ($_SESSION['registration_email'] !== $email) {
        echo json_encode(['success' => false, 'message' => 'Email mismatch']);
        exit();
    }
    
    // Check if OTP matches
    if ($_SESSION['registration_otp'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        exit();
    }
    
    // OTP is valid - set verification flag
    $_SESSION['email_verified'] = true;
    $_SESSION['verified_email'] = $email;
    
    // Clear OTP data
    unset($_SESSION['registration_otp'], $_SESSION['registration_email'], $_SESSION['otp_expiry']);
    
    echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
}
?>