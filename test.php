<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Handle AJAX requests for cart operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please log in first.']);
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    
    // Database connection
    $db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');
    
    if ($db->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }
    
    $db->set_charset("utf8");
    
    // Handle product rating submission
    if ($_POST['ajax_action'] === 'submit_rating') {
        $product_id = (int)$_POST['product_id'];
        $rating = (int)$_POST['rating'];
        $comment = trim($_POST['comment']);
        
        // Validate inputs
        if ($product_id <= 0 || $rating < 1 || $rating > 5) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID or rating.']);
            exit();
        }
        
        try {
            // Check if user already rated this product
            $stmt = $db->prepare("SELECT id FROM product_ratings WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing rating
                $stmt->close();
                $stmt = $db->prepare("UPDATE product_ratings SET rating = ?, comment = ?, updated_at = NOW() WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("isii", $rating, $comment, $user_id, $product_id);
            } else {
                // Insert new rating
                $stmt->close();
                $stmt = $db->prepare("INSERT INTO product_ratings (user_id, product_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("iiis", $user_id, $product_id, $rating, $comment);
            }
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Rating submitted successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to submit rating.']);
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        }
        
        $db->close();
        exit();
    }
    
    // Handle get product details
    if ($_POST['ajax_action'] === 'get_product_details') {
        $product_id = (int)$_POST['product_id'];
        
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
            exit();
        }
        
        try {
            // Get product details
            $stmt = $db->prepare("SELECT p.*, ec.name as event_category_name, s.business_name as seller_name 
                                 FROM products p 
                                 LEFT JOIN event_categories ec ON p.event_category_id = ec.id
                                 LEFT JOIN sellers s ON p.seller_id = s.id
                                 WHERE p.id = ? AND (p.archived = 0 OR p.archived IS NULL)");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Product not found.']);
                exit();
            }
            
            $product = $result->fetch_assoc();
            $stmt->close();
            
            // Get product ratings and comments
            $stmt = $db->prepare("SELECT pr.rating, pr.comment, pr.created_at, u.username 
                                 FROM product_ratings pr 
                                 JOIN users u ON pr.user_id = u.id 
                                 WHERE pr.product_id = ? 
                                 ORDER BY pr.created_at DESC");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $ratings_result = $stmt->get_result();
            $ratings = $ratings_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            // Calculate average rating
            $avg_rating = 0;
            $total_ratings = count($ratings);
            if ($total_ratings > 0) {
                $sum = array_sum(array_column($ratings, 'rating'));
                $avg_rating = round($sum / $total_ratings, 1);
            }
            
            $product['average_rating'] = $avg_rating;
            $product['total_ratings'] = $total_ratings;
            $product['ratings'] = $ratings;
            
            echo json_encode(['success' => true, 'product' => $product]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        }
        
        $db->close();
        exit();
    }
    
    if ($_POST['ajax_action'] === 'add_to_cart') {
        $product_id = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        
        // Validate inputs
        if ($product_id <= 0 || $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID or quantity.']);
            exit();
        }
        
        try {
            // Check if product exists and has enough stock
            $stmt = $db->prepare("SELECT stock FROM products WHERE id = ? AND (archived = 0 OR archived IS NULL)");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Product not found or unavailable.']);
                exit();
            }
            
            $product = $result->fetch_assoc();
            if ($product['stock'] < $quantity) {
                echo json_encode(['success' => false, 'message' => 'Not enough stock available.']);
                exit();
            }
            $stmt->close();
            
            // Check if item already exists in cart
            $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing cart item
                $cart_item = $result->fetch_assoc();
                $new_quantity = $cart_item['quantity'] + $quantity;
                
                $stmt->close();
                $stmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("iii", $new_quantity, $user_id, $product_id);
                
                if ($stmt->execute()) {
                    // Get updated cart count
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM cart_items WHERE user_id = ?");
                    $count_stmt->bind_param("i", $user_id);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $cart_count = $count_result->fetch_assoc()['count'];
                    $count_stmt->close();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Cart updated successfully!',
                        'cart_count' => $cart_count
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update cart.']);
                }
            } else {
                // Add new cart item
                $stmt->close();
                $stmt = $db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $user_id, $product_id, $quantity);
                
                if ($stmt->execute()) {
                    // Get updated cart count
                    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM cart_items WHERE user_id = ?");
                    $count_stmt->bind_param("i", $user_id);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $cart_count = $count_result->fetch_assoc()['count'];
                    $count_stmt->close();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Product added to cart successfully!',
                        'cart_count' => $cart_count
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to add product to cart.']);
                }
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        }
        
        $db->close();
        exit();
    }
    
    if ($_POST['ajax_action'] === 'check_cart') {
        $product_id = (int)$_POST['product_id'];
        
        if ($product_id <= 0) {
            echo json_encode(['exists' => false, 'message' => 'Invalid product ID.']);
            exit();
        }
        
        try {
            $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $cart_item = $result->fetch_assoc();
                echo json_encode([
                    'exists' => true, 
                    'quantity' => $cart_item['quantity'],
                    'message' => 'Product already exists in cart.'
                ]);
            } else {
                echo json_encode(['exists' => false, 'message' => 'Product not in cart.']);
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            echo json_encode(['exists' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        }
        
        $db->close();
        exit();
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

// Check DB connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Fetch user's email from the database
$email = '';
$stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $email = $user['email'];
} else {
    $email = 'Email not found';
}

// Updated function to get recent orders grouped by status
function getRecentOrdersGrouped($user_id, $limit = 50) {
  global $db;
  $query = "SELECT o.id, o.total_amount, o.status, o.created_at, 
            GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name, '|||', p.image) SEPARATOR '---') as products
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE o.user_id = ? AND o.archived = 0
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT ?";
  $stmt = $db->prepare($query);
  $stmt->bind_param("ii", $user_id, $limit);
  $stmt->execute();
  $result = $stmt->get_result();
  $orders = $result->fetch_all(MYSQLI_ASSOC);

  $grouped_orders = [
      'all' => $orders,
      'to_pay' => [],
      'to_ship' => [],
      'to_receive' => [],
      'completed' => [],
      'cancelled' => [],
      'return_refund' => []
  ];

  foreach ($orders as $order) {
      switch ($order['status']) {
          case 'pending':
              $grouped_orders['to_pay'][] = $order;
              break;
          case 'processing':
              $grouped_orders['to_ship'][] = $order;
              break;
          case 'shipped':
              $grouped_orders['to_receive'][] = $order;
              break;
          case 'delivered':
              $grouped_orders['completed'][] = $order;
              break;
          case 'cancelled':
              $grouped_orders['cancelled'][] = $order;
              break;
          case 'return_pending':
          case 'return_approved':
          case 'refunded':
              $grouped_orders['return_refund'][] = $order;
              break;
      }
  }

  return $grouped_orders;
}

function getRelatedProducts($product_id, $limit = 4) {
  global $db;
  $stmt = $db->prepare("SELECT p.* FROM products p 
                        JOIN products current ON p.category = current.category 
                        WHERE current.id = ? AND p.id != ? 
                        ORDER BY RAND() LIMIT ?");
  $stmt->bind_param("iii", $product_id, $product_id, $limit);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_all(MYSQLI_ASSOC);
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$all_products = getProducts($search, $category);

$all_products_display = $all_products;

$grouped_orders = getRecentOrdersGrouped($_SESSION['user_id']);

$categories = ['Paper', 'Plastic', 'Metal', 'Glass', 'Electronics', 'Textiles'];
$event_categories = getEventCategories();

// Function to get all products
function getProducts($search = '', $category = '') {
    global $db;
    $query = "SELECT DISTINCT p.*, ec.name as event_category_name, s.business_name as seller_name 
              FROM products p 
              LEFT JOIN event_categories ec ON p.event_category_id = ec.id
              LEFT JOIN sellers s ON p.seller_id = s.id
              WHERE (p.archived = 0 OR p.archived IS NULL)";
    if (!empty($search)) {
        $search = $db->real_escape_string($search);
        $query .= " AND (p.name LIKE '%$search%' OR p.description LIKE '%$search%')";
    }
    if (!empty($category)) {
        $category = $db->real_escape_string($category);
        $query .= " AND p.category = '$category'";
    }
    $query .= " GROUP BY p.id ORDER BY p.created_at DESC";
    $result = $db->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to get all event categories
function getEventCategories() {
global $db;
$query = "SELECT * FROM event_categories ORDER BY name ASC";
$result = $db->query($query);
return $result->fetch_all(MYSQLI_ASSOC);
}

// Get the cart count for the logged-in user
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    // Query to count the items in the user's cart
    $cart_query = "SELECT COUNT(*) AS cart_count FROM cart_items WHERE user_id = ?";
    $cart_stmt = $db->prepare($cart_query);
    $cart_stmt->bind_param("i", $user_id);
    $cart_stmt->execute();
    $cart_result = $cart_stmt->get_result();
    $cart_count = $cart_result->fetch_assoc()['cart_count'];
}

// </CHANGE> Get featured products from the featured_products table instead of just slicing all products
require_once 'get_featured_products.php';
$featured_products = getFeaturedProducts($db);

// If no featured products exist, fall back to first 8 products
if (empty($featured_products) && !empty($all_products_display)) {
    $featured_products = array_slice($all_products_display, 0, 8);
}

// Placeholder for regular products if needed, currently using all_products_display
$regular_products = $all_products_display;

// --- [ Other variables like $name, $email, $phone needed for modals should also be defined ] ---
$name = isset($_SESSION['user_fullname']) ? $_SESSION['user_fullname'] : 'User Name'; // Example
$email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'user@example.com'; // Example
$phone = isset($_SESSION['user_phone']) ? $_SESSION['user_phone'] : 'N/A'; // Example

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
  <title>Homepage</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="icon" type="image/png" href="logo.png">

<style>
    /* --- START OF CORRECTED STYLE BLOCK --- */
    .overflow-x-auto {
        overflow-x: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
    }
    .overflow-x-auto::-webkit-scrollbar { height: 6px; }
    .overflow-x-auto::-webkit-scrollbar-track { background: transparent; }
    .overflow-x-auto::-webkit-scrollbar-thumb { background-color: rgba(156, 163, 175, 0.5); border-radius: 3px; }

    /* Global Styles */
    html, body {
        overflow-x: hidden; /* Prevent horizontal scroll globally */
        height: 100%;
        margin: 0;
        padding: 0 !important;
    }

    /* Make images responsive */
    img { max-width: 100%; height: auto; }

    /* Profile Pic (If used anywhere else) */
    .profile-pic { width: 50px; height: 50px; border-radius: 50%; background-color: gray; display: flex; align-items: center; justify-content: center; }

    /* Tooltip styles for nav icons */
    a[data-title] { position: relative; }
    a[data-title]::after {
        content: attr(data-title); position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 6px;
        background-color: #333; color: #fff; padding: 5px 8px; border-radius: 4px; white-space: nowrap; font-size: 12px;
        opacity: 0; pointer-events: none; transition: opacity 0.2s ease-in-out; z-index: 101;
    }
    a[data-title]:hover::after { opacity: 1; }

    /* Cart Number Styling */
    .cart-number {
        position: absolute; bottom: 10px; left: 10px; background-color: red; color: white; border-radius: 50%;
        font-size: 10px; width: 18px; height: 18px; display: flex; justify-content: center; align-items: center; line-height: 1;
    }

    /* --- BODY PADDING FOR STICKY HEADER --- */
    body {
        /* Set padding based on the sticky header's height (h-16 = 4rem) */
        /* This applies to desktop primarily */
        padding-top: 4rem;
    }

    /* Main Content Area */
    main { flex: 1; overflow-y: auto; margin-left: 0; padding: 0; transition: margin-left 0.3s ease-in-out; }

    /* Footer */
    footer { background-color: #2f855a; color: white; text-align: center; padding: 1rem 0; z-index: 30; margin-top: auto; }

    /* Adjusted grid for product cards (Tailwind breakpoints) */
    @media (min-width: 640px) { .sm\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 1024px) { .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 1280px) { .xl\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

    /* Specific styles for product card badges */
    .badge-hot { background-color: #ec4899; }
    .badge-sale { background-color: #3b82f6; }
    .badge-new { background-color: #22c55e; }

    /* Product Detail Section Styles (Modal) */
    .product-detail-section { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.8); z-index: 1000; overflow-y: auto; }
    .product-detail-content { background-color: white; margin: 2% auto; padding: 0; width: 95%; max-width: 1200px; border-radius: 10px; position: relative; }
    .close-product-detail { position: absolute; top: 15px; right: 20px; font-size: 28px; font-weight: bold; color: #666; cursor: pointer; z-index: 1001; }
    .close-product-detail:hover { color: #000; }
    .product-images-container { position: relative; }
    .product-main-image { width: 100%; height: 400px; object-fit: cover; border-radius: 10px 0 0 0; }
    .product-thumbnails { display: flex; gap: 10px; padding: 10px; overflow-x: auto; }
    .product-thumbnail { width: 80px; height: 80px; object-fit: cover; border-radius: 5px; cursor: pointer; border: 2px solid transparent; transition: border-color 0.3s; }
    .product-thumbnail:hover, .product-thumbnail.active { border-color: #2f855a; }
    .rating-stars { display: flex; gap: 2px; margin-bottom: 10px; }
    .rating-star { font-size: 20px; color: #ddd; cursor: pointer; transition: color 0.2s; }
    .rating-star.active, .rating-star:hover { color: #ffd700; }
    .comment-item { border-bottom: 1px solid #eee; padding: 15px 0; }
    .comment-item:last-child { border-bottom: none; }
    .size-option, .color-option { padding: 8px 16px; border: 2px solid #ddd; border-radius: 5px; cursor: pointer; transition: all 0.3s; margin: 5px; display: inline-block; }
    .size-option:hover, .color-option:hover { border-color: #2f855a; }
    .size-option.selected, .color-option.selected { border-color: #2f855a; background-color: #2f855a; color: white; }

    /* Dropdown Animation Styles - PillowMart Style */
    .dropdown .dropdown-menu {
      transition: all 0.3s ease;
      overflow: hidden;
      transform-origin: top center;
      transform: scale(1, 0);
      display: block;
      border: 0px solid transparent;
      background-color: #fff;
      opacity: 0;
      visibility: hidden;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      border-radius: 8px;
    }

    .dropdown .dropdown-menu .dropdown-item {
      font-size: 14px;
      padding: 8px 20px !important;
      color: #374151 !important;
      background-color: transparent;
      text-transform: capitalize;
      transition: all 0.3s ease;
      border-radius: 4px;
      margin: 2px 8px;
    }

    .dropdown .dropdown-menu .dropdown-item:hover {
      background-color: #f3f4f6;
      color: #059669 !important;
    }

    @media (min-width: 768px) {
      .dropdown:hover .dropdown-menu {
        transform: scale(1);
        opacity: 1;
        visibility: visible;
      }
    }

    @media (max-width: 767px) {
      .dropdown .dropdown-menu {
        transform: scale(1, 0);
        display: none;
        margin-top: 10px;
      }
      
      .dropdown:hover .show {
        transform: scale(1);
        display: block;
      }
    }

    /* --- MOBILE STYLES --- */
    @media (max-width: 767px) { /* Tailwind 'md' breakpoint is 768px, use 767px */
        body {
            /* Adjust padding for the taller mobile header (h-16 + mobile nav bar height) */
            /* Estimate: h-16 (4rem) + py-2 (0.5rem * 2) + border (1px approx 0.06rem) ~= 5rem */
            /* Add a little extra space: 5.5rem seems reasonable, adjust if needed */
            padding-top: 5.5rem; /* ADJUST AS NEEDED */
        }

        /* Adjustments for mobile product detail modal */
        .product-detail-content { width: 100%; margin: 0; border-radius: 0; max-height: 100vh; }
        .product-detail-content .grid { grid-template-columns: 1fr !important; padding: 1rem; }
        .product-main-image { height: 250px; border-radius: 0; }
        .product-thumbnail { width: 60px; height: 60px; }
        #productDetailTitle { font-size: 1.25rem; }
        #productDetailPrice { font-size: 1.5rem; }
        .close-product-detail { font-size: 24px; top: 10px; right: 10px; }

        /* Other Mobile adjustments */
        #feature-section { padding: 2rem 1rem; }
        #feature-section h1 { font-size: 1.75rem; }
        main.container { padding: 1rem 0.5rem; }
        section .grid { grid-template-columns: repeat(2, 1fr) !important; gap: 0.75rem; } /* Product grid */
        section .grid img { height: 150px; }
        section h2 { font-size: 1.5rem; }
        footer .grid { grid-template-columns: 1fr; }
        footer { padding: 1.5rem 1rem; }
        nav.flex { overflow-x: auto; } /* For order tabs if used */
        #notifications .grid { grid-template-columns: 1fr !important; } /* Order cards */
        .cart-number { top: 2px; right: 2px; width: 16px; height: 16px; font-size: 9px;} /* Mobile cart badge position */
    }

    @media (max-width: 480px) {
        section .grid { grid-template-columns: 1fr !important; } /* Single column products */
        section .grid img { height: 200px; }
    }

    /* Touch target adjustments */
    @media (hover: none) and (pointer: coarse) {
        /* Ensure buttons/links in header are easily tappable */
        header nav a, header button {
           min-height: 44px;
           min-width: 44px;
           /* Use inline-flex to allow centering icons inside */
           display: inline-flex;
           align-items: center;
           justify-content: center;
        }
    }
    /* --- END OF CORRECTED STYLE BLOCK --- */
</style>
</head>
<body class="bg-gray-100 min-h-screen" onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">

<div id="preloader-modal" class="fixed inset-0 bg-white bg-opacity-90 flex items-center justify-center z-50">
  <div id="preloader-cart" class="text-green-600 text-6xl animate-spin">
    <i class="fas fa-shopping-cart"></i>
  </div>
  <div id="preloader-check" class="hidden text-green-600 text-6xl">
    <i class="fas fa-check-circle"></i>
  </div>
</div>

<header class="bg-green-700 text-white shadow-md sticky top-0 z-50">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-20 items-center justify-between gap-4">
      <div class="flex items-center gap-2">
        <a class="block" href="home.php">
          <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
        </a>
        <h1 class="text-xl font-bold hidden sm:block">Wastewise</h1>
      </div>

      <ul class="flex items-center gap-12 text-2xl">
        <li>
          <a href="home.php" class="hover:text-gray-300 transition"><i class="fas fa-home"></i></a>
        </li>
        <li>
          <a href="community_impact.php" class="hover:text-gray-300 transition"><i class="fas fa-leaf"></i></a>
        </li>
        <li>
          <a href="my_orders.php" class="hover:text-gray-300 transition"><i class="fas fa-shopping-bag"></i></a>
        </li>
        <li>
          <a href="cart.php" class="relative hover:text-gray-300 transition">
            <i class="fas fa-shopping-cart"></i>
            <?php if ($cart_count > 0): ?>
              <span class="cart-number"><?php echo $cart_count; ?></span>
            <?php endif; ?>
          </a>
        </li>
        <li>
          <a href="seller-search.php" class="hover:text-gray-300 transition"><i class="fas fa-store"></i></a>
        </li>
      </ul>

      <div class="flex items-center gap-3">
  
        <div class="relative group dropdown">
          <button class="flex items-center px-3 py-2 rounded-md text-gray-800 text-sm bg-white hover:bg-green-100 transition focus:outline-none font-medium">
        
            <span><?= !empty($category) ? htmlspecialchars($category) : 'Category' ?></span>
        
            <svg class="w-4 h-4 ml-2 transition-transform group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path d="M19 9l-7 7-7-7"></path>
            </svg>
          </button>
          
          <div class="dropdown-menu absolute right-0 w-56 py-2 z-50" style="min-width: 180px;">

            <a href="test.php" class="dropdown-item block px-4 py-2 hover:bg-green-100 transition-colors">
              All Categories
            </a>
        
            <?php foreach ($categories as $cat): ?>
              <a href="?category=<?= urlencode($cat) ?>" class="dropdown-item block px-4 py-2 hover:bg-green-100 transition-colors">
                <?= htmlspecialchars($cat) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <button id="openSearch" class="flex items-center px-2 py-2 rounded hover:bg-green-800 transition">
          <i class="fas fa-search text-xl"></i>
        </button>
        
        <div class="relative">
          <button onclick="toggleSidebar()" class="flex items-center justify-center rounded-md p-2 hover:bg-green-600">
            <i class="fas fa-cog text-xl"></i>
          </button>
        </div>
      </div>
    </div>

    <div id="searchBar" class="fixed left-0 right-0 top-20 mx-auto w-full max-w-2xl z-50 hidden">
      <form action="" method="GET" class="flex items-center bg-green-200 rounded shadow-lg px-4 py-4 mx-auto">
        <input type="text" name="search" placeholder="Search Here" class="flex-grow px-4 py-2 rounded-l focus:outline-none text-lg text-gray-800">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-r hover:bg-green-700 transition"><i class="fas fa-search"></i></button>
        <button type="button" class="pl-4 text-green-700 text-2xl font-bold" onclick="closeSearchBar()">&times;</button>
      </form>
    </div>
  </div>
</header>

<div id="settingsSidebar" class="fixed top-0 right-0 w-64 h-full bg-white shadow-lg transform translate-x-full transition-transform duration-300 ease-in-out z-50">
  <div class="flex justify-between items-center p-4 border-b">
    <h2 class="text-lg font-semibold text-green-700">Settings</h2>
    <button onclick="toggleSidebar()" class="text-gray-600 hover:text-gray-900 text-xl font-bold">&times;</button>
  </div>

  <div class="p-4 space-y-3">
    <button onclick="openProfileModal(event)" class="w-full flex items-center text-gray-700 hover:bg-gray-100 px-3 py-2 rounded">
      <i class="fas fa-user-circle mr-3 text-green-600"></i> Profile
    </button>

    <button onclick="openLogoutModal()" class="w-full flex items-center text-red-600 hover:bg-red-100 px-3 py-2 rounded">
      <i class="fas fa-sign-out-alt mr-3"></i> Logout
    </button>
  </div>
</div>

<div id="profileModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-8 relative">
    <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-600 hover:text-gray-900 text-xl font-bold">&times;</button>

    <h2 class="text-2xl font-bold text-gray-800 mb-6">My Profile</h2>
    <p class="text-sm text-gray-500 mb-4">Manage and protect your account</p>

    <form class="space-y-5">
      <div>
        <label class="block text-gray-700 font-semibold mb-1">Username</label>
        <input type="text" value="<?= htmlspecialchars($_SESSION['username']); ?>" class="w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-green-500" readonly>
        <p class="text-xs text-gray-500 mt-1">Username can only be changed once.</p>
      </div>

      <div>
        <label class="block text-gray-700 font-semibold mb-1">Fullname</label>
        <input type="text" value="<?= htmlspecialchars($name); ?>" class="w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-green-500">
      </div>

      <div>
        <label class="block text-gray-700 font-semibold mb-1">Email</label>
        <div class="flex items-center gap-2">
          <input type="email" value="<?= htmlspecialchars($email); ?>" class="w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-green-500" readonly>
          <a href="#" class="text-green-600 text-sm hover:underline">Change</a>
        </div>
      </div>

      <div>
        <label class="block text-gray-700 font-semibold mb-1">Phone Number</label>
        <div class="flex items-center gap-2">
          <input type="text" value="<?= htmlspecialchars($phone); ?>" class="w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-green-500" readonly>
          <a href="#" class="text-green-600 text-sm hover:underline">Change</a>
        </div>
      </div>

      <div>
        <label class="block text-gray-700 font-semibold mb-1">Gender</label>
        <div class="flex items-center gap-6">
          <label class="flex items-center gap-1">
            <input type="radio" name="gender" value="Male" checked>
            <span>Male</span>
          </label>
          <label class="flex items-center gap-1">
            <input type="radio" name="gender" value="Female">
            <span>Female</span>
          </label>
          <label class="flex items-center gap-1">
            <input type="radio" name="gender" value="Other">
            <span>Other</span>
          </label>
        </div>
      </div>

      <div class="pt-4">
        <button type="submit" class="bg-green-600 text-white font-semibold py-2 px-6 rounded hover:bg-green-700 transition-colors">Save</button>
      </div>
    </form>
  </div>
</div>

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

<div id="productDetailSection" class="product-detail-section">
    <div class="product-detail-content">
        <span class="close-product-detail" onclick="closeProductDetail()">&times;</span>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 p-6">
          <div class="product-images-container">
                <img id="productMainImage" src="/placeholder.svg" alt="Product Image" class="product-main-image">
                <div class="product-thumbnails" id="productThumbnails">
                </div>
            </div>
            
             <div class="product-info-container">
                <div class="mb-4">
                    <span id="productBadge" class="inline-block px-3 py-1 text-xs font-semibold text-white rounded-full mb-2"></span>
                    <h1 id="productDetailTitle" class="text-3xl font-bold text-gray-900 mb-2"></h1>
                    
                     <!-- Rating Display -->
                    <div class="flex items-center mb-4">
                        <div class="flex items-center" id="productRatingStars">
                             <!-- Stars will be populated by JavaScript -->
                        </div>
                        <span id="productRatingText" class="ml-2 text-sm text-gray-600"></span>
                    </div>
                    
                    <p id="productSellerName" class="text-gray-600 mb-4"></p>
                </div>
                
               <div class="mb-6">
                    <span id="productDetailPrice" class="text-3xl font-bold text-green-600"></span>
                    <span id="productOriginalPrice" class="text-lg text-gray-400 line-through ml-2"></span>
                </div>
                
               <div class="mb-6">
                     <!-- Color Selection -->
                    <div class="mb-4" id="colorSelection" style="display: none;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                        <div id="colorOptions" class="flex flex-wrap gap-2">
                             <!-- Color options will be populated by JavaScript -->
                        </div>
                    </div>
                    
                  <div class="mb-4" id="sizeSelection" style="display: none;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Size</label>
                        <div id="sizeOptions" class="flex flex-wrap gap-2">
                             <!-- Size options will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantity</label>
                        <div class="flex items-center">
                            <button onclick="decreaseQuantity()" class="bg-gray-200 text-gray-700 px-3 py-1 rounded-l-lg hover:bg-gray-300">-</button>
                            <input type="number" id="productDetailQuantity" value="1" min="1" class="w-20 text-center border-t border-b border-gray-200 py-1">
                            <button onclick="increaseQuantity()" class="bg-gray-200 text-gray-700 px-3 py-1 rounded-r-lg hover:bg-gray-300">+</button>
                        </div>
                        <p id="stockInfo" class="text-sm text-gray-500 mt-1"></p>
                    </div>
                </div>
                
              <div class="flex gap-4 mb-6">
                    <button id="addToCartDetailBtn" onclick="addToCartFromDetail()" class="flex-1 bg-green-600 text-white py-3 px-6 rounded-lg hover:bg-green-700 transition duration-300">
                        <i class="fas fa-shopping-cart mr-2"></i> Add to Cart
                    </button>
                    <button id="buyNowDetailBtn" onclick="buyNowFromDetail()" class="flex-1 bg-blue-600 text-white py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-300">
                        Buy Now
                    </button>
                </div>
                
               <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-2">Description</h3>
                    <p id="productDetailDescription" class="text-gray-700"></p>
                </div>
            </div>
        </div>
        
       <div class="border-t border-gray-200 p-6">
            <h3 class="text-xl font-semibold mb-4">Reviews & Ratings</h3>
            
           <div class="bg-gray-50 p-4 rounded-lg mb-6">
                <h4 class="font-semibold mb-3">Add Your Review</h4>
                <form id="reviewForm" onsubmit="submitReview(event)">
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
                        <div class="rating-stars" id="ratingStars">
                            <span class="rating-star" data-rating="1">★</span>
                            <span class="rating-star" data-rating="2">★</span>
                            <span class="rating-star" data-rating="3">★</span>
                            <span class="rating-star" data-rating="4">★</span>
                            <span class="rating-star" data-rating="5">★</span>
                        </div>
                        <input type="hidden" id="selectedRating" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Comment</label>
                        <textarea id="reviewComment" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Share your experience with this product..."></textarea>
                    </div>
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition duration-300">
                        Submit Review
                    </button>
                </form>
            </div>
            
            <div id="reviewsList">
              </div>
        </div>
    </div>
</div>

 <main>
<?php for ($i = 0; $i < 50; $i++): ?>
              <div class="snow" style="left: <?= rand(0, 100); ?>vw; animation: snowfall <?= rand(5, 15); ?>s linear infinite;"></div>
          <?php endfor; ?>
      <div id="feature-section" class="bg-gradient-to-r from-green-600 to-emerald-600 text-white py-16 md:py-24 relative overflow-hidden">
    <div class="absolute top-0 right-0 w-96 h-96 bg-green-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -mr-32 -mt-32"></div>
    <div class="absolute bottom-0 left-0 w-96 h-96 bg-emerald-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -ml-32 -mb-32"></div>
    
    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-2xl">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold mb-4 leading-tight">
                Shop Sustainable, Live Better
            </h1>
            <p class="text-lg md:text-xl mb-8 text-green-50">
                Discover eco-friendly products from trusted sellers. Every purchase supports a greener future.
            </p>
            <div class="flex flex-col sm:flex-row gap-4">
                <a href="#featured-products" class="inline-block bg-white text-green-600 font-bold py-3 px-8 rounded-full hover:bg-green-50 transition duration-300 shadow-lg text-center">
                    <i class="fas fa-leaf mr-2"></i> Explore Products
                </a>
                <a href="seller-search.php" class="inline-block border-2 border-white text-white font-bold py-3 px-8 rounded-full hover:bg-white hover:text-green-600 transition duration-300 text-center">
                    <i class="fas fa-store mr-2"></i> Find Sellers
                </a>
            </div>
        </div>
    </div>
</div>

<section class="container mx-auto px-4 py-8 mb-8">
  <div class="bg-gradient-to-r from-blue-50 to-green-50 rounded-lg shadow-md p-6 md:p-8 text-center border border-green-200">
    <h2 class="text-2xl md:text-3xl font-bold text-green-800 mb-4">
      <i class="fas fa-handshake mr-2"></i> Connect with Eco-Friendly Sellers
    </h2>
    <p class="text-gray-700 mb-6 max-w-2xl mx-auto">
      Support sustainable businesses and discover unique eco-friendly products from verified sellers committed to environmental responsibility.
    </p>
    <a href="seller-search.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded-full transition duration-300 shadow-md">
      <i class="fas fa-search mr-2"></i> Discover Sellers
    </a>
  </div>
</section>

<main class="container mx-auto px-4 py-12">
    <section id="notifications" class="mb-16 hidden">
              <h2 class="text-3xl font-bold mb-8 text-center text-green-800">Your Orders</h2>

             <div class="mb-8 overflow-x-auto">
                  <nav class="flex border-b border-gray-200" aria-label="Order status">
                      <?php
                      $tabs = [
                          'all' => 'All',
                          'to_pay' => 'To Pay',
                          'to_ship' => 'To Ship',
                          'to_receive' => 'To Receive',
                          'completed' => 'Completed',
                          'cancelled' => 'Cancelled',
                          'return_refund' => 'Return Refund'
                      ];
                      ?>
                      <?php foreach ($tabs as $key => $label): ?>
                          <button onclick="switchTab('<?= $key ?>')" 
                                  class="tab-button flex-shrink-0 py-4 px-6 border-b-2 font-medium text-sm whitespace-nowrap
                                         <?= $key === 'all' ? 'border-green-500 text-green-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>"
                                  data-tab="<?= $key ?>">
                              <?= $label ?>
                              <?php if (!empty($grouped_orders[$key])): ?>
                                  <span class="ml-2 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-xs">
                                      <?= count($grouped_orders[$key]) ?>
                                  </span>
                              <?php endif; ?>
                          </button>
                      <?php endforeach; ?>
                  </nav>
              </div>

              <?php foreach ($tabs as $key => $label): ?>
                  <div id="<?= $key ?>-orders" class="order-section <?= $key === 'all' ? 'block' : 'hidden' ?>">
                      <?php if (empty($grouped_orders[$key])): ?>
                          <p class="text-center text-gray-600">No orders found in this section.</p>
                      <?php else: ?>
                          <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                              <?php foreach ($grouped_orders[$key] as $order): 
                                  $products = explode('---', $order['products']);
                              ?>
                                  <div class="bg-white rounded-lg shadow-md p-6">
                                      <div class="flex justify-between items-start mb-4">
                                          <h4 class="text-xl font-semibold">Order #<?= $order['id'] ?></h4>
                                          <span class="inline-block px-3 py-1 text-sm font-semibold rounded-full
                                              <?php
                                              switch($order['status']) {
                                                  case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                  case 'processing': echo 'bg-blue-100 text-blue-800'; break;
                                                  case 'shipped': echo 'bg-purple-100 text-purple-800'; break;
                                                  case 'delivered': echo 'bg-green-100 text-green-800'; break;
                                                  case 'cancelled': echo 'bg-red-100 text-red-800'; break;
                                                  case 'return_pending':
                                                  case 'return_approved':
                                                  case 'refunded':
                                                      echo 'bg-orange-100 text-orange-800'; break;
                                                  default: echo 'bg-gray-100 text-gray-800';
                                              }
                                              ?>">
                                              <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                                          </span>
                                      </div>
                                      <p class="text-gray-600 mb-2">Date: <?= date('M d, Y H:i', strtotime($order['created_at'])) ?></p>
                                      <p class="text-gray-600 mb-4">Total: ₱<?= number_format($order['total_amount'], 2) ?></p>
                                      <div class="mb-4">
                                          <h5 class="font-semibold mb-2">Products:</h5>
                                          <div class="grid grid-cols-2 gap-2">
                                              <?php 
                                              $displayedProducts = array_slice($products, 0, 4);
                                              foreach ($displayedProducts as $product):
                                                  list($productInfo, $productImage) = explode('|||', $product);
                                              ?>
                                                  <div class="flex items-center space-x-2">
                                                      <img src="<?= htmlspecialchars($productImage) ?>" alt="Product" class="w-10 h-10 object-cover rounded">
                                                      <span class="text-sm"><?= htmlspecialchars($productInfo) ?></span>
                                                  </div>
                                              <?php endforeach; ?>
                                          </div>
                                          <?php if (count($products) > 4): ?>
                                              <p class="text-sm text-gray-500 mt-2">and <?= count($products) - 4 ?> more item(s)</p>
                                          <?php endif; ?>
                                      </div>
                                      <div class="flex justify-between items-center">
                                          <a href="order_details.php?id=<?= $order['id'] ?>" class="text-blue-600 hover:text-blue-800">View Details</a>
                                          <?php if ($order['status'] == 'processing' || $order['status'] == 'shipped'): ?>
                                              <button onclick="cancelOrder(<?= $order['id'] ?>)" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Cancel Order</button>
                                          <?php elseif ($order['status'] == 'delivered'): ?>
                                              <button onclick="requestReturn(<?= $order['id'] ?>)" class="bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600">Request Return</button>
                                          <?php elseif ($order['status'] == 'cancelled'): ?>
                                              <button onclick="buyAgain(<?= $order['id'] ?>)" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">Buy Again</button>
                                          <?php endif; ?>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      <?php endif; ?>
                  </div>
              <?php endforeach; ?>
          </section>

          <section id="featured-products" class="mb-16">
        <div class="mb-8">
            <h2 class="text-3xl md:text-4xl font-bold text-green-800 mb-2">Featured Products</h2>
            <p class="text-gray-600">Handpicked sustainable products for conscious consumers</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
            <?php if (empty($featured_products)): ?>
                <p class="col-span-full text-center text-gray-600">No featured products available at the moment.</p>
            <?php else: ?>
                <?php foreach ($featured_products as $product): 
                    $status = null;
                    if ($product['id'] % 3 === 1) {
                        $status = 'Hot';
                    } elseif ($product['id'] % 3 === 2) {
                        $status = 'Sale';
                    } else {
                        $status = 'New';
                    }
                ?>
                    <div class="bg-white rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow duration-300 relative cursor-pointer" onclick="showProductDetail(<?= $product['id'] ?>)">
                        <?php if ($status): ?>
                            <div class="absolute top-0 left-0 px-3 py-1 text-xs font-semibold text-white rounded-br-lg
                                <?php 
                                    if ($status === 'Hot') echo 'badge-hot';
                                    else if ($status === 'Sale') echo 'badge-sale';
                                    else if ($status === 'New') echo 'badge-new';
                                ?>
                            ">
                                <?= htmlspecialchars($status) ?>
                            </div>
                        <?php endif; ?>
                        <img src="<?= !empty($product['image_path']) ? htmlspecialchars($product['image_path']) : (!empty($product['image']) ? htmlspecialchars($product['image']) : '/placeholder.svg?height=300&width=300'); ?>" 
                             alt="<?= htmlspecialchars($product['name']); ?>" 
                             class="w-full h-48 object-cover">
                        <div class="p-4">
                            <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars($product['category']); ?></p>
                            <h3 class="text-lg font-semibold mb-2 line-clamp-2"><?= htmlspecialchars($product['name']); ?></h3>
                            <div class="flex items-center mb-2">
                                <div class="flex items-center gap-0.5">
                                    <?php 
                                    $rating = isset($product['rating']) ? $product['rating'] : 4.0;
                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= floor($rating) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-sm text-gray-600 ml-2">(<?= number_format($rating, 1) ?>)</span>
                            </div>
                            <p class="text-sm text-gray-600 mb-2">
                                <?php if (!empty($product['seller_name'])): ?>
                                    By <?= htmlspecialchars($product['seller_name']); ?>
                                <?php endif; ?>
                            </p>
                            <div class="flex items-baseline gap-2 mb-4">
                                <span class="text-xl font-bold text-green-600">₱<?= number_format($product['price'], 2); ?></span>
                                <?php 
                                $originalPrice = isset($product['original_price']) ? $product['original_price'] : $product['price'] * 1.15; 
                                if ($originalPrice > $product['price']): ?>
                                    <span class="text-sm text-gray-400 line-through">₱<?= number_format($originalPrice, 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500 mb-2">
                                <?php if ($product['stock'] > 0): ?>
                                    In stock: <?= $product['stock'] ?> available
                                <?php else: ?>
                                    <span class="text-red-500 font-semibold">Out of Stock</span>
                                <?php endif; ?>
                            </p>
                            <div class="flex justify-between items-center mt-2 gap-2" onclick="event.stopPropagation()">
                                <?php if ($product['stock'] > 0): ?>
                                    <button onclick="addToCart(<?= $product['id']; ?>, '<?= htmlspecialchars($product['name']); ?>', <?= $product['price']; ?>, <?= $product['stock']; ?>)" class="flex-1 bg-green-500 text-white p-2 rounded-full hover:bg-green-600 transition duration-300 flex items-center justify-center gap-1">
                                        <i class="fas fa-shopping-cart"></i> Add
                                    </button>
                                    <button onclick="buyProduct(<?= $product['id']; ?>, '<?= htmlspecialchars($product['name']); ?>', <?= $product['price']; ?>, <?= $product['stock']; ?>)" class="flex-1 border border-blue-500 text-blue-500 px-4 py-2 rounded-full hover:bg-blue-50 hover:text-blue-600 transition duration-300">
                                        Buy Now
                                    </button>
                                <?php else: ?>
                                    <button disabled class="flex-1 bg-gray-400 text-white px-4 py-2 rounded-full cursor-not-allowed">
                                        Sold Out
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section id="all-products" class="mb-16">
        <div class="mb-8">
            <h2 class="text-3xl md:text-4xl font-bold text-green-800 mb-2">All Products</h2>
            <p class="text-gray-600">Browse our complete collection of sustainable products</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
            <?php if (empty($regular_products)): ?>
                <p class="col-span-full text-center text-gray-600">No products found.</p>
            <?php else: ?>
                <?php foreach ($regular_products as $product): 
                    $status = null;
                    if ($product['id'] % 3 === 1) {
                        $status = 'Hot';
                    } elseif ($product['id'] % 3 === 2) {
                        $status = 'Sale';
                    } else {
                        $status = 'New';
                    }
                ?>
                    <div class="bg-white rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow duration-300 relative cursor-pointer" onclick="showProductDetail(<?= $product['id'] ?>)">
                        <?php if ($status): ?>
                            <div class="absolute top-0 left-0 px-3 py-1 text-xs font-semibold text-white rounded-br-lg
                                <?php 
                                    if ($status === 'Hot') echo 'badge-hot';
                                    else if ($status === 'Sale') echo 'badge-sale';
                                    else if ($status === 'New') echo 'badge-new';
                                ?>
                            ">
                                <?= htmlspecialchars($status) ?>
                            </div>
                        <?php endif; ?>
                        <img src="<?= !empty($product['image_path']) ? htmlspecialchars($product['image_path']) : (!empty($product['image']) ? htmlspecialchars($product['image']) : '/placeholder.svg?height=300&width=300'); ?>" 
                             alt="<?= htmlspecialchars($product['name']); ?>" 
                             class="w-full h-48 object-cover">
                        <div class="p-4">
                            <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars($product['category']); ?></p>
                            <h3 class="text-lg font-semibold mb-2 line-clamp-2"><?= htmlspecialchars($product['name']); ?></h3>
                            <div class="flex items-center mb-2">
                                <div class="flex items-center gap-0.5">
                                    <?php 
                                    $rating = isset($product['rating']) ? $product['rating'] : 4.0;
                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= floor($rating) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-sm text-gray-600 ml-2">(<?= number_format($rating, 1) ?>)</span>
                            </div>
                            <p class="text-sm text-gray-600 mb-2">
                                <?php if (!empty($product['seller_name'])): ?>
                                    By <?= htmlspecialchars($product['seller_name']); ?>
                                <?php endif; ?>
                            </p>
                            <div class="flex items-baseline gap-2 mb-4">
                                <span class="text-xl font-bold text-green-600">₱<?= number_format($product['price'], 2); ?></span>
                                <?php 
                                $originalPrice = isset($product['original_price']) ? $product['original_price'] : $product['price'] * 1.15; 
                                if ($originalPrice > $product['price']): ?>
                                    <span class="text-sm text-gray-400 line-through">₱<?= number_format($originalPrice, 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500 mb-2">
                                <?php if ($product['stock'] > 0): ?>
                                    In stock: <?= $product['stock'] ?> available
                                <?php else: ?>
                                    <span class="text-red-500 font-semibold">Out of Stock</span>
                                <?php endif; ?>
                            </p>
                            <div class="flex justify-between items-center mt-2 gap-2" onclick="event.stopPropagation()">
                                <?php if ($product['stock'] > 0): ?>
                                    <button onclick="addToCart(<?= $product['id']; ?>, '<?= htmlspecialchars($product['name']); ?>', <?= $product['price']; ?>, <?= $product['stock']; ?>)" class="flex-1 bg-green-500 text-white p-2 rounded-full hover:bg-green-600 transition duration-300 flex items-center justify-center gap-1">
                                        <i class="fas fa-shopping-cart"></i> Add
                                    </button>
                                    <button onclick="buyProduct(<?= $product['id']; ?>, '<?= htmlspecialchars($product['name']); ?>', <?= $product['price']; ?>, <?= $product['stock']; ?>)" class="flex-1 border border-blue-500 text-blue-500 px-4 py-2 rounded-full hover:bg-blue-50 hover:text-blue-600 transition duration-300">
                                        Buy Now
                                    </button>
                                <?php else: ?>
                                    <button disabled class="flex-1 bg-gray-400 text-white px-4 py-2 rounded-full cursor-not-allowed">
                                        Sold Out
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

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
</div>

<div id="quantity-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden flex items-center justify-center z-50">
   <div class="relative p-5 border w-96 shadow-lg rounded-md bg-white">

        <div class="mt-3 text-center">
            <button id="close-modal" class="absolute top-2 right-2 text-gray-600 hover:text-gray-900">
                <i class="fas fa-times"></i>
            </button>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="quantity-modal-title">Select Quantity</h3>
            <div class="mt-2 px-7 py-3">
                <input type="number" id="quantity-input" min="1" value="1" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                <p id="stock-info" class="text-sm text-gray-500 mt-1"></p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="add-to-cart-btn" class="px-4 py-2 bg-green-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-300">
                    Add to Cart
                </button>
            </div>
        </div>
    </div>
</div>


  <div id="success-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden flex items-center justify-center z-50">
       <div class="relative p-5 border w-96 shadow-lg rounded-md bg-white">
           <div class="mt-3 text-center">
               <button id="close-success-modal" class="absolute top-2 right-2 text-gray-600 hover:text-gray-900">
                   <i class="fas fa-times"></i>
               </button>
               <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="success-modal-title">Success</h3>
               <div class="mt-2 px-7 py-3">
                   <div class="text-sm text-gray-500" id="success-modal-message">Your action was completed successfully.</div>
               </div>
               <div class="items-center px-4 py-3 flex justify-center gap-2">
                   <a href="#" id="success-modal-action-btn" class="px-4 py-2 bg-green-500 text-white text-base font-medium rounded-md w-auto shadow-sm hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-300 inline-block">
                    </a>
                   <button id="cancel-modal-btn" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300" style="display: none;">
                   </button>
               </div>
           </div>
       </div>
    </div>

   <div id="product-quantity-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden flex items-center justify-center z-50">
       <div class="relative p-6 border w-96 shadow-lg rounded-md bg-white">
           <div class="flex justify-between items-center mb-4">
               <h3 class="text-lg font-medium text-gray-900" id="product-quantity-modal-title">Add Product to Cart</h3>
               <button id="close-product-quantity-modal" class="text-gray-600 hover:text-gray-900 text-xl font-bold">
                   &times;
               </button>
           </div>
           
           <div class="mb-4">
               <input type="number" id="product-quantity-input" min="1" value="1" 
                      class="w-full px-3 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-center text-lg">
               <p id="product-stock-info" class="text-sm text-gray-500 mt-2 text-center">Max: 0 available</p>
           </div>
           
           <button id="confirm-add-to-cart-btn" 
                   class="w-full bg-green-500 text-white py-3 px-4 rounded-md hover:bg-green-600 transition duration-300 font-medium">
          </button>
       </div>
    </div>
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    </script>
    
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="preload.js"></script>
<script>
        // Dropdown toggle for categories
      function toggleCategoryDropdown() {
        const dropdown = document.getElementById('categoryDropdown');
        dropdown.classList.toggle('hidden');
        document.addEventListener('click', function outsideDropdown(e) {
          if (!dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
            document.removeEventListener('click', outsideDropdown);
          }
        });
      }
      // Search dropdown toggle (Pillow-Mart style)
      const openSearchBtn = document.getElementById('openSearch');
      const searchBar = document.getElementById('searchBar');
      if(openSearchBtn && searchBar){
        openSearchBtn.addEventListener('click', function(e){
          e.preventDefault();
          searchBar.classList.remove('hidden');
          searchBar.querySelector('input').focus();
        });
      }
      function closeSearchBar(){
        document.getElementById('searchBar').classList.add('hidden');
      }
      // Settings Sidebar
      function toggleSidebar() {
        const sidebar = document.getElementById('settingsSidebar');
        sidebar.classList.toggle('translate-x-full');
      }
      // Profile modal etc, keep your implementation or previous event handlers as needed
        

        function showSection(sectionId) {
          document.getElementById('regular-products').style.display = sectionId === 'home' ? 'block' : 'none';
          document.getElementById('christmas-products').style.display = sectionId === 'home' ? 'block' : 'none';
          document.getElementById('notifications').style.display = sectionId === 'notifications' ? 'block' : 'none';
      }

// Global variables for product detail
let currentProductId = null;
let currentProductData = null;
let selectedRating = 0;

// Function to show product detail modal
function showProductDetail(productId) {
    currentProductId = productId;
    
    // Show loading state
    document.getElementById('productDetailSection').style.display = 'block';
    
    // Fetch product details via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax_action=get_product_details&product_id=${productId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentProductData = data.product;
            populateProductDetail(data.product);
        } else {
            alert('Error loading product details: ' + data.message);
            closeProductDetail();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading product details.');
        closeProductDetail();
    });
}

// Function to populate product detail modal with data
function populateProductDetail(product) {
    // Set product images
    const mainImage = document.getElementById('productMainImage');
    const thumbnailsContainer = document.getElementById('productThumbnails');
    
    const imageSrc = product.image_path || product.image || '/placeholder.svg?height=400&width=400';
    mainImage.src = imageSrc;
    
    // Create thumbnails (for now, using the same image - you can modify this for multiple images)
    thumbnailsContainer.innerHTML = '';
    for (let i = 0; i < 4; i++) {
        const thumbnail = document.createElement('img');
        thumbnail.src = imageSrc;
        thumbnail.className = 'product-thumbnail' + (i === 0 ? ' active' : '');
        thumbnail.onclick = () => {
            mainImage.src = imageSrc;
            document.querySelectorAll('.product-thumbnail').forEach(t => t.classList.remove('active'));
            thumbnail.classList.add('active');
        };
        thumbnailsContainer.appendChild(thumbnail);
    }
    
    // Set product information
    document.getElementById('productDetailTitle').textContent = product.name;
    document.getElementById('productDetailPrice').textContent = `₱${parseFloat(product.price).toFixed(2)}`;
    document.getElementById('productDetailDescription').textContent = product.description || 'No description available.';
    document.getElementById('productSellerName').textContent = product.seller_name ? `By ${product.seller_name}` : 'By Unknown Seller';
    
    // Set stock info
    const stockInfo = document.getElementById('stockInfo');
    const quantityInput = document.getElementById('productDetailQuantity');
    if (product.stock > 0) {
        stockInfo.textContent = `${product.stock} pieces available`;
        quantityInput.max = product.stock;
        quantityInput.disabled = false;
        document.getElementById('addToCartDetailBtn').disabled = false;
        document.getElementById('buyNowDetailBtn').disabled = false;
    } else {
        stockInfo.textContent = 'Out of stock';
        quantityInput.disabled = true;
        document.getElementById('addToCartDetailBtn').disabled = true;
        document.getElementById('buyNowDetailBtn').disabled = true;
    }
    
    // Set product badge
    const badge = document.getElementById('productBadge');
    const status = getProductStatus(product.id);
    if (status) {
        badge.textContent = status;
        badge.className = 'inline-block px-3 py-1 text-xs font-semibold text-white rounded-full mb-2';
        if (status === 'Hot') badge.classList.add('badge-hot');
        else if (status === 'Sale') badge.classList.add('badge-sale');
        else if (status === 'New') badge.classList.add('badge-new');
        badge.style.display = 'inline-block';
    } else {
        badge.style.display = 'none';
    }
    
    // Set rating stars
    const ratingStars = document.getElementById('productRatingStars');
    const ratingText = document.getElementById('productRatingText');
    ratingStars.innerHTML = '';
    
    for (let i = 1; i <= 5; i++) {
        const star = document.createElement('i');
        star.className = `fas fa-star ${i <= Math.floor(product.average_rating) ? 'text-yellow-400' : 'text-gray-300'}`;
        ratingStars.appendChild(star);
    }
    
    ratingText.textContent = `(${product.average_rating.toFixed(1)}) ${product.total_ratings} review${product.total_ratings !== 1 ? 's' : ''}`;
    
    // Populate reviews
    populateReviews(product.ratings);
    
    // Reset rating form
    resetRatingForm();
}

// Function to populate reviews list
function populateReviews(ratings) {
    const reviewsList = document.getElementById('reviewsList');
    reviewsList.innerHTML = '';
    
    if (ratings.length === 0) {
        reviewsList.innerHTML = '<p class="text-gray-500 text-center py-4">No reviews yet. Be the first to review this product!</p>';
        return;
    }
    
    ratings.forEach(rating => {
        const reviewItem = document.createElement('div');
        reviewItem.className = 'comment-item';
        
        const stars = Array.from({length: 5}, (_, i) => 
            `<i class="fas fa-star ${i < rating.rating ? 'text-yellow-400' : 'text-gray-300'}"></i>`
        ).join('');
        
        reviewItem.innerHTML = `
            <div class="flex justify-between items-start mb-2">
                <div>
                    <h5 class="font-semibold">${rating.username}</h5>
                    <div class="flex items-center gap-1">${stars}</div>
                </div>
                <span class="text-sm text-gray-500">${new Date(rating.created_at).toLocaleDateString()}</span>
            </div>
            ${rating.comment ? `<p class="text-gray-700">${rating.comment}</p>` : ''}
        `;
        
        reviewsList.appendChild(reviewItem);
    });
}

// Function to get product status (same logic as in the main page)
function getProductStatus(productId) {
    if (productId % 3 === 1) return 'Hot';
    else if (productId % 3 === 2) return 'Sale';
    else return 'New';
}

// Function to close product detail modal
function closeProductDetail() {
    document.getElementById('productDetailSection').style.display = 'none';
    currentProductId = null;
    currentProductData = null;
}

// Function to handle quantity increase
function increaseQuantity() {
    const quantityInput = document.getElementById('productDetailQuantity');
    const currentValue = parseInt(quantityInput.value);
    const maxValue = parseInt(quantityInput.max);
    
    if (currentValue < maxValue) {
        quantityInput.value = currentValue + 1;
    }
}

// Function to handle quantity decrease
function decreaseQuantity() {
    const quantityInput = document.getElementById('productDetailQuantity');
    const currentValue = parseInt(quantityInput.value);
    
    if (currentValue > 1) {
        quantityInput.value = currentValue - 1;
    }
}

// Function to add to cart from detail view
        function addToCartFromDetail() {
            if (!currentProductData) return;
            
            const quantity = parseInt(document.getElementById('productDetailQuantity').value);
            
            if (quantity <= 0 || quantity > currentProductData.stock) {
                showErrorModal('Invalid Quantity', `Please enter a quantity between 1 and ${currentProductData.stock}.`);
                return;
            }
            
            // Use the existing addToCart function logic
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=add_to_cart&product_id=${currentProductId}&quantity=${quantity}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success modal instead of alert
                    const successModalTitle = document.getElementById('success-modal-title');
                    const successModalMessage = document.getElementById('success-modal-message');
                    const successModalActionBtn = document.getElementById('success-modal-action-btn');
                    
                    if (successModalTitle) successModalTitle.textContent = 'Successfully Added to Cart';
                    if (successModalMessage) successModalMessage.textContent = data.message || 'Your item has been added to the cart.';
                    if (successModalActionBtn) {
                        successModalActionBtn.textContent = 'View Cart';
                        successModalActionBtn.href = 'cart.php';
                        successModalActionBtn.onclick = null;
                    }
                    
                    updateCartCount(data.cart_count);
                    document.getElementById('success-modal').classList.remove('hidden');
                    closeProductDetail();
                } else {
                    showErrorModal('Error', data.message || 'Failed to add product to cart. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorModal('Error', 'An error occurred while loading product details.');
            });
        }

function buyNowFromDetail() {
   if (!currentProductData) return;
   
   const quantity = parseInt(document.getElementById('productDetailQuantity').value);
   
   if (quantity <= 0 || quantity > currentProductData.stock) {
       showErrorModal('Invalid Quantity', `Please enter a quantity between 1 and ${currentProductData.stock}.`);
       return;
   }
   
   // Redirect directly to checkout with direct_buy parameters
   window.location.href = `checkout.php?direct_buy=1&product_id=${currentProductId}&quantity=${quantity}`;
}

        // Updated showErrorModal to hide cancel button
        function showErrorModal(title, message) {
           const successModal = document.getElementById('success-modal');
           const successModalTitle = document.getElementById('success-modal-title');
           const successModalMessage = document.getElementById('success-modal-message');
           const successModalActionBtn = document.getElementById('success-modal-action-btn');
           const cancelModalBtn = document.getElementById('cancel-modal-btn');
           
           if (successModalTitle) successModalTitle.textContent = title;
           if (successModalMessage) successModalMessage.innerHTML = message;
           if (successModalActionBtn) {
               successModalActionBtn.textContent = 'Close';
               successModalActionBtn.href = '#';
               successModalActionBtn.onclick = function(e) {
                   e.preventDefault();
                   successModal.classList.add('hidden');
               };
           }
           
           // Hide cancel button for error messages
           if (cancelModalBtn) {
               cancelModalBtn.style.display = 'none';
           }
           
           successModal.classList.remove('hidden');
        }

        // Helper function to update cart count in the UI
        function updateCartCount(count) {
            const cartCountElement = document.querySelector('.cart-number');
            if (cartCountElement) {
                if (count > 0) {
                    cartCountElement.textContent = count;
                    cartCountElement.classList.remove('hidden');
                } else {
                    cartCountElement.classList.add('hidden');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Add cancel functionality for buy now confirmation
            const successModal = document.getElementById('success-modal');
            
            // Create cancel button if it doesn't exist
            let cancelButton = document.getElementById('cancel-modal-btn');
            if (!cancelButton) {
                cancelButton = document.createElement('button');
                cancelButton.id = 'cancel-modal-btn';
                cancelButton.className = 'px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300 ml-2';
                cancelButton.textContent = 'Cancel';
                cancelButton.style.display = 'none';
                
                // Insert after the action button
                const actionBtn = document.getElementById('success-modal-action-btn');
                if (actionBtn && actionBtn.parentNode) {
                    actionBtn.parentNode.appendChild(cancelButton);
                }
            }
            
            cancelButton.onclick = function() {
                successModal.classList.add('hidden');
            };

            // Close success modal
            const closeSuccessModalBtn = document.getElementById('close-success-modal');
            if (closeSuccessModalBtn) {
                closeSuccessModalBtn.addEventListener('click', function() {
                    successModal.classList.add('hidden');
                });
            }
        });

// Function to handle rating star clicks
document.addEventListener('DOMContentLoaded', function() {
    const ratingStars = document.querySelectorAll('#ratingStars .rating-star');
    
    ratingStars.forEach(star => {
        star.addEventListener('click', function() {
            selectedRating = parseInt(this.dataset.rating);
            document.getElementById('selectedRating').value = selectedRating;
            
            // Update star display
            ratingStars.forEach((s, index) => {
                if (index < selectedRating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });
        
        star.addEventListener('mouseover', function() {
            const hoverRating = parseInt(this.dataset.rating);
            ratingStars.forEach((s, index) => {
                if (index < hoverRating) {
                    s.style.color = '#ffd700';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
    });
    
    // Reset stars on mouse leave
    document.getElementById('ratingStars').addEventListener('mouseleave', function() {
        ratingStars.forEach((s, index) => {
            if (index < selectedRating) {
                s.style.color = '#ffd700';
            } else {
                s.style.color = '#ddd';
            }
        });
    });
});

// Function to submit review
function submitReview(event) {
    event.preventDefault();
    
    if (!currentProductId) return;
    
    const rating = parseInt(document.getElementById('selectedRating').value);
    const comment = document.getElementById('reviewComment').value.trim();
    
    if (rating === 0) {
        alert('Please select a rating.');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax_action=submit_rating&product_id=${currentProductId}&rating=${rating}&comment=${encodeURIComponent(comment)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            // Refresh product details to show new review
            showProductDetail(currentProductId);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Function to reset rating form
function resetRatingForm() {
    selectedRating = 0;
    document.getElementById('selectedRating').value = 0;
    document.getElementById('reviewComment').value = '';
    
    const ratingStars = document.querySelectorAll('#ratingStars .rating-star');
    ratingStars.forEach(star => {
        star.classList.remove('active');
        star.style.color = '#ddd';
    });
}

// Function to handle adding to cart
function addToCart(productId, productName, productPrice, productStock) {
    const modal = document.getElementById('quantity-modal');
    const modalTitle = document.getElementById('quantity-modal-title');
    const quantityInput = document.getElementById('quantity-input');
    const stockInfo = document.getElementById('stock-info');
    const actionButton = document.getElementById('add-to-cart-btn');
    
    if (modalTitle) modalTitle.textContent = `Add ${productName} to Cart`;
    if (quantityInput) {
        quantityInput.value = 1; // Reset quantity to 1
        quantityInput.max = productStock; // Set max quantity based on stock
    }
    if (stockInfo) stockInfo.textContent = `Max: ${productStock} available`;

    if (actionButton) {
        actionButton.textContent = 'Add to Cart';
        actionButton.setAttribute('data-product-id', productId);
        actionButton.setAttribute('data-action', 'cart');
        actionButton.setAttribute('data-product-name', productName);
        actionButton.setAttribute('data-product-price', productPrice);
        actionButton.setAttribute('data-product-stock', productStock);
    }
    
    modal.classList.remove('hidden');
}

function buyProduct(productId, productName, productPrice, productStock) {
    const quantityInput = document.getElementById('quantity-input');
    const quantity = parseInt(quantityInput.value);
    
    if (quantity <= 0 || quantity > productStock) {
        showErrorModal('Invalid Quantity', `Please enter a quantity between 1 and ${productStock}.`);
        return;
    }
    
    // Redirect directly to checkout with direct_buy parameters
    window.location.href = `checkout.php?direct_buy=1&product_id=${productId}&quantity=${quantity}`;
}

// Add these event listeners when the document is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Close quantity modal
    const closeModalBtn = document.getElementById('close-modal');
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', function() {
            document.getElementById('quantity-modal').classList.add('hidden');
        });
    }
    
    // Close success modal
    const closeSuccessBtn = document.getElementById('close-success-modal');
    if (closeSuccessBtn) {
        closeSuccessBtn.addEventListener('click', function() {
            document.getElementById('success-modal').classList.add('hidden');
        });
    }
    
    // Handle quantity input change to respect max stock
    const quantityInput = document.getElementById('quantity-input');
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            let value = parseInt(this.value);
            const max = parseInt(this.max);
            if (isNaN(value) || value < 1) {
                this.value = 1;
            } else if (value > max) {
                this.value = max;
            }
        });
    }

    // Handle the add to cart or buy now action
    const actionButton = document.getElementById('add-to-cart-btn');
    if (actionButton) {
        actionButton.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const quantity = parseInt(document.getElementById('quantity-input').value);
            const action = this.getAttribute('data-action');
            const productName = this.getAttribute('data-product-name');
            const productPrice = parseFloat(this.getAttribute('data-product-price'));
            const productStock = parseInt(this.getAttribute('data-product-stock'));

            if (quantity <= 0 || quantity > productStock) {
                showErrorModal('Invalid Quantity', `Please enter a quantity between 1 and ${productStock}.`);
                return;
            }
            
            if (action === 'buy') {
                document.getElementById('quantity-modal').classList.add('hidden');
                
                // Add product to cart first
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_action=add_to_cart&product_id=${productId}&quantity=${quantity}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to checkout.php after adding to cart
                        window.location.href = 'checkout.php';
                    } else {
                        showErrorModal('Error', data.message || 'Failed to add product to cart.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showErrorModal('Error', 'An error occurred. Please try again.');
                });
            } else {
                // Product not in cart, add it normally
                // First check if product already exists
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_action=check_cart&product_id=${productId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.exists) {
                        // If product exists, confirm if user wants to add more
                        const confirmAddMore = confirm(`This product is already in your cart (Quantity: ${data.quantity}). Do you want to add ${quantity} more?`);
                        if (confirmAddMore) {
                            addProductToCart(productId, quantity);
                        } else {
                            document.getElementById('quantity-modal').classList.add('hidden');
                        }
                    } else {
                        // Product not in cart, add it normally
                        addProductToCart(productId, quantity);
                    }
                })
                .catch(error => {
                    console.error('Error checking cart:', error);
                    showErrorModal('Error', 'An error occurred while checking cart. Please try again.');
                });
            }
        });
    }
    
    // Helper function to add product to cart
    function addProductToCart(productId, quantity) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax_action=add_to_cart&product_id=${productId}&quantity=${quantity}`
        })
        .then(response => response.json())
        .then(data => {
            handleCartResponse(data);
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorModal('Error', 'An error occurred. Please try again.');
        });
    }
    
    // Helper function to handle cart response
    function handleCartResponse(data) {
        // Hide quantity modal
        document.getElementById('quantity-modal').classList.add('hidden');
        
        // Show success or error in modal
        const successModalTitle = document.getElementById('success-modal-title');
        const successModalMessage = document.getElementById('success-modal-message');
        const successModalActionBtn = document.getElementById('success-modal-action-btn');
        
        if (data.success) {
            if (successModalTitle) successModalTitle.textContent = 'Successfully Added to Cart';
            if (successModalMessage) successModalMessage.textContent = data.message || 'Your item has been added to the cart.';
            if (successModalActionBtn) {
                successModalActionBtn.textContent = 'View Cart';
                successModalActionBtn.href = 'cart.php';
            }
            
            // Update cart count in the UI if available
            updateCartCount(data.cart_count);
        } else {
            if (successModalTitle) successModalTitle.textContent = 'Error';
            if (successModalMessage) successModalMessage.textContent = data.message || 'Failed to add product to cart. Please try again.';
            if (successModalActionBtn) {
                successModalActionBtn.textContent = 'Try Again';
                successModalActionBtn.href = '#';
                successModalActionBtn.onclick = function(e) {
                    e.preventDefault();
                    document.getElementById('success-modal').classList.add('hidden');
                };
            }
        }
        
        document.getElementById('success-modal').classList.remove('hidden');
    }
    
    // Helper function to show error modal
    function showErrorModal(title, message) {
        // Hide quantity modal
        document.getElementById('quantity-modal').classList.add('hidden');
        
        // Show error in modal
        const successModal = document.getElementById('success-modal');
        const successModalTitle = document.getElementById('success-modal-title');
        const successModalMessage = document.getElementById('success-modal-message');
        const successModalActionBtn = document.getElementById('success-modal-action-btn');
        
        if (successModalTitle) successModalTitle.textContent = title;
        if (successModalMessage) successModalMessage.textContent = message;
        if (successModalActionBtn) {
            successModalActionBtn.textContent = 'Close';
            successModalActionBtn.href = '#';
            successModalActionBtn.onclick = function(e) {
                e.preventDefault();
                successModal.classList.add('hidden');
            };
        }
        
        successModal.classList.remove('hidden');
    }
    
    // Helper function to update cart count in the UI
    function updateCartCount(count) {
        const cartCountElement = document.querySelector('.cart-number');
        if (cartCountElement) {
            if (count > 0) {
                cartCountElement.textContent = count;
                cartCountElement.classList.remove('hidden');
            } else {
                cartCountElement.classList.add('hidden');
            }
        }
    }
});
      function cancelOrder(orderId) {
        if (confirm('Are you sure you want to cancel this order?')) {
            // Send AJAX request to cancel the order
            fetch('cancel_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `order_id=${orderId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order cancelled successfully');
                    location.reload(); // Reload the page to reflect the changes
                } else {
                    alert('Failed to cancel the order. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    }

    function switchTab(tabId) {
        // Update tab buttons
        document.querySelectorAll('.tab-button').forEach(button => {
            if (button.dataset.tab === tabId) {
                button.classList.remove('border-transparent', 'text-gray-500');
                button.classList.add('border-green-500', 'text-green-600');
            } else {
                button.classList.remove('border-green-500', 'text-green-600');
                button.classList.add('border-transparent', 'text-gray-500');
            }
        });

        // Show/hide order sections
        document.querySelectorAll('.order-section').forEach(section => {
            section.classList.toggle('hidden', section.id !== `${tabId}-orders`);
        });
    }

    function requestReturn(orderId) {
        if (confirm('Are you sure you want to request a return for this order?')) {
            fetch('request_return.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `order_id=${orderId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Return request submitted successfully');
                    location.reload();
                } else {
                    alert(data.message || 'Failed to submit return request. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    }

    function buyAgain(orderId) {
        if (confirm('Add these items to your cart and proceed to checkout?')) {
            fetch('buy_again.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `order_id=${orderId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Redirect to checkout with the cart items
                    window.location.href = 'checkout.php?' + new URLSearchParams(data.params).toString();
                } else {
                    alert('Failed to add items to cart. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    }

    function switchTab(tabId) {
    }

    function toggleSidebar() {
        document.body.classList.toggle('sidebar-open');
    }
    document.addEventListener("DOMContentLoaded", function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

    // Removed the Christmas specific features array as it's replaced by the new hero section

    // Function to rotate features (now used for the new hero section)
    // No longer needed with the static hero section, but kept for reference if dynamic hero were re-added.
    // function rotateFeature() { ... }


   // Function to toggle dropdown visibility
    function toggleDropdown() {
      const dropdown = document.getElementById('userMenuDropdown');
      dropdown.classList.toggle('hidden');
    }

    // Function to open the profile modal
    function openModal() {
      document.getElementById('profileModal').classList.remove('hidden');
    }

    // Function to close the profile modal
    function closeModal() {
      document.getElementById('profileModal').classList.add('hidden');
    }

    // Prevent the profile link from redirecting and open the modal instead
    function openProfileModal(event) {
      event.preventDefault(); // Prevent the default link behavior
      openModal(); // Open the modal
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
  function toggleSidebar() {
    const sidebar = document.getElementById('settingsSidebar');
    sidebar.classList.toggle('translate-x-full');
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
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }
  
  
  // Disable back navigation
    window.history.pushState(null, "", window.location.href);
    window.onpopstate = function() {
      window.history.pushState(null, "", window.location.href);
    };
function closeModal() {
  document.getElementById('profileModal').classList.add('hidden');
}
</script>

</body>
</html>