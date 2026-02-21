<?php
// Ensure this file is included and not accessed directly
if (!defined('ADMIN_PANEL')) {
    die('Direct access not permitted');
}

// Function to get archived orders with pagination and filtering
function getArchivedOrders($page = 1, $limit = 10, $search = '') {
    global $db;
    $offset = ($page - 1) * $limit;

    $query = "SELECT o.*, u.username, 
              GROUP_CONCAT(DISTINCT p.name SEPARATOR '|||') as product_names,
              GROUP_CONCAT(DISTINCT p.image SEPARATOR '|||') as product_images,
              GROUP_CONCAT(DISTINCT oi.quantity SEPARATOR '|||') as product_quantities
              FROM orders o 
              JOIN users u ON o.user_id = u.id
              LEFT JOIN order_items oi ON o.id = oi.order_id
              LEFT JOIN products p ON oi.product_id = p.id
              WHERE o.archived = 1";
    $countQuery = "SELECT COUNT(*) as total FROM orders o WHERE o.archived = 1";

    if (!empty($search)) {
        $search = $db->real_escape_string($search);
        $query .= " AND (o.id LIKE '%$search%' OR u.username LIKE '%$search%' OR o.name LIKE '%$search%' OR o.email LIKE '%$search%')";
        $countQuery .= " AND (o.id LIKE '%$search%' OR u.username LIKE '%$search%' OR o.name LIKE '%$search%' OR o.email LIKE '%$search%')";
    }

    $query .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT {$offset}, {$limit}";

    $result = $db->query($query);
    if (!$result) {
        die("Database query failed: " . $db->error);
    }
    $orders = $result->fetch_all(MYSQLI_ASSOC);

    $countResult = $db->query($countQuery);
    if (!$countResult) {
        die("Count query failed: " . $db->error);
    }
    $totalOrders = $countResult->fetch_assoc()['total'];

    return [
        'orders' => $orders,
        'total' => $totalOrders
    ];
}

// Get current page number from URL
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get archived orders with pagination
$ordersData = getArchivedOrders($page, 10, $search);
$orders = $ordersData['orders'];
$totalOrders = $ordersData['total'];
$totalPages = ceil($totalOrders / 10);
?>

<div class="container mx-auto px-4">
    <h2 class="text-2xl font-bold mb-4">Archived Orders</h2>

    <!-- Link back to Order Management -->
    <div class="mb-4">
        <a href="?page=order_management" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            Back to Order Management
        </a>
    </div>

    <!-- Search Form -->
    <form action="" method="GET" class="mb-6">
        <input type="hidden" name="page" value="archived_orders">
        <div class="flex gap-2">
            <input type="text" name="search" placeholder="Search archived orders..." 
                   value="<?php echo htmlspecialchars($search); ?>" 
                   class="flex-1 border rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
            <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">
                Search
            </button>
        </div>
    </form>

    <!-- Archived Orders Table -->
    <div class="overflow-x-auto">
        <table class="min-w-full bg-white">
            <thead>
                <tr class="bg-gray-200 text-gray-600 uppercase text-sm leading-normal">
                    <th class="py-3 px-6 text-left">Order ID</th>
                    <th class="py-3 px-6 text-left">Customer</th>
                    <th class="py-3 px-6 text-left">Products</th>
                    <th class="py-3 px-6 text-left">Total Amount</th>
                    <th class="py-3 px-6 text-left">Date</th>
                </tr>
            </thead>
            <tbody class="text-gray-600 text-sm font-light">
                <?php foreach ($orders as $order): 
                    $productNames = explode('|||', $order['product_names']);
                    $productImages = explode('|||', $order['product_images']);
                    $productQuantities = explode('|||', $order['product_quantities']);
                ?>
                <tr class="border-b border-gray-200 hover:bg-gray-100">
                    <td class="py-3 px-6 text-left whitespace-nowrap">
                        <?php echo htmlspecialchars($order['id']); ?>
                    </td>
                    <td class="py-3 px-6 text-left">
                        <?php echo htmlspecialchars($order['name']); ?><br>
                        <span class="text-gray-500"><?php echo htmlspecialchars($order['email']); ?></span>
                    </td>
                    <td class="py-3 px-6 text-left">
                        <div class="flex flex-col space-y-2">
                            <?php for ($i = 0; $i < min(count($productNames), 3); $i++): ?>
                                <div class="flex items-center space-x-2">
                                    <img src="<?php echo htmlspecialchars($productImages[$i]); ?>" alt="<?php echo htmlspecialchars($productNames[$i]); ?>" class="w-10 h-10 object-cover rounded">
                                    <span><?php echo htmlspecialchars($productNames[$i]) . ' (x' . $productQuantities[$i] . ')'; ?></span>
                                </div>
                            <?php endfor; ?>
                            <?php if (count($productNames) > 3): ?>
                                <span class="text-sm text-gray-500">and <?php echo count($productNames) - 3; ?> more...</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="py-3 px-6 text-left">
                        ₱<?php echo number_format($order['total_amount'], 2); ?>
                    </td>
                    <td class="py-3 px-6 text-left">
                        <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mt-4 flex justify-center">
        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=archived_orders&p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50
                          <?php echo $page === $i ? 'bg-green-50 text-green-600' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>

