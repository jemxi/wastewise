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
    
    // Get seller documents
    $stmt = $pdo->prepare("SELECT * FROM seller_documents WHERE seller_id = ?");
    $stmt->execute([$seller['id']]);
    $documents = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle AJAX requests for archive/restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $ajax_action = $_POST['ajax_action'];
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $seller_id = isset($_POST['seller_id']) ? intval($_POST['seller_id']) : 0;
    
    // Validate seller ID matches current user
    if ($seller_id !== $seller['id']) {
        echo json_encode(['success' => false, 'message' => 'Invalid seller ID']);
        exit();
    }
    
    if ($ajax_action === 'archive_product') {
        try {
            // Verify product belongs to this seller
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
            $product = $stmt->fetch();
            
            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found or you don\'t have permission to archive it.']);
                exit();
            }
            
            // Archive the product
            $stmt = $pdo->prepare("UPDATE products SET archived = 1, updated_at = NOW() WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
            
            echo json_encode(['success' => true, 'message' => 'Product archived successfully!']);
            exit();
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }
    
    if ($ajax_action === 'restore_product') {
        try {
            // Verify product belongs to this seller
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
            $product = $stmt->fetch();
            
            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found or you don\'t have permission to restore it.']);
                exit();
            }
            
            // Restore the product
            $stmt = $pdo->prepare("UPDATE products SET archived = 0, updated_at = NOW() WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
            
            echo json_encode(['success' => true, 'message' => 'Product restored successfully!']);
            exit();
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }
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

// Get product categories
try {
    $stmt = $pdo->prepare("SELECT * FROM product_categories ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $categories = [];
}

// Handle product form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($seller['status'] !== 'approved') {
        $error = "You can only manage products after your seller account has been approved. Your current status is: " . ucfirst($seller['status']);
    } else {
        $action = $_POST['action'];
        
        // Add new product
        if ($action === 'add_product') {
            try {
                // Validate input
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $price = floatval($_POST['price']);
                $stock = intval($_POST['stock']);
                $category = trim($_POST['category']);
                
                if (empty($name) || empty($description) || $price <= 0 || $stock < 0) {
                    throw new Exception("Please fill in all required fields with valid values.");
                }
                
                // Handle image upload
                $image_path = null;
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === 0) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    
                    if (!in_array($_FILES['product_image']['type'], $allowed_types)) {
                        throw new Exception("Only JPG, PNG, and GIF images are allowed.");
                    }
                    
                    if ($_FILES['product_image']['size'] > $max_size) {
                        throw new Exception("Image size should be less than 5MB.");
                    }
                    
                    $upload_dir = 'uploads/products/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                    $file_name = 'product_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) {
                        $image_path = $target_file;
                    } else {
                        throw new Exception("Failed to upload image. Please try again.");
                    }
                }
                
                // Insert product into database
                $stmt = $pdo->prepare("
                    INSERT INTO products (
                        seller_id, name, description, price, stock, category, 
                        image_path, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $seller['id'], $name, $description, $price, $stock, $category, 
                    $image_path
                ]);
                
                $success = "Product added successfully!";
                
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        
        // Edit existing product
        elseif ($action === 'edit_product' && isset($_POST['product_id'])) {
            try {
                $product_id = intval($_POST['product_id']);
                
                // Verify product belongs to this seller
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
                $stmt->execute([$product_id, $seller['id']]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    throw new Exception("Product not found or you don't have permission to edit it.");
                }
                
                // Validate input
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $price = floatval($_POST['price']);
                $stock = intval($_POST['stock']);
                $category = trim($_POST['category']);
                
                if (empty($name) || empty($description) || $price <= 0 || $stock < 0) {
                    throw new Exception("Please fill in all required fields with valid values.");
                }
                
                // Handle image upload if a new image is provided
                $image_path = $product['image_path']; // Keep existing image by default
                
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === 0) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    
                    if (!in_array($_FILES['product_image']['type'], $allowed_types)) {
                        throw new Exception("Only JPG, PNG, and GIF images are allowed.");
                    }
                    
                    if ($_FILES['product_image']['size'] > $max_size) {
                        throw new Exception("Image size should be less than 5MB.");
                    }
                    
                    $upload_dir = 'uploads/products/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                    $file_name = 'product_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) {
                        // Delete old image if it exists
                        if ($product['image_path'] && file_exists($product['image_path'])) {
                            unlink($product['image_path']);
                        }
                        $image_path = $target_file;
                    } else {
                        throw new Exception("Failed to upload image. Please try again.");
                    }
                }
                
                // Update product in database
                $stmt = $pdo->prepare("
                    UPDATE products SET 
                        name = ?, 
                        description = ?, 
                        price = ?, 
                        stock = ?, 
                        category = ?, 
                        image_path = ?,
                        updated_at = NOW()
                    WHERE id = ? AND seller_id = ?
                ");
                
                $stmt->execute([
                    $name, $description, $price, $stock, $category, 
                    $image_path, $product_id, $seller['id']
                ]);
                
                $success = "Product updated successfully!";
                
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        
        // Delete product
        elseif ($action === 'delete_product' && isset($_POST['product_id'])) {
            try {
                $product_id = intval($_POST['product_id']);
                
                // Verify product belongs to this seller
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
                $stmt->execute([$product_id, $seller['id']]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    throw new Exception("Product not found or you don't have permission to delete it.");
                }
                
                // Check if product is referenced in order_items
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
                $stmt->execute([$product_id]);
                $has_orders = ($stmt->fetchColumn() > 0);
                
                if ($has_orders) {
                    // Soft delete - mark as archived instead of deleting
                    $stmt = $pdo->prepare("
                        UPDATE products 
                        SET archived = 1, 
                            stock = 0, 
                            updated_at = NOW()
                        WHERE id = ? AND seller_id = ?
                    ");
                    $stmt->execute([$product_id, $seller['id']]);
                    
                    $success = "Product has been archived because it has existing orders. It will no longer appear in the store.";
                } else {
                    // Hard delete - product has no orders
                    // Delete product image if it exists
                    if ($product['image_path'] && file_exists($product['image_path'])) {
                        unlink($product['image_path']);
                    }
                    
                    // Delete product from database
                    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
                    $stmt->execute([$product_id, $seller['id']]);
                    
                    $success = "Product deleted successfully!";
                }
                
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get seller's products with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // Check if seller_id column exists in products table
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'products' 
        AND COLUMN_NAME = 'seller_id'
    ");
    $stmt->execute();
    $seller_id_exists = (bool)$stmt->fetchColumn();
    
    // Get total count
    if ($seller_id_exists) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.seller_id = ?");
        $stmt->execute([$seller['id']]);
    } else {
        // Fallback if seller_id column doesn't exist
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM products p");
        $stmt->execute();
    }
    $total_products = $stmt->fetchColumn();
    
    // Get products for current page
    if ($seller_id_exists) {
        $stmt = $pdo->prepare("
        SELECT DISTINCT p.* FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        WHERE p.seller_id = ? AND (p.archived = 0 OR p.archived IS NULL)
        GROUP BY p.id
        ORDER BY p.created_at DESC 
        LIMIT ?, ?
    ");
        $stmt->bindValue(1, $seller['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    } else {
        // Fallback if seller_id column doesn't exist
        $stmt = $pdo->prepare("
        SELECT DISTINCT p.* FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        WHERE (p.archived = 0 OR p.archived IS NULL)
        GROUP BY p.id
        ORDER BY p.created_at DESC 
        LIMIT ?, ?
    ");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $products = $stmt->fetchAll();
    
    $total_pages = ceil($total_products / $limit);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $products = [];
    $total_pages = 0;
    $total_products = 0;
}

// Get product for editing if edit mode is active
$edit_product = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    try {
        $product_id = intval($_GET['edit']);
        
        // Check if seller_id column exists in products table
        if ($seller_id_exists) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
        } else {
            // Fallback if seller_id column doesn't exist
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
        }
        $edit_product = $stmt->fetch();
        
        if (!$edit_product) {
            $error = "Product not found or you don't have permission to edit it.";
        }
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get product categories for the filter
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Apply filters if set
if (!empty($filter_category) || !empty($search_term)) {
    try {
        // Check if seller_id column exists in products table
        if ($seller_id_exists) {
            $query = "SELECT DISTINCT p.* FROM products p
                      LEFT JOIN order_items oi ON p.id = oi.product_id
                      WHERE p.seller_id = ?";
            $params = [$seller['id']];
        } else {
            // Fallback if seller_id column doesn't exist
            $query = "SELECT DISTINCT p.* FROM products p
                      LEFT JOIN order_items oi ON p.id = oi.product_id
                      WHERE 1=1";
            $params = [];
        }
        
        if (!empty($filter_category)) {
            $query .= " AND p.category = ?";
            $params[] = $filter_category;
        }
        
        if (!empty($search_term)) {
            $query .= " AND (p.name LIKE ? OR p.description LIKE ?)";
            $search_param = "%{$search_term}%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $query .= " GROUP BY p.id ORDER BY p.created_at DESC LIMIT ?, ?";
        
        $stmt = $pdo->prepare($query);
        
        // Bind parameters properly
        for ($i = 0; $i < count($params); $i++) {
            $stmt->bindValue($i + 1, $params[$i]);
        }
        $stmt->bindValue(count($params) + 1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        // Get total count with filters
        $count_query = str_replace("SELECT DISTINCT p.*", "SELECT COUNT(DISTINCT p.id)", $query);
        $count_query = preg_replace("/GROUP BY p.id ORDER BY p.created_at DESC LIMIT \?, \?/", "", $count_query);
        
        $stmt = $pdo->prepare($count_query);
        for ($i = 0; $i < count($params); $i++) {
            $stmt->bindValue($i + 1, $params[$i]);
        }
        $stmt->execute();
        $total_products = $stmt->fetchColumn();
        
        $total_pages = ceil($total_products / $limit);
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get product statistics
try {
    // Check if seller_id column exists in products table
    if ($seller_id_exists) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ?");
        $stmt->execute([$seller['id']]);
    } else {
        // Fallback if seller_id column doesn't exist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products");
        $stmt->execute();
    }
    $product_count = $stmt->fetchColumn();
    
    // Check if seller_id column exists in orders table
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'orders' 
        AND COLUMN_NAME = 'seller_id'
    ");
    $stmt->execute();
    $order_seller_id_exists = (bool)$stmt->fetchColumn();
    
    if ($order_seller_id_exists) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE seller_id = ?");
        $stmt->execute([$seller['id']]);
        $order_count = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM orders WHERE seller_id = ? AND status = 'completed'");
        $stmt->execute([$seller['id']]);
        $total_revenue = $stmt->fetchColumn() ?: 0;
    } else {
        // Fallback if seller_id column doesn't exist in orders
        $order_count = 0;
        $total_revenue = 0;
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $product_count = 0;
    $order_count = 0;
    $total_revenue = 0;
}

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - Wastewise</title>
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
        
        <?php if (isset($success)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>

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
                        <a href="?tab=dashboard" class="flex items-center px-4 py-2 <?php echo $active_tab === 'dashboard' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-tachometer-alt w-5 mr-2"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="?tab=products" class="flex items-center px-4 py-2 <?php echo $active_tab === 'products' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-box w-5 mr-2"></i>
                            <span>Products</span>
                        </a>
                        <a href="?tab=archived" class="flex items-center px-4 py-2 <?php echo $active_tab === 'archived' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
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
                
                <?php if ($active_tab === 'products'): ?>
                <!-- Product Categories -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Product Categories</h3>
                    <ul class="space-y-2">
                        <li>
                            <a href="?tab=products" class="text-gray-700 hover:text-green-600 flex items-center <?php echo empty($filter_category) ? 'font-bold text-green-600' : ''; ?>">
                                <i class="fas fa-layer-group mr-2"></i>
                                <span>All Categories</span>
                            </a>
                        </li>
                        <?php foreach ($categories as $category): ?>
                            <li>
                                <a href="?tab=products&category=<?php echo urlencode($category['name']); ?>" class="text-gray-700 hover:text-green-600 flex items-center <?php echo $filter_category === $category['name'] ? 'font-bold text-green-600' : ''; ?>">
                                    <i class="fas fa-tag mr-2"></i>
                                    <span><?php echo htmlspecialchars($category['name']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-3">
                <?php if ($active_tab === 'dashboard'): ?>
                <!-- Dashboard Content -->
                <!-- Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Products</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $product_count; ?></h3>
                            </div>
                            <div class="bg-blue-100 p-3 rounded-full">
                                <i class="fas fa-box text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Orders</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $order_count; ?></h3>
                            </div>
                            <div class="bg-green-100 p-3 rounded-full">
                                <i class="fas fa-shopping-cart text-green-500"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Revenue</p>
                                <h3 class="text-2xl font-bold text-gray-800">₱<?php echo number_format($total_revenue, 2); ?></h3>
                            </div>
                            <div class="bg-purple-100 p-3 rounded-full">
                                <i class="fas fa-money-bill-wave text-purple-500"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Business Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Business Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Business Name</p>
                            <p class="font-medium"><?php echo htmlspecialchars($seller['business_name']); ?></p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500">Business Type</p>
                            <p class="font-medium"><?php echo htmlspecialchars($seller['business_type']); ?></p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500">Tax ID</p>
                            <p class="font-medium"><?php echo isset($seller['tax_id']) && $seller['tax_id'] ? htmlspecialchars($seller['tax_id']) : 'Not provided'; ?></p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500">Phone Number</p>
                            <p class="font-medium"><?php echo htmlspecialchars($seller['phone_number']); ?></p>
                        </div>
                        
                        <div class="md:col-span-2">
                            <p class="text-sm text-gray-500">Business Address</p>
                            <p class="font-medium">
                                <?php echo htmlspecialchars($seller['business_address']); ?>,
                                <?php echo htmlspecialchars($seller['city']); ?>,
                                <?php echo htmlspecialchars($seller['state']); ?>,
                                <?php echo htmlspecialchars($seller['postal_code']); ?>,
                                <?php echo htmlspecialchars($seller['country']); ?>
                            </p>
                        </div>
                        
                        <?php if (isset($seller['website']) && $seller['website']): ?>
                        <div class="md:col-span-2">
                            <p class="text-sm text-gray-500">Website</p>
                            <p class="font-medium">
                                <a href="<?php echo htmlspecialchars($seller['website']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                                    <?php echo htmlspecialchars($seller['website']); ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($seller['description']) && $seller['description']): ?>
                        <div class="md:col-span-2">
                            <p class="text-sm text-gray-500">Description</p>
                            <p class="font-medium"><?php echo nl2br(htmlspecialchars($seller['description'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-4 flex justify-end">
                        <a href="?tab=settings" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-edit mr-1"></i> Edit Information
                        </a>
                    </div>
                </div>
                
                <!-- Documents -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Verification Documents</h3>
                    
                    <?php if (empty($documents)): ?>
                        <p class="text-gray-600">No documents uploaded yet.</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($documents as $document): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                    <div>
                                        <p class="font-medium"><?php echo htmlspecialchars($document['document_type']); ?></p>
                                        <p class="text-sm text-gray-500">Uploaded: <?php echo date('M d, Y', strtotime($document['uploaded_at'])); ?></p>
                                    </div>
                                    <div class="flex items-center">
                                        <?php if ($document['is_verified']): ?>
                                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded-md text-xs mr-2">Verified</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-md text-xs mr-2">Pending</span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($document['document_url']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4 flex justify-end">
                      <!--  <a href="?tab=settings" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-upload mr-1"></i> Upload New Document
                        </a> -->
                </div>
                
                <!-- Getting Started -->
                <?php if ($seller['status'] === 'approved'): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Getting Started</h3>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="bg-green-100 p-2 rounded-full mr-4">
                                <i class="fas fa-check text-green-500"></i>
                            </div>
                            <div>
                                <h4 class="font-medium">Complete your seller profile</h4>
                                <p class="text-sm text-gray-600">Your seller account has been approved and is ready to use.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-100 p-2 rounded-full mr-4">
                                <span class="text-gray-500 font-medium">2</span>
                            </div>
                            <div>
                                <h4 class="font-medium">Add your first product</h4>
                                <p class="text-sm text-gray-600">Start selling by adding your products to the marketplace.</p>
                                <a href="?tab=products" class="text-blue-600 hover:text-blue-800 text-sm mt-1 inline-block">
                                    Add Product <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-100 p-2 rounded-full mr-4">
                                <span class="text-gray-500 font-medium">3</span>
                            </div>
                            <div>
                                <h4 class="font-medium">Set up payment methods</h4>
                                <p class="text-sm text-gray-600">Configure how you want to receive payments from customers.</p>
                                <a href="?tab=settings" class="text-blue-600 hover:text-blue-800 text-sm mt-1 inline-block">
                                    Setup Payments <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-100 p-2 rounded-full mr-4">
                                <span class="text-gray-500 font-medium">4</span>
                            </div>
                            <div>
                                <h4 class="font-medium">Configure shipping options</h4>
                                <p class="text-sm text-gray-600">Set up your shipping methods and delivery areas.</p>
                                <a href="?tab=settings" class="text-blue-600 hover:text-blue-800 text-sm mt-1 inline-block">
                                    Configure Shipping <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php elseif ($active_tab === 'products'): ?>
                <!-- Products Content -->
                <!-- Add approval check - only approved sellers can add products -->
                <?php if ($seller['status'] !== 'approved'): ?>
                    <!-- Not Approved Message -->
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-yellow-400 text-2xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-lg font-medium text-yellow-800">Account Not Approved</h3>
                                    <div class="mt-2 text-sm text-yellow-700">
                                        <p>Your seller account is currently <strong><?php echo ucfirst($seller['status']); ?></strong>. You can only add and manage products after your account has been approved by our admin team.</p>
                                        <p class="mt-2">Please wait for approval notification or contact support if you have any questions.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Approved - Show Product Management Form -->
                <!-- Product Management -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">
                            <?php echo $edit_product ? 'Edit Product' : 'Add New Product'; ?>
                        </h2>
                        <?php if ($edit_product): ?>
                            <a href="?tab=products" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                <i class="fas fa-plus mr-2"></i> Add New Product
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <form action="seller-dashboard.php?tab=products" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="<?php echo $edit_product ? 'edit_product' : 'add_product'; ?>">
                        <?php if ($edit_product): ?>
                            <input type="hidden" name="product_id" value="<?php echo $edit_product['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Product Name *</label>
                                <input type="text" id="name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required value="<?php echo $edit_product ? htmlspecialchars($edit_product['name']) : ''; ?>">
                            </div>
                            
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category *</label>
                                <select id="category" name="category" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                                    <option value="">Select a category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category['name']); ?>" <?php echo ($edit_product && $edit_product['category'] === $category['name']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price (₱) *</label>
                                <input type="number" id="price" name="price" min="0.01" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required value="<?php echo $edit_product ? htmlspecialchars($edit_product['price']) : ''; ?>">
                            </div>
                            
                            <div>
                                <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Stock Quantity *</label>
                                <input type="number" id="stock" name="stock" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required value="<?php echo $edit_product ? htmlspecialchars($edit_product['stock']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                            <textarea id="description" name="description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required><?php echo $edit_product ? htmlspecialchars($edit_product['description']) : ''; ?></textarea>
                        </div>
                        
                        <div>
                            <label for="product_image" class="block text-sm font-medium text-gray-700 mb-1">
                                Product Image <?php echo $edit_product ? '(Leave empty to keep current image)' : '*'; ?>
                            </label>
                            <input type="file" id="product_image" name="product_image" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" <?php echo $edit_product ? '' : 'required'; ?>>
                            <p class="text-xs text-gray-500 mt-1">Accepted formats: JPG, PNG, GIF. Max size: 5MB.</p>
                            
                            <?php if ($edit_product && isset($edit_product['image_path']) && $edit_product['image_path']): ?>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-600 mb-1">Current Image:</p>
                                    <img src="<?php echo htmlspecialchars($edit_product['image_path']); ?>" alt="Product Image" class="h-32 object-contain border border-gray-200 rounded-md">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <?php if ($edit_product): ?>
                                <a href="?tab=products" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    Cancel
                                </a>
                                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Update Product
                                </button>
                            <?php else: ?>
                                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Add Product
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Product List -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Your Products</h2>
                        
                        <!-- Search and Filter -->
                        <div class="flex flex-col md:flex-row space-y-2 md:space-y-0 md:space-x-2">
                            <form action="" method="GET" class="flex">
                                <input type="hidden" name="tab" value="products">
                                <input type="text" name="search" placeholder="Search products..." class="px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($search_term); ?>">
                                <button type="submit" class="px-3 py-2 bg-green-600 text-white rounded-r-md hover:bg-green-700">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                            
                            <?php if (!empty($search_term) || !empty($filter_category)): ?>
                                <a href="?tab=products" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($products)): ?>
                        <div class="text-center py-8">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                <i class="fas fa-box-open text-3xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-1">No products found</h3>
                            <p class="text-gray-500">
                                <?php if (!empty($search_term) || !empty($filter_category)): ?>
                                    No products match your search criteria. Try different keywords or filters.
                                <?php else: ?>
                                    You haven't added any products yet. Use the form above to add your first product.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Product
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Price
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Stock
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Date Added
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="flex items-center">
                                                    <div class="h-10 w-10 flex-shrink-0">
                                                        <?php if (isset($product['image_path']) && $product['image_path']): ?>
                                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                                        <?php else: ?>
                                                            <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                                <i class="fas fa-box text-gray-400"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    <?php echo htmlspecialchars($product['category']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="text-sm text-gray-900">₱<?php echo number_format($product['price'], 2); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="text-sm text-gray-900"><?php echo $product['stock']; ?></div>
                                                <?php if ($product['stock'] <= 5): ?>
                                                    <span class="text-xs text-red-600">Low stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($product['created_at'])); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-sm font-medium text-right">
                                                <a href="?tab=products&edit=<?php echo $product['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button type="button" onclick="confirmArchive(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>')" class="text-amber-600 hover:text-amber-900">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="mt-6 flex justify-center">
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?tab=products&page=<?php echo $page - 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?tab=products&page=<?php echo $i; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?tab=products&page=<?php echo $page + 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php elseif ($active_tab === 'archived'): ?>
                <!-- Archived Products Content -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Archived Products</h2>
                        
                        <!-- Search and Filter -->
                        <div class="flex flex-col md:flex-row space-y-2 md:space-y-0 md:space-x-2">
                            <form action="" method="GET" class="flex">
                                <input type="hidden" name="tab" value="archived">
                                <input type="text" name="search" placeholder="Search archived products..." class="px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($search_term); ?>">
                                <button type="submit" class="px-3 py-2 bg-green-600 text-white rounded-r-md hover:bg-green-700">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                            
                            <?php if (!empty($search_term) || !empty($filter_category)): ?>
                                <a href="?tab=archived" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php
                    // Get archived products
                    try {
                        if ($seller_id_exists) {
                            $stmt = $pdo->prepare("
                            SELECT DISTINCT p.* FROM products p
                            LEFT JOIN order_items oi ON p.id = oi.product_id
                            WHERE p.seller_id = ? AND p.archived = 1
                            GROUP BY p.id
                            ORDER BY p.updated_at DESC 
                            LIMIT ?, ?
                        ");
                            $stmt->bindValue(1, $seller['id'], PDO::PARAM_INT);
                            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
                        } else {
                            $stmt = $pdo->prepare("
                            SELECT DISTINCT p.* FROM products p
                            LEFT JOIN order_items oi ON p.id = oi.product_id
                            WHERE p.archived = 1
                            GROUP BY p.id
                            ORDER BY p.updated_at DESC 
                            LIMIT ?, ?
                        ");
                            $stmt->bindValue(1, $offset, PDO::PARAM_INT);
                            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
                        }
                        $stmt->execute();
                        $archived_products = $stmt->fetchAll();
                        
                        // Get total count
                        if ($seller_id_exists) {
                            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.seller_id = ? AND p.archived = 1");
                            $stmt->execute([$seller['id']]);
                        } else {
                            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.archived = 1");
                            $stmt->execute();
                        }
                        $total_archived = $stmt->fetchColumn();
                        $total_archived_pages = ceil($total_archived / $limit);
                        
                    } catch (PDOException $e) {
                        $error = "Database error: " . $e->getMessage();
                        $archived_products = [];
                        $total_archived = 0;
                        $total_archived_pages = 0;
                    }
                    ?>
                    
                    <?php if (empty($archived_products)): ?>
                        <div class="text-center py-8">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                <i class="fas fa-archive text-3xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-1">No archived products found</h3>
                            <p class="text-gray-500">
                                <?php if (!empty($search_term) || !empty($filter_category)): ?>
                                    No archived products match your search criteria. Try different keywords or filters.
                                <?php else: ?>
                                    You haven't archived any products yet.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Product
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Price
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Date Archived
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($archived_products as $product): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="flex items-center">
                                                    <div class="h-10 w-10 flex-shrink-0">
                                                        <?php if (isset($product['image_path']) && $product['image_path']): ?>
                                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                                        <?php else: ?>
                                                            <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                                <i class="fas fa-box text-gray-400"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    <?php echo htmlspecialchars($product['category']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="text-sm text-gray-900">₱<?php echo number_format($product['price'], 2); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                                <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($product['updated_at'])); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-sm font-medium text-right">
                                                <button type="button" onclick="confirmRestore(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>')" class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination for archived products -->
                        <?php if ($total_archived_pages > 1): ?>
                            <div class="mt-6 flex justify-center">
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?tab=archived&page=<?php echo $page - 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_archived_pages; $i++): ?>
                                        <a href="?tab=archived&page=<?php echo $i; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_archived_pages): ?>
                                        <a href="?tab=archived&page=<?php echo $page + 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Restore Confirmation Modal -->
                <div id="restore-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
                    <div class="bg-white rounded-lg max-w-md w-full p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Restore</h3>
                        <p class="text-gray-600 mb-4">Are you sure you want to restore the product "<span id="restore-product-name"></span>"? It will appear in the store again.</p>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeRestoreModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </button>
                            <button type="button" onclick="handleRestore()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Restore
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Archive Confirmation Modal -->
    <div id="archive-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg max-w-md w-full p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Archive</h3>
            <p class="text-gray-600 mb-4">Are you sure you want to archive the product "<span id="archive-product-name"></span>"? Archived products will no longer appear in the store.</p>
        
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeArchiveModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </button>
                 <button type="button" onclick="handleArchive()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Archive
                            </button>
            </div>
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

    <script>
        // Global variables for modal handling
        let currentProductId = null;
        let currentProductName = null;

        // Archive confirmation modal
        function confirmArchive(productId, productName) {
            currentProductId = productId;
            currentProductName = productName;
            document.getElementById('archive-product-name').textContent = productName;
            document.getElementById('archive-modal').classList.remove('hidden');
        }

        function closeArchiveModal() {
            document.getElementById('archive-modal').classList.add('hidden');
            currentProductId = null;
            currentProductName = null;
        }

        // Restore confirmation modal
        function confirmRestore(productId, productName) {
            currentProductId = productId;
            currentProductName = productName;
            document.getElementById('restore-product-name').textContent = productName;
            document.getElementById('restore-modal').classList.remove('hidden');
        }

        function closeRestoreModal() {
            document.getElementById('restore-modal').classList.add('hidden');
            currentProductId = null;
            currentProductName = null;
        }

        // Handle archive action
        function handleArchive() {
            if (!currentProductId) {
                alert('No product selected');
                return;
            }

            fetch('seller-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=archive_product&product_id=${currentProductId}&seller_id=<?php echo $seller['id']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload(); // Reload to reflect changes
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
            
            closeArchiveModal();
        }

        // Handle restore action
        function handleRestore() {
            if (!currentProductId) {
                alert('No product selected');
                return;
            }

            fetch('seller-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=restore_product&product_id=${currentProductId}&seller_id=<?php echo $seller['id']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload(); // Reload to reflect changes
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
            
            closeRestoreModal();
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const archiveModal = document.getElementById('archive-modal');
            if (event.target === archiveModal) {
                closeArchiveModal();
            }
            
            const restoreModal = document.getElementById('restore-modal');
            if (event.target === restoreModal) {
                closeRestoreModal();
            }
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
