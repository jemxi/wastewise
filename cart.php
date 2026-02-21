<?php
session_start();

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

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For testing purposes, set a default user_id if not logged in
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
        $user_id = 1;
    } else {
        $user_id = $_SESSION['user_id'];
    }

    $servername = "localhost";
    $username = "u255729624_wastewise";
    $password = "/l5Dv04*K";
    $dbname = "u255729624_wastewise";

    try {
        $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'update_quantity') {
                $cart_id = intval($_POST['cart_id']);
                $quantity = max(1, intval($_POST['quantity']));
                
                $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?");
                $result = $stmt->execute([$quantity, $cart_id, $user_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $_SESSION['success_message'] = 'Quantity updated successfully';
                } else {
                    $_SESSION['error_message'] = 'Failed to update quantity';
                }
            }
            
            if ($action === 'remove_item') {
                $cart_id = intval($_POST['cart_id']);
                
                $stmt = $pdo->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
                $result = $stmt->execute([$cart_id, $user_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $_SESSION['success_message'] = 'Item removed from cart';
                } else {
                    $_SESSION['error_message'] = 'Failed to remove item';
                }
            }
        }
        
        // Redirect to prevent form resubmission
        header("Location: cart.php");
        exit();
        
    } catch(PDOException $e) {
        $_SESSION['error_message'] = 'Database error: ' . $e->getMessage();
        header("Location: cart.php");
        exit();
    }
}

// For testing purposes, let's set a default user_id if not logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Use user_id = 1 for testing
    $user_id = 1;
} else {
    $user_id = $_SESSION['user_id'];
}

$servername = "localhost";
$username = "u255729624_wastewise";
$password = "/l5Dv04*K";
$dbname = "u255729624_wastewise";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get cart items
try {
    $stmt = $pdo->prepare("SELECT ci.id as cart_id, ci.quantity, ci.created_at, p.id as product_id, p.name, p.price, p.image_path, p.image, p.stock, p.category, COALESCE(s.business_name, 'Unknown Seller') as seller_name FROM cart_items ci JOIN products p ON ci.product_id = p.id LEFT JOIN sellers s ON p.seller_id = s.id WHERE ci.user_id = ? ORDER BY ci.created_at DESC");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $subtotal = 0;
    $total_items = 0;
    foreach ($cart_items as $item) {
        $subtotal += $item['price'] * $item['quantity'];
        $total_items += $item['quantity'];
    }
    
    // Get cart count for navigation
    $cart_count = $total_items;
    
} catch(PDOException $e) {
    $cart_items = [];
    $subtotal = 0;
    $total_items = 0;
    $cart_count = 0;
}

// Shipping and tax calculations
$shipping_fee = $subtotal > 500 ? 0 : 50; // Free shipping over ₱500
$tax_rate = 0.12; // 12% VAT
$tax_amount = $subtotal * $tax_rate;
$total = $subtotal + $shipping_fee + $tax_amount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo.png">
    
    <style>
        /* Same styles as home.php */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 5.5rem;
            background-color: #2f855a;
            z-index: 50;
        }

        .main-content {
            margin-top: 5.5rem;
            flex: 1;
            padding: 2rem 0;
        }

        .cart-number {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: red;
            color: white;
            border-radius: 50%;
            font-size: 10px;
            width: 18px;
            height: 18px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .quantity-input {
            width: 60px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.25rem;
        }

        .cart-item {
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <!-- Navigation Header (same as home.php) -->
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
    <div class="main-content">
        <div class="container mx-auto px-4">
            <div class="max-w-6xl mx-auto">
                <!-- Page Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Shopping Cart</h1>
                    <p class="text-gray-600">Review your items and proceed to checkout</p>
                </div>

                <!-- Display success/error messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($cart_items)): ?>
                    <!-- Empty Cart -->
                    <div class="text-center py-16">
                        <i class="fas fa-shopping-cart text-6xl text-gray-300 mb-4"></i>
                        <h2 class="text-2xl font-semibold text-gray-700 mb-2">Your cart is empty</h2>
                        <p class="text-gray-500 mb-6">Add some products to get started!</p>
                        <a href="home.php" class="bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition duration-300">
                            <i class="fas fa-arrow-left mr-2"></i> Continue Shopping
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Cart Items -->
                        <div class="lg:col-span-2">
                            <div class="bg-white rounded-lg shadow-md p-6">
                                <h2 class="text-xl font-semibold mb-4">Cart Items (<?php echo $total_items; ?>)</h2>
                                
                                <div class="space-y-4">
                                    <?php foreach ($cart_items as $item): ?>
                                        <div class="cart-item border-b border-gray-200 pb-4 last:border-b-0" data-cart-id="<?php echo $item['cart_id']; ?>" data-price="<?php echo $item['price']; ?>">
                                            <div class="flex items-center gap-4">
                                                <!-- Added checkbox for item selection -->
                                                <div class="flex-shrink-0">
                                                    <input type="checkbox" 
                                                           id="item_<?php echo $item['cart_id']; ?>"
                                                           name="selected_items[]"
                                                           value="<?php echo $item['cart_id']; ?>"
                                                           class="item-checkbox w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500" 
                                                           data-cart-id="<?php echo $item['cart_id']; ?>"
                                                           data-price="<?php echo $item['price']; ?>"
                                                           data-quantity="<?php echo $item['quantity']; ?>">
                                                </div>
                                                
                                                <!-- Product Image -->
                                                <div class="flex-shrink-0">
                                                    <img src="<?php echo !empty($item['image_path']) ? htmlspecialchars($item['image_path']) : (!empty($item['image']) ? htmlspecialchars($item['image']) : '/placeholder.svg?height=80&width=80'); ?>" 
                                                         alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                         class="w-20 h-20 object-cover rounded-lg">
                                                </div>
                                                
                                                <!-- Product Details -->
                                                <div class="flex-grow">
                                                    <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['name']); ?></h3>
                                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($item['category']); ?></p>
                                                    <p class="text-sm text-gray-500">By <?php echo htmlspecialchars($item['seller_name']); ?></p>
                                                    <p class="text-lg font-bold text-green-600">₱<?php echo number_format($item['price'], 2); ?></p>
                                                </div>
                                                
                                                <!-- Quantity Controls with PHP forms -->
                                                <div class="flex items-center gap-2">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="update_quantity">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['cart_id']; ?>">
                                                        <input type="hidden" name="quantity" value="<?php echo max(1, $item['quantity'] - 1); ?>">
                                                        <button type="submit" 
                                                                class="bg-gray-200 text-gray-700 px-2 py-1 rounded hover:bg-gray-300 <?php echo $item['quantity'] <= 1 ? 'opacity-50 cursor-not-allowed' : ''; ?>" 
                                                                <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>
                                                            <i class="fas fa-minus"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="update_quantity">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['cart_id']; ?>">
                                                        <input type="number" 
                                                               name="quantity"
                                                               value="<?php echo $item['quantity']; ?>" 
                                                               min="1" 
                                                               max="<?php echo $item['stock']; ?>" 
                                                               class="quantity-input" 
                                                               onchange="this.form.submit()">
                                                    </form>
                                                    
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="update_quantity">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['cart_id']; ?>">
                                                        <input type="hidden" name="quantity" value="<?php echo min($item['stock'], $item['quantity'] + 1); ?>">
                                                        <button type="submit" 
                                                                class="bg-gray-200 text-gray-700 px-2 py-1 rounded hover:bg-gray-300 <?php echo $item['quantity'] >= $item['stock'] ? 'opacity-50 cursor-not-allowed' : ''; ?>" 
                                                                <?php echo $item['quantity'] >= $item['stock'] ? 'disabled' : ''; ?>>
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                                
                                                <!-- Item Total -->
                                                <div class="text-right">
                                                    <p class="font-semibold text-gray-900 item-total">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></p>
                                                    <!-- Remove button with PHP form -->
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this item from your cart?')">
                                                        <input type="hidden" name="action" value="remove_item">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['cart_id']; ?>">
                                                        <button type="submit" class="text-red-500 hover:text-red-700 text-sm mt-1">
                                                            <i class="fas fa-trash mr-1"></i> Remove
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                            
                                            <!-- Stock Warning -->
                                            <?php if ($item['quantity'] > $item['stock']): ?>
                                                <div class="mt-2 text-sm text-red-600">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                                    Only <?php echo $item['stock']; ?> items available in stock
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Added select all checkbox -->
                                <div class="mt-4 pt-4 border-t border-gray-200 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" 
                                               id="selectAll" 
                                               name="select_all"
                                               class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500">
                                        <label for="selectAll" class="text-sm font-medium text-gray-700">Select All Items</label>
                                    </div>
                                    <a href="home.php" class="text-green-600 hover:text-green-700 font-medium">
                                        <i class="fas fa-arrow-left mr-2"></i> Continue Shopping
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Summary -->
                        <div class="lg:col-span-1">
                            <div class="bg-white rounded-lg shadow-md p-6 sticky top-24">
                                <h2 class="text-xl font-semibold mb-4">Order Summary</h2>
                                
                                <div class="space-y-3">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Subtotal (<span id="selectedItemsCount">0</span> items)</span>
                                        <span class="font-medium" id="subtotalAmount">₱0.00</span>
                                    </div>
                                    
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Shipping Fee</span>
                                        <span class="font-medium" id="shippingAmount">₱0.00</span>
                                    </div>
                                    
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Tax (12% VAT)</span>
                                        <span class="font-medium" id="taxAmount">₱0.00</span>
                                    </div>
                                    
                                    <hr class="my-3">
                                    
                                    <div class="flex justify-between text-lg font-bold">
                                        <span>Total</span>
                                        <span class="text-green-600" id="totalAmount">₱0.00</span>
                                    </div>
                                </div>
                                
                                <!-- Updated shipping promotion to be dynamic -->
                                <div id="shippingPromo" class="mt-4 p-3 bg-blue-50 rounded-lg" style="display: none;">
                                    <p class="text-sm text-blue-700">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <span id="promoText">Add more for free shipping!</span>
                                    </p>
                                </div>
                                
                                <!-- Checkout Button -->
                                <button onclick="proceedToCheckout()" 
                                        class="w-full bg-green-600 text-white py-3 px-4 rounded-lg hover:bg-green-700 transition duration-300 mt-6 font-semibold">
                                    <i class="fas fa-credit-card mr-2"></i> Proceed to Checkout
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer (same as home.php) -->
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

    <!-- Success/Error Modal -->
    <div id="messageModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden flex items-center justify-center z-50">
        <div class="relative p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div id="modalIcon" class="mx-auto flex items-center justify-center h-12 w-12 rounded-full mb-4">
                    <i id="modalIconClass" class="text-2xl"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modalTitle">Message</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500" id="modalMessage">Message content</p>
                </div>
                <div class="items-center px-4 py-3">
                    <button id="modalCloseBtn" class="px-4 py-2 bg-green-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-300">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function proceedToCheckout() {
            const selectedItems = document.querySelectorAll('.item-checkbox:checked');
            if (selectedItems.length === 0) {
                alert('Please select at least one item to checkout.');
                return;
            }
            
            let subtotal = 0;
            let totalItems = 0;
            const selectedCartIds = [];
            
            selectedItems.forEach(checkbox => {
                const price = parseFloat(checkbox.getAttribute('data-price'));
                const quantity = parseInt(checkbox.getAttribute('data-quantity'));
                const cartId = checkbox.getAttribute('data-cart-id');
                
                if (!isNaN(price) && !isNaN(quantity)) {
                    subtotal += price * quantity;
                    totalItems += quantity;
                    selectedCartIds.push(cartId);
                }
            });
            
            const shippingFee = subtotal > 500 ? 0 : 50;
            const taxAmount = subtotal * 0.12;
            const total = subtotal + shippingFee + taxAmount;
            
            // Pass calculated totals to checkout page
            const params = new URLSearchParams({
                items: selectedCartIds.join(','),
                subtotal: subtotal.toFixed(2),
                shipping: shippingFee.toFixed(2),
                tax: taxAmount.toFixed(2),
                total: total.toFixed(2),
                item_count: totalItems
            });
            
            window.location.href = 'checkout.php?' + params.toString();
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


        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const itemCheckboxes = document.querySelectorAll('.item-checkbox');
            
            itemCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectAllCheckbox.checked = false;
            
            selectAllCheckbox.addEventListener('change', function() {
                itemCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateOrderSummary();
            });
            
            itemCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const cartId = this.getAttribute('data-cart-id');
                    const quantityInput = document.querySelector(`input[name="quantity"][form*="${cartId}"], input[name="quantity"][data-cart-id="${cartId}"]`);
                    if (quantityInput) {
                        this.setAttribute('data-quantity', quantityInput.value);
                    }
                    
                    const checkedBoxes = document.querySelectorAll('.item-checkbox:checked');
                    selectAllCheckbox.checked = checkedBoxes.length === itemCheckboxes.length;
                    selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < itemCheckboxes.length;
                    
                    updateOrderSummary();
                });
            });
            
            const quantityInputs = document.querySelectorAll('input[name="quantity"]');
            quantityInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const form = this.closest('form');
                    const cartId = form.querySelector('input[name="cart_id"]').value;
                    const checkbox = document.querySelector(`.item-checkbox[data-cart-id="${cartId}"]`);
                    if (checkbox) {
                        checkbox.setAttribute('data-quantity', this.value);
                        if (checkbox.checked) {
                            updateOrderSummary();
                        }
                    }
                });
            });
            
            updateOrderSummary();
        });

        function updateOrderSummary() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            let subtotal = 0;
            let totalItems = 0;
            
            checkboxes.forEach(checkbox => {
                const price = parseFloat(checkbox.getAttribute('data-price'));
                const quantity = parseInt(checkbox.getAttribute('data-quantity'));
                
                if (!isNaN(price) && !isNaN(quantity)) {
                    subtotal += price * quantity;
                    totalItems += quantity;
                }
            });
            
            const shippingFee = subtotal > 500 ? 0 : 50;
            const taxAmount = subtotal * 0.12;
            const total = subtotal + shippingFee + taxAmount;

            const formatCurrency = (amount) => {
                if (isNaN(amount)) return '₱0.00';
                return '₱' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            };
            
            document.getElementById('selectedItemsCount').textContent = totalItems;
            document.getElementById('subtotalAmount').textContent = formatCurrency(subtotal);
            document.getElementById('taxAmount').textContent = formatCurrency(taxAmount);
            document.getElementById('totalAmount').textContent = formatCurrency(total);
            
            const shippingElement = document.getElementById('shippingAmount');
            if (subtotal === 0) {
                shippingElement.textContent = '₱0.00';
            } else if (shippingFee === 0) {
                shippingElement.innerHTML = '<span class="text-green-600">FREE</span>';
            } else {
                shippingElement.textContent = formatCurrency(shippingFee);
            }
            
            const promoDiv = document.getElementById('shippingPromo');
            const promoText = document.getElementById('promoText');
            if (subtotal < 500 && subtotal > 0) {
                const needed = 500 - subtotal;
                promoText.textContent = `Add ${formatCurrency(needed)} more for free shipping!`;
                promoDiv.style.display = 'block';
            } else {
                promoDiv.style.display = 'none';
            }
        }

        function toggleDropdown() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.classList.toggle('hidden');
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userMenuDropdown');
            const button = event.target.closest('button');
            
            if (!button || !button.onclick || button.onclick.toString().indexOf('toggleDropdown') === -1) {
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

document.addEventListener('DOMContentLoaded', function() {
  const quantityForms = document.querySelectorAll('form');

  quantityForms.forEach(form => {
    form.addEventListener('submit', function(e) {
      const button = this.querySelector('button[type="submit"]');
      if (button) {
        // Disable the button immediately
        button.disabled = true;
        button.style.opacity = "0.6";
        button.style.cursor = "not-allowed";
      }

      // Re-enable after 1 second (enough for server to respond and reload)
      setTimeout(() => {
        if (button) {
          button.disabled = false;
          button.style.opacity = "1";
          button.style.cursor = "pointer";
        }
      }, 1000);
    });
  });
});
    </script>
</body>
</html>
