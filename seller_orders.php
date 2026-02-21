<?php
session_start();
require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if user is a seller
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'seller') {
        // Not a seller, redirect to seller registration
        header('Location: seller-centre.php');
        exit();
    }
    
    // Get seller information
    $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();
    
    if (!$seller) {
        // Seller record not found, redirect to seller registration
        header('Location: seller-centre.php');
        exit();
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Debug: Check the structure of the orders table
try {
    $table_structure = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_ASSOC);
    error_log("Orders table structure: " . print_r($table_structure, true));
} catch (PDOException $e) {
    error_log("Error checking orders table structure: " . $e->getMessage());
}

// Check if order_status_history table exists, if not create it
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_status_history'");
    if ($stmt->rowCount() == 0) {
        // Table doesn't exist, create it
        $pdo->exec("CREATE TABLE `order_status_history` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `status` varchar(50) NOT NULL,
            `notes` text DEFAULT NULL,
            `updated_by` varchar(100) NOT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        error_log("Created order_status_history table");
    }
} catch (PDOException $e) {
    error_log("Error checking/creating order_status_history table: " . $e->getMessage());
}

// Check if notifications table exists, if not create it
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if ($stmt->rowCount() == 0) {
        // Table doesn't exist, create it
        try {
            $pdo->exec("CREATE TABLE `notifications` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `type` varchar(50) NOT NULL,
                `message` text NOT NULL,
                `reference_id` int(11) DEFAULT NULL,
                `is_read` tinyint(1) DEFAULT 0,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            error_log("Created notifications table");
        } catch (PDOException $create_e) {
            error_log("Error creating notifications table: " . $create_e->getMessage());
        }
    } else {
        // Table exists, verify the reference_id column exists
        $columns = $pdo->query("DESCRIBE notifications")->fetchAll(PDO::FETCH_ASSOC);
        $column_names = array_column($columns, 'Field');
        if (!in_array('reference_id', $column_names)) {
            // Add the reference_id column if missing
            try {
                $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `reference_id` int(11) DEFAULT NULL");
                error_log("Added reference_id column to notifications table");
            } catch (PDOException $alter_e) {
                error_log("Error altering notifications table: " . $alter_e->getMessage());
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error checking/creating notifications table: " . $e->getMessage());
}

// Check if status column exists in orders table, if not add it
try {
    $columns = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('status', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN status VARCHAR(50) DEFAULT 'pending'");
        error_log("Added status column to orders table");
    } else {
        // Check if the status column is properly defined
        $stmt = $pdo->prepare("SHOW COLUMNS FROM orders WHERE Field = 'status'");
        $stmt->execute();
        $status_column = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Status column definition: " . print_r($status_column, true));
        
        // If the status column is defined as an enum, alter it to be a VARCHAR
        if (strpos($status_column['Type'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
            error_log("Modified status column from enum to VARCHAR");
        }
    }
    
    // Add status_description column if it doesn't exist
    if (!in_array('status_description', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN status_description TEXT DEFAULT NULL");
        error_log("Added status_description column to orders table");
    }
    
    // Add tracking_number column if it doesn't exist
    if (!in_array('tracking_number', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) DEFAULT NULL");
        error_log("Added tracking_number column to orders table");
    }
    
    // Add courier column if it doesn't exist
    if (!in_array('courier', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN courier VARCHAR(100) DEFAULT NULL");
        error_log("Added courier column to orders table");
    }
    
    // Add estimated_delivery column if it doesn't exist
    if (!in_array('estimated_delivery', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN estimated_delivery DATE DEFAULT NULL");
        error_log("Added estimated_delivery column to orders table");
    }

    // Add rider_name column if it doesn't exist
    if (!in_array('rider_name', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN rider_name VARCHAR(100) DEFAULT NULL");
        error_log("Added rider_name column to orders table");
    }
    
    // Add proof_of_delivery column if it doesn't exist
    if (!in_array('proof_of_delivery', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN proof_of_delivery VARCHAR(255) DEFAULT NULL");
        error_log("Added proof_of_delivery column to orders table");
    }

    // Add proof_of_delivery_date column if it doesn't exist
    if (!in_array('proof_of_delivery_date', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN proof_of_delivery_date DATETIME DEFAULT NULL");
        error_log("Added proof_of_delivery_date column to orders table");
    }
} catch (PDOException $e) {
    error_log("Error checking/adding columns to orders table: " . $e->getMessage());
}

// Update existing orders with default status if they don't have one
try {
    $stmt = $pdo->prepare("UPDATE orders SET status = 'pending' WHERE status IS NULL OR status = ''");
    $stmt->execute();
    $updated_rows = $stmt->rowCount();
    if ($updated_rows > 0) {
        error_log("Updated $updated_rows orders with default status");
    }
} catch (PDOException $e) {
    error_log("Error updating orders with default status: " . $e->getMessage());
}

// Handle order status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    $action = $_POST['action'];
    
    try {
        // Verify this order belongs to this seller
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM order_items 
            WHERE order_id = ? AND seller_id = ?
        ");
        $stmt->execute([$order_id, $seller['id']]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            // Order belongs to this seller
            $status = '';
            $status_description = '';
            
            switch ($action) {
                case 'process':
                    $status = 'processing';
                    $status_description = 'Your order is being prepared by the seller.';
                    $success_message = "Order #$order_id has been marked as processing.";
                    break;
                case 'ship':
                    $status = 'awaiting_rider_acceptance';
                    $status_description = 'Your order has been sent to the rider for acceptance.';
                    $success_message = "Order #$order_id has been notified to the rider.";
                    break;
                case 'out_for_delivery':
                    $status = 'out_for_delivery';
                    $status_description = 'Your order is out for delivery and will arrive soon.';
                    $success_message = "Order #$order_id has been marked as out for delivery.";
                    break;
                case 'deliver':
                    $status = 'delivered';
                    $status_description = 'Your order has been delivered.';
                    $success_message = "Order #$order_id has been marked as delivered.";
                    break;
                case 'cancel':
                    $status = 'cancelled';
                    $status_description = 'Your order has been cancelled.';
                    $success_message = "Order #$order_id has been cancelled.";
                    break;
                case 'delay':
                    $status = 'delayed';
                    $status_description = 'Your order has been delayed. We apologize for the inconvenience.';
                    if (isset($_POST['delay_reason']) && !empty($_POST['delay_reason'])) {
                        $status_description .= " Reason: " . $_POST['delay_reason'];
                    }
                    $success_message = "Order #$order_id has been marked as delayed.";
                    break;
                default:
                    throw new Exception("Invalid action");
            }
            
            // Debug: Log the status update
            error_log("Updating order #$order_id status to: $status");
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update order status
            error_log("Updating order #$order_id status from pending to: $status");
            
            // First check the current status
            $check_stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
            $check_stmt->execute([$order_id]);
            $current_db_status = $check_stmt->fetchColumn();
            error_log("Current status in database for order #$order_id: $current_db_status");
            
            // Now update the status
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, status_description = ? WHERE id = ?");
            $stmt->execute([$status, $status_description, $order_id]);
            
            // Check if the update was successful
            $affected_rows = $stmt->rowCount();
            error_log("Affected rows: $affected_rows");
            
            // Verify the update
            $verify_stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
            $verify_stmt->execute([$order_id]);
            $new_status = $verify_stmt->fetchColumn();
            error_log("New status in database for order #$order_id: $new_status");
            
            if ($affected_rows > 0) {
                // Add tracking information if provided and action is 'ship' or 'out_for_delivery'
                if ($action === 'ship' || $action === 'out_for_delivery') {
                    $tracking_number = $_POST['tracking_number'] ?? null;
                    $courier = $_POST['courier'] ?? null;
                    $rider_name = $_POST['rider_name'] ?? null; 
                    $estimated_delivery = isset($_POST['estimated_delivery']) && !empty($_POST['estimated_delivery']) ? $_POST['estimated_delivery'] : null;
                    
                    // Update order with tracking number, courier, rider name, and estimated delivery
                    $update_fields = [];
                    $update_params = [];

                    if ($tracking_number !== null) {
                        $update_fields[] = "tracking_number = ?";
                        $update_params[] = $tracking_number;
                    }
                    if ($courier !== null) {
                        $update_fields[] = "courier = ?";
                        $update_params[] = $courier;
                    }
                    if ($rider_name !== null) {
                        $update_fields[] = "rider_name = ?";
                        $update_params[] = $rider_name;
                    }
                    if ($estimated_delivery !== null) {
                        $update_fields[] = "estimated_delivery = ?";
                        $update_params[] = $estimated_delivery;
                    }
                    
                    if (!empty($update_fields)) {
                        $update_query = "UPDATE orders SET " . implode(', ', $update_fields) . " WHERE id = ?";
                        $update_params[] = $order_id;
                        $stmt = $pdo->prepare($update_query);
                        $stmt->execute($update_params);
                        error_log("Updated tracking, courier, rider, and estimated delivery for order #$order_id");
                    }
                }
                
                // Handle proof of delivery upload specifically for 'deliver' action
                if ($action === 'deliver' && isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/proof_of_delivery/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $fileName = uniqid('pod_') . '_' . basename($_FILES['proof_of_delivery']['name']);
                    $targetPath = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['proof_of_delivery']['tmp_name'], $targetPath)) {
                        $stmt = $pdo->prepare("UPDATE orders SET proof_of_delivery = ?, proof_of_delivery_date = NOW() WHERE id = ?");
                        $stmt->execute([$targetPath, $order_id]);
                        error_log("Uploaded and saved proof of delivery for order #$order_id");
                    } else {
                        error_log("Failed to upload proof of delivery for order #$order_id");
                        // Optionally, throw an exception or set an error message
                    }
                }
                
                // Add status update to order history
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
                    $status, 
                    $status_description, 
                    $user['username']
                ]);
                error_log("Added status history for order #$order_id");
                
                // Send notification to customer
                $stmt = $pdo->prepare("
                    SELECT o.user_id, u.email, u.username 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.id 
                    WHERE o.id = ?
                ");
                $stmt->execute([$order_id]);
                $customer = $stmt->fetch();
                
                if ($customer) {
                    $message = "Your order #$order_id has been updated to: " . ucfirst(str_replace('_', ' ', $status));
                    // Modified notification message for shipped status to include rider name and courier
                    if ($status == 'awaiting_rider_acceptance' && isset($_POST['rider_name']) && !empty($_POST['rider_name'])) {
                        $message .= ". Your order is now with rider: " . $_POST['rider_name'];
                    } elseif ($status === 'out_for_delivery' && isset($_POST['tracking_number']) && !empty($_POST['tracking_number'])) {
                         $message .= " Track your order using tracking number: " . $_POST['tracking_number'];
                         if (isset($_POST['courier']) && !empty($_POST['courier'])) {
                             $message .= " via " . $_POST['courier'];
                         }
                    } elseif ($status === 'delivered') {
                        $message .= " Thank you for your purchase!";
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (
                            user_id, 
                            type, 
                            message, 
                            reference_id, 
                            is_read, 
                            created_at
                        ) VALUES (?, ?, ?, ?, 0, NOW())
                    ");
                    $stmt->execute([
                        $customer['user_id'], 
                        'order', 
                        $message, 
                        $order_id
                    ]);
                    error_log("Added notification for customer about order #$order_id");
                }
                
                // Commit transaction
                $pdo->commit();
                
                $success = $success_message;
            } else {
                // Rollback transaction
                $pdo->rollBack();
                $error = "Failed to update order status. Please try again.";
            }
        } else {
            $error = "You don't have permission to update this order.";
        }
    } catch (Exception $e) {
        // Rollback transaction
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error updating order: " . $e->getMessage();
        error_log("Error updating order: " . $e->getMessage());
    }
}

// Get current page and status filter
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$status_filter = isset($_GET['status']) ? $_GET['status'] : null;
$limit = 10;
$offset = ($page - 1) * $limit;

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'orders';

// Get seller's orders
if ($active_tab === 'orders') {
    try {
        // Debug: Check for orders with this seller
        $debug_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM order_items WHERE seller_id = ?
        ");
        $debug_stmt->execute([$seller['id']]);
        $debug_count = $debug_stmt->fetchColumn();
        error_log("Total order items for seller {$seller['id']}: $debug_count");
        
        // Get orders based on filters
        $query_params = [];
        $count_query = "
        SELECT COUNT(DISTINCT o.id) 
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        WHERE oi.seller_id = ?
    ";
        $query_params[] = $seller['id'];

        $orders_query = "
        SELECT o.*, u.username, u.email, o.phone as phone_number,
           COUNT(oi.id) as item_count,
           SUM(oi.quantity) as total_items,
           SUM(oi.price * oi.quantity) as seller_total
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN users u ON o.user_id = u.id
        WHERE oi.seller_id = ?
    ";

        if ($status_filter && $status_filter !== 'all') {
            $count_query .= " AND o.status = ?";
            $orders_query .= " AND o.status = ?";
            $query_params[] = $status_filter;
        }

        $orders_query .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT ?, ?";

        // Debug: Log the queries
        error_log("Count query: $count_query with params: " . print_r($query_params, true));
        
        // Get total count
        $stmt = $pdo->prepare($count_query);
        $count_params = array_slice($query_params, 0, count($query_params));
        $stmt->execute($count_params);
        $total_orders = $stmt->fetchColumn();
        
        error_log("Total orders: $total_orders");

        // Calculate pagination
        $total_pages = ceil($total_orders / $limit);

        // Get orders for current page
        $stmt = $pdo->prepare($orders_query);

        // Bind all parameters except LIMIT
        for ($i = 0; $i < count($query_params); $i++) {
            $stmt->bindValue($i + 1, $query_params[$i]);
        }

        // Bind LIMIT parameters explicitly as integers
        $stmt->bindValue(count($query_params) + 1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(count($query_params) + 2, $limit, PDO::PARAM_INT);

        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log the orders
        error_log("Orders found: " . count($orders));
        if (count($orders) > 0) {
            error_log("First order: " . print_r($orders[0], true));
        }
        
        // Get order counts by status
        $status_counts = [];
        $statuses = [
            'pending', 
            'processing', 
            'awaiting_rider_acceptance', // Changed 'shipped' to 'awaiting_rider_acceptance' for pending orders
            'shipped', 
            'out_for_delivery',
            'delivered', 
            'cancelled', 
            'delayed',
            'return_pending', 
            'return_approved', 
            'refunded'
        ];

        foreach ($statuses as $status) {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT o.id) 
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                WHERE oi.seller_id = ? AND o.status = ?
            ");
            $stmt->execute([$seller['id'], $status]);
            $status_counts[$status] = $stmt->fetchColumn();
            
            // Debug: Log the status counts
            error_log("Status count for '$status': {$status_counts[$status]}");
        }

        // Get total count
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT o.id) 
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            WHERE oi.seller_id = ?
        ");
        $stmt->execute([$seller['id']]);
        $status_counts['all'] = $stmt->fetchColumn();
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
        error_log("Error getting orders: " . $e->getMessage());
        $orders = [];
        $total_pages = 0;
        $status_counts = [
            'all' => 0,
            'pending' => 0,
            'processing' => 0,
            'awaiting_rider_acceptance' => 0, // Added for consistency
            'shipped' => 0,
            'out_for_delivery' => 0,
            'delivered' => 0,
            'cancelled' => 0,
            'delayed' => 0,
            'return_pending' => 0,
            'return_approved' => 0,
            'refunded' => 0
        ];
    }
}

// Get product ratings for seller's products
if ($active_tab === 'ratings') {
    try {
        // Get product ratings with pagination
        $rating_page = isset($_GET['rating_page']) ? max(1, intval($_GET['rating_page'])) : 1;
        $rating_limit = 10;
        $rating_offset = ($rating_page - 1) * $rating_limit;
        
        // Get total count of ratings
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM product_ratings pr
            JOIN products p ON pr.product_id = p.id
            WHERE p.seller_id = ?
        ");
        $stmt->execute([$seller['id']]);
        $total_ratings = $stmt->fetchColumn();
        
        $total_rating_pages = ceil($total_ratings / $rating_limit);
        
        // Get ratings for current page
        $stmt = $pdo->prepare("
            SELECT pr.*, p.name as product_name, p.image as image_path, u.username,
                   p.id as product_id, pr.created_at as rating_date
            FROM product_ratings pr
            JOIN products p ON pr.product_id = p.id
            JOIN users u ON pr.user_id = u.id
            WHERE p.seller_id = ?
            ORDER BY pr.created_at DESC
            LIMIT ?, ?
        ");
        $stmt->bindValue(1, $seller['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $rating_offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $rating_limit, PDO::PARAM_INT);
        $stmt->execute();
        $product_ratings = $stmt->fetchAll();
        
        // Get rating statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_ratings,
                AVG(pr.rating) as average_rating,
                SUM(CASE WHEN pr.rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN pr.rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN pr.rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN pr.rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN pr.rating = 1 THEN 1 ELSE 0 END) as one_star
            FROM product_ratings pr
            JOIN products p ON pr.product_id = p.id
            WHERE p.seller_id = ?
        ");
        $stmt->execute([$seller['id']]);
        $rating_stats = $stmt->fetch();
        
        if (!$rating_stats) {
            $rating_stats = [
                'total_ratings' => 0, 
                'average_rating' => 0, 
                'five_star' => 0, 
                'four_star' => 0, 
                'three_star' => 0, 
                'two_star' => 0, 
                'one_star' => 0
            ];
        }
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
        $product_ratings = [];
        $rating_stats = [
            'total_ratings' => 0, 
            'average_rating' => 0, 
            'five_star' => 0, 
            'four_star' => 0, 
            'three_star' => 0, 
            'two_star' => 0, 
            'one_star' => 0
        ];
        $total_rating_pages = 0;
    }
}

// Get order details if viewing a specific order
$order_details = null;
$order_items = [];
$order_history = [];
$delivery_info = null; // Initialize delivery_info
if (isset($_GET['view']) && !empty($_GET['view'])) {
    try {
        $order_id = intval($_GET['view']);
        
        // Get order details
        $stmt = $pdo->prepare("
            SELECT o.*, u.username, u.email, o.phone as phone_number,
                   o.rider_name, o.estimated_delivery, o.tracking_number, o.courier, o.proof_of_delivery,
                   o.proof_of_delivery_date -- Added proof_of_delivery_date
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order_details) {
            // Debug: Log the order details
            error_log("Order details: " . print_r($order_details, true));
            error_log("Order status from database: " . $order_details['status']);
            
            // Ensure status is set
            if (empty($order_details['status'])) {
                $order_details['status'] = 'pending';
                error_log("Status was empty, defaulting to pending");
            }
            
            // Get order items for this seller
            $stmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name, p.image as product_image, p.image_path
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ? AND oi.seller_id = ?
            ");
            $stmt->execute([$order_id, $seller['id']]);
            $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate seller's total for this order
            $seller_total = 0;
            foreach ($order_items as $item) {
                $seller_total += $item['price'] * $item['quantity'];
            }
            $order_details['seller_total'] = $seller_total;
            
            // Get order status history
            $stmt = $pdo->prepare("
                SELECT * FROM order_status_history
                WHERE order_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$order_id]);
            $order_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Order history: " . print_r($order_history, true));
            
            // Fetch delivery information if the order is delivered
            if ($order_details['status'] === 'delivered') {
                $stmt = $pdo->prepare("
                    SELECT d.*, CONCAT(r.first_name, ' ', r.last_name) as rider_full_name
                    FROM deliveries d
                    LEFT JOIN riders r ON d.rider_id = r.id
                    WHERE d.order_id = ?
                ");
                $stmt->execute([$order_id]);
                $delivery_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // If delivery_info fetched, update order_details with rider name and potentially delivery date
                if ($delivery_info) {
                    if (!empty($delivery_info['rider_full_name']) && empty($order_details['rider_name'])) {
                        $order_details['rider_name'] = $delivery_info['rider_full_name'];
                    }
                    if (!empty($delivery_info['delivery_date']) && empty($order_details['proof_of_delivery_date'])) {
                        $order_details['proof_of_delivery_date'] = $delivery_info['delivery_date'];
                    }
                }
            }
            
        } else {
            $error = "Order not found.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
        error_log("Error getting order details: " . $e->getMessage());
    }
}

// Get available couriers
$couriers = [
    'J&T Express',
    'LBC Express',
    'Ninja Van',
    'Grab Express',
    'Lalamove',
    'JRS Express',
    'DHL Express',
    'FedEx',
    'Other'
];

// Helper function to convert numbers to words for rating display
function number_to_words($number) {
    $words = ['one', 'two', 'three', 'four', 'five'];
    return isset($words[$number - 1]) ? $words[$number - 1] : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Management - Wastewise Seller Dashboard</title>
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
        
        /* Status timeline */
        .status-timeline {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
            position: relative;
        }
        
        .status-timeline::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: #e5e7eb;
            z-index: 1;
        }
        
        .status-step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 20%;
        }
        
        .status-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e5e7eb;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .status-icon.active {
            background-color: #4CAF50;
            color: white;
        }
        
        .status-icon.completed {
            background-color: #4CAF50;
            color: white;
        }
        
        .status-label {
            font-size: 12px;
            text-align: center;
            color: #6b7280;
        }
        
        .status-label.active {
            color: #4CAF50;
            font-weight: 600;
        }
        
        /* Recent update badge */
        .recent-update {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: #ef4444;
            color: white;
            border-radius: 9999px;
            font-size: 10px;
            padding: 2px 6px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            }
            
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
            }
            
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }
        
        /* Rating styles */
        .rating-bar {
            background-color: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }
        .rating-fill {
            background-color: #fbbf24;
            height: 100%;
            transition: width 0.3s ease;
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
            <div class="flex justify-end w-full md:w-1/3">
      <div class="relative inline-block text-left">
         <button onclick="toggleDropdown()" class="flex items-center gap-2  text-white px-4 py-2">
          <i class="fas fa-cog"></i>
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

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-gray-200 bg-opacity-90 p-6 rounded-lg shadow-lg w-full max-w-sm text-center relative">
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
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8 px-6">
                    <a href="?tab=orders" class="<?php echo $active_tab === 'orders' ? 'border-green-500 text-green-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        <i class="fas fa-shopping-cart mr-2"></i>Orders Management
                    </a>
                    <a href="?tab=ratings" class="<?php echo $active_tab === 'ratings' ? 'border-green-500 text-green-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        <i class="fas fa-star mr-2"></i>Product Ratings
                    </a>
                </nav>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <div class="md:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="text-center mb-6">
                        <div class="w-24 h-24 rounded-full bg-gray-200 mx-auto mb-4 flex items-center justify-center">
                            <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                <img src="<?php echo htmlspecialchars($seller['logo_url']); ?>" alt="Business Logo" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <i class="fas fa-store text-gray-400 text-4xl"></i>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($seller['business_name']); ?></h2>
                        <p class="text-gray-600"><?php echo htmlspecialchars($seller['business_type']); ?></p>
                    </div>
                    
                      <nav class="space-y-2">
                        <a href="seller-dashboard?tab=dashboard" class="flex items-center px-4 py-2 <?php echo $active_tab === 'dashboard' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-tachometer-alt w-5 mr-2"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="seller-dashboard?tab=products" class="flex items-center px-4 py-2 <?php echo $active_tab === 'products' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-box w-5 mr-2"></i>
                            <span>Products</span>
                        </a>
                        <a href="seller-dashboard?tab=archived" class="flex items-center px-4 py-2 <?php echo $active_tab === 'archived' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-archive w-5 mr-2"></i>
                            <span>Archived Products</span>
                        </a>
                        <a href="seller_orders.php" class="flex items-center px-4 py-2 <?php echo $active_tab === 'orders' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-shopping-cart w-5 mr-2"></i>
                            <span>Orders</span>
                        </a>
                        <a href="seller_analytics.php" class="flex items-center px-4 py-2 <?php echo $active_tab === 'analytics' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-chart-line w-5 mr-2"></i>
                            <span>Analytics</span>
                        </a>
                        <a href="seller_settings.php" class="flex items-center px-4 py-2 <?php echo $active_tab === 'settings' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-cog w-5 mr-2"></i>
                            <span>Settings</span>
                        </a>
                    </nav>
                </div>
                
                <?php if ($active_tab === 'orders'): ?>
                <!-- Order Status Filter -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Status</h3>
                    <ul class="space-y-2">
                        <li>
                            <a href="?tab=orders&status=all" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo !$status_filter || $status_filter === 'all' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-list-ul mr-2"></i> All Orders</span>
                                <span class="bg-gray-200 text-gray-700 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['all']) ? $status_counts['all'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=pending" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'pending' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-clock mr-2"></i> Pending</span>
                                <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['pending']) ? $status_counts['pending'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=processing" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'processing' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-cog mr-2"></i> Processing</span>
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['processing']) ? $status_counts['processing'] : 0; ?></span>
                            </a>
                        </li>
                        <!-- CHANGED: Added new awaiting_rider_acceptance status tab -->
                        <li>
                            <a href="?tab=orders&status=awaiting_rider_acceptance" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'awaiting_rider_acceptance' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-hourglass-half mr-2"></i> Awaiting Rider</span>
                                <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['awaiting_rider_acceptance']) ? $status_counts['awaiting_rider_acceptance'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=shipped" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'shipped' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-shipping-fast mr-2"></i> Shipped</span>
                                <span class="bg-purple-100 text-purple-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['shipped']) ? $status_counts['shipped'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=out_for_delivery" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'out_for_delivery' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-truck mr-2"></i> Out for Delivery</span>
                                <span class="bg-indigo-100 text-indigo-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['out_for_delivery']) ? $status_counts['out_for_delivery'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=delivered" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'delivered' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-check-circle mr-2"></i> Delivered</span>
                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['delivered']) ? $status_counts['delivered'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=cancelled" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'cancelled' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-times-circle mr-2"></i> Cancelled</span>
                                <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['cancelled']) ? $status_counts['cancelled'] : 0; ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="?tab=orders&status=delayed" class="flex justify-between items-center text-gray-700 hover:text-green-600 <?php echo $status_filter === 'delayed' ? 'font-bold text-green-600' : ''; ?>">
                                <span><i class="fas fa-exclamation-triangle mr-2"></i> Delayed</span>
                                <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-xs"><?php echo isset($status_counts['delayed']) ? $status_counts['delayed'] : 0; ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php elseif ($active_tab === 'ratings'): ?>
                <!-- Rating Statistics Sidebar -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Overview</h3>
                    
                    <div class="text-center mb-6">
                        <div class="text-4xl font-bold text-gray-800 mb-2">
                            <?= $rating_stats['average_rating'] ? number_format($rating_stats['average_rating'], 1) : '0.0' ?>
                        </div>
                        <div class="flex justify-center mb-2">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?= $i <= round($rating_stats['average_rating']) ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="text-gray-600"><?= $rating_stats['total_ratings'] ?> total ratings</p>
                    </div>
                    
                    <!-- Rating Breakdown -->
                    <div class="space-y-2">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <?php 
                            $count = $rating_stats[number_to_words($star) . '_star'];
                            $percentage = $rating_stats['total_ratings'] > 0 ? ($count / $rating_stats['total_ratings']) * 100 : 0;
                            ?>
                            <div class="flex items-center">
                                <span class="text-sm text-gray-600 w-8"><?= $star ?></span>
                                <i class="fas fa-star text-yellow-400 mr-2"></i>
                                <div class="flex-1 rating-bar mr-2">
                                    <div class="rating-fill" style="width: <?= $percentage ?>%"></div>
                                </div>
                                <span class="text-sm text-gray-600 w-8"><?= $count ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-3">
                <?php if ($active_tab === 'ratings'): ?>
                    <!-- Product Ratings Content -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800">Product Ratings & Reviews</h2>
                        </div>
                        
                        <?php if (empty($product_ratings)): ?>
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-star text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No ratings yet</h3>
                                <p class="text-gray-500">Your products haven't received any ratings yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-6">
                                <?php foreach ($product_ratings as $rating): ?>
                                    <div class="border border-gray-200 rounded-lg p-6">
                                        <div class="flex items-start space-x-4">
                                            <div class="flex-shrink-0">
                                                <?php if ($rating['image_path']): ?>
                                                    <img src="<?= htmlspecialchars($rating['image_path']) ?>" alt="Product" class="w-16 h-16 rounded-lg object-cover">
                                                <?php else: ?>
                                                    <div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center">
                                                        <i class="fas fa-box text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center justify-between mb-2">
                                                    <h4 class="text-lg font-medium text-gray-900"><?= htmlspecialchars($rating['product_name']) ?></h4>
                                                    <div class="flex items-center">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star <?= $i <= $rating['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                                                        <?php endfor; ?>
                                                        <span class="ml-2 text-sm text-gray-600"><?= $rating['rating'] ?>/5</span>
                                                    </div>
                                                </div>
                                                <p class="text-sm text-gray-600 mb-2">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?= htmlspecialchars($rating['username']) ?>
                                                </p>
                                                <?php if ($rating['comment']): ?>
                                                    <div class="bg-gray-50 rounded-lg p-3 mb-2">
                                                        <p class="text-gray-700"><?= htmlspecialchars($rating['comment']) ?></p>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="text-xs text-gray-500">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    <?= date('M d, Y H:i', strtotime($rating['rating_date'])) ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination for ratings -->
                            <?php if ($total_rating_pages > 1): ?>
                                <div class="mt-6 flex justify-center">
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                        <?php if ($rating_page > 1): ?>
                                            <a href="?tab=ratings&rating_page=<?php echo $rating_page - 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                                <span class="sr-only">Previous</span>
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = 1; $i <= $total_rating_pages; $i++): ?>
                                            <a href="?tab=ratings&rating_page=<?php echo $i; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $rating_page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>
                                        
                                        <?php if ($rating_page < $total_rating_pages): ?>
                                            <a href="?tab=ratings&rating_page=<?php echo $rating_page + 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                                <span class="sr-only">Next</span>
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($order_details): ?>
                    <!-- Order Details View -->
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800">Order #<?php echo $order_details['id']; ?> Details</h2>
                                <p class="text-gray-600">Placed on <?php echo date('M d, Y h:i A', strtotime($order_details['created_at'])); ?></p>
                            </div>
                            <a href="seller_orders.php?tab=orders<?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                <i class="fas fa-arrow-left mr-2"></i> Back to Orders
                            </a>
                        </div>
                        
                        <!-- Order Status Timeline -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold mb-4">Order Status</h3>
                            <div class="status-timeline">
                                <?php
                                $statuses = [
                                    ['key' => 'pending', 'label' => 'Pending', 'icon' => 'fa-clock'],
                                    ['key' => 'processing', 'label' => 'Processing', 'icon' => 'fa-box'],
                                    // Added 'Awaiting Rider Acceptance' to the timeline
                                    ['key' => 'awaiting_rider_acceptance', 'label' => 'Awaiting Rider', 'icon' => 'fa-user-tag'],
                                    ['key' => 'shipped', 'label' => 'Shipped', 'icon' => 'fa-shipping-fast'],
                                    ['key' => 'out_for_delivery', 'label' => 'Out for Delivery', 'icon' => 'fa-truck'],
                                    ['key' => 'delivered', 'label' => 'Delivered', 'icon' => 'fa-check-circle'],
                                    // ['key' => 'completed', 'label' => 'Completed', 'icon' => 'fa-star'] // Removed 'Completed' as it's not a standard status
                                ];
                                
                                // Default to pending if status is not set
                                $current_status = isset($order_details['status']) && !empty($order_details['status']) ? $order_details['status'] : 'pending';
                                error_log("Current status for timeline display: $current_status");
                                
                                $current_status_index = -1; // Initialize to -1, meaning not found yet
                                foreach ($statuses as $index => $status_data) {
                                    if ($current_status === $status_data['key']) {
                                        $current_status_index = $index;
                                        error_log("Found exact status match at index $index: {$status_data['key']}");
                                        break; // Stop searching once found
                                    }
                                }
                                
                                // If current_status isn't in the defined statuses, find the closest preceding one for timeline visual
                                if ($current_status_index === -1) {
                                    error_log("Status '$current_status' not found in defined timeline statuses.");
                                    // Logic to find the last completed step visually if the exact status is not listed
                                    if ($current_status === 'return_pending' || $current_status === 'return_approved' || $current_status === 'refunded') {
                                        $current_status_index = array_search('delivered', array_column($statuses, 'key')); // Position it after delivered
                                    } elseif ($current_status === 'delayed') {
                                         $current_status_index = array_search('processing', array_column($statuses, 'key')); // Position it after processing
                                    } else {
                                        // Fallback for unknown statuses, might need specific handling
                                        $current_status_index = count($statuses) -1; // Assume it's after the last defined step
                                    }
                                    error_log("Adjusted current_status_index for timeline display to: $current_status_index based on status: $current_status");
                                }
                                
                                foreach ($statuses as $index => $status_data):
                                    // Check if this step is active, completed, or past
                                    $is_active = ($current_status === $status_data['key']);
                                    $is_completed = ($index < $current_status_index);

                                    // Special handling for statuses that don't directly map to a timeline step but should visually advance it
                                    if ($current_status === 'out_for_delivery' && $status_data['key'] === 'shipped') {
                                        $is_active = true; // If order is out for delivery, the 'Shipped' step is considered active for visual flow
                                    }
                                    if ($current_status === 'delivered' && $status_data['key'] === 'out_for_delivery') {
                                        $is_active = true; // If order is delivered, the 'Out for Delivery' step is considered active for visual flow
                                    }

                                    $status_class = $is_active ? 'active' : ($is_completed ? 'completed' : '');
                                ?>
                                    <div class="status-step">
                                        <div class="status-icon <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $status_data['icon']; ?> text-sm"></i>
                                        </div>
                                        <div class="status-label <?php echo $is_active ? 'active' : ''; ?>">
                                            <?php echo $status_data['label']; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Order Information -->
                            <div class="md:col-span-2">
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Information</h3>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Order Status</p>
                                            <p class="font-medium">
                                                <?php
                                                // Default to pending if status is not set
                                                $current_status = isset($order_details['status']) && !empty($order_details['status']) ? $order_details['status'] : 'pending';
                                                error_log("Current status for badge display: $current_status");
                                                
                                                $status_class = '';
                                                switch($current_status) {
                                                    case 'pending': $status_class = 'bg-yellow-100 text-yellow-800'; break;
                                                    case 'processing': $status_class = 'bg-blue-100 text-blue-800'; break;
                                                    case 'awaiting_rider_acceptance': $status_class = 'bg-gray-200 text-gray-800'; break; // New status class
                                                    case 'shipped': $status_class = 'bg-purple-100 text-purple-800'; break;
                                                    case 'out_for_delivery': $status_class = 'bg-indigo-100 text-indigo-800'; break;
                                                    case 'delivered': $status_class = 'bg-green-100 text-green-800'; break;
                                                    case 'cancelled': $status_class = 'bg-red-100 text-red-800'; break;
                                                    case 'delayed': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                    case 'return_pending': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                    case 'return_approved': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                    case 'refunded': $status_class = 'bg-gray-100 text-gray-800'; break;
                                                    default: $status_class = 'bg-gray-100 text-gray-800';
                                                }
                                                ?>
                                                <span class="px-2 py-1 <?php echo $status_class; ?> rounded-full text-xs font-semibold uppercase">
                                                    <?php echo ucfirst(str_replace('_', ' ', $current_status)); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-500">Order Date</p>
                                            <p class="font-medium"><?php echo date('M d, Y h:i A', strtotime($order_details['created_at'])); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-500">Order Total</p>
                                            <p class="font-medium">₱<?php echo number_format($order_details['total_amount'], 2); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-500">Your Portion</p>
                                            <p class="font-medium">₱<?php echo number_format($order_details['seller_total'], 2); ?></p>
                                        </div>
                                        <?php if (isset($order_details['tracking_number']) && $order_details['tracking_number']): ?>
                                        <div>
                                            <p class="text-sm text-gray-500">Tracking Number</p>
                                            <p class="font-medium"><?php echo htmlspecialchars($order_details['tracking_number']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (isset($order_details['courier']) && $order_details['courier']): ?>
                                        <div>
                                            <p class="text-sm text-gray-500">Courier</p>
                                            <p class="font-medium"><?php echo htmlspecialchars($order_details['courier']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (isset($order_details['estimated_delivery']) && $order_details['estimated_delivery']): ?>
                                        <div>
                                            <p class="text-sm text-gray-500">Estimated Delivery</p>
                                            <p class="font-medium"><?php echo date('M d, Y', strtotime($order_details['estimated_delivery'])); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Customer Information</h3>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Customer Name</p>
                                            <p class="font-medium"><?php echo htmlspecialchars($order_details['name']); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-500">Email</p>
                                            <p class="font-medium"><?php echo htmlspecialchars($order_details['email']); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-500">Phone</p>
                                            <p class="font-medium"><?php echo htmlspecialchars($order_details['phone'] ?? $order_details['phone_number'] ?? 'Not provided'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <p class="text-sm text-gray-500">Shipping Address</p>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($order_details['address']); ?><br>
                                            <?php echo htmlspecialchars($order_details['barangay'] . ', ' . $order_details['city'] . ', ' . $order_details['province']); ?><br>
                                            <?php echo htmlspecialchars($order_details['country'] . ' ' . $order_details['zip']); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Items</h3>
                                    
                                    <?php if (empty($order_items)): ?>
                                        <p class="text-gray-600">No items found for this order.</p>
                                    <?php else: ?>
                                        <div class="overflow-x-auto">
                                            <table class="min-w-full bg-white">
                                                <thead>
                                                    <tr>
                                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                            Product
                                                        </th>
                                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                            Price
                                                        </th>
                                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                            Quantity
                                                        </th>
                                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                            Subtotal
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $total = 0;
                                                    foreach ($order_items as $item): 
                                                        $subtotal = $item['price'] * $item['quantity'];
                                                        $total += $subtotal;
                                                        
                                                        // Determine which image field to use
                                                        $product_image = !empty($item['image_path']) ? $item['image_path'] : 
                                                                        (!empty($item['product_image']) ? $item['product_image'] : '');
                                                    ?>
                                                        <tr>
                                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                                <div class="flex items-center">
                                                                    <div class="flex-shrink-0 h-10 w-10">
                                                                        <?php if (!empty($product_image)): ?>
                                                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($product_image); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                                                        <?php else: ?>
                                                                            <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                                                <i class="fas fa-box text-gray-400"></i>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="ml-4">
                                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-right text-sm text-gray-500">
                                                                ₱<?php echo number_format($item['price'], 2); ?>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-right text-sm text-gray-500">
                                                                <?php echo $item['quantity']; ?>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-right text-sm font-medium text-gray-900">
                                                                ₱<?php echo number_format($subtotal, 2); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr>
                                                        <td colspan="3" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                                            Total:
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                                            ₱<?php echo number_format($total, 2); ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Order Status History -->
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Status History</h3>
                                    
                                    <?php if (empty($order_history)): ?>
                                        <p class="text-gray-600">No status history found for this order.</p>
                                    <?php else: ?>
                                        <div class="space-y-4">
                                            <?php foreach ($order_history as $history): ?>
                                                <div class="flex">
                                                    <div class="flex-shrink-0">
                                                        <?php
                                                        $icon_class = '';
                                                        switch($history['status']) {
                                                            case 'pending': $icon_class = 'bg-yellow-100 text-yellow-800'; break;
                                                            case 'processing': $icon_class = 'bg-blue-100 text-blue-800'; break;
                                                            case 'awaiting_rider_acceptance': $icon_class = 'bg-gray-200 text-gray-800'; break; // New status class
                                                            case 'shipped': $icon_class = 'bg-purple-100 text-purple-800'; break;
                                                            case 'out_for_delivery': $icon_class = 'bg-indigo-100 text-indigo-800'; break;
                                                            case 'delivered': $icon_class = 'bg-green-100 text-green-800'; break;
                                                            case 'cancelled': $icon_class = 'bg-red-100 text-red-800'; break;
                                                            case 'delayed': $icon_class = 'bg-orange-100 text-orange-800'; break;
                                                            case 'return_pending': $icon_class = 'bg-orange-100 text-orange-800'; break;
                                                            case 'return_approved': $icon_class = 'bg-orange-100 text-orange-800'; break;
                                                            case 'refunded': $icon_class = 'bg-gray-100 text-gray-800'; break;
                                                            default: $icon_class = 'bg-gray-100 text-gray-800';
                                                        }
                                                        ?>
                                                        <div class="h-8 w-8 rounded-full <?php echo $icon_class; ?> flex items-center justify-center">
                                                            <?php
                                                            $icon = '';
                                                            switch($history['status']) {
                                                                case 'pending': $icon = 'fa-clock'; break;
                                                                case 'processing': $icon = 'fa-cog'; break;
                                                                case 'awaiting_rider_acceptance': $icon = 'fa-user-tag'; break; // New status icon
                                                                case 'shipped': $icon = 'fa-shipping-fast'; break;
                                                                case 'out_for_delivery': $icon = 'fa-truck'; break;
                                                                case 'delivered': $icon = 'fa-check-circle'; break;
                                                                case 'cancelled': $icon = 'fa-times-circle'; break;
                                                                case 'delayed': $icon = 'fa-exclamation-triangle'; break;
                                                                case 'return_pending': $icon = 'fa-undo'; break;
                                                                case 'return_approved': $icon = 'fa-undo'; break;
                                                                case 'refunded': $icon = 'fa-money-bill-wave'; break;
                                                                default: $icon = 'fa-info-circle';
                                                            }
                                                            ?>
                                                            <i class="fas <?php echo $icon; ?> text-sm"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4 flex-1">
                                                        <div class="flex justify-between">
                                                            <p class="text-sm font-medium text-gray-900">
                                                                <?php echo ucfirst(str_replace('_', ' ', $history['status'])); ?>
                                                            </p>
                                                            <p class="text-sm text-gray-500">
                                                                <?php echo date('M d, Y h:i A', strtotime($history['created_at'])); ?>
                                                            </p>
                                                        </div>
                                                        <p class="text-sm text-gray-600 mt-1">
                                                            <?php echo htmlspecialchars($history['notes'] ?? ''); ?>
                                                        </p>
                                                        <p class="text-xs text-gray-500 mt-1">
                                                            Updated by: <?php echo htmlspecialchars($history['updated_by'] ?? 'System'); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Proof of Delivery Display -->
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Delivery Proof</h3>
                                    
                                    <?php 
                                    $current_status = isset($order_details['status']) && !empty($order_details['status']) ? $order_details['status'] : 'pending';
                                    if ($current_status === 'delivered'): 
                                    ?>
                                        <?php if ($order_details['proof_of_delivery']): ?>
                                            <div class="bg-blue-50 rounded-lg p-6 border border-blue-200">
                                                <div class="mb-4">
                                                    <p class="text-sm text-gray-600 mb-2"><strong>Delivered by:</strong></p>
                                                    <p id="modalRiderName" class="text-lg font-semibold text-gray-800">
                                                        <?php echo htmlspecialchars($order_details['rider_name'] ?? 'Unknown Rider'); ?>
                                                    </p>
                                                </div>
                                                
                                                <div class="mb-4">
                                                    <p class="text-sm text-gray-600 mb-2"><strong>Delivery Date & Time:</strong></p>
                                                    <p id="modalDeliveryTime" class="text-lg font-semibold text-gray-800">
                                                        <?php 
                                                        if ($order_details['proof_of_delivery_date']) {
                                                            echo date('M d, Y H:i A', strtotime($order_details['proof_of_delivery_date']));
                                                        } else {
                                                            // Fallback to order status history
                                                            foreach ($order_history as $history) {
                                                                if ($history['status'] === 'delivered') {
                                                                    echo date('M d, Y H:i A', strtotime($history['created_at']));
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                        ?>
                                                    </p>
                                                </div>
                                                
                                                <div>
                                                    <p class="text-sm text-gray-600 mb-3"><strong>Proof of Delivery:</strong></p>
                                                    <button onclick="openProofModal('<?php echo htmlspecialchars($order_details['proof_of_delivery']); ?>', '<?php echo htmlspecialchars($order_details['rider_name'] ?? 'Unknown Rider'); ?>', '<?php echo $order_details['proof_of_delivery_date'] ? date('M d, Y H:i A', strtotime($order_details['proof_of_delivery_date'])) : (isset($history) && $history['status'] === 'delivered' ? date('M d, Y H:i A', strtotime($history['created_at'])) : ''); ?>')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md flex items-center gap-2">
                                                        <i class="fas fa-image"></i> View Proof Photo
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-gray-600 text-center py-6">No proof of delivery available for this order.</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-gray-600 text-center py-6">Proof of delivery will appear once order is delivered.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Action Panel -->
                            <div>
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 sticky top-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Actions</h3>
                                    
                                    <?php 
                                    // Default to pending if status is not set
                                    $current_status = isset($order_details['status']) && !empty($order_details['status']) ? $order_details['status'] : 'pending';
                                    error_log("Current status for action panel: $current_status");
                                    
                                    if ($current_status == 'pending'): 
                                    ?>
                                        <form action="" method="POST" class="mb-4">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="process">
                                            <button type="submit" class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                <i class="fas fa-box mr-2"></i> Process Order
                                            </button>
                                        </form>
                                        
                                        <form action="" method="POST">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" onclick="return confirm('Are you sure you want to cancel this order?');" class="w-full py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                                <i class="fas fa-times-circle mr-2"></i> Cancel Order
                                            </button>
                                        </form>
                                    <?php elseif ($current_status == 'processing'): ?>
                                        <form action="" method="POST" class="mb-4">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="ship">
                                            
                                            <!-- Removed courier dropdown and tracking number, replaced with fixed rider name -->
                                            <div class="mb-4">
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Rider Name</label>
                                                <input type="text" value="Mark Angelo Lumang" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
                                                <input type="hidden" name="rider_name" value="Mark Angelo Lumang">
                                            </div>
                                            
                                            <!-- Removed estimated delivery date field from processing tab - now set by rider -->
                                            
                                            <!-- Changed button text from "Mark as Shipped" to "Notify rider" -->
                                            <button type="submit" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                <i class="fas fa-bell mr-2"></i> Notify rider
                                            </button>
                                        </form>
                                        
                                        <form action="" method="POST" class="mb-4">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="delay">
                                            
                                            <div class="mb-4">
                                                <label for="delay_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for Delay</label>
                                                <textarea id="delay_reason" name="delay_reason" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Explain the reason for delay"></textarea>
                                            </div>
                                            
                                            <button type="submit" class="w-full py-2 px-4 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                                                <i class="fas fa-exclamation-triangle mr-2"></i> Mark as Delayed
                                            </button>
                                        </form>
                                    <?php elseif ($current_status == 'awaiting_rider_acceptance'): ?>
                                        <!-- Order actions removed from awaiting rider acceptance status - no action buttons shown -->
                                        <p class="text-center text-gray-600 mb-4">Order is waiting for a rider to accept.</p>
                                    <?php elseif ($current_status == 'shipped'): ?>
                                        <form action="" method="POST" class="mb-4">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="ship">
                                            
                                            <div class="mb-4">
                                                <label for="courier" class="block text-sm font-medium text-gray-700 mb-1">Courier Service</label>
                                                <select id="courier" name="courier" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    <option value="">Select Courier</option>
                                                    <?php foreach ($couriers as $courier): ?>
                                                        <option value="<?= htmlspecialchars($courier) ?>" <?= (isset($order_details['courier']) && $order_details['courier'] == $courier) ? 'selected' : '' ?>><?= htmlspecialchars($courier) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label for="tracking_number" class="block text-sm font-medium text-gray-700 mb-1">Tracking Number</label>
                                                <input type="text" id="tracking_number" name="tracking_number" value="<?= $order_details['tracking_number'] ?? '' ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label for="estimated_delivery" class="block text-sm font-medium text-gray-700 mb-1">Estimated Delivery Date</label>
                                                <input type="date" id="estimated_delivery" name="estimated_delivery" value="<?= $order_details['estimated_delivery'] ?? '' ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                            
                                            <button type="submit" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                <i class="fas fa-bell mr-2"></i> Notify rider
                                            </button>
                                        </form>
                                    <?php elseif ($current_status == 'out_for_delivery'): ?>
                                        <form action="" method="POST" enctype="multipart/form-data"> <!-- Added enctype for file upload -->
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="deliver">
                                            
                                            <div class="mb-4">
                                                <label for="proof_of_delivery" class="block text-sm font-medium text-gray-700 mb-1">Proof of Delivery Photo</label>
                                                <input type="file" id="proof_of_delivery" name="proof_of_delivery" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>

                                            <button type="submit" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                <i class="fas fa-check-circle mr-2"></i> Mark as Delivered
                                            </button>
                                        </form>
                                    <?php elseif ($current_status == 'delayed'): ?>
                                        <form action="" method="POST" class="mb-4">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="process">
                                            <button type="submit" class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                <i class="fas fa-box mr-2"></i> Resume Processing
                                            </button>
                                        </form>
                                        
                                        <form action="" method="POST">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" onclick="return confirm('Are you sure you want to cancel this order?');" class="w-full py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                                <i class="fas fa-times-circle mr-2"></i> Cancel Order
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-gray-600 text-center">No actions available for this order status.</p>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($current_status, ['shipped', 'out_for_delivery', 'delivered']) && !empty($order_details['rider_name'])): ?>
                                        <!-- Added section to display rider name -->
                                        <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                                            <h4 class="font-medium text-blue-800 mb-2">Delivery Information</h4>
                                            <p class="text-sm text-blue-700">
                                                <span class="font-medium">Rider:</span> <?php echo htmlspecialchars($order_details['rider_name']); ?>
                                            </p>
                                            <?php if (!empty($order_details['estimated_delivery'])): ?>
                                                <p class="text-sm text-blue-700 mt-1">
                                                    <span class="font-medium">Estimated Delivery:</span> <?php echo date('M d, Y', strtotime($order_details['estimated_delivery'])); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-6">
                                        <h4 class="text-sm font-medium text-gray-700 mb-2">Customer Communication</h4>
                                        <a href="mailto:<?php echo htmlspecialchars($order_details['email']); ?>" class="w-full py-2 px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 flex items-center justify-center">
                                            <i class="fas fa-envelope mr-2"></i> Email Customer
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Orders List -->
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800">Order Management</h2>
                                <p class="text-gray-600">Manage your customer orders</p>
                            </div>
                        </div>
                        
                        <!-- Order Statistics -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                            <a href="?tab=orders&status=pending" class="bg-yellow-50 rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                <h3 class="text-lg font-semibold text-yellow-700">Pending</h3>
                                <p class="text-3xl font-bold text-yellow-600"><?php echo isset($status_counts['pending']) ? $status_counts['pending'] : 0; ?></p>
                            </a>
                            <a href="?tab=orders&status=processing" class="bg-blue-50 rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                <h3 class="text-lg font-semibold text-blue-700">Processing</h3>
                                <p class="text-3xl font-bold text-blue-600"><?php echo isset($status_counts['processing']) ? $status_counts['processing'] : 0; ?></p>
                            </a>
                            <!-- CHANGED: Changed status label for 'Awaiting Rider Acceptance' -->
                            <a href="?tab=orders&status=awaiting_rider_acceptance" class="bg-orange-50 rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                <h3 class="text-lg font-semibold text-orange-700">Awaiting Rider</h3>
                                <p class="text-3xl font-bold text-orange-600"><?php echo isset($status_counts['awaiting_rider_acceptance']) ? $status_counts['awaiting_rider_acceptance'] : 0; ?></p>
                            </a>
                            <a href="?tab=orders&status=shipped" class="bg-purple-50 rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                <h3 class="text-lg font-semibold text-purple-700">Shipped</h3>
                                <p class="text-3xl font-bold text-purple-600"><?php echo isset($status_counts['shipped']) ? $status_counts['shipped'] : 0; ?></p>
                            </a>
                            <a href="?tab=orders&status=delivered" class="bg-green-50 rounded-lg p-4 hover:shadow-md transition-shadow duration-200">
                                <h3 class="text-lg font-semibold text-green-700">Delivered</h3>
                                <p class="text-3xl font-bold text-green-600"><?php echo isset($status_counts['delivered']) ? $status_counts['delivered'] : 0; ?></p>
                            </a>
                        </div>
                        
                        <?php if (isset($success)): ?>
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                                <p><?php echo $success; ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                                <p><?php echo $error; ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Orders Table -->
                        <div class="overflow-x-auto">
                            <?php if (empty($orders)): ?>
                                <div class="p-6 text-center">
                                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                        <i class="fas fa-shopping-bag text-3xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 mb-1">No orders found</h3>
                                    <p class="text-gray-500">
                                        <?php if ($status_filter === 'pending'): ?>
                                            There are no pending orders at this time.
                                        <?php elseif ($status_filter === 'processing'): ?>
                                            There are no orders being processed.
                                        <?php elseif ($status_filter === 'awaiting_rider_acceptance'): ?>
                                            There are no orders awaiting rider acceptance.
                                        <?php elseif ($status_filter === 'shipped'): ?>
                                            There are no shipped orders.
                                        <?php elseif ($status_filter === 'delivered'): ?>
                                            There are no delivered orders.
                                        <?php elseif ($status_filter === 'cancelled'): ?>
                                            There are no cancelled orders.
                                        <?php else: ?>
                                            There are no orders in the system.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <table class="min-w-full bg-white">
                                    <thead>
                                        <tr>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Order ID
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Customer
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Items
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Total
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Date
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <div class="text-sm font-medium text-gray-900">#<?php echo $order['id']; ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['username']); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($order['email']); ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <div class="text-sm text-gray-900"><?php echo $order['item_count']; ?> products</div>
                                                    <div class="text-sm text-gray-500"><?php echo $order['total_items']; ?> items total</div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <div class="text-sm font-medium text-gray-900">₱<?php echo number_format($order['seller_total'], 2); ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo date('h:i A', strtotime($order['created_at'])); ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                    <?php
                                                    // Default to pending if status is not set
                                                    $current_status = isset($order['status']) && !empty($order['status']) ? $order['status'] : 'pending';
                                                    error_log("Status for order #{$order['id']} in list: $current_status");
                                                    
                                                    $status_class = '';
                                                    switch($current_status) {
                                                        case 'pending': $status_class = 'bg-yellow-100 text-yellow-800'; break;
                                                        case 'processing': $status_class = 'bg-blue-100 text-blue-800'; break;
                                                        case 'awaiting_rider_acceptance': $status_class = 'bg-gray-200 text-gray-800'; break; // New status class
                                                        case 'shipped': $status_class = 'bg-purple-100 text-purple-800'; break;
                                                        case 'out_for_delivery': $status_class = 'bg-indigo-100 text-indigo-800'; break;
                                                        case 'delivered': $status_class = 'bg-green-100 text-green-800'; break;
                                                        case 'cancelled': $status_class = 'bg-red-100 text-red-800'; break;
                                                        case 'delayed': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                        case 'return_pending': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                        case 'return_approved': $status_class = 'bg-orange-100 text-orange-800'; break;
                                                        case 'refunded': $status_class = 'bg-gray-100 text-gray-800'; break;
                                                        default: $status_class = 'bg-gray-100 text-gray-800';
                                                    }
                                                    ?>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $current_status)); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-right text-sm font-medium">
                                                    <a href="?tab=orders&view=<?php echo $order['id']; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                    </a>
                                                    
                                                    <?php 
                                                    // Default to pending if status is not set
                                                    $current_status = isset($order['status']) && !empty($order['status']) ? $order['status'] : 'pending';
                                                    error_log("Status for order #{$order['id']} actions: $current_status");
                                                    
                                                    if ($current_status == 'pending'): 
                                                    ?>
                                                        <form action="" method="POST" class="inline">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <input type="hidden" name="action" value="process">
                                                            <button type="submit" class="text-blue-600 hover:text-blue-900 mr-3">
                                                                <i class="fas fa-box mr-1"></i> Process
                                                            </button>
                                                        </form>
                                                    <?php elseif ($current_status == 'processing'): ?>
                                                        <form action="" method="POST" class="inline">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <input type="hidden" name="action" value="ship">
                                                            <button type="submit" class="text-purple-600 hover:text-purple-900">
                                                                <i class="fas fa-shipping-fast mr-1"></i> Ship
                                                            </button>
                                                        </form>
                                                    <?php elseif ($current_status == 'awaiting_rider_acceptance'): ?>
                                                        <!-- No direct action here, waiting for rider -->
                                                    <?php elseif ($current_status == 'shipped'): ?>
                                                        <form action="" method="POST" class="inline">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <input type="hidden" name="action" value="deliver">
                                                            <button type="submit" class="text-green-600 hover:text-green-900">
                                                                <i class="fas fa-check-circle mr-1"></i> Deliver
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                    <div class="mt-6 flex justify-center">
                                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                            <?php if ($page > 1): ?>
                                                <a href="?tab=orders&page=<?php echo $page - 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                                    <span class="sr-only">Previous</span>
                                                    <i class="fas fa-chevron-left"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <a href="?tab=orders&page=<?php echo $i; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <a href="?tab=orders&page=<?php echo $page + 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                                    <span class="sr-only">Next</span>
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </nav>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
     <!-- Footer -->
    <footer class="bg-green-800 text-white py-8 mt-auto">
  <div class="container mx-auto px-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div>
              <h3 class="text-lg font-semibold mb-4">About Wastewise</h3>
              <p class="text-sm">Committed to a sustainable future through recycling and eco-friendly shopping.</p>
          </div>
          <div>
              <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
              <ul class="space-y-2 text-sm">
                  <li><a href="home.php" class="hover:text-green-200">Home</a></li>
                         <li><a href="community_impact.php" class="hover:text-green-200">Community Impact</a></li>
                  <li><a href="about.php" class="hover:text-green-200">About Us</a></li>
                  <li><a href="contact-uss.php" class="hover:text-green-200">Contact</a></li>
                  <li><a href="faq.php" class="hover:text-green-200">FAQ</a></li>
              </ul>
          </div>
          <div>
              <h3 class="text-lg font-semibold mb-4">Connect With Us</h3>
              <div class="flex space-x-4">
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-facebook-f"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-twitter"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-instagram"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-linkedin-in"></i></a>
              </div>
          </div>
      </div>
      <div class="border-t border-green-700 mt-6 pt-6 text-center">
          <p>&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p>
      </div>
  </div>
</footer>
    
    <script>
        // Function to show current date in estimated delivery date field
        document.addEventListener('DOMContentLoaded', function() {
            const estimatedDeliveryField = document.getElementById('estimated_delivery');
            if (estimatedDeliveryField) {
                // Set default date to 7 days from now
                const today = new Date();
                const nextWeek = new Date(today);
                nextWeek.setDate(today.getDate() + 7);
                
                // Format date as YYYY-MM-DD for input field
                const year = nextWeek.getFullYear();
                const month = String(nextWeek.getMonth() + 1).padStart(2, '0');
                const day = String(nextWeek.getDate()).padStart(2, '0');
                
                estimatedDeliveryField.value = `${year}-${month}-${day}`;
                
                // Set min date to today
                const todayFormatted = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                estimatedDeliveryField.min = todayFormatted;
            }
        });

        // Proof Modal functionality
        let proofImageUrl = ''; // Added this declaration

        function openProofModal(imageUrl, riderName, deliveryTime) {
            proofImageUrl = imageUrl; // Use the global variable
            document.getElementById('proofImage').src = imageUrl;
            document.getElementById('modalRiderName').textContent = riderName;
            document.getElementById('modalDeliveryTime').textContent = deliveryTime;
            document.getElementById('proofModal').classList.remove('hidden');
        }
        
        function closeProofModal() {
            document.getElementById('proofModal').classList.add('hidden');
        }
        
        function downloadProofImage() {
            const link = document.createElement('a');
            link.href = proofImageUrl; // Use the global variable
            link.download = 'proof_of_delivery.jpg'; // Consistent download name
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Close modal when clicking outside
        document.getElementById('proofModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeProofModal();
            }
        });

        function toggleDropdown() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.classList.toggle('hidden');
        }

        function openProfileModal(event) {
            event.preventDefault();
            document.getElementById('profileModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('profileModal').classList.add('hidden');
        }

        function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
            document.getElementById('userMenuDropdown').classList.add('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }

        // Optional: Click outside to close dropdown
        window.addEventListener('click', function (e) {
            const dropdown = document.getElementById('userMenuDropdown');
            if (!e.target.closest('.relative')) {
                dropdown.classList.add('hidden');
            }
        });
    </script>

    <!-- Proof of Delivery Modal -->
    <div id="proofModal" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white border-b border-gray-200 p-6 flex justify-between items-center">
                <h2 class="text-2xl font-bold text-gray-800">Proof of Delivery</h2>
                <button onclick="closeProofModal()" class="text-gray-500 hover:text-gray-700 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-6">
                <div class="mb-6">
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div>
                            <p class="text-sm text-gray-600 mb-1"><strong>Delivered by:</strong></p>
                            <p id="modalRiderName" class="text-lg font-semibold text-gray-800"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 mb-1"><strong>Delivery Date & Time:</strong></p>
                            <p id="modalDeliveryTime" class="text-lg font-semibold text-gray-800"></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-100 rounded-lg overflow-hidden">
                    <img id="proofImage" src="/placeholder.svg" alt="Proof of Delivery" class="w-full h-auto">
                </div>
                
                <div class="mt-6 flex justify-center">
                    <button onclick="downloadProofImage()" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md flex items-center gap-2">
                        <i class="fas fa-download"></i> Download Image
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
