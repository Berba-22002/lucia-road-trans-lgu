<?php
function compareFaces($image1_base64, $image2_base64) {
    $api_key = 'sUVFbVUxUQ76m2it-zC2yeOlvKWgJoRA';
    $api_secret = 'CixAxY7NOtjg2zh91aRTcUuDdUNKbQAF';
    
    $ch = curl_init('https://api-us.faceplusplus.com/facepp/v3/compare');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'api_key' => $api_key,
        'api_secret' => $api_secret,
        'image_base64_1' => $image1_base64,
        'image_base64_2' => $image2_base64
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'API request failed'];
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error_message'])) {
        return ['success' => false, 'error' => $result['error_message']];
    }
    
    if (!isset($result['confidence'])) {
        return ['success' => false, 'error' => 'No face detected'];
    }
    
    return [
        'success' => true,
        'confidence' => $result['confidence'],
        'thresholds' => $result['thresholds'],
        'match' => $result['confidence'] >= $result['thresholds']['1e-5']
    ];
}
?>
