<?php
// Ensure this file is included and not accessed directly
if (!defined('ADMIN_PANEL')) {
    exit('Direct access not permitted');
}

// Function to get total number of users
function getTotalUsers() {
    global $db;
    $result = $db->query("SELECT COUNT(*) as total FROM users");
    return $result->fetch_assoc()['total'];
}

// Function to get total number of products
function getTotalProducts() {
    global $db;
    $result = $db->query("SELECT COUNT(*) as total FROM products");
    return $result->fetch_assoc()['total'];
}

// Function to get total number of orders (assuming you have an orders table)
function getTotalOrders() {
    global $db;
    $result = $db->query("SELECT COUNT(*) as total FROM orders");
    return $result->fetch_assoc()['total'];
}

$totalUsers = getTotalUsers();
$totalProducts = getTotalProducts();
$totalOrders = getTotalOrders();
?>

<h2 class="text-2xl font-bold mb-4">Dashboard</h2>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-xl font-semibold mb-2">Total Users</h3>
        <p class="text-3xl font-bold text-blue-600"><?php echo $totalUsers; ?></p>
    </div>
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-xl font-semibold mb-2">Total Products</h3>
        <p class="text-3xl font-bold text-green-600"><?php echo $totalProducts; ?></p>
    </div>
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-xl font-semibold mb-2">Total Orders</h3>
        <p class="text-3xl font-bold text-purple-600"><?php echo $totalOrders; ?></p>
    </div>
</div>

<div class="mt-8 bg-white p-6 rounded-lg shadow-md">
    <h3 class="text-xl font-semibold mb-4">Recent Activity</h3>
    <p class="text-gray-600">No recent activity to display.</p>
    <!-- You can add more detailed recent activity here, such as recent orders or user registrations -->
</div>
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    </script>

