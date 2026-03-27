<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';

if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        putenv("$key=$value");
    }
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'resident') {
    echo json_encode(value: ['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = (new Database())->getConnection();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT * FROM ovr_tickets WHERE id = ? AND resident_id = ? AND payment_status != 'paid'");
        $stmt->execute([$ticket_id, $_SESSION['user_id']]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            throw new Exception('Ticket not found or already paid');
        }

        $paymongo = new PayMongo();
        $amount = floatval($ticket['penalty_amount']);
        
        $session = $paymongo->createCheckoutSession([
            'line_items' => [[
                'name' => 'OVR Ticket #' . $ticket['ticket_number'],
                'amount' => intval($amount * 100),
                'currency' => 'PHP',
                'quantity' => 1
            ]],
            'payment_method_types' => ['card'],
            'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/resident/view_tickets.php?id=' . $ticket_id . '&success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/resident/view_tickets.php?id=' . $ticket_id,
            'description' => 'Payment for OVR Ticket #' . $ticket['ticket_number']
        ]);

        echo json_encode([
            'success' => true,
            'checkout_url' => $session['data']['attributes']['checkout_url'],
            'session_id' => $session['data']['id']
        ]);

    } elseif ($action === 'status') {
        $session_id = $_POST['session_id'] ?? '';
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        
        if (!$session_id || !$ticket_id) {
            throw new Exception('Missing parameters');
        }

        $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . $session_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(getenv('PAYMONGO_SECRET_KEY') . ':')
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        $status = $data['data']['attributes']['payment_status'] ?? 'pending';

        if ($status === 'paid') {
            $stmt = $pdo->prepare("UPDATE ovr_tickets SET payment_status = 'paid', paid_date = NOW() WHERE id = ? AND resident_id = ?");
            $stmt->execute([$ticket_id, $_SESSION['user_id']]);
        }

        echo json_encode([
            'success' => true,
            'status' => $status === 'paid' ? 'succeeded' : $status
        ]);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
