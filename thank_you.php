<?php
session_start();
$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id === 0) {
    header("Location: home.php");
    exit();
}

// Fetch order details and products
$query = "SELECT o.id, o.total_amount, p.name, oi.quantity
          FROM orders o
          JOIN order_items oi ON o.id = oi.order_id
          JOIN products p ON oi.product_id = p.id
          WHERE o.id = ?";

$stmt = $db->prepare($query);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

$order_details = [];
$total_amount = 0;
while ($row = $result->fetch_assoc()) {
    $order_details[] = $row;
    $total_amount = $row['total_amount'];
}

if (empty($order_details)) {
    header("Location: home.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - Wastewise E-commerce</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 font-sans">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white p-8 rounded-lg shadow-md max-w-md mx-auto text-center">
            <h1 class="text-3xl font-bold mb-4 text-green-800">Thank You for Your Order!</h1>
            <p class="text-xl mb-4">Your order has been successfully placed.</p>
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-2">Order Summary:</h2>
                <ul class="list-disc list-inside text-left">
                    <?php foreach ($order_details as $item): ?>
                        <li><?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <p class="mt-4 font-semibold">Total Amount: ₱<?= number_format($total_amount, 2) ?></p>
            </div>
            <p class="mb-8">We'll send you an email with the order details and tracking information once your order has been shipped.</p>
            <a href="home.php" class="bg-green-600 text-white px-6 py-2 rounded-full hover:bg-green-700 transition duration-300">Continue Shopping</a>
        </div>
    </div>
     <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
</body>
</html>

