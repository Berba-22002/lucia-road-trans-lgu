<?php
/**
 * Login Attempt Handler
 * Tracks failed login attempts and implements cooldown
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class LoginAttemptHandler {
    private $max_attempts = 2;
    private $cooldown_duration = 180; // 3 minutes
    
    public function checkAttempts($email) {
        $attempt_key = 'login_attempts_' . md5($email);
        $cooldown_key = 'login_cooldown_' . md5($email);
        
        // Check if user is in cooldown
        if (isset($_SESSION[$cooldown_key]) && time() < $_SESSION[$cooldown_key]) {
            $remaining = $_SESSION[$cooldown_key] - time();
            return [
                'status' => 'cooldown',
                'remaining' => $remaining,
                'message' => "Too many failed attempts. Please wait " . ceil($remaining / 60) . " minute(s) before trying again."
            ];
        }
        
        // Clear cooldown if expired
        if (isset($_SESSION[$cooldown_key]) && time() >= $_SESSION[$cooldown_key]) {
            unset($_SESSION[$cooldown_key]);
            unset($_SESSION[$attempt_key]);
        }
        
        return ['status' => 'allowed'];
    }
    
    public function recordFailedAttempt($email) {
        $attempt_key = 'login_attempts_' . md5($email);
        $cooldown_key = 'login_cooldown_' . md5($email);
        
        $_SESSION[$attempt_key] = ($_SESSION[$attempt_key] ?? 0) + 1;
        $attempts = $_SESSION[$attempt_key];
        
        if ($attempts >= $this->max_attempts) {
            $_SESSION[$cooldown_key] = time() + $this->cooldown_duration;
            return [
                'status' => 'locked',
                'attempts' => $attempts,
                'message' => 'Too many failed attempts. Your account is locked for 3 minutes.'
            ];
        }
        
        return [
            'status' => 'warning',
            'attempts' => $attempts,
            'message' => 'Invalid credentials. ' . ($this->max_attempts - $attempts) . ' attempt(s) remaining.'
        ];
    }
    
    public function clearAttempts($email) {
        $attempt_key = 'login_attempts_' . md5($email);
        unset($_SESSION[$attempt_key]);
    }
}

$login_handler = new LoginAttemptHandler();
?>
