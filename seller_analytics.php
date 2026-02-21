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

// Handle status messages
$status_class = '';
$status_message = '';

switch ($seller['status']) {
    case 'pending':
        $status_class = 'bg-yellow-100 text-yellow-800 border-yellow-200';
        $status_message = 'Your seller account is pending approval. We will review your application and notify you once it\'s approved.';
        break;
    case 'approved':
        $status_class = 'bg-green-100 text-green-800 border-green-200';
        $status_message = 'Your seller account is approved. You can now start selling on Wastewise!';
        break;
    case 'rejected':
        $status_class = 'bg-red-100 text-red-800 border-red-200';
        $status_message = 'Your seller application was rejected. Reason: ' . $seller['rejection_reason'];
        break;
    case 'suspended':
        $status_class = 'bg-red-100 text-red-800 border-red-200';
        $status_message = 'Your seller account is currently suspended. Please contact support for more information.';
        break;
}

// Get date range for analytics
$date_range = isset($_GET['range']) ? $_GET['range'] : 'month';
$custom_start = isset($_GET['start']) ? $_GET['start'] : '';
$custom_end = isset($_GET['end']) ? $_GET['end'] : '';

// Set date range based on selection
$today = date('Y-m-d');
$start_date = '';
$end_date = $today;

switch ($date_range) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $range_label = 'Last 7 Days';
        break;
    case 'month':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $range_label = 'Last 30 Days';
        break;
    case 'quarter':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        $range_label = 'Last 90 Days';
        break;
    case 'year':
        $start_date = date('Y-m-d', strtotime('-365 days'));
        $range_label = 'Last 365 Days';
        break;
    case 'custom':
        if (!empty($custom_start) && !empty($custom_end)) {
            $start_date = $custom_start;
            $end_date = $custom_end;
            $range_label = date('M d, Y', strtotime($start_date)) . ' - ' . date('M d, Y', strtotime($end_date));
        } else {
            $start_date = date('Y-m-d', strtotime('-30 days'));
            $range_label = 'Last 30 Days';
        }
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $range_label = 'Last 30 Days';
}

// Check if seller_id column exists in orders table
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'orders' 
        AND COLUMN_NAME = 'seller_id'
    ");
    $stmt->execute();
    $seller_id_exists_in_orders = (bool)$stmt->fetchColumn();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $seller_id_exists_in_orders = false;
}

// Get sales data
try {
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as order_date,
                COUNT(*) as order_count,
                SUM(total_amount) as total_sales,
                0 as waste_recycled
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ? 
            AND status != 'cancelled'
            GROUP BY DATE(created_at)
            ORDER BY order_date
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(o.created_at) as order_date,
                COUNT(DISTINCT o.id) as order_count,
                SUM(oi.price * oi.quantity) as total_sales,
                SUM(oi.quantity * COALESCE(p.weight, 0.5)) as waste_recycled
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY DATE(o.created_at)
            ORDER BY order_date
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    }
    
    $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for charts
    $dates = [];
    $sales = [];
    $orders = [];
    $waste_recycled_daily = [];
    
    foreach ($sales_data as $data) {
        $dates[] = date('M d', strtotime($data['order_date']));
        $sales[] = floatval($data['total_sales']);
        $orders[] = intval($data['order_count']);
        $waste_recycled_daily[] = floatval($data['waste_recycled'] ?? 0);
    }
    
    // Calculate totals
    $total_sales = array_sum($sales);
    $total_orders = array_sum($orders);
    $avg_order_value = $total_orders > 0 ? $total_sales / $total_orders : 0;
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $sales_data = [];
    $dates = [];
    $sales = [];
    $orders = [];
    $waste_recycled_daily = [];
    $total_sales = 0;
    $total_orders = 0;
    $avg_order_value = 0;
}

// ECO IMPACT METRICS - NEW SECTION
try {
    // Calculate total waste recycled from seller's products
    $stmt = $pdo->prepare("
        SELECT 
            SUM(oi.quantity * COALESCE(p.weight, 0.5)) as total_waste_recycled,
            COUNT(DISTINCT oi.product_id) as unique_products_sold,
            SUM(oi.quantity) as total_items_sold
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE p.seller_id = ? 
        AND o.created_at BETWEEN ? AND ? 
        AND o.status != 'cancelled'
    ");
    $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    $eco_metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $waste_recycled = floatval($eco_metrics['total_waste_recycled'] ?? 0);
    $unique_products_sold = intval($eco_metrics['unique_products_sold'] ?? 0);
    $total_items_sold = intval($eco_metrics['total_items_sold'] ?? 0);
    
    // Calculate CO2 saved (estimate: 1kg recycled = 2.5kg CO2 saved)
    $co2_saved = $waste_recycled * 2.5;
    
    // Calculate trees equivalent (1 tree absorbs ~22kg CO2 per year)
    $trees_equivalent = $co2_saved / 22;
    
    // Get seller's eco-friendly product categories
    $stmt = $pdo->prepare("
        SELECT 
            p.category,
            COUNT(*) as product_count,
            SUM(oi.quantity) as items_sold,
            SUM(oi.price * oi.quantity) as revenue
        FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.id
        WHERE p.seller_id = ? 
        AND (o.created_at BETWEEN ? AND ? OR o.created_at IS NULL)
        AND (o.status != 'cancelled' OR o.status IS NULL)
        GROUP BY p.category
        ORDER BY revenue DESC
    ");
    $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    $eco_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate seller's environmental impact score
    $impact_score = min(100, ($waste_recycled * 10) + ($unique_products_sold * 5) + ($total_items_sold * 2));
    
    // Get seller's ranking among all sellers (based on waste recycled)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as seller_rank
        FROM (
            SELECT 
                p.seller_id,
                SUM(oi.quantity * COALESCE(p.weight, 0.5)) as seller_waste
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY p.seller_id
            HAVING seller_waste > ?
        ) as ranking
    ");
    $stmt->execute([$start_date, $end_date . ' 23:59:59', $waste_recycled]);
    $seller_rank = $stmt->fetchColumn() ?: 1;
    
    // Get total number of active sellers for ranking context
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.seller_id) as total_sellers
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE o.created_at BETWEEN ? AND ? 
        AND o.status != 'cancelled'
    ");
    $stmt->execute([$start_date, $end_date . ' 23:59:59']);
    $total_active_sellers = $stmt->fetchColumn() ?: 1;
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $waste_recycled = 0;
    $co2_saved = 0;
    $trees_equivalent = 0;
    $impact_score = 0;
    $seller_rank = 1;
    $total_active_sellers = 1;
    $eco_categories = [];
}

// Get top selling products
try {
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.name,
                p.image_path,
                p.category,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.price * oi.quantity) as total_revenue,
                SUM(oi.quantity * COALESCE(p.weight, 0.5)) as waste_recycled
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY p.id, p.name
            ORDER BY total_quantity DESC
            LIMIT 5
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.name,
                p.image_path,
                p.category,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.price * oi.quantity) as total_revenue,
                SUM(oi.quantity * COALESCE(p.weight, 0.5)) as waste_recycled
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY p.id, p.name
            ORDER BY total_quantity DESC
            LIMIT 5
        ");
    }
    
    $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $top_products = [];
}

// Get order status distribution
try {
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ?
            GROUP BY status
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                o.status,
                COUNT(DISTINCT o.id) as count
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ?
            GROUP BY o.status
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    }
    
    $status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for charts
    $status_labels = [];
    $status_counts = [];
    
    foreach ($status_data as $data) {
        $status_labels[] = ucfirst(str_replace('_', ' ', $data['status']));
        $status_counts[] = intval($data['count']);
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $status_data = [];
    $status_labels = [];
    $status_counts = [];
}

// Get customer demographics (if available)
try {
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT 
                o.city,
                COUNT(DISTINCT o.id) as order_count
            FROM orders o
            WHERE o.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            AND o.city IS NOT NULL
            GROUP BY o.city
            ORDER BY order_count DESC
            LIMIT 5
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                o.city,
                COUNT(DISTINCT o.id) as order_count
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            AND o.city IS NOT NULL
            GROUP BY o.city
            ORDER BY order_count DESC
            LIMIT 5
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    }
    
    $customer_cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $customer_cities = [];
}

// Get sales by category
try {
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT 
                p.category,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.price * oi.quantity) as total_revenue
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY p.category
            ORDER BY total_revenue DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.category,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.price * oi.quantity) as total_revenue
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
            GROUP BY p.category
            ORDER BY total_revenue DESC
        ");
    }
    
    $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    $category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for charts
    $category_labels = [];
    $category_sales = [];
    
    foreach ($category_data as $data) {
        $category_labels[] = $data['category'];
        $category_sales[] = floatval($data['total_revenue']);
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $category_data = [];
    $category_labels = [];
    $category_sales = [];
}

// Calculate growth metrics (compare with previous period)
try {
    // Calculate previous period
    $prev_start_date = date('Y-m-d', strtotime($start_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));
    $prev_end_date = date('Y-m-d', strtotime($end_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));
    
    // Get current period sales
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT SUM(total_amount) as total_sales
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ? 
            AND status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT SUM(oi.price * oi.quantity) as total_sales
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    }
    
    $current_sales = $stmt->fetchColumn() ?: 0;
    
    // Get previous period sales
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT SUM(total_amount) as total_sales
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ? 
            AND status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $prev_start_date, $prev_end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT SUM(oi.price * oi.quantity) as total_sales
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $prev_start_date, $prev_end_date . ' 23:59:59']);
    }
    
    $prev_sales = $stmt->fetchColumn() ?: 0;
    
    // Calculate growth percentage
    $sales_growth = $prev_sales > 0 ? (($current_sales - $prev_sales) / $prev_sales) * 100 : 0;
    
    // Get current period orders
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as order_count
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ? 
            AND status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT o.id) as order_count
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $start_date, $end_date . ' 23:59:59']);
    }
    
    $current_orders = $stmt->fetchColumn() ?: 0;
    
    // Get previous period orders
    if ($seller_id_exists_in_orders) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as order_count
            FROM orders 
            WHERE seller_id = ? 
            AND created_at BETWEEN ? AND ? 
            AND status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $prev_start_date, $prev_end_date . ' 23:59:59']);
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT o.id) as order_count
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ? 
            AND o.created_at BETWEEN ? AND ? 
            AND o.status != 'cancelled'
        ");
        $stmt->execute([$seller['id'], $prev_start_date, $prev_end_date . ' 23:59:59']);
    }
    
    $prev_orders = $stmt->fetchColumn() ?: 0;
    
    // Calculate growth percentage
    $orders_growth = $prev_orders > 0 ? (($current_orders - $prev_orders) / $prev_orders) * 100 : 0;
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $sales_growth = 0;
    $orders_growth = 0;
}

// Active tab
$active_tab = 'analytics';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Analytics & Eco Impact - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .eco-gradient {
            background: linear-gradient(135deg, #4CAF50 0%, #8BC34A 50%, #CDDC39 100%);
        }
        .pulse-eco {
            animation: pulse-eco 2s infinite;
        }
        @keyframes pulse-eco {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .impact-card {
            transition: all 0.3s ease;
        }
        .impact-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
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
        <!-- Status Banner -->
        <?php if ($status_message): ?>
            <div class="<?php echo $status_class; ?> border p-4 rounded-lg mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <?php if ($seller['status'] === 'approved'): ?>
                            <i class="fas fa-check-circle text-green-500"></i>
                        <?php elseif ($seller['status'] === 'pending'): ?>
                            <i class="fas fa-clock text-yellow-500"></i>
                        <?php else: ?>
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                        <?php endif; ?>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium">
                            Account Status: <span class="font-bold"><?php echo ucfirst($seller['status']); ?></span>
                        </h3>
                        <div class="mt-2 text-sm">
                            <p><?php echo $status_message; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <!-- ECO IMPACT HERO SECTION -->
        <div class="eco-gradient rounded-2xl p-8 mb-8 text-white relative overflow-hidden">
            <div class="absolute inset-0 bg-black bg-opacity-10"></div>
            <div class="relative z-10">
                <div class="flex flex-col md:flex-row items-center justify-between">
                    <div class="mb-6 md:mb-0">
                        <h1 class="text-4xl font-bold mb-2">🌱 Your Eco Impact</h1>
                        <p class="text-xl opacity-90">Making a difference, one sale at a time</p>
                    </div>
                    <div class="text-center">
                        <div class="pulse-eco bg-white bg-opacity-20 rounded-full w-32 h-32 flex items-center justify-center mb-4">
                            <i class="fas fa-leaf text-6xl"></i>
                        </div>
                        <p class="text-lg font-semibold">Eco Champion</p>
                    </div>
                </div>
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
                        <a href="seller-dashboard" class="flex items-center px-4 py-2 <?php echo $active_tab === 'dashboard' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
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
                
                <!-- Analytics Filters -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Date Range</h3>
                    <form action="seller_analytics.php" method="GET" class="space-y-4">
                        <div class="space-y-2">
                            <label class="inline-flex items-center">
                                <input type="radio" name="range" value="week" class="form-radio text-green-600" <?php echo $date_range === 'week' ? 'checked' : ''; ?>>
                                <span class="ml-2">Last 7 Days</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="range" value="month" class="form-radio text-green-600" <?php echo $date_range === 'month' ? 'checked' : ''; ?>>
                                <span class="ml-2">Last 30 Days</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="range" value="quarter" class="form-radio text-green-600" <?php echo $date_range === 'quarter' ? 'checked' : ''; ?>>
                                <span class="ml-2">Last 90 Days</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="range" value="year" class="form-radio text-green-600" <?php echo $date_range === 'year' ? 'checked' : ''; ?>>
                                <span class="ml-2">Last 365 Days</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="range" value="custom" class="form-radio text-green-600" <?php echo $date_range === 'custom' ? 'checked' : ''; ?>>
                                <span class="ml-2">Custom Range</span>
                            </label>
                        </div>
                        
                        <div id="custom-date-range" class="space-y-2 <?php echo $date_range === 'custom' ? '' : 'hidden'; ?>">
                            <div>
                                <label for="start" class="block text-sm text-gray-600">Start Date</label>
                                <input type="date" id="start" name="start" class="w-full px-3 py-2 border border-gray-300 rounded-md" value="<?php echo $custom_start; ?>">
                            </div>
                            <div>
                                <label for="end" class="block text-sm text-gray-600">End Date</label>
                                <input type="date" id="end" name="end" class="w-full px-3 py-2 border border-gray-300 rounded-md" value="<?php echo $custom_end; ?>">
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                            Apply Filters
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-3">
                <!-- Analytics Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Analytics & Eco Impact Dashboard</h2>
                            <p class="text-gray-600">Viewing data for: <span class="font-medium"><?php echo $range_label; ?></span></p>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <button id="export-analytics" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                <i class="fas fa-download mr-2"></i> Export Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ECO IMPACT METRICS -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="impact-card bg-gradient-to-br from-green-400 to-green-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Waste Recycled</p>
                                <h3 class="text-2xl font-bold"><?php echo number_format($waste_recycled, 1); ?> kg</h3>
                                <p class="text-xs mt-1 opacity-75">
                                    <i class="fas fa-recycle mr-1"></i>
                                    From <?php echo $total_items_sold; ?> items sold
                                </p>
                            </div>
                            <div class="bg-white bg-opacity-20 p-3 rounded-full">
                                <i class="fas fa-recycle text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Sales</p>
                                <h3 class="text-2xl font-bold text-gray-800">₱<?php echo number_format($total_sales, 2); ?></h3>
                            </div>
                            <div class="bg-green-100 p-3 rounded-full">
                                <i class="fas fa-money-bill-wave text-green-500"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Orders</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_orders; ?></h3>
                            </div>
                            <div class="bg-blue-100 p-3 rounded-full">
                                <i class="fas fa-shopping-cart text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Average Order Value</p>
                                <h3 class="text-2xl font-bold text-gray-800">₱<?php echo number_format($avg_order_value, 2); ?></h3>
                            </div>
                            <div class="bg-purple-100 p-3 rounded-full">
                                <i class="fas fa-receipt text-purple-500"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales Trend Chart -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Sales & Environmental Impact Trend</h3>
                    <div class="h-80">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
                
                <!-- Two Column Analytics -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Top Eco Products -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">🌱 Top Eco-Friendly Products</h3>
                        <?php if (empty($top_products)): ?>
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-leaf text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No eco products sold</h3>
                                <p class="text-gray-500">Start selling eco-friendly products to see your impact!</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($top_products as $product): ?>
                                    <div class="flex items-center">
                                        <div class="h-12 w-12 flex-shrink-0">
                                            <?php if (isset($product['image_path']) && $product['image_path']): ?>
                                                <img class="h-12 w-12 rounded-md object-cover" src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                            <?php else: ?>
                                                <div class="h-12 w-12 rounded-md bg-green-100 flex items-center justify-center">
                                                    <i class="fas fa-leaf text-green-500"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <div class="flex justify-between">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo $product['total_quantity']; ?> units • <?php echo number_format($product['waste_recycled'], 1); ?>kg recycled
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-sm font-semibold text-gray-900">₱<?php echo number_format($product['total_revenue'], 2); ?></div>
                                                    <div class="text-xs text-green-600">
                                                        <i class="fas fa-leaf mr-1"></i><?php echo htmlspecialchars($product['category']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
                                                <div class="bg-green-600 h-1.5 rounded-full" style="width: <?php echo min(100, ($product['total_revenue'] / $top_products[0]['total_revenue']) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Order Status Distribution -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Status Distribution</h3>
                        <?php if (empty($status_data)): ?>
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-chart-pie text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No order data</h3>
                                <p class="text-gray-500">No orders have been placed in this period.</p>
                            </div>
                        <?php else: ?>
                            <div class="h-64">
                                <canvas id="statusChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Two Column Analytics -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Eco Categories Performance -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">🌿 Eco Categories Performance</h3>
                        <?php if (empty($eco_categories)): ?>
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-tags text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No category data</h3>
                                <p class="text-gray-500">Add more eco-friendly products to see category performance.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($eco_categories as $category): ?>
                                    <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="bg-green-100 p-2 rounded-full mr-3">
                                                <i class="fas fa-leaf text-green-600"></i>
                                            </div>
                                            <div>
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['category']); ?></span>
                                                <div class="text-xs text-gray-500"><?php echo $category['product_count']; ?> products</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-semibold text-gray-900">₱<?php echo number_format($category['revenue'], 2); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo $category['items_sold']; ?> sold</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Customer Demographics -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Top Customer Locations</h3>
                        <?php if (empty($customer_cities)): ?>
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-map-marker-alt text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No location data</h3>
                                <p class="text-gray-500">No customer location data available for this period.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($customer_cities as $city): ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <i class="fas fa-map-marker-alt text-red-500 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($city['city']); ?></span>
                                        </div>
                                        <div class="text-sm text-gray-600"><?php echo $city['order_count']; ?> orders</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Performance Insights -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">🎯 Performance & Eco Insights</h3>
                    
                    <div class="space-y-4">
                        <?php if ($impact_score >= 80): ?>
                            <div class="flex items-start p-4 bg-green-50 rounded-lg">
                                <div class="flex-shrink-0 bg-green-100 rounded-full p-2">
                                    <i class="fas fa-trophy text-green-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-green-800">Eco Champion Status!</h4>
                                    <p class="text-sm text-green-700 mt-1">Outstanding eco impact! Your impact score of <?php echo round($impact_score); ?>/100 puts you among the top eco-friendly sellers. Keep up the amazing work!</p>
                                </div>
                            </div>
                        <?php elseif ($impact_score >= 50): ?>
                            <div class="flex items-start p-4 bg-blue-50 rounded-lg">
                                <div class="flex-shrink-0 bg-blue-100 rounded-full p-2">
                                    <i class="fas fa-leaf text-blue-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-blue-800">Good Eco Progress</h4>
                                    <p class="text-sm text-blue-700 mt-1">You're making a positive environmental impact! Your score of <?php echo round($impact_score); ?>/100 shows good progress. Consider adding more eco-friendly products to boost your impact.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="flex items-start p-4 bg-yellow-50 rounded-lg">
                                <div class="flex-shrink-0 bg-yellow-100 rounded-full p-2">
                                    <i class="fas fa-seedling text-yellow-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-yellow-800">Growing Your Eco Impact</h4>
                                    <p class="text-sm text-yellow-700 mt-1">Great start! Your impact score of <?php echo round($impact_score); ?>/100 has room to grow. Focus on selling more eco-friendly products to increase your environmental contribution.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($seller_rank <= 5): ?>
                            <div class="flex items-start p-4 bg-purple-50 rounded-lg">
                                <div class="flex-shrink-0 bg-purple-100 rounded-full p-2">
                                    <i class="fas fa-crown text-purple-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-purple-800">Top Performer!</h4>
                                    <p class="text-sm text-purple-700 mt-1">Congratulations! You're ranked #<?php echo $seller_rank; ?> among all sellers for environmental impact. You're leading by example in sustainable commerce!</p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($waste_recycled >= 10): ?>
                            <div class="flex items-start p-4 bg-emerald-50 rounded-lg">
                                <div class="flex-shrink-0 bg-emerald-100 rounded-full p-2">
                                    <i class="fas fa-recycle text-emerald-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-emerald-800">Waste Reduction Hero</h4>
                                    <p class="text-sm text-emerald-700 mt-1">Amazing! You've helped recycle <?php echo number_format($waste_recycled, 1); ?>kg of waste, saving <?php echo number_format($co2_saved, 1); ?>kg of CO₂. That's equivalent to <?php echo number_format($trees_equivalent, 1); ?> trees!</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($sales_growth > 10): ?>
                            <div class="flex items-start p-4 bg-green-50 rounded-lg">
                                <div class="flex-shrink-0 bg-green-100 rounded-full p-2">
                                    <i class="fas fa-arrow-trend-up text-green-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-green-800">Strong Sales Growth</h4>
                                    <p class="text-sm text-green-700 mt-1">Your sales have increased by <?php echo round($sales_growth, 1); ?>% compared to the previous period. Keep up the good work!</p>
                                </div>
                            </div>
                        <?php elseif ($sales_growth < -10): ?>
                            <div class="flex items-start p-4 bg-red-50 rounded-lg">
                                <div class="flex-shrink-0 bg-red-100 rounded-full p-2">
                                    <i class="fas fa-arrow-trend-down text-red-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-red-800">Sales Opportunity</h4>
                                    <p class="text-sm text-red-700 mt-1">Your sales have decreased by <?php echo abs(round($sales_growth, 1)); ?>%. Consider promoting your eco-friendly products more or adding new sustainable items to your catalog.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($top_products) && count($top_products) >= 2): ?>
                            <div class="flex items-start p-4 bg-blue-50 rounded-lg">
                                <div class="flex-shrink-0 bg-blue-100 rounded-full p-2">
                                    <i class="fas fa-star text-blue-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-blue-800">Top Eco Product</h4>
                                    <p class="text-sm text-blue-700 mt-1">"<?php echo htmlspecialchars($top_products[0]['name']); ?>" is your best-selling eco product with <?php echo $top_products[0]['total_quantity']; ?> units sold, recycling <?php echo number_format($top_products[0]['waste_recycled'], 1); ?>kg of waste!</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Export Modal -->
    <div id="export-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-4 mb-4">
                <h3 class="text-xl font-semibold text-gray-900">Export Analytics Report</h3>
                <button id="close-export-modal" class="text-gray-600 hover:text-gray-900">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="export-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Export Format</label>
                    <div class="space-y-2">
                        <p class="text-sm text-gray-600">PDF Report</p>
                        <input type="hidden" name="export_format" value="pdf">
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" id="cancel-export" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Export PDF
                    </button>
                </div>
            </form>
        </div>
    </div>

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
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    </script>
    <script>
        // Toggle custom date range inputs
        document.querySelectorAll('input[name="range"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                const customDateRange = document.getElementById('custom-date-range');
                if (this.value === 'custom') {
                    customDateRange.classList.remove('hidden');
                } else {
                    customDateRange.classList.add('hidden');
                }
            });
        });
        
        // Export analytics report
        document.getElementById('export-analytics').addEventListener('click', function() {
            // Show export options modal
            document.getElementById('export-modal').classList.remove('hidden');
        });
        
        // Close export modal
        document.getElementById('close-export-modal').addEventListener('click', function() {
            document.getElementById('export-modal').classList.add('hidden');
        });
        
        document.getElementById('cancel-export').addEventListener('click', function() {
            document.getElementById('export-modal').classList.add('hidden');
        });
        
        // Handle export form submission
        document.getElementById('export-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const startDate = '<?php echo $start_date; ?>';
            const endDate = '<?php echo $end_date; ?>';
            
            // Create export URL
            const exportUrl = `export_analytics.php?start_date=${startDate}&end_date=${endDate}`;
            
            // Redirect to export URL (will trigger download)
            window.location.href = exportUrl;
            
            // Hide modal
            document.getElementById('export-modal').classList.add('hidden');
        });
        
        // Sales Trend Chart with Eco Impact
        <?php if (!empty($sales_data)): ?>
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        
        const ecoImpactData = <?php echo json_encode($waste_recycled_daily); ?>;
        
        const salesChart = new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [
                    {
                        label: 'Total Sales (₱)',
                        data: <?php echo json_encode($sales); ?>,
                        borderColor: '#4CAF50',
                        backgroundColor: 'rgba(76, 175, 80, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Total Orders',
                        data: <?php echo json_encode($orders); ?>,
                        borderColor: '#2196F3',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Waste Recycled (kg)',
                        data: ecoImpactData,
                        borderColor: '#8BC34A',
                        backgroundColor: 'rgba(139, 195, 74, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y2'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Sales (₱)',
                            font: { weight: 'bold', size: 12 }
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        },
                        grid: {
                            display: true,
                            drawBorder: false,
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Orders',
                            font: { weight: 'bold', size: 12 }
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' orders';
                            }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    },
                    y2: {
                        beginAtZero: true,
                        position: 'right',
                        offset: true,
                        title: {
                            display: true,
                            text: 'Waste Recycled (kg)',
                            font: { weight: 'bold', size: 12 }
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toFixed(1) + ' kg';
                            }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date',
                            font: { weight: 'bold', size: 12 }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: { size: 12, weight: 'bold' },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += '₱' + context.parsed.y.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                } else if (context.datasetIndex === 1) {
                                    label += context.parsed.y + ' orders';
                                } else if (context.datasetIndex === 2) {
                                    label += context.parsed.y.toFixed(2) + ' kg';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Order Status Chart
        <?php if (!empty($status_data)): ?>
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_counts); ?>,
                    backgroundColor: [
                        '#FFC107', // pending - yellow
                        '#2196F3', // processing - blue
                        '#9C27B0', // shipped - purple
                        '#4CAF50', // delivered - green
                        '#F44336', // cancelled - red
                        '#FF9800', // return_pending - orange
                        '#FF5722', // return_approved - deep orange
                        '#9E9E9E'  // refunded - gray
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Add hover effects to impact cards
        document.querySelectorAll('.impact-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
         function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }
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
</body>
</html>
