<?php
// config/database.php (Production version with environment variables)

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db_name = getenv('DB_NAME') ?: 'road_rtim';
        $this->username = getenv('DB_USER') ?: 'road_rtim';
        $this->password = getenv('DB_PASS') ?: 'O!^Ea*Ny0G#^ZM#y';
    }

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4", 
                $this->username, 
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
        } catch (PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
        
        return $this->conn;
    }
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $user_id = $_SESSION['user_id'] ?? null;
    $fullname = $_SESSION['user_name'] ?? 'Guest';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $portal = $_SESSION['user_role'] ?? 'guest';
    $path = basename($_SERVER['PHP_SELF']);
    
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, fullname, action, description, portal, path, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $fullname, 'page_access', 'User accessed page', $portal, $path, $ip_address]);
} catch (Exception $e) {
    error_log("Audit log error: " . $e->getMessage());
}
?>