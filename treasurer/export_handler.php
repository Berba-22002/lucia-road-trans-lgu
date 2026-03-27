<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'treasurer') exit('Access Denied');

$start = $_GET['start'];
$end = $_GET['end'];

$database = new Database();
$pdo = $database->getConnection();

$query = "
    SELECT paid_at as date, ticket_number as ref, 'Income' as type, penalty_amount as amt FROM ovr_tickets WHERE payment_status='paid' AND DATE(paid_at) BETWEEN ? AND ?
    UNION ALL
    SELECT approved_at as date, CAST(id AS CHAR) as ref, 'Expense' as type, -approved_amount as amt FROM fund_requests WHERE status IN ('approved','completed') AND DATE(approved_at) BETWEEN ? AND ?
    ORDER BY date ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$start, $end, $start, $end]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Financial_Report_'.$start.'_to_'.$end.'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Financial Report Period:', $start, 'to', $end]);
fputcsv($output, ['Date', 'Reference', 'Type', 'Amount (PHP)']);

foreach ($data as $row) {
    fputcsv($output, [$row['date'], $row['ref'], $row['type'], $row['amt']]);
}
fclose($output);