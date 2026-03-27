<?php
function getRelatedHazards($pdo, $report_id, $address) {
    $query = "
        SELECT r.id, r.hazard_type, r.address, r.status, r.created_at, u.fullname as reporter_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id != ? 
        AND r.address LIKE ?
        AND r.status != 'completed'
        ORDER BY r.created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$report_id, "%$address%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function displayRelatedHazards($related) {
    if (empty($related)) return '';
    
    $html = '<div class="mt-3 p-3 bg-orange-50 rounded-lg border border-orange-200">';
    $html .= '<div class="flex items-center gap-2 mb-2">';
    $html .= '<i class="fas fa-link text-orange-600"></i>';
    $html .= '<span class="font-medium text-orange-800 text-sm">Related Hazards in Area</span>';
    $html .= '</div>';
    $html .= '<div class="space-y-2">';
    
    foreach ($related as $rel) {
        $html .= '<div class="text-xs bg-white p-2 rounded border border-orange-100">';
        $html .= '<p class="font-semibold text-orange-700">';
        $html .= '<i class="fas fa-exclamation-triangle mr-1"></i>';
        $html .= 'Report #' . htmlspecialchars($rel['id']) . ' - ' . ucfirst($rel['hazard_type']);
        $html .= '</p>';
        $html .= '<p class="text-gray-600">' . htmlspecialchars($rel['address']) . '</p>';
        $html .= '<p class="text-gray-500">Status: ' . ucfirst(str_replace('_', ' ', $rel['status'])) . '</p>';
        $html .= '</div>';
    }
    
    $html .= '</div></div>';
    return $html;
}
?>
