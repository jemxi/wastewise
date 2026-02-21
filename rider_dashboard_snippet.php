<?php

if ($user['role'] == 'rider') {
    // Get pending deliveries for this rider
    $rider_query = "
        SELECT d.id, d.order_id, d.delivery_address, o.name as customer_name, o.phone, 
               d.status, d.assigned_date, o.total_amount
        FROM deliveries d
        JOIN orders o ON d.order_id = o.id
        WHERE d.rider_id = (SELECT id FROM riders WHERE user_id = ?)
        ORDER BY d.assigned_date DESC
    ";
    
    $stmt = $pdo->prepare($rider_query);
    $stmt->execute([$_SESSION['user_id']]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Display deliveries with proof upload button
    foreach ($deliveries as $delivery) {
        if ($delivery['status'] != 'delivered') {
            echo '<a href="proof_of_delivery.php?delivery_id=' . $delivery['id'] . '" class="block p-4 bg-white rounded-lg shadow hover:shadow-lg transition-shadow">';
            echo '<h4 class="font-bold">Order #' . $delivery['order_id'] . '</h4>';
            echo '<p class="text-sm text-gray-600">' . htmlspecialchars($delivery['customer_name']) . '</p>';
            echo '<p class="text-xs text-green-600 mt-2">Click to submit proof of delivery</p>';
            echo '</a>';
        }
    }
}
?>
