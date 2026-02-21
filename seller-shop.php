<?php
session_start();

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
// Get seller ID from URL
$seller_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Check if seller exists
$seller = null;
$seller_products = [];
$is_following = false;

if ($seller_id > 0) {
    // Get seller information
    $stmt = $db->prepare("SELECT s.*, u.username FROM sellers s JOIN users u ON s.user_id = u.id WHERE s.id = ? AND s.status = 'approved'");
    $stmt->bind_param("i", $seller_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        
        // Get seller's products
        $stmt = $db->prepare("SELECT * FROM products WHERE seller_id = ? AND archived = 0 ORDER BY created_at DESC");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        $seller_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Check if user is following this seller
        if (isset($_SESSION['user_id'])) {
            $stmt = $db->prepare("SELECT * FROM seller_followers WHERE user_id = ? AND seller_id = ?");
            $stmt->bind_param("ii", $_SESSION['user_id'], $seller_id);
            $stmt->execute();
            $is_following = $stmt->get_result()->num_rows > 0;
        }
    }
}

// Handle follow/unfollow action
if (isset($_POST['follow_action']) && isset($_SESSION['user_id']) && $seller_id > 0) {
    $action = $_POST['follow_action'];
    
    if ($action === 'follow') {
        // Add follow relationship
        $stmt = $db->prepare("INSERT INTO seller_followers (user_id, seller_id, followed_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $_SESSION['user_id'], $seller_id);
        $stmt->execute();
        $is_following = true;
    } elseif ($action === 'unfollow') {
        // Remove follow relationship
        $stmt = $db->prepare("DELETE FROM seller_followers WHERE user_id = ? AND seller_id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $seller_id);
        $stmt->execute();
        $is_following = false;
    }
    
    // Redirect to prevent form resubmission
    header("Location: seller-shop.php?id=" . $seller_id);
    exit();
}

// Get product categories for filter
$stmt = $db->prepare("SELECT DISTINCT category FROM products WHERE seller_id = ? AND archived = 0");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$categories_result = $stmt->get_result();
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

// Apply category filter if set
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';
if (!empty($filter_category) && $seller_id > 0) {
    $stmt = $db->prepare("SELECT * FROM products WHERE seller_id = ? AND category = ? AND archived = 0 ORDER BY created_at DESC");
    $stmt->bind_param("is", $seller_id, $filter_category);
    $stmt->execute();
    $seller_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
// ✅ Fixed Cart Count Query (shows total quantity, defaults to 0)
$cart_count = 0;
if (!empty($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $cart_query = "SELECT COALESCE(SUM(quantity), 0) AS cart_count FROM cart_items WHERE user_id = ?";
    if ($cart_stmt = $db->prepare($cart_query)) {
        $cart_stmt->bind_param("i", $user_id);
        $cart_stmt->execute();
        $cart_result = $cart_stmt->get_result();
        if ($row = $cart_result->fetch_assoc()) {
            $cart_count = (int)$row['cart_count'];
        }
        $cart_stmt->close();
    }
}
// Get follower count
$follower_count = 0;
if ($seller_id > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM seller_followers WHERE seller_id = ?");
    $stmt->bind_param("i", $seller_id);
    $stmt->execute();
    $follower_count = $stmt->get_result()->fetch_assoc()['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $seller ? htmlspecialchars($seller['business_name']) . ' - Wastewise Shop' : 'Seller Shop - Wastewise' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        /* Header */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 5.5rem;
            background-color: #2f855a;
            z-index: 50;
        }

        /* Main Content */
        main {
            margin-top: 5.5rem;
            padding: 2rem 0;
            flex: 1;
        }

        /* Footer */
        footer {
            background-color: #2f855a;
            color: white;
            text-align: center;
            padding: 1rem 0;
            z-index: 30;
        }

        .profile-banner {
            height: 200px;
            background-size: cover;
            background-position: center;
            background-color: #276749;
        }

        .profile-pic {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            background-color: #e2e8f0;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        .badge-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: #e53e3e;
            color: white;
            border-radius: 9999px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: bold;
        }

        a[data-title] {
            position: relative;
        }

        a[data-title]::after {
            content: attr(data-title);
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-top: 6px;
            background-color: #333;
            color: #fff;
            padding: 5px 8px;
            border-radius: 4px;
            white-space: nowrap;
            font-size: 12px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease-in-out;
            z-index: 100;
        }

        a[data-title]:hover::after {
            opacity: 1;
        }
        .cart-number {
            position: absolute;
            top: -5px;  /* Adjust the position closer to the top */
            right: -5px;  /* Adjust to make it closer to the right */
            background-color: red;
            color: white;
            border-radius: 50%;
            font-size: 10px; /* Smaller font size */
            width: 18px;  /* Smaller circle */
            height: 18px;  /* Smaller circle */
            display: flex;
            justify-content: center;
            align-items: center;
        }
        /* Adjusted grid for product cards to match image */
        @media (min-width: 640px) {
            .sm\:grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 1024px) {
            .lg\:grid-cols-3 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (min-width: 1280px) {
            .xl\:grid-cols-4 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
        /* Specific styles for product card badges */
        .badge-hot {
            background-color: #ec4899; /* Pink-500 */
        }
        .badge-sale {
            background-color: #3b82f6; /* Blue-500 */
        }
        .badge-new {
            background-color: #22c55e; /* Green-500 */
        }
    </style>
<header class="bg-green-700 text-white py-6">
  <div class="container mx-auto px-4">
    <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4 relative">

      <!-- Left: Logo and Title -->
      <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
        <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
        <h1 class="text-2xl font-bold">Wastewise</h1>
      </div>

      <!-- Center: Navigation Buttons -->
      <div class="flex justify-center items-center w-full md:w-1/3 gap-20 text-base">
        <a href="home.php" data-title="Home" class="hover:text-gray-300"><i class="fas fa-home"></i></a>
        <a href="community_impact.php" data-title="Community Impact" class="hover:text-gray-300"><i class="fas fa-leaf"></i></a>
        <a href="my_orders.php" data-title="My Orders" class="hover:text-gray-300"><i class="fas fa-shopping-bag"></i></a>
        <a href="cart.php" data-title="Cart" class="relative flex items-center hover:text-gray-300">
          <i class="fas fa-shopping-cart"></i>
          <?php if ($cart_count > 0): ?>
            <span class="cart-number"><?php echo $cart_count; ?></span>
          <?php endif; ?>
        </a>
        <a href="seller-search.php" data-title="Find Sellers" class="hover:text-gray-300"><i class="fas fa-store"></i></a>
      </div>

      <!-- Right: Settings Icon (opens sidebar) -->
      <div class="flex justify-end w-full md:w-1/3">
        <button onclick="toggleSidebar()" class="flex items-center gap-2 text-white px-4 py-2">
          <i class="fas fa-cog text-xl"></i>
        </button>
      </div>

    </div>
  </div>
</header>

<!-- Sidebar -->
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

<!-- Profile Modal -->
<div id="profileModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md relative">
    <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-600 hover:text-gray-900 text-xl font-bold">&times;</button>

    <h2 class="text-2xl font-bold text-green-700 text-center mb-6">Profile Information</h2>
    <div class="space-y-4">
      <p><strong>Username:</strong> <?= htmlspecialchars($_SESSION['username']); ?></p>
      <p><strong>Email:</strong> <?= htmlspecialchars($email); ?></p>
    </div>
  </div>
</div>

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
    <main class="container mx-auto px-4">
        <!-- Close Button -->
<div class="flex justify-end mb-4">
    <a href="seller-search.php" class="text-gray-600 hover:text-red-600 text-2xl">
        <i class="fas fa-times-circle"></i>
    </a>
</div>

        <?php if (!$seller): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-store text-gray-400 text-5xl mb-4"></i>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">Seller Not Found</h2>
                <p class="text-gray-600 mb-4">The seller you're looking for doesn't exist or isn't approved yet.</p>
                <a href="seller-search.php" class="inline-block bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-500 transition duration-300">
                    Find Other Sellers
                </a>
            </div>
        <?php else: ?>
            <!-- Seller Profile -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
                <div class="profile-banner"></div>
                <div class="px-6 py-4 flex flex-col md:flex-row items-center md:items-start">
                    <div class="flex flex-col items-center md:items-start md:flex-row md:space-x-6">
                        <div class="profile-pic flex items-center justify-center">
                            <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                <img src="<?= htmlspecialchars($seller['logo_url']) ?>" alt="<?= htmlspecialchars($seller['business_name']) ?>" class="w-full h-full object-cover rounded-full">
                            <?php else: ?>
                                <i class="fas fa-store text-gray-400 text-4xl"></i>
                            <?php endif; ?>
                        </div>
                        <div class="text-center md:text-left mt-4 md:mt-0">
                            <h1 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($seller['business_name']) ?></h1>
                            <p class="text-gray-600 mb-2"><?= htmlspecialchars($seller['business_type']) ?></p>
                            <div class="flex items-center justify-center md:justify-start space-x-2 text-sm text-gray-500">
                                <span><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($seller['city']) ?>, <?= htmlspecialchars($seller['state']) ?></span>
                                <span><i class="fas fa-users mr-1"></i><?= $follower_count ?> followers</span>
                                <span><i class="fas fa-box mr-1"></i><?= count($seller_products) ?> products</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 md:mt-0 md:ml-auto">
                        <form method="POST" action="seller-shop.php?id=<?= $seller_id ?>">
                            <input type="hidden" name="follow_action" value="<?= $is_following ? 'unfollow' : 'follow' ?>">
                            <button type="submit" class="<?= $is_following ? 'bg-gray-600 hover:bg-gray-700' : 'bg-green-600 hover:bg-green-700' ?> text-white px-6 py-2 rounded-full transition duration-300">
                                <i class="<?= $is_following ? 'fas fa-user-minus' : 'fas fa-user-plus' ?> mr-2"></i>
                                <?= $is_following ? 'Unfollow' : 'Follow' ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php if (isset($seller['description']) && $seller['description']): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">About</h3>
                        <p class="text-gray-600"><?= nl2br(htmlspecialchars($seller['description'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if (isset($seller['website']) && $seller['website']): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Website</h3>
                        <a href="<?= htmlspecialchars($seller['website']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                            <?= htmlspecialchars($seller['website']) ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Product Filters -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Products by <?= htmlspecialchars($seller['business_name']) ?></h2>
                    
                    <!-- Category Filter -->
                    <form id="categoryForm" action="" method="GET" class="w-full md:w-auto">
                        <input type="hidden" name="id" value="<?= $seller_id ?>">
                        <select id="categorySelect" name="category" class="px-4 py-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-gray-800 w-full md:w-auto">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
                <?php if (empty($seller_products)): ?>
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-box-open text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">No Products Available</h3>
                        <p class="text-gray-600">This seller hasn't added any products yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($seller_products as $product): 
                        // Simulate status for display based on product ID or other criteria
                        $status = null;
                        if ($product['id'] % 3 === 1) {
                            $status = 'Hot';
                        } elseif ($product['id'] % 3 === 2) {
                            $status = 'Sale';
                        } else {
                            $status = 'New';
                        }
                    ?>
                        <div class="bg-white rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow duration-300 relative">
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
                            <img src="<?= htmlspecialchars($product['image_path'] ?? $product['image'] ?? '/placeholder.svg?height=300&width=300'); ?>" 
                                alt="<?= htmlspecialchars($product['name']); ?>" 
                                class="w-full h-48 object-cover rounded-t-lg">
                            <div class="p-4">
                                <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars($product['category']); ?></p>
                                <h3 class="text-lg font-semibold mb-2 line-clamp-2"><?= htmlspecialchars($product['name']); ?></h3>
                                <div class="flex items-center mb-2">
                                    <div class="flex items-center gap-0.5">
                                        <?php 
                                        // Assuming a 'rating' field exists or can be simulated
                                        $rating = isset($product['rating']) ? $product['rating'] : 4.0; // Default rating if not available
                                        for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= floor($rating) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="text-sm text-gray-600 ml-2">(<?= number_format($rating, 1) ?>)</span>
                                </div>
                                <p class="text-sm text-gray-600 mb-2">
                                    <?php if (!empty($seller['business_name'])): // Use seller's business name as "brand" ?>
                                        By <?= htmlspecialchars($seller['business_name']); ?>
                                    <?php else: ?>
                                        By Unknown Seller
                                    <?php endif; ?>
                                </p>
                                <div class="flex items-baseline gap-2 mb-4">
                                    <span class="text-xl font-bold text-green-600">₱<?= number_format($product['price'], 2); ?></span>
                                    <?php 
                                    // Simulate original price if not available, or use a fixed percentage higher
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
                                <div class="flex justify-between items-center mt-2 gap-2">
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
        <?php endif; ?>
    </main>

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

    <!-- Quantity Modal -->
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


  <!--   Success Modal - Updated version -->
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
                    <!--   OK -->
                   </a>
                   <button id="cancel-modal-btn" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300" style="display: none;">
                   <!--    Cancel -->
                   </button>
               </div>
           </div>
       </div>
    </div>

   <!--  Quantity Selection Modal for Product Details -->
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
          <!--     Add to Cart-->
           </button>
       </div>
    </div>
     <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="preload.js"></script>
    <script>
        // Add event listener for real-time dropdown changes
        document.getElementById('categorySelect').addEventListener('change', function () {
            // Automatically submit the form when the category changes
            document.getElementById('categoryForm').submit();
        });

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

        // Function to handle buy now
        function buyProduct(productId, productName, productPrice, productStock) {
            const modal = document.getElementById('quantity-modal');
            const modalTitle = document.getElementById('quantity-modal-title');
            const quantityInput = document.getElementById('quantity-input');
            const stockInfo = document.getElementById('stock-info');
            const actionButton = document.getElementById('add-to-cart-btn');
            
            if (modalTitle) modalTitle.textContent = `Buy ${productName}`;
            if (quantityInput) {
                quantityInput.value = 1; // Reset quantity to 1
                quantityInput.max = productStock; // Set max quantity based on stock
            }
            if (stockInfo) stockInfo.textContent = `Max: ${productStock} available`;

            if (actionButton) {
                actionButton.textContent = 'Buy Now';
                actionButton.setAttribute('data-product-id', productId);
                actionButton.setAttribute('data-action', 'buy');
                actionButton.setAttribute('data-product-name', productName);
                actionButton.setAttribute('data-product-price', productPrice);
                actionButton.setAttribute('data-product-stock', productStock);
            }
            
            modal.classList.remove('hidden');
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
                        // Handle Buy Now
                        const total = productPrice * quantity;
                        
                        // Hide quantity modal
                        document.getElementById('quantity-modal').classList.add('hidden');
                        
                        // Show confirmation in success modal
                        const successModalTitle = document.getElementById('success-modal-title');
                        const successModalMessage = document.getElementById('success-modal-message');
                        const successModalActionBtn = document.getElementById('success-modal-action-btn');
                        
                        if (successModalTitle) successModalTitle.textContent = 'Confirm Purchase';
                        if (successModalMessage) successModalMessage.innerHTML = `Total for ${quantity} ${productName}(s): <strong>₱${total.toFixed(2)}</strong>`;
                        if (successModalActionBtn) {
                            successModalActionBtn.textContent = 'Proceed to Checkout';
                            successModalActionBtn.href = `proceed_checkout.php?product_id=${productId}&quantity=${quantity}`;
                        }
                        
                        document.getElementById('success-modal').classList.remove('hidden');
                    } else {
                        // Handle Add to Cart - First check if product already exists
                        fetch(window.location.href, { // Send AJAX to current page
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
                fetch(window.location.href, { // Send AJAX to current page
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
    </script>
</body>
</html>
