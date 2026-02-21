<?php
// Start session if not already started
if (session_status() == PHP_SESSION_INACTIVE) {
    session_start();
}

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: login.php?redirect=view_order.php");
    exit;
}

// Include database connection
require_once 'includes/db_connection.php';

// Define constants
define('SELLER_PANEL', true);

// Get seller ID
$seller_id = null;
$user_id = $_SESSION['user_id'];

// Get seller ID from user ID
$stmt = $db->prepare("SELECT id FROM sellers WHERE user_id = ? AND status = 'approved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $seller = $result->fetch_assoc();
    $seller_id = $seller['id'];
} else {
    // If not an approved seller, redirect to dashboard
    header("Location: dashboard.php?error=not_approved_seller");
    exit;
}

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: seller_orders.php");
    exit;
}

$order_id = intval($_GET['id']);

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['status'])) {
    $status = $db->real_escape_string($_POST['status']);
    $allowed_statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
    
    if (in_array($status, $allowed_statuses)) {
        // Update order status
        $stmt = $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND seller_id = ?");
        $stmt->bind_param("sii", $status, $order_id, $seller_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $success_message = "Order status updated to " . ucfirst($status);
            
            // Add order status history
            $stmt = $db->prepare("INSERT INTO order_status_history (order_id, status, updated_by, updated_by_role) VALUES (?, ?, ?, 'seller')");
            $stmt->bind_param("isi", $order_id, $status, $user_id);
            $stmt->execute();
        } else {
            $error_message = "Failed to update order status. Order might not belong to you.";
        }
    } else {
        $error_message = "Invalid status selected.";
    }
}

// Get order details
$query = "SELECT o.*, 
          c.first_name, c.last_name, c.email, c.phone,
          a.address_line1, a.address_line2, a.city, a.state, a.postal_code, a.country
          FROM orders o
          JOIN users c ON o.customer_id = c.id
          LEFT JOIN addresses a ON o.shipping_address_id = a.id
          WHERE o.id = ? AND o.seller_id = ?";

$stmt = $db->prepare($query);
$stmt->bind_param("ii", $order_id, $seller_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Order not found or doesn't belong to this seller
    header("Location: seller_orders.php?error=order_not_found");
    exit;
}

$order = $result->fetch_assoc();

// Get order items
$query = "SELECT oi.*, p.name as product_name, p.sku
          FROM order_items oi
          JOIN products p ON oi.product_id = p.id
          WHERE oi.order_id = ?";

$stmt = $db->prepare($query);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get order status history
$query = "SELECT h.*, 
          CASE 
            WHEN h.updated_by_role = 'admin' THEN a.username
            WHEN h.updated_by_role = 'seller' THEN s.business_name
            WHEN h.updated_by_role = 'customer' THEN CONCAT(u.first_name, ' ', u.last_name)
            ELSE 'System'
          END as updated_by_name
          FROM order_status_history h
          LEFT JOIN users a ON h.updated_by = a.id AND h.updated_by_role = 'admin'
          LEFT JOIN sellers s ON h.updated_by = s.user_id AND h.updated_by_role = 'seller'
          LEFT JOIN users u ON h.updated_by = u.id AND h.updated_by_role = 'customer'
          WHERE h.order_id = ?
          ORDER BY h.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$status_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Include header
include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Order #<?php echo $order_id; ?></h1>
        <a href="seller_orders.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
            <i class="fas fa-arrow-left mr-2"></i> Back to Orders
        </a>
    </div>
    
    <?php if (isset($success_message)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?php echo $success_message; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Order Details and Items -->
        <div class="md:col-span-2">
            <!-- Order Summary -->
            <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Order Summary</h2>
                    <div>
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
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-500">Order Date</p>
                        <p class="font-medium"><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Payment Method</p>
                        <p class="font-medium"><?php echo ucfirst($order['payment_method']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Payment Status</p>
                        <p class="font-medium">
                            <?php if ($order['payment_status'] == 'paid'): ?>
                                <span class="text-green-600">Paid</span>
                            <?php elseif ($order['payment_status'] == 'pending'): ?>
                                <span class="text-yellow-600">Pending</span>
                            <?php else: ?>
                                <span class="text-red-600">Failed</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Total Amount</p>
                        <p class="font-medium">$<?php echo number_format($order['total_amount'], 2); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Order Items -->
            <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Order Items</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Product
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    SKU
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Price
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Quantity
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Subtotal
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($item['sku']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            $<?php echo number_format($item['price'], 2); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $item['quantity']; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            $<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-6 py-3 text-right text-sm font-medium text-gray-900">
                                    Subtotal:
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-900">
                                    $<?php echo number_format($order['subtotal'], 2); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="px-6 py-3 text-right text-sm font-medium text-gray-900">
                                    Shipping:
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-900">
                                    $<?php echo number_format($order['shipping_fee'], 2); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="px-6 py-3 text-right text-sm font-medium text-gray-900">
                                    Tax:
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-900">
                                    $<?php echo number_format($order['tax'], 2); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="px-6 py-3 text-right text-sm font-bold text-gray-900">
                                    Total:
                                </td>
                                <td class="px-6 py-3 text-sm font-bold text-gray-900">
                                    $<?php echo number_format($order['total_amount'], 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- Status History -->
            <?php if (!empty($status_history)): ?>
                <div class="bg-white shadow-md rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Status History</h2>
                    <div class="space-y-4">
                        <?php foreach ($status_history as $history): ?>
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <div class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center text-white">
                                        <i class="fas fa-history"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-900">
                                        Status changed to <span class="font-semibold"><?php echo ucfirst($history['status']); ?></span>
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        By <?php echo htmlspecialchars($history['updated_by_name']); ?> (<?php echo ucfirst($history['updated_by_role']); ?>)
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        <?php echo date('M d, Y h:i A', strtotime($history['created_at'])); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Customer Info and Actions -->
        <div>
            <!-- Customer Information -->
            <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Customer Information</h2>
                <div class="space-y-4">
                    <div>
                        <p class="text-sm text-gray-500">Name</p>
                        <p class="font-medium"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Email</p>
                        <p class="font-medium"><?php echo htmlspecialchars($order['email']); ?></p>
                    </div>
                    <?php if (!empty($order['phone'])): ?>
                        <div>
                            <p class="text-sm text-gray-500">Phone</p>
                            <p class="font-medium"><?php echo htmlspecialchars($order['phone']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Shipping Address -->
            <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Shipping Address</h2>
                <div>
                    <p class="font-medium"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                    <p><?php echo htmlspecialchars($order['address_line1']); ?></p>
                    <?php if (!empty($order['address_line2'])): ?>
                        <p><?php echo htmlspecialchars($order['address_line2']); ?></p>
                    <?php endif; ?>
                    <p><?php echo htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postal_code']); ?></p>
                    <p><?php echo htmlspecialchars($order['country']); ?></p>
                </div>
            </div>
            
            <!-- Order Actions -->
            <div class="bg-white shadow-md rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Order Actions</h2>
                <form action="" method="POST">
                    <div class="mb-4">
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Update Status</label>
                        <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="pending" <?php echo $order['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo $order['status'] == 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="shipped" <?php echo $order['status'] == 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered" <?php echo $order['status'] == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $order['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Update Status
                    </button>
                </form>
                
                <div class="mt-4">
                    <a href="print_order.php?id=<?php echo $order_id; ?>" target="_blank" class="block w-full py-2 px-4 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-md text-center focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <i class="fas fa-print mr-2"></i> Print Order
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
<?php include 'includes/footer.php'; ?>
