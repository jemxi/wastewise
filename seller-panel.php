<?php
// Start session if not already started
if (session_status() == PHP_SESSION_INACTIVE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=seller_panel.php");
    exit;
}

// Include database connection
require_once 'includes/db_connection.php';

// Define constants
define('SELLER_PANEL', true);

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Check if user is a seller
$stmt = $db->prepare("SELECT * FROM sellers WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // User is not a seller, redirect to become a seller page
    header("Location: become_seller.php");
    exit;
}

$seller = $result->fetch_assoc();

// Check if seller is approved
if ($seller['status'] !== 'approved') {
    // Seller is not approved yet
    header("Location: seller_verification_pending.php");
    exit;
}

// Get seller statistics
// Total products
$stmt = $db->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ?");
$stmt->bind_param("i", $seller['id']);
$stmt->execute();
$total_products = $stmt->get_result()->fetch_assoc()['total'];

// Total orders
$stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE seller_id = ?");
$stmt->bind_param("i", $seller['id']);
$stmt->execute();
$total_orders = $stmt->get_result()->fetch_assoc()['total'];

// Total sales
$stmt = $db->prepare("SELECT SUM(total_amount) as total FROM orders WHERE seller_id = ? AND status != 'cancelled'");
$stmt->bind_param("i", $seller['id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$total_sales = $result['total'] ? $result['total'] : 0;

// Recent orders
$stmt = $db->prepare("SELECT o.*, 
                     u.first_name, u.last_name,
                     COUNT(oi.id) as item_count
                     FROM orders o
                     JOIN users u ON o.customer_id = u.id
                     JOIN order_items oi ON o.id = oi.order_id
                     WHERE o.seller_id = ?
                     GROUP BY o.id
                     ORDER BY o.created_at DESC
                     LIMIT 5");
$stmt->bind_param("i", $seller['id']);
$stmt->execute();
$recent_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Order counts by status
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM orders WHERE seller_id = ? GROUP BY status";
$stmt = $db->prepare($status_query);
$stmt->bind_param("i", $seller['id']);
$stmt->execute();
$status_results = $stmt->get_result();
while ($row = $status_results->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
}

// Include header
include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Seller Dashboard</h1>
            <p class="text-gray-600">Welcome back, <?php echo htmlspecialchars($seller['business_name']); ?>!</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="add_product.php" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                <i class="fas fa-plus mr-2"></i> Add New Product
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-box-open text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Total Products</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $total_products; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-600">
                    <i class="fas fa-shopping-cart text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Total Orders</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $total_orders; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                    <i class="fas fa-dollar-sign text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Total Sales</p>
                    <p class="text-2xl font-semibold text-gray-800">$<?php echo number_format($total_sales, 2); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                    <i class="fas fa-clock text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">Pending Orders</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo isset($status_counts['pending']) ? $status_counts['pending'] : 0; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Order Status Overview -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Order Status Overview</h2>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <a href="seller_orders.php?status=pending" class="bg-yellow-50 rounded-lg p-4 hover:bg-yellow-100">
                <h3 class="text-lg font-semibold text-yellow-700">Pending</h3>
                <p class="text-3xl font-bold text-yellow-600"><?php echo isset($status_counts['pending']) ? $status_counts['pending'] : 0; ?></p>
            </a>
            <a href="seller_orders.php?status=processing" class="bg-purple-50 rounded-lg p-4 hover:bg-purple-100">
                <h3 class="text-lg font-semibold text-purple-700">Processing</h3>
                <p class="text-3xl font-bold text-purple-600"><?php echo isset($status_counts['processing']) ? $status_counts['processing'] : 0; ?></p>
            </a>
            <a href="seller_orders.php?status=shipped" class="bg-indigo-50 rounded-lg p-4 hover:bg-indigo-100">
                <h3 class="text-lg font-semibold text-indigo-700">Shipped</h3>
                <p class="text-3xl font-bold text-indigo-600"><?php echo isset($status_counts['shipped']) ? $status_counts['shipped'] : 0; ?></p>
            </a>
            <a href="seller_orders.php?status=delivered" class="bg-green-50 rounded-lg p-4 hover:bg-green-100">
                <h3 class="text-lg font-semibold text-green-700">Delivered</h3>
                <p class="text-3xl font-bold text-green-600"><?php echo isset($status_counts['delivered']) ? $status_counts['delivered'] : 0; ?></p>
            </a>
            <a href="seller_orders.php?status=cancelled" class="bg-red-50 rounded-lg p-4 hover:bg-red-100">
                <h3 class="text-lg font-semibold text-red-700">Cancelled</h3>
                <p class="text-3xl font-bold text-red-600"><?php echo isset($status_counts['cancelled']) ? $status_counts['cancelled'] : 0; ?></p>
            </a>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Orders -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Recent Orders</h2>
                <a href="seller_orders.php" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
            </div>
            
            <?php if (empty($recent_orders)): ?>
                <div class="text-center py-4">
                    <p class="text-gray-500">No orders yet.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Order
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Customer
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" class="hover:text-blue-600">
                                                #<?php echo $order['id']; ?>
                                            </a>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo $order['item_count']; ?> items
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_class = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'processing' => 'bg-purple-100 text-purple-800',
                                            'shipped' => 'bg-indigo-100 text-indigo-800',
                                            'delivered' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $status = $order['status'];
                                        $class = isset($status_class[$status]) ? $status_class[$status] : 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        $<?php echo number_format($order['total_amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Links -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Quick Actions</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="add_product.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <div class="p-3 rounded-full bg-green-100 text-green-600">
                        <i class="fas fa-plus"></i>
                    </div>
                    <div class="ml-4">
                        <p class="font-medium text-gray-900">Add Product</p>
                        <p class="text-sm text-gray-500">Create a new product listing</p>
                    </div>
                </a>
                
                <a href="seller_orders.php?status=pending" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="ml-4">
                        <p class="font-medium text-gray-900">Pending Orders</p>
                        <p class="text-sm text-gray-500">View and process new orders</p>
                    </div>
                </a>
                
                <a href="seller_products.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="ml-4">
                        <p class="font-medium text-gray-900">Manage Products</p>
                        <p class="text-sm text-gray-500">Edit your product listings</p>
                    </div>
                </a>
                
                <a href="seller_profile.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="ml-4">
                        <p class="font-medium text-gray-900">Store Settings</p>
                        <p class="text-sm text-gray-500">Update your store profile</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
</script>