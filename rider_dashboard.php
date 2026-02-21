<?php
session_start();
require 'db_connection.php';

// Check if user is logged in (for rider authentication)
// For now, we'll create a simple rider access
$rider_name = "Mark Eric Parajas";

// Check if there's a rider table or if we're using a specific rider ID
// For simplicity, we'll just use the rider name
$rider_id = isset($_SESSION['rider_id']) ? $_SESSION['rider_id'] : 1;

// Check if rider accepted an order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_order') {
    $order_id = intval($_POST['order_id']);
    $estimated_delivery = isset($_POST['estimated_delivery']) ? $_POST['estimated_delivery'] : null;
    
    try {
        // Update the order to mark it as assigned to this rider
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET status = 'shipped', rider_assigned = ?, rider_status = 'accepted', estimated_delivery = ?
            WHERE id = ?
        ");
        $stmt->execute([$rider_name, $estimated_delivery, $order_id]);
        
        // Log the acceptance
        $stmt = $pdo->prepare("
            INSERT INTO order_status_history (
                order_id, 
                status, 
                notes, 
                updated_by, 
                created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_id, 
            'assigned_to_rider', 
            'Order accepted by rider ' . $rider_name . '. Est. Delivery: ' . $estimated_delivery,
            $rider_name
        ]);
        
        $success_message = "Order #$order_id accepted successfully!";
    } catch (PDOException $e) {
        $error_message = "Error accepting order: " . $e->getMessage();
    }
}

// Check if rider rejected an order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_order') {
    $order_id = intval($_POST['order_id']);
    
    try {
        // Update the order to mark rejection reason
        $reject_reason = isset($_POST['reject_reason']) ? $_POST['reject_reason'] : 'Rejected by rider';
        
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET rider_status = 'rejected'
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);
        
        // Log the rejection
        $stmt = $pdo->prepare("
            INSERT INTO order_status_history (
                order_id, 
                status, 
                notes, 
                updated_by, 
                created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_id, 
            'rider_rejected', 
            'Rejection reason: ' . $reject_reason,
            $rider_name
        ]);
        
        $success_message = "Order #$order_id rejected.";
    } catch (PDOException $e) {
        $error_message = "Error rejecting order: " . $e->getMessage();
    }
}

// Check if rider marked an order as delivered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_delivered') {
    $order_id = intval($_POST['order_id']);
    
    try {
        // Handle file upload
        $proof_of_delivery_path = null;
        if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['size'] > 0) {
            $upload_dir = 'proofs_of_delivery/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_ext = pathinfo($_FILES['proof_of_delivery']['name'], PATHINFO_EXTENSION);
            $filename = 'proof_' . $order_id . '_' . time() . '.' . $file_ext;
            $proof_of_delivery_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['proof_of_delivery']['tmp_name'], $proof_of_delivery_path)) {
                // File uploaded successfully
                // Update order status to delivered with proof of delivery
                $stmt = $pdo->prepare("
                    UPDATE orders 
                    SET status = 'delivered', 
                        rider_status = 'delivered',
                        proof_of_delivery = ?
                    WHERE id = ?
                ");
                $stmt->execute([$proof_of_delivery_path, $order_id]);
                
                // Add to order status history
                $stmt = $pdo->prepare("
                    INSERT INTO order_status_history (
                        order_id, 
                        status, 
                        notes, 
                        updated_by, 
                        created_at
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $order_id, 
                    'delivered', 
                    'Order delivered by rider ' . $rider_name . ' with proof of delivery',
                    $rider_name
                ]);
                
                $success_message = "Order #$order_id marked as delivered with proof!";
            } else {
                $error_message = "Failed to upload proof of delivery image.";
            }
        } else {
            $error_message = "Please upload a photo as proof of delivery.";
        }
    } catch (PDOException $e) {
        $error_message = "Error marking order as delivered: " . $e->getMessage();
    }
}

// Check if table columns exist, if not create them
try {
    $columns = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('rider_assigned', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN rider_assigned VARCHAR(100) DEFAULT NULL");
    }
    
    if (!in_array('rider_status', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN rider_status VARCHAR(50) DEFAULT NULL");
    }
    
    if (!in_array('estimated_delivery', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN estimated_delivery DATE DEFAULT NULL");
    }
    
    if (!in_array('proof_of_delivery', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN proof_of_delivery VARCHAR(255) DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log("Error checking/adding columns: " . $e->getMessage());
}

// Get paginated list of notified orders (orders awaiting rider acceptance)
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count of pending rider notifications
try {
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT o.id) 
        FROM orders o
        WHERE o.status = 'awaiting_rider_acceptance' AND (o.rider_status IS NULL OR o.rider_status = 'pending')
    ");
    $total_pending = $stmt->fetchColumn();
    $total_pages = ceil($total_pending / $limit);
    
    // Get pending orders to accept
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*, u.username, u.email,
               COUNT(oi.id) as item_count,
               SUM(oi.quantity) as total_items,
               SUM(oi.price * oi.quantity) as total_amount
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.status = 'awaiting_rider_acceptance' AND (o.rider_status IS NULL OR o.rider_status = 'pending')
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT ?, ?
    ");
    $stmt->bindValue(1, $offset, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get accepted orders by this rider
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*, u.username, u.email,
               COUNT(oi.id) as item_count,
               SUM(oi.quantity) as total_items,
               SUM(oi.price * oi.quantity) as total_amount
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.rider_assigned = ? AND o.status = 'shipped' AND o.rider_status = 'accepted'
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$rider_name]);
    $accepted_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get delivered orders by this rider
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*, u.username, u.email,
               COUNT(oi.id) as item_count,
               SUM(oi.quantity) as total_items,
               SUM(oi.price * oi.quantity) as total_amount
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.rider_assigned = ? AND o.status = 'delivered' AND o.rider_status = 'delivered'
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$rider_name]);
    $delivered_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    $pending_orders = [];
    $accepted_orders = [];
    $delivered_orders = [];
    $total_pages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Dashboard - <?php echo $rider_name; ?> - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        .text-wastewise {
            color: #4CAF50;
        }
        .border-wastewise {
            border-color: #4CAF50;
        }
        
        .notification-badge {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }
        
        footer {
            background-color: #2f855a;
            color: white;
            text-align: center;
            padding: 1rem 0;
            z-index: 30;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-green-700 text-white py-6">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4">
                <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
                    <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
                    <h1 class="text-2xl font-bold">Wastewise</h1>
                </div>
                <div class="text-center w-full md:w-1/3">
                    <p class="text-lg font-semibold">Rider Dashboard</p>
                </div>
                <div class="flex justify-end w-full md:w-1/3">
                    <div class="relative inline-block text-left">
                        <button onclick="toggleDropdown()" class="flex items-center gap-2 text-white px-4 py-2">
                            <i class="fas fa-user-circle text-2xl"></i>
                            <span><?php echo $rider_name; ?></span>
                        </button>
                        <div id="userMenuDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-2 z-50">
                            <button onclick="openLogoutModal()" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-100">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Logout Modal -->
    <div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
        <div class="bg-gray-200 bg-opacity-90 p-6 rounded-lg shadow-lg w-full max-w-sm text-center">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Are you sure you want to log out?</h2>
            <div class="flex justify-center gap-4">
                <form action="logout.php" method="POST">
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Yes</button>
                </form>
                <button onclick="closeLogoutModal()" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">No</button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center">
                        <i class="fas fa-bell text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Pending Orders</p>
                        <p class="text-2xl font-bold text-gray-800 notification-badge">
                            <?php echo count($pending_orders); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-green-100 text-green-600 flex items-center justify-center">
                        <i class="fas fa-check-circle text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Accepted Orders</p>
                        <p class="text-2xl font-bold text-gray-800">
                            <?php echo count($accepted_orders); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                        <i class="fas fa-truck text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Total Orders</p>
                        <p class="text-2xl font-bold text-gray-800">
                            <?php echo count($pending_orders) + count($accepted_orders) + count($delivered_orders); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders Section -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center mr-4">
                    <i class="fas fa-bell text-xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Pending Notifications</h2>
            </div>
            
            <?php if (empty($pending_orders)): ?>
                <div class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                        <i class="fas fa-inbox text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No pending notifications</h3>
                    <p class="text-gray-500">You'll see new delivery requests here.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($pending_orders as $order): ?>
                        <div class="border border-yellow-200 bg-yellow-50 rounded-lg p-6">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                                <div class="flex-1 mb-4 md:mb-0">
                                    <div class="flex items-center mb-2">
                                        <h3 class="text-lg font-bold text-gray-800">Order #<?php echo $order['id']; ?></h3>
                                        <span class="ml-2 px-2 py-1 bg-yellow-200 text-yellow-800 rounded-full text-xs font-semibold">NEW</span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Customer</p>
                                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($order['username']); ?></p>
                                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order['email']); ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Items</p>
                                            <p class="font-medium text-gray-800"><?php echo $order['item_count']; ?> product(s)</p>
                                            <p class="text-sm text-gray-600"><?php echo $order['total_items']; ?> items</p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Total Amount</p>
                                            <p class="font-medium text-lg text-green-600">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Est. Delivery Date</p>
                                            <p class="font-medium text-gray-800">
                                                <?php 
                                                    if (!empty($order['estimated_delivery'])) {
                                                        echo date('M d, Y', strtotime($order['estimated_delivery']));
                                                    } else {
                                                        echo 'Not set';
                                                    }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <p class="text-sm text-gray-500 mb-1">Delivery Address</p>
                                        <p class="text-gray-700">
                                            <?php echo htmlspecialchars($order['address'] ?? 'N/A'); ?><br>
                                            <?php echo htmlspecialchars(($order['barangay'] ?? '') . ', ' . ($order['city'] ?? '')); ?><br>
                                            <?php echo htmlspecialchars(($order['province'] ?? '') . ' ' . ($order['zip'] ?? '')); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="flex flex-col gap-3 md:w-48">
                                    <!-- Changed accept button to open modal instead of direct form submission -->
                                    <button onclick="openAcceptModal(<?php echo $order['id']; ?>)" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md flex items-center justify-center">
                                        <i class="fas fa-check mr-2"></i> Accept Order
                                    </button>
                                    
                                    <button onclick="openRejectModal(<?php echo $order['id']; ?>)" class="w-full py-2 px-4 bg-red-200 hover:bg-red-300 text-red-700 font-medium rounded-md flex items-center justify-center">
                                        <i class="fas fa-times mr-2"></i> Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination for pending orders -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-6 flex justify-center">
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Accepted Orders Section -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 rounded-full bg-green-100 text-green-600 flex items-center justify-center mr-4">
                    <i class="fas fa-check-circle text-xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">My Accepted Orders</h2>
            </div>
            
            <?php if (empty($accepted_orders)): ?>
                <div class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                        <i class="fas fa-clipboard-list text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No accepted orders yet</h3>
                    <p class="text-gray-500">Accept orders from the pending notifications section to deliver them.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($accepted_orders as $order): ?>
                        <div class="border border-green-200 bg-green-50 rounded-lg p-6">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <h3 class="text-lg font-bold text-gray-800">Order #<?php echo $order['id']; ?></h3>
                                        <span class="ml-2 px-2 py-1 bg-green-200 text-green-800 rounded-full text-xs font-semibold">ACCEPTED</span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Customer</p>
                                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($order['username']); ?></p>
                                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order['email']); ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Items</p>
                                            <p class="font-medium text-gray-800"><?php echo $order['item_count']; ?> product(s)</p>
                                            <p class="text-sm text-gray-600"><?php echo $order['total_items']; ?> items</p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Total Amount</p>
                                            <p class="font-medium text-lg text-green-600">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Est. Delivery Date</p>
                                            <p class="font-medium text-gray-800">
                                                <?php 
                                                    if (!empty($order['estimated_delivery'])) {
                                                        echo date('M d, Y', strtotime($order['estimated_delivery']));
                                                    } else {
                                                        echo 'Not set';
                                                    }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <p class="text-sm text-gray-500 mb-1">Delivery Address</p>
                                        <p class="text-gray-700">
                                            <?php echo htmlspecialchars($order['address'] ?? 'N/A'); ?><br>
                                            <?php echo htmlspecialchars(($order['barangay'] ?? '') . ', ' . ($order['city'] ?? '')); ?><br>
                                            <?php echo htmlspecialchars(($order['province'] ?? '') . ' ' . ($order['zip'] ?? '')); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="md:w-48">
                                    <p class="text-sm text-gray-500 mb-2">Status</p>
                                    <p class="font-medium text-green-700 text-lg">Ready for Delivery</p>
                                    <div class="border-t border-green-200 mt-4 pt-4">
                                        <div class="flex items-center gap-4">
                                            <div class="flex-1">
                                                <p class="text-sm text-gray-500 mb-2">Proof of Delivery</p>
                                                <div id="proof_<?php echo $order['id']; ?>" class="flex items-center gap-2">
                                                    <label for="camera_<?php echo $order['id']; ?>" class="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 hover:bg-blue-200 cursor-pointer text-blue-600">
                                                        <i class="fas fa-camera text-lg"></i>
                                                        <input type="file" id="camera_<?php echo $order['id']; ?>" name="proof_of_delivery" accept="image/*" class="hidden" onchange="handleProofUpload(<?php echo $order['id']; ?>, this)">
                                                    </label>
                                                    <span id="proof_status_<?php echo $order['id']; ?>" class="text-sm text-gray-500">No photo uploaded</span>
                                                </div>
                                            
                                                <form id="deliver_form_<?php echo $order['id']; ?>" action="" method="POST" enctype="multipart/form-data" style="display: none; margin-top: 0.5rem;">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                    <input type="hidden" name="action" value="mark_delivered">
                                                    <input type="file" id="proof_file_input_<?php echo $order['id']; ?>" name="proof_of_delivery" style="display: none;">
                                                    <button type="submit" id="deliver_btn_<?php echo $order['id']; ?>" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                                        <i class="fas fa-check-circle mr-2"></i> Mark as Delivered
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Delivered Orders section -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mr-4">
                    <i class="fas fa-check-double text-xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Delivered Orders</h2>
            </div>
            
            <?php if (empty($delivered_orders)): ?>
                <div class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                        <i class="fas fa-box text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No delivered orders yet</h3>
                    <p class="text-gray-500">Your completed deliveries will appear here.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($delivered_orders as $order): ?>
                        <div class="border border-blue-200 bg-blue-50 rounded-lg p-6">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <h3 class="text-lg font-bold text-gray-800">Order #<?php echo $order['id']; ?></h3>
                                        <span class="ml-2 px-2 py-1 bg-blue-200 text-blue-800 rounded-full text-xs font-semibold">DELIVERED</span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Customer</p>
                                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($order['username']); ?></p>
                                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order['email']); ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Items</p>
                                            <p class="font-medium text-gray-800"><?php echo $order['item_count']; ?> product(s)</p>
                                            <p class="text-sm text-gray-600"><?php echo $order['total_items']; ?> items</p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Total Amount</p>
                                            <p class="font-medium text-lg text-blue-600">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Est. Delivery Date</p>
                                            <p class="font-medium text-gray-800">
                                                <?php 
                                                    if (!empty($order['estimated_delivery'])) {
                                                        echo date('M d, Y', strtotime($order['estimated_delivery']));
                                                    } else {
                                                        echo 'Not set';
                                                    }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <p class="text-sm text-gray-500 mb-1">Delivery Address</p>
                                        <p class="text-gray-700">
                                            <?php echo htmlspecialchars($order['address'] ?? 'N/A'); ?><br>
                                            <?php echo htmlspecialchars(($order['barangay'] ?? '') . ', ' . ($order['city'] ?? '')); ?><br>
                                            <?php echo htmlspecialchars(($order['province'] ?? '') . ' ' . ($order['zip'] ?? '')); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="mt-4 border-t border-blue-200 pt-4">
                                        <p class="text-sm text-gray-500 mb-2">Proof of Delivery</p>
                                        <?php if (!empty($order['proof_of_delivery'])): ?>
                                            <div class="flex items-center gap-2 text-blue-600">
                                                <i class="fas fa-image"></i>
                                                <a href="<?php echo htmlspecialchars($order['proof_of_delivery']); ?>" target="_blank" class="underline hover:text-blue-800">
                                                    View Proof
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500">No proof uploaded</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Reject Modal -->
    <div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-sm">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Reject Order</h2>
            
            <form action="" method="POST" id="rejectForm">
                <input type="hidden" name="order_id" id="rejectOrderId" value="">
                <input type="hidden" name="action" value="reject_order">
                
                <div class="mb-4">
                    <label for="reject_reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for Rejection</label>
                    <textarea id="reject_reason" name="reject_reason" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Please explain why you cannot deliver this order" required></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                        Reject Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Accept Order Modal with delivery date field -->
    <div id="acceptModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-sm">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Accept Order</h2>
            
            <form action="" method="POST" id="acceptForm">
                <input type="hidden" name="order_id" id="acceptOrderId" value="">
                <input type="hidden" name="action" value="accept_order">
                
                <div class="mb-4">
                    <label for="estimated_delivery" class="block text-sm font-medium text-gray-700 mb-2">Estimated Delivery Date</label>
                    <input type="date" id="estimated_delivery" name="estimated_delivery" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeAcceptModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Accept Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Deliver Order Modal with proof of delivery upload -->
    <div id="deliverModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-sm">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Mark Order as Delivered</h2>
            
            <form action="" method="POST" id="deliverForm" enctype="multipart/form-data">
                <input type="hidden" name="order_id" id="deliverOrderId" value="">
                <input type="hidden" name="action" value="mark_delivered">
                
                <div class="mb-4">
                    <label for="proof_of_delivery" class="block text-sm font-medium text-gray-700 mb-2">Upload Proof of Delivery</label>
                    <input type="file" id="proof_of_delivery" name="proof_of_delivery" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" accept="image/*" required>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeDeliverModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Mark as Delivered
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-green-800 text-white py-8 mt-auto">
        <div class="container mx-auto px-4">
            <div class="text-center">
                <p>&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p>
                <p class="text-sm mt-2">Rider: <?php echo $rider_name; ?></p>
            </div>
        </div>
    </footer>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.classList.toggle('hidden');
        }

        function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }

        function openAcceptModal(orderId) {
            document.getElementById('acceptOrderId').value = orderId;
            document.getElementById('acceptModal').classList.remove('hidden');
            
            // Set min date to today and default to 2 days from now
            const today = new Date();
            const minDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
            document.getElementById('estimated_delivery').min = minDate;
            
            const deliveryDate = new Date(today);
            deliveryDate.setDate(today.getDate() + 2);
            const defaultDate = `${deliveryDate.getFullYear()}-${String(deliveryDate.getMonth() + 1).padStart(2, '0')}-${String(deliveryDate.getDate()).padStart(2, '0')}`;
            document.getElementById('estimated_delivery').value = defaultDate;
        }

        function closeAcceptModal() {
            document.getElementById('acceptModal').classList.add('hidden');
        }

        function openRejectModal(orderId) {
            document.getElementById('rejectOrderId').value = orderId;
            document.getElementById('rejectModal').classList.remove('hidden');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
        }

        function openDeliverModal(orderId) {
            document.getElementById('deliverOrderId').value = orderId;
            document.getElementById('deliverModal').classList.remove('hidden');
        }

        function closeDeliverModal() {
            document.getElementById('deliverModal').classList.add('hidden');
        }

        function handleProofUpload(orderId, input) {
            const file = input.files[0];
            if (file) {
                // Store the file in the hidden input
                const fileInput = document.getElementById('proof_file_input_' + orderId);
                fileInput.files = input.files;
                
                // Show the file name
                document.getElementById('proof_status_' + orderId).textContent = 'Photo: ' + file.name;
                document.getElementById('proof_status_' + orderId).classList.add('text-green-600', 'font-medium');
                
                // Enable the deliver button
                const deliverBtn = document.getElementById('deliver_btn_' + orderId);
                deliverBtn.disabled = false;
                
                // Show the deliver form
                document.getElementById('deliver_form_' + orderId).style.display = 'block';
            }
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            const rejectModal = document.getElementById('rejectModal');
            const acceptModal = document.getElementById('acceptModal');
            const deliverModal = document.getElementById('deliverModal');
            if (e.target === rejectModal) {
                closeRejectModal();
            }
            if (e.target === acceptModal) {
                closeAcceptModal();
            }
            if (e.target === deliverModal) {
                closeDeliverModal();
            }
        });
    </script>

    <script type="text/javascript">
        window.history.forward();
        function noBack() {
            window.history.forward();
        }
    </script>
</body>
</html>
