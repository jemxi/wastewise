<?php
session_start();
require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if user is a rider
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'rider') {
        header('Location: home.php');
        exit();
    }
    
    // Get rider information
    $stmt = $pdo->prepare("SELECT * FROM riders WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $rider = $stmt->fetch();
    
    if (!$rider) {
        header('Location: rider-registration.php');
        exit();
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get filter and pagination
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get rider's assigned deliveries
try {
    // Count total deliveries by status
    $statuses = ['pending', 'in_transit', 'delivered', 'failed'];
    $delivery_counts = [];
    
    foreach ($statuses as $st) {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT d.id) 
            FROM deliveries d
            WHERE d.rider_id = ? AND d.status = ?
        ");
        $stmt->execute([$rider['id'], $st]);
        $delivery_counts[$st] = $stmt->fetchColumn();
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id) 
        FROM deliveries d
        WHERE d.rider_id = ?
    ");
    $stmt->execute([$rider['id']]);
    $delivery_counts['all'] = $stmt->fetchColumn();
    
    // Get paginated deliveries
    $query = "
        SELECT d.*, o.id as order_id, o.name, o.email, o.phone, o.address, 
               o.barangay, o.city, o.province, o.country, o.zip,
               o.total_amount, s.business_name as seller_name
        FROM deliveries d
        JOIN orders o ON d.order_id = o.id
        JOIN sellers s ON d.seller_id = s.id
        WHERE d.rider_id = ?
    ";
    
    $count_query = "
        SELECT COUNT(DISTINCT d.id) 
        FROM deliveries d
        WHERE d.rider_id = ?
    ";
    
    $params = [$rider['id']];
    
    if ($status_filter && $status_filter !== 'all') {
        $query .= " AND d.status = ?";
        $count_query .= " AND d.status = ?";
        $params[] = $status_filter;
    }
    
    // Get total count
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_deliveries = $stmt->fetchColumn();
    $total_pages = ceil($total_deliveries / $limit);
    
    // Add pagination to main query
    $query .= " ORDER BY d.created_at DESC LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $limit;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $deliveries = [];
    $total_pages = 0;
    $delivery_counts = ['all' => 0, 'pending' => 0, 'in_transit' => 0, 'delivered' => 0, 'failed' => 0];
}

// Get specific delivery details if viewing
$delivery_details = null;
if (isset($_GET['view'])) {
    try {
        $delivery_id = intval($_GET['view']);
        $stmt = $pdo->prepare("
            SELECT d.*, o.*, s.business_name as seller_name,
                   GROUP_CONCAT(oi.product_name SEPARATOR ', ') as products
            FROM deliveries d
            JOIN orders o ON d.order_id = o.id
            JOIN sellers s ON d.seller_id = s.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE d.id = ? AND d.rider_id = ?
            GROUP BY d.id
        ");
        $stmt->execute([$delivery_id, $rider['id']]);
        $delivery_details = $stmt->fetch();
        
        if (!$delivery_details) {
            $error = "Delivery not found";
        }
    } catch (PDOException $e) {
        $error = "Error getting delivery details: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Deliveries - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .delivery-card {
            background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
            border-left: 4px solid #4CAF50;
        }
        .status-pending { background-color: #FEF3C7; color: #92400E; }
        .status-in_transit { background-color: #DBEAFE; color: #1E40AF; }
        .status-delivered { background-color: #DCFCE7; color: #15803D; }
        .status-failed { background-color: #FEE2E2; color: #991B1B; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-green-700 text-white py-6">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
                    <h1 class="text-2xl font-bold">Wastewise Rider Dashboard</h1>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-white"><?php echo htmlspecialchars($rider['first_name'] . ' ' . $rider['last_name']); ?></span>
                    <button onclick="openLogoutModal()" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($delivery_details): ?>
            <!-- Delivery Details View -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Delivery Details</h2>
                        <p class="text-gray-600">Order #<?php echo $delivery_details['order_id']; ?></p>
                    </div>
                    <a href="rider_deliveries.php?status=<?php echo $status_filter; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                        <i class="fas fa-arrow-left mr-2"></i> Back
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Delivery Info -->
                    <div class="md:col-span-2 space-y-6">
                        <!-- Delivery Status -->
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Delivery Status</h3>
                            <div class="status-<?php echo $delivery_details['status']; ?> px-4 py-3 rounded-lg font-medium text-center">
                                <?php echo ucfirst(str_replace('_', ' ', $delivery_details['status'])); ?>
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Customer Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm text-gray-500">Name</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($delivery_details['name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Phone</p>
                                    <p class="font-medium"><a href="tel:<?php echo htmlspecialchars($delivery_details['phone']); ?>" class="text-blue-600"><?php echo htmlspecialchars($delivery_details['phone']); ?></a></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Email</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($delivery_details['email']); ?></p>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div>
                                <p class="text-sm text-gray-500 mb-2">Delivery Address</p>
                                <p class="font-medium">
                                    <?php echo htmlspecialchars($delivery_details['address']); ?><br>
                                    <?php echo htmlspecialchars($delivery_details['barangay'] . ', ' . $delivery_details['city'] . ', ' . $delivery_details['province']); ?><br>
                                    <?php echo htmlspecialchars($delivery_details['country'] . ' ' . $delivery_details['zip']); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Products Being Delivered -->
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Items</h3>
                            <p class="font-medium"><?php echo htmlspecialchars($delivery_details['products']); ?></p>
                            <p class="text-sm text-gray-600 mt-2">Total Amount: <span class="font-bold">₱<?php echo number_format($delivery_details['total_amount'], 2); ?></span></p>
                        </div>

                        <!-- Proof of Delivery (if already delivered) -->
                        <?php if ($delivery_details['status'] === 'delivered' && $delivery_details['proof_image']): ?>
                            <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Proof of Delivery</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600 mb-2">Photo</p>
                                        <img src="<?php echo htmlspecialchars($delivery_details['proof_image']); ?>" alt="Delivery proof" class="w-full rounded-lg border border-gray-300">
                                    </div>
                                    <?php if ($delivery_details['signature_image']): ?>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-2">Signature</p>
                                            <img src="<?php echo htmlspecialchars($delivery_details['signature_image']); ?>" alt="Signature" class="w-full h-40 rounded-lg border border-gray-300 object-contain bg-white">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($delivery_details['delivery_notes']): ?>
                                    <div class="mt-4">
                                        <p class="text-sm text-gray-600 mb-1">Delivery Note</p>
                                        <p class="bg-white p-3 rounded border border-gray-300"><?php echo htmlspecialchars($delivery_details['delivery_notes']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Panel -->
                    <div>
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 sticky top-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions</h3>

                            <?php if ($delivery_details['status'] === 'pending'): ?>
                                <button onclick="pickupDelivery(<?php echo $delivery_details['id']; ?>)" class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md mb-3">
                                    <i class="fas fa-box mr-2"></i> Mark as Picked Up
                                </button>
                                <p class="text-sm text-gray-600">Click to confirm that you've picked up this order from the seller.</p>
                            <?php elseif ($delivery_details['status'] === 'in_transit'): ?>
                                <button onclick="openProofModal(<?php echo $delivery_details['id']; ?>)" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md mb-3">
                                    <i class="fas fa-check-circle mr-2"></i> Mark as Delivered
                                </button>
                                <p class="text-sm text-gray-600">Upload proof of delivery and mark the order as complete.</p>
                            <?php elseif ($delivery_details['status'] === 'delivered'): ?>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                    <p class="text-green-700 font-medium"><i class="fas fa-check-circle mr-2"></i>Delivery Completed</p>
                                </div>
                            <?php endif; ?>

                            <div class="mt-6 pt-6 border-t border-gray-200">
                                <h4 class="font-medium text-gray-700 mb-3">Seller Information</h4>
                                <p class="text-sm text-gray-600 mb-1">Business</p>
                                <p class="font-medium"><?php echo htmlspecialchars($delivery_details['seller_name']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Deliveries List View -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">My Deliveries</h2>

                <!-- Status Filter Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                    <a href="?status=all" class="bg-blue-50 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-semibold text-blue-700">All</h3>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $delivery_counts['all']; ?></p>
                    </a>
                    <a href="?status=pending" class="bg-yellow-50 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-semibold text-yellow-700">Pending</h3>
                        <p class="text-3xl font-bold text-yellow-600"><?php echo $delivery_counts['pending']; ?></p>
                    </a>
                    <a href="?status=in_transit" class="bg-blue-50 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-semibold text-blue-700">In Transit</h3>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $delivery_counts['in_transit']; ?></p>
                    </a>
                    <a href="?status=delivered" class="bg-green-50 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-semibold text-green-700">Delivered</h3>
                        <p class="text-3xl font-bold text-green-600"><?php echo $delivery_counts['delivered']; ?></p>
                    </a>
                    <a href="?status=failed" class="bg-red-50 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-semibold text-red-700">Failed</h3>
                        <p class="text-3xl font-bold text-red-600"><?php echo $delivery_counts['failed']; ?></p>
                    </a>
                </div>

                <!-- Deliveries Table -->
                <?php if (empty($deliveries)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-box text-4xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-1">No deliveries found</h3>
                        <p class="text-gray-500">No deliveries are available for this status.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($deliveries as $delivery): ?>
                            <div class="delivery-card rounded-lg p-6 border border-gray-200 hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-800">Order #<?php echo $delivery['order_id']; ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($delivery['name']); ?> • <?php echo htmlspecialchars($delivery['seller_name']); ?></p>
                                    </div>
                                    <span class="status-<?php echo $delivery['status']; ?> px-3 py-1 rounded-full text-xs font-semibold">
                                        <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4 text-sm">
                                    <div>
                                        <p class="text-gray-600">Customer</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($delivery['name']); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-600">Phone</p>
                                        <p class="font-medium"><a href="tel:<?php echo htmlspecialchars($delivery['phone']); ?>" class="text-blue-600"><?php echo htmlspecialchars($delivery['phone']); ?></a></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-600">City</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($delivery['city']); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-600">Amount</p>
                                        <p class="font-medium">₱<?php echo number_format($delivery['total_amount'], 2); ?></p>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <a href="?view=<?php echo $delivery['id']; ?>&status=<?php echo $status_filter; ?>" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-center">
                                        <i class="fas fa-eye mr-1"></i> View Details
                                    </a>
                                    <?php if ($delivery['status'] === 'pending'): ?>
                                        <button onclick="pickupDelivery(<?php echo $delivery['id']; ?>)" class="flex-1 px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">
                                            <i class="fas fa-box mr-1"></i> Pickup
                                        </button>
                                    <?php elseif ($delivery['status'] === 'in_transit'): ?>
                                        <button onclick="openProofModal(<?php echo $delivery['id']; ?>)" class="flex-1 px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">
                                            <i class="fas fa-check-circle mr-1"></i> Delivered
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="mt-6 flex justify-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i === $page ? 'bg-green-600 text-white' : 'bg-gray-200 hover:bg-gray-300'; ?> rounded">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Proof of Delivery Modal (included from modals folder) -->
    <?php include 'modals/proof-of-delivery-modal.php'; ?>

    <!-- Logout Modal -->
    <div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm w-full">
            <h3 class="text-xl font-bold text-gray-800 mb-4">Logout</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to logout?</p>
            <div class="flex gap-2">
                <button onclick="closeLogoutModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Cancel</button>
                <form action="logout.php" method="POST" class="flex-1">
                    <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Logout</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function pickupDelivery(deliveryId) {
            if (confirm('Mark this order as picked up?')) {
                const formData = new FormData();
                formData.append('action', 'pickup_order');
                formData.append('order_id', deliveryId);
                
                fetch('api/order-actions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Order marked as picked up');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(err => alert('Error: ' + err.message));
            }
        }

        function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }
    </script>
</body>
</html>
