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
    $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();
    
    if (!$seller) {
        // Not a seller, redirect to seller registration
        header('Location: seller-centre.php');
        exit();
    }
    
    // Check if seller is approved
    if ($seller['status'] !== 'approved') {
        $error = "Your seller account must be approved before you can manage products.";
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get product categories
try {
    $stmt = $pdo->prepare("SELECT * FROM product_categories ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle product form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
            
            // Delete product image if it exists
            if ($product['image_path'] && file_exists($product['image_path'])) {
                unlink($product['image_path']);
            }
            
            // Delete product from database
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
            $stmt->execute([$product_id, $seller['id']]);
            
            $success = "Product deleted successfully!";
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get seller's products with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ?");
    $stmt->execute([$seller['id']]);
    $total_products = $stmt->fetchColumn();
    
    // Get products for current page
    $stmt = $pdo->prepare("
        SELECT * FROM products 
        WHERE seller_id = ? 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$seller['id'], $limit, $offset]);
    $products = $stmt->fetchAll();
    
    $total_pages = ceil($total_products / $limit);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get product for editing if edit mode is active
$edit_product = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    try {
        $product_id = intval($_GET['edit']);
        
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
        $stmt->execute([$product_id, $seller['id']]);
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
        $query = "SELECT * FROM products WHERE seller_id = ?";
        $params = [$seller['id']];
        
        if (!empty($filter_category)) {
            $query .= " AND category = ?";
            $params[] = $filter_category;
        }
        
        if (!empty($search_term)) {
            $query .= " AND (name LIKE ? OR description LIKE ?)";
            $search_param = "%{$search_term}%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Get total count with filters
        $count_query = str_replace("SELECT *", "SELECT COUNT(*)", $query);
        $count_query = preg_replace("/LIMIT \? OFFSET \?/", "", $count_query);
        array_pop($params); // Remove offset
        array_pop($params); // Remove limit
        
        $stmt = $pdo->prepare($count_query);
        $stmt->execute($params);
        $total_products = $stmt->fetchColumn();
        
        $total_pages = ceil($total_products / $limit);
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - Seller Dashboard - Wastewise</title>
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
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <img src="/assets/images/logo.png" alt="Wastewise Logo" class="h-10 mr-3">
                <span class="text-2xl font-bold text-gray-800">Wastewise</span>
            </div>
            <div>
                <span class="text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" class="text-red-600 hover:text-red-800">
                    <i class="fas fa-sign-out-alt mr-1"></i> Logout
                </a>
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
                            <?php if ($seller['logo_url']): ?>
                                <img src="<?php echo htmlspecialchars($seller['logo_url']); ?>" alt="Business Logo" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <i class="fas fa-store text-gray-400 text-4xl"></i>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($seller['business_name']); ?></h2>
                        <p class="text-gray-600"><?php echo htmlspecialchars($seller['business_type']); ?></p>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="seller-dashboard.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-tachometer-alt w-5 mr-2"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="seller-products.php" class="flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-md">
                            <i class="fas fa-box w-5 mr-2"></i>
                            <span>Products</span>
                        </a>
                        <a href="seller-orders.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-shopping-cart w-5 mr-2"></i>
                            <span>Orders</span>
                        </a>
                        <a href="seller-analytics.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-chart-line w-5 mr-2"></i>
                            <span>Analytics</span>
                        </a>
                        <a href="seller-settings.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-cog w-5 mr-2"></i>
                            <span>Settings</span>
                        </a>
                    </nav>
                </div>
                
                <!-- Product Categories -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Product Categories</h3>
                    <ul class="space-y-2">
                        <li>
                            <a href="seller-products.php" class="text-gray-700 hover:text-green-600 flex items-center <?php echo empty($filter_category) ? 'font-bold text-green-600' : ''; ?>">
                                <i class="fas fa-layer-group mr-2"></i>
                                <span>All Categories</span>
                            </a>
                        </li>
                        <?php foreach ($categories as $category): ?>
                            <li>
                                <a href="?category=<?php echo urlencode($category['name']); ?>" class="text-gray-700 hover:text-green-600 flex items-center <?php echo $filter_category === $category['name'] ? 'font-bold text-green-600' : ''; ?>">
                                    <i class="fas fa-tag mr-2"></i>
                                    <span><?php echo htmlspecialchars($category['name']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-3">
                <!-- Product Management -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">
                            <?php echo $edit_product ? 'Edit Product' : 'Add New Product'; ?>
                        </h2>
                        <?php if ($edit_product): ?>
                            <a href="seller-products.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                <i class="fas fa-plus mr-2"></i> Add New Product
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <form action="seller-products.php" method="POST" enctype="multipart/form-data" class="space-y-4">
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
                            
                            <?php if ($edit_product && $edit_product['image_path']): ?>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-600 mb-1">Current Image:</p>
                                    <img src="<?php echo htmlspecialchars($edit_product['image_path']); ?>" alt="Product Image" class="h-32 object-contain border border-gray-200 rounded-md">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <?php if ($edit_product): ?>
                                <a href="seller-products.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
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
                                <input type="text" name="search" placeholder="Search products..." class="px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($search_term); ?>">
                                <button type="submit" class="px-3 py-2 bg-green-600 text-white rounded-r-md hover:bg-green-700">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                            
                            <?php if (!empty($search_term) || !empty($filter_category)): ?>
                                <a href="seller-products.php" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
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
                                                        <?php if ($product['image_path']): ?>
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
                                                <a href="?edit=<?php echo $product['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button type="button" onclick="confirmDelete(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>')" class="text-red-600 hover:text-red-900">
                                                    <i class="fas fa-trash"></i> Delete
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
                                        <a href="?page=<?php echo $page - 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?page=<?php echo $i; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $page === $i ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page + 1; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
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
        </div>
    </main>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg max-w-md w-full p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Delete</h3>
            <p class="text-gray-600 mb-4">Are you sure you want to delete the product "<span id="delete-product-name"></span>"? This action cannot be undone.</p>
            
            <form action="seller-products.php" method="POST" id="delete-form">
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="product_id" id="delete-product-id">
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-8 mt-12">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <p>&copy; <?php echo date('Y'); ?> Wastewise. All rights reserved.</p>
                </div>
                <div class="flex space-x-4">
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-facebook"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-linkedin"></i></a>
                </div>
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
    <script>
        // Delete confirmation modal
        function confirmDelete(productId, productName) {
            document.getElementById('delete-product-id').value = productId;
            document.getElementById('delete-product-name').textContent = productName;
            document.getElementById('delete-modal').classList.remove('hidden');
        }
        
        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.add('hidden');
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('delete-modal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        });
    </script>
</body>
</html>