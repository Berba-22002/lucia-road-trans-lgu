<?php
header('Content-Type: application/json');

if (!isset($_GET['address']) || empty($_GET['address'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Address parameter required']);
    exit;
}

$address = $_GET['address'];
$tomtomKey = getenv('TOMTOM_API_KEY') ?: 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';

$url = 'https://api.tomtom.com/search/2/geocode/' . urlencode($address) . '.json?key=' . urlencode($tomtomKey);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo $response;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Geocoding service unavailable']);
}
