<?php
session_start();
require_once 'db_connection.php'; // Make sure this file correctly establishes the $pdo connection

error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production

function base_url($path = '') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    // Adjust base path if your app isn't at the root
    // $subdir = '/your_app_subdir/';
    // $base = $protocol . '://' . $host . $subdir;
    $base = $protocol . '://' . $host . '/'; // Added trailing slash for consistency
    return $base . ltrim($path, '/');
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Check if PDO connection exists
if (!isset($pdo)) {
     error_log("Database connection failed. PDO object not found.");
     die("A database connection error occurred. Please try again later.");
}

// Fetch user's email
$email = '';
try {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user_email_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $email = $user_email_data['email'] ?? 'Email not found';
} catch (PDOException $e) {
    error_log("Error fetching user email for user_id $user_id: " . $e->getMessage());
    $email = 'Error retrieving email';
}

// Get full user information
$user = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
     $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
     if (!$user) {
         error_log("User data not found for logged-in user_id: " . $user_id);
         $user = ['full_name' => $username, 'email' => $email, 'default_phone' => '', 'default_address' => '', 'default_barangay' => ''];
     }
} catch (PDOException $e) {
     error_log("Error fetching user details for user_id $user_id: " . $e->getMessage());
     die("Could not retrieve user information. Please try again.");
}

// Initialize checkout variables
$selected_cart_ids = [];
$cart_items = [];
$subtotal = 0.0;
$shipping_fee = 50.0;
$tax = 0.0;
$total = 0.0;
$item_count = 0;
$is_direct_buy = false;
$product_id = null;
$quantity = null;

// Determine checkout context: Direct Buy or Cart
$is_direct_buy = isset($_GET['direct_buy']) && $_GET['direct_buy'] == 1 && isset($_GET['product_id']) && isset($_GET['quantity']);

if ($is_direct_buy) {
    // --- Direct Buy Logic ---
    $product_id = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
    $quantity = filter_input(INPUT_GET, 'quantity', FILTER_VALIDATE_INT);

    if ($product_id && $quantity && $quantity > 0) {
        try {
            $stmt = $pdo->prepare("SELECT p.*, s.id as seller_id, u.username as seller_name FROM products p LEFT JOIN sellers s ON p.seller_id = s.id LEFT JOIN users u ON s.user_id = u.id WHERE p.id = :product_id AND (p.archived = 0 OR p.archived IS NULL)");
            $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) { header('Location: home.php?error=product_not_found'); exit(); }
            if ($product['stock'] < $quantity) { header('Location: home.php?error=insufficient_stock&product_name=' . urlencode($product['name'])); exit(); }

            $image_url = $product['image_url'] ?? $product['image_path'] ?? (isset($product['image']) ? (filter_var($product['image'], FILTER_VALIDATE_URL) ? $product['image'] : 'uploads/' . $product['image']) : null) ?? '/placeholder.svg?height=100&width=100';

            $cart_items = [[
                'id' => 'direct_buy_' . $product_id, 'product_id' => $product['id'], 'quantity' => $quantity,
                'name' => $product['name'], 'price' => $product['price'], 'image_url' => $image_url,
                'seller_id' => $product['seller_id'], 'seller_name' => $product['seller_name'] ?? 'Unknown Seller'
            ]];

            $subtotal = $product['price'] * $quantity;
            $item_count = $quantity;

         } catch (PDOException $e) { error_log("Direct buy product fetch error: " . $e->getMessage()); header('Location: home.php?error=database_error'); exit(); }
    } else { header('Location: home.php?error=invalid_direct_buy_params'); exit(); }

} elseif (isset($_GET['items'])) {
    // --- Cart Checkout Logic ---
    // Use FILTER_SANITIZE_SPECIAL_CHARS instead of FILTER_SANITIZE_STRING
    $selected_cart_ids_str = filter_input(INPUT_GET, 'items', FILTER_SANITIZE_SPECIAL_CHARS);
    $selected_cart_ids = !empty($selected_cart_ids_str) ? explode(',', $selected_cart_ids_str) : [];
    $selected_cart_ids = array_map('intval', array_filter($selected_cart_ids, 'is_numeric'));

    if (!empty($selected_cart_ids)) {
         try {
            $placeholders = rtrim(str_repeat('?,', count($selected_cart_ids)), ',');
            $stmt = $pdo->prepare("SELECT ci.id as cart_item_id, ci.quantity, ci.product_id, p.name, p.price, p.image_url, p.image_path, p.image, s.id as seller_id, u.username as seller_name FROM cart_items ci JOIN products p ON ci.product_id = p.id LEFT JOIN sellers s ON p.seller_id = s.id LEFT JOIN users u ON s.user_id = u.id WHERE ci.id IN ($placeholders) AND ci.user_id = ? AND (p.archived = 0 OR p.archived IS NULL)");
            $params = array_merge($selected_cart_ids, [$user_id]);
            $stmt->execute($params);
            $fetched_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cart_items_temp = [];
            foreach ($fetched_items as $item) {
                 $subtotal += $item['price'] * $item['quantity'];
                 $item_count += $item['quantity'];
                 $image_url = $item['image_url'] ?? $item['image_path'] ?? (isset($item['image']) ? (filter_var($item['image'], FILTER_VALIDATE_URL) ? $item['image'] : 'uploads/' . $item['image']) : null) ?? '/placeholder.svg?height=100&width=100';
                 $cart_items_temp[] = [
                     'id' => $item['cart_item_id'], 'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
                     'name' => $item['name'], 'price' => $item['price'], 'image_url' => $image_url,
                     'seller_id' => $item['seller_id'], 'seller_name' => $item['seller_name'] ?? 'Unknown Seller'
                 ];
            }
             $cart_items = $cart_items_temp;

         } catch (PDOException $e) { error_log("Error fetching selected cart items for user_id $user_id: " . $e->getMessage()); header('Location: cart.php?error=fetch_failed'); exit(); }
    } else { header('Location: cart.php?error=no_items_selected'); exit(); }
} else {
    // Redirect if neither direct buy nor items from cart are specified
    header('Location: cart.php?error=invalid_access');
    exit();
}

// Calculate final totals based on context
$shipping_fee = $subtotal >= 500 ? 0 : 50;
$tax = $subtotal * 0.12;
$total = $subtotal + $shipping_fee + $tax;

// Get overall cart count for header icon
try {
    $stmt_count = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) as count FROM cart_items WHERE user_id = :user_id");
     $stmt_count->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_count->execute();
    $cart_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
     error_log("Error fetching cart count for user_id $user_id: " . $e->getMessage());
     $cart_count = 0;
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
     try {
        echo "<script>console.log('[v0] Starting order placement process');</script>";

        // Sanitize POST data - Replace FILTER_SANITIZE_STRING
        $full_name = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS);
        $email_form = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS); // Basic sanitization
        $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_SPECIAL_CHARS); // Purok
        $barangay = filter_input(INPUT_POST, 'barangay', FILTER_SANITIZE_SPECIAL_CHARS);

        // Basic validation
        if (empty($full_name) || empty($email_form) || empty($phone) || empty($address) || empty($barangay)) {
             throw new Exception("Missing required shipping information.");
        }

        echo "<script>console.log('[v0] Form data received: " . json_encode($_POST) . "');</script>";

        // Fixed location data
        $city = 'Guimba'; $province = 'Nueva Ecija'; $region = 'Region 3'; $country = 'Philippines'; $zip_code = '3115';

        // --- Transaction and Re-fetching/Locking items ---
        $pdo->beginTransaction();

        $current_cart_items = []; $order_subtotal = 0.0; $order_item_count = 0; $final_selected_cart_ids = [];
        $is_order_direct_buy = isset($_POST['direct_buy']) && $_POST['direct_buy'] == 1;

        if ($is_order_direct_buy && isset($_POST['product_id']) && isset($_POST['quantity'])) {
            // Direct buy logic... (as before)
            $p_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT); $p_qty = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
            if(!$p_id || !$p_qty || $p_qty <= 0) { throw new Exception("Invalid product data for direct buy."); }
            $stmt = $pdo->prepare("SELECT p.*, s.id as seller_id, u.username as seller_name FROM products p LEFT JOIN sellers s ON p.seller_id = s.id LEFT JOIN users u ON s.user_id = u.id WHERE p.id = :product_id AND (p.archived = 0 OR p.archived IS NULL) FOR UPDATE");
            $stmt->bindParam(':product_id', $p_id, PDO::PARAM_INT); $stmt->execute(); $product_check = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product_check) { throw new Exception("Product #{$p_id} not available."); }
            if ($product_check['stock'] < $p_qty) { throw new Exception("Insufficient stock for {$product_check['name']}. Available: {$product_check['stock']}"); }
            $image_url = $product_check['image_url'] ?? $product_check['image_path'] ?? (isset($product_check['image']) ? (filter_var($product_check['image'], FILTER_VALIDATE_URL) ? $product_check['image'] : 'uploads/' . $product_check['image']) : null) ?? '/placeholder.svg?height=100&width=100';
            $current_cart_items = [[ 'cart_item_id' => 'direct_' . $p_id, 'product_id' => $p_id, 'quantity' => $p_qty, 'name' => $product_check['name'], 'price' => $product_check['price'], 'stock' => $product_check['stock'], 'seller_id' => $product_check['seller_id'], 'seller_name' => $product_check['seller_name'] ?? 'Unknown Seller', 'image_url' => $image_url ]];
            $order_subtotal = $product_check['price'] * $p_qty; $order_item_count = $p_qty;

        } elseif (isset($_POST['selected_items'])) { // Cart checkout logic... (as before)
            // Use FILTER_SANITIZE_SPECIAL_CHARS instead of FILTER_SANITIZE_STRING
            $selected_ids_str = filter_input(INPUT_POST, 'selected_items', FILTER_SANITIZE_SPECIAL_CHARS);
            $final_selected_cart_ids = !empty($selected_ids_str) ? explode(',', $selected_ids_str) : [];
            $final_selected_cart_ids = array_map('intval', array_filter($final_selected_cart_ids, 'is_numeric'));
            if (empty($final_selected_cart_ids)) { throw new Exception("No selected cart items found for order."); }
            $placeholders = rtrim(str_repeat('?,', count($final_selected_cart_ids)), ',');
            $sql = "SELECT ci.id as cart_item_id, ci.quantity, ci.product_id, p.name, p.price, p.stock, p.image_url, p.image_path, p.image, s.id as seller_id, u.username as seller_name FROM cart_items ci JOIN products p ON ci.product_id = p.id LEFT JOIN sellers s ON p.seller_id = s.id LEFT JOIN users u ON s.user_id = u.id WHERE ci.id IN ($placeholders) AND ci.user_id = ? AND (p.archived = 0 OR p.archived IS NULL) ORDER BY p.id FOR UPDATE";
            $stmt = $pdo->prepare($sql); $params = array_merge($final_selected_cart_ids, [$user_id]); $stmt->execute($params); $fetched_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($fetched_items) !== count($final_selected_cart_ids)) { throw new Exception("Some selected cart items are no longer available or products are archived."); }
            foreach ($fetched_items as $item) {
                if ($item['stock'] < $item['quantity']) { throw new Exception("Insufficient stock for {$item['name']}. Available: {$item['stock']}"); }
                $order_subtotal += $item['price'] * $item['quantity']; $order_item_count += $item['quantity'];
                $image_url = $item['image_url'] ?? $item['image_path'] ?? (isset($item['image']) ? (filter_var($item['image'], FILTER_VALIDATE_URL) ? $item['image'] : 'uploads/' . $item['image']) : null) ?? '/placeholder.svg?height=100&width=100';
                $current_cart_items[] = array_merge($item, ['image_url' => $image_url]);
            }
        } else { throw new Exception("Could not determine items for checkout. Missing item data."); }

        if (empty($current_cart_items)) { throw new Exception("No valid items to checkout."); }

        // Reduce stock (as before)
        foreach ($current_cart_items as $item) {
            $update_stock = $pdo->prepare("UPDATE products SET stock = stock - :quantity WHERE id = :product_id");
            if (!$update_stock->execute([':quantity' => $item['quantity'], ':product_id' => $item['product_id']])) { throw new Exception("Failed to update stock for product: {$item['name']}"); }
            echo "<script>console.log('[v0] Stock reduced for product {$item['product_id']}: -{$item['quantity']}');</script>";
        }

        // Calculate final order totals (as before)
        $order_shipping_fee = $order_subtotal >= 500 ? 0 : 50; $order_tax = $order_subtotal * 0.12; $order_total = $order_subtotal + $order_shipping_fee + $order_tax;

        $stmt = $pdo->prepare("
            INSERT INTO orders (
                user_id, name, email, phone, address, barangay, city, province, region, country, zip,
                total_amount, status, status_description, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'Your order has been placed and is waiting for seller confirmation.', NOW())
        ");
        $result = $stmt->execute([
            $user_id, $full_name, $email_form, $phone, $address, $barangay,
            $city, $province, $region, $country, $zip_code, $order_total
        ]);
        // *******************************************************************

        if (!$result) { throw new Exception("Failed to create order record. SQL Error: " . implode(' ', $stmt->errorInfo())); } // More detailed error
        $order_id = $pdo->lastInsertId();
        echo "<script>console.log('[v0] Order created with ID: " . $order_id . "');</script>";

        // Insert order items & group for notifications (as before)
        $sellers_items_for_notif = [];
        foreach ($current_cart_items as $item) {
            if (empty($item['seller_id'])) { throw new Exception("Missing seller_id for product: " . $item['name']); }
            $stmt_item = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, seller_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $item_result = $stmt_item->execute([$order_id, $item['product_id'], $item['quantity'], $item['price'], $item['seller_id']]);
            if (!$item_result) { throw new Exception("Failed to create order item for product: " . $item['name']); }
            $seller_id_notif = $item['seller_id'];
            if (!isset($sellers_items_for_notif[$seller_id_notif])) { $sellers_items_for_notif[$seller_id_notif] = ['seller_name' => $item['seller_name'], 'items' => []]; }
            $sellers_items_for_notif[$seller_id_notif]['items'][] = $item;
        }
        echo "<script>console.log('[v0] Order items inserted successfully');</script>";

        // Create initial order status history (as before)
        $stmt_hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, updated_by, created_at) VALUES (?, 'pending', 'Order placed by customer', ?, NOW())");
        $stmt_hist->execute([$order_id, $user['username'] ?? 'Customer']);

        // Send notifications (as before)
        foreach ($sellers_items_for_notif as $seller_id => $seller_data) {
             $item_names = array_map(function($i) { return $i['name']; }, $seller_data['items']); $items_text = implode(', ', $item_names); $message = "New order #$order_id received! Items: $items_text.";
             $stmt_seller_user = $pdo->prepare("SELECT user_id FROM sellers WHERE id = ?"); $stmt_seller_user->execute([$seller_id]); $seller_user_id = $stmt_seller_user->fetchColumn();
             if ($seller_user_id) {
                 $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, related_id, is_read, created_at) VALUES (?, 'order', 'New Order Received', ?, ?, 0, NOW())");
                 $stmt_notif->execute([$seller_user_id, $message, $order_id]);
                 echo "<script>console.log('[v0] Notification sent to seller user_id: $seller_user_id');</script>";
             } else { error_log("Warning: Could not find user_id for seller_id: $seller_id for order notification."); }
        }

        // Remove selected items from cart if it was a cart checkout (as before)
        if (!$is_order_direct_buy && !empty($final_selected_cart_ids)) {
            $placeholders_del = rtrim(str_repeat('?,', count($final_selected_cart_ids)), ',');
            $stmt_del = $pdo->prepare("DELETE FROM cart_items WHERE id IN ($placeholders_del) AND user_id = ?");
            $params_del = array_merge($final_selected_cart_ids, [$user_id]);
            if (!$stmt_del->execute($params_del)) { error_log("Failed to delete cart items for user $user_id, order $order_id. Items: " . implode(',', $final_selected_cart_ids)); }
            else { echo "<script>console.log('[v0] Cart items removed successfully');</script>"; }
        } else if ($is_order_direct_buy) { echo "<script>console.log('[v0] Direct buy - no cart items to remove');</script>"; }

        // Commit transaction
        $pdo->commit();

        // Redirect to receipt page
        header('Location: receipt.php?order_id=' . $order_id);
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("Checkout Error: UserID=$user_id | Error: " . $e->getMessage());
        // Display user-friendly error message
        echo "<div style='background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 1rem; margin: 1rem; border-radius: .25rem;'>";
        echo "<strong>Error occurred during checkout:</strong><br>" . htmlspecialchars($e->getMessage()) . "<br>Please check item availability or try again. Contact support if the problem persists.</div>";
        echo "<script>console.error('[Checkout Error] " . addslashes($e->getMessage()) . "');</script>";
         // Re-enable button
         echo "<script>const placeOrderBtn = document.getElementById('placeOrderBtn'); if (placeOrderBtn) { placeOrderBtn.disabled = false; placeOrderBtn.innerHTML = '<i class=\"fas fa-credit-card mr-2\"></i> Place Order - ₱" . number_format($total, 2) . "'; }</script>";
    }
}


// =================================================================
// BARANGAY LIST PHP
// =================================================================
$barangays_guimba = [ 'Agcano', 'Ayos Lomboy', 'Bacayao', 'Bagong Barrio', 'Balbalino', 'Balingog East', 'Balingog West', 'Banitan', 'Bantug', 'Bulakid', 'Bunol', 'Caballero', 'Cabaruan', 'Caingin Tabing Ilog', 'Calem', 'Camiling', 'Cardinal', 'Casongsong', 'Catimon', 'Cavite', 'Cawayan Bugtong', 'Consuelo', 'Culong', 'Escano', 'Faigal', 'Galvan', 'Guiset', 'Lamorito', 'Lennec', 'Macamias', 'Macapabellag', 'Macatcatuit', 'Manacsac', 'Manggang Marikit', 'Maturanoc', 'Maybubon', 'Naglabrahan', 'Nagpandayan', 'Narvacan I', 'Narvacan II', 'Pacac', 'Partida I', 'Partida II', 'Pasong Inchic', 'Saint John District (Pob.)', 'San Agustin', 'San Andres', 'San Bernardino', 'San Marcelino', 'San Miguel', 'San Rafael', 'San Roque', 'Santa Ana', 'Santa Cruz', 'Santa Lucia', 'Santa Veronica District (Pob.)', 'Santo Cristo District (Pob.)', 'Saranay District (Pob.)', 'Sinulatan', 'Subol', 'Tampac I', 'Tampac II & III', 'Triala', 'Yuson' ];
sort($barangays_guimba);
$saved_barangay = $user['default_barangay'] ?? '';
// =================================================================

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo.png">

    <style>
        html, body { height: 100%; margin: 0; padding: 0; display: flex; flex-direction: column; }
        header { position: fixed; top: 0; left: 0; width: 100%; height: 5.5rem; background-color: #2f855a; z-index: 50; display: flex; align-items: center; }
        header > .container { width: 100%; }
        .cart-number { position: absolute; top: -8px; right: -8px; background-color: red; color: white; border-radius: 50%; font-size: 10px; width: 18px; height: 18px; display: flex; justify-content: center; align-items: center; font-weight: bold; }
        a[data-title] { position: relative; }
        a[data-title]::after { content: attr(data-title); position: absolute; bottom: -28px; left: 50%; transform: translateX(-50%); margin-top: 6px; background-color: rgba(0, 0, 0, 0.75); color: #fff; padding: 4px 8px; border-radius: 4px; white-space: nowrap; font-size: 11px; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out; z-index: 100; }
        a[data-title]:hover::after { opacity: 1; visibility: visible; }
        body { padding-top: 5.5rem; display: flex; flex-direction: column; min-height: 100vh; background-color: #f7fafc; /* Tailwind gray-100 */ }
        main { flex-grow: 1; margin-top: 0; padding-bottom: 2rem; }
        footer { background-color: #2f855a; color: white; text-align: center; padding: 1.5rem 0; z-index: 30; width: 100%; }
        #editAddressModal .bg-white { max-height: 85vh; overflow-y: auto; }
        select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill="black" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>'); background-repeat: no-repeat; background-position: right 0.7em top 50%; background-size: 1.2em auto; padding-right: 2.5em; }
        input:focus, select:focus, button:focus { outline: none; box-shadow: 0 0 0 2px #fff, 0 0 0 4px theme('colors.green.500'); } /* Tailwind v2 style focus */
        button:focus-visible { outline: 2px solid theme('colors.green.500'); outline-offset: 2px; } /* Keyboard focus */
        label:has(input[type="radio"]:checked) { background-color: #f0fdf4; border-color: #16a34a; }
    </style>
</head>

<body class="bg-gray-100">
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

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-8"> <h1 class="text-3xl font-bold text-green-800 mb-2">Checkout</h1> <nav aria-label="Breadcrumb" class="text-sm text-gray-600"> <ol class="list-none p-0 inline-flex"> <li class="flex items-center"> <a href="home.php" class="hover:text-green-600 hover:underline">Home</a> <svg class="fill-current w-3 h-3 mx-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z"/></svg> </li> <?php if (!$is_direct_buy): ?> <li class="flex items-center"> <a href="cart.php" class="hover:text-green-600 hover:underline">Cart</a> <svg class="fill-current w-3 h-3 mx-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z"/></svg> </li> <?php endif; ?> <li class="flex items-center"> <span class="text-green-600 font-medium" aria-current="page">Checkout</span> </li> </ol> </nav> </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <section class="lg:col-span-2" aria-labelledby="shipping-info-heading">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 id="shipping-info-heading" class="text-xl font-semibold text-gray-800 mb-6 flex items-center"> <i class="fas fa-shipping-fast mr-3 text-green-600 text-2xl" aria-hidden="true"></i> Shipping Information </h2>
                        
                        <div class="border border-gray-200 rounded-lg p-4 mb-6 w-full bg-gray-50"> 
                            <div class="flex justify-between items-start flex-col gap-4"> 
                                <div class="flex items-start gap-3 w-full"> 
                                    <i class="fas fa-map-marker-alt text-red-500 text-xl mt-1 flex-shrink-0" aria-hidden="true"></i> 
                                    <div class="flex-1"> 
                                        <h3 class="text-base font-semibold text-gray-800 mb-1">Delivery Address</h3> 
                                        <div class="text-sm text-gray-700 space-y-0.5"> 
                                            <div class="flex items-center gap-2 flex-wrap"> 
                                                <span id="display_full_name" class="font-medium"><?php echo htmlspecialchars($user['full_name'] ?? $username); ?></span> 
                                                <span class="text-gray-400">|</span> 
                                                <span id="display_phone"><?php echo htmlspecialchars($user['default_phone'] ?? 'No phone'); ?></span> 
                                            </div> 
                                            <p id="display_full_address" class="text-gray-600"> 
                                                <?php $purok = htmlspecialchars($user['default_address'] ?? 'No Purok'); $brgy = htmlspecialchars($user['default_barangay'] ?? 'No Barangay'); echo $purok . ', ' . $brgy . ', Guimba, Nueva Ecija 3115'; ?> 
                                            </p> 
                                        </div> 
                                    </div> 
                                </div> 
                                <!-- Made Change Address button larger, more visible, and easier to click -->
                                <button type="button" onclick="showEditAddressModal()" class="w-full md:w-auto bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 transition duration-200"> 
                                    <i class="fas fa-edit" aria-hidden="true"></i>Change Address 
                                </button> 
                            </div> 
                        </div>
                        <fieldset class="mt-8"> <legend class="text-lg font-semibold text-gray-800 mb-3 flex items-center"> <i class="fas fa-wallet text-green-600 mr-2" aria-hidden="true"></i>Payment Method </legend> <div class="space-y-3"> <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:border-green-500 has-[:checked]:bg-green-50 has-[:checked]:border-green-600 transition-colors"> <input type="radio" name="payment_method_option" value="COD" checked class="accent-green-600 h-4 w-4 mr-3 focus:ring-green-500 focus:ring-offset-1"> <span class="text-sm font-medium text-gray-700">Cash on Delivery (COD)</span> </label> </div> </fieldset>
                        <form id="checkoutForm" method="POST" class="hidden" aria-hidden="true"> <input type="hidden" name="action" value="place_order"> <input type="hidden" name="full_name" id="form_full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? $username); ?>"> <input type="hidden" name="email" id="form_email" value="<?php echo htmlspecialchars($user['email'] ?? $email); ?>"> <input type="hidden" name="phone" id="form_phone" value="<?php echo htmlspecialchars($user['default_phone'] ?? ''); ?>"> <input type="hidden" name="address" id="form_address" value="<?php echo htmlspecialchars($user['default_address'] ?? ''); ?>"> <input type="hidden" name="barangay" id="form_barangay" value="<?php echo htmlspecialchars($user['default_barangay'] ?? ''); ?>"> <?php if (!$is_direct_buy && !empty($selected_cart_ids)): ?> <input type="hidden" name="selected_items" value="<?php echo htmlspecialchars(implode(',', $selected_cart_ids)); ?>"> <?php endif; ?> <?php if ($is_direct_buy && isset($product_id) && isset($quantity)): ?> <input type="hidden" name="direct_buy" value="1"> <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product_id); ?>"> <input type="hidden" name="quantity" value="<?php echo htmlspecialchars($quantity); ?>"> <?php endif; ?> </form>
                    </div>
                </section>

                <aside class="lg:col-span-1" aria-labelledby="order-summary-heading">
                    <div class="bg-white rounded-lg shadow-md p-6 sticky top-24">
                        <h2 id="order-summary-heading" class="text-xl font-semibold text-gray-800 mb-5 flex items-center"> <i class="fas fa-receipt mr-3 text-green-600 text-2xl" aria-hidden="true"></i>Order Summary </h2>
                        <div class="space-y-3 mb-5 max-h-60 overflow-y-auto pr-2 border-b pb-3"> <?php if (!empty($cart_items)): ?> <?php foreach ($cart_items as $item): ?> <div class="flex items-center space-x-3 py-2"> <?php $display_image_url = $item['image_url'] ?? '/placeholder.svg?height=60&width=60'; ?> <img src="<?php echo htmlspecialchars($display_image_url); ?>" alt="<?php echo htmlspecialchars($item['name'] ?? 'Product Image'); ?>" class="w-12 h-12 object-cover rounded flex-shrink-0 border" onerror="this.onerror=null; this.src='/placeholder.svg?height=60&width=60';"> <div class="flex-1 min-w-0"> <h4 class="font-medium text-sm text-gray-800 truncate" title="<?php echo htmlspecialchars($item['name'] ?? ''); ?>"><?php echo htmlspecialchars($item['name'] ?? 'N/A'); ?></h4> <p class="text-xs text-gray-600">Qty: <?php echo htmlspecialchars($item['quantity'] ?? 0); ?></p> </div> <div class="text-right flex-shrink-0"> <p class="font-semibold text-sm text-green-600">₱<?php echo number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 0), 2); ?></p> </div> </div> <?php endforeach; ?> <?php else: ?> <p class="text-center text-gray-500 py-4">Your checkout is empty.</p> <?php endif; ?> </div>
                         <?php if (!empty($cart_items)): ?> <div class="pt-1 space-y-2 text-sm"> <div class="flex justify-between"> <span class="text-gray-600">Subtotal (<?php echo $item_count; ?> item<?php echo $item_count !== 1 ? 's' : ''; ?>):</span> <span class="font-medium text-gray-800">₱<?php echo number_format($subtotal, 2); ?></span> </div> <div class="flex justify-between"> <span class="text-gray-600">Shipping:</span> <span class="font-medium text-gray-800"> <?php if ($shipping_fee == 0 && $subtotal > 0): ?> <span class="text-green-600">FREE</span> <?php else: ?> ₱<?php echo number_format($shipping_fee, 2); ?> <?php endif; ?> </span> </div> <div class="flex justify-between"> <span class="text-gray-600">Tax (12%):</span> <span class="font-medium text-gray-800">₱<?php echo number_format($tax, 2); ?></span> </div> <div class="border-t pt-3 mt-3 flex justify-between text-base font-bold"> <span class="text-gray-900">Total:</span> <span class="text-green-600">₱<?php echo number_format($total, 2); ?></span> </div> </div>
                        <button type="submit" form="checkoutForm" id="placeOrderBtn" class="w-full mt-6 bg-green-600 hover:bg-green-700 disabled:bg-green-300 disabled:cursor-not-allowed text-white font-bold py-3 px-4 rounded-lg transition duration-300 shadow-md focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-opacity-75" <?php echo empty($cart_items) ? 'disabled' : ''; ?> > <i class="fas fa-credit-card mr-2" aria-hidden="true"></i> Place Order - ₱<?php echo number_format($total, 2); ?> </button>
                        <div class="mt-4 text-center"> <p class="text-xs text-gray-500 flex items-center justify-center"> <i class="fas fa-lock mr-1.5" aria-hidden="true"></i>Your payment information is secure. </p> </div>
                         <?php else: ?> <button type="button" disabled class="w-full mt-6 bg-gray-300 cursor-not-allowed text-gray-500 font-bold py-3 px-4 rounded-lg shadow-md"> Add Items to Cart to Order </button> <?php endif; ?>
                    </div>
                </aside>
            </div>

<!-- Improved Edit Address Modal with better styling and layout -->
<div id="editAddressModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-[70] p-4" role="dialog" aria-modal="true" aria-labelledby="editAddressModalTitle">
    <div class="bg-white p-6 md:p-8 rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 id="editAddressModalTitle" class="text-2xl font-bold text-green-700">Edit Delivery Address</h2>
            <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-800 text-2xl font-bold" aria-label="Close Edit Address Modal">&times;</button>
        </div>
        
        <div class="space-y-4">
            <div> 
                <label for="modal_full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label> 
                <input type="text" id="modal_full_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent" value="<?php echo htmlspecialchars($user['full_name'] ?? $username); ?>"> 
            </div>
            
            <div> 
                <label for="modal_phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label> 
                <input type="tel" id="modal_phone" maxlength="11" placeholder="0XXXXXXXXXX" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent" oninput="validatePhoneInput(this)" value="<?php echo htmlspecialchars($user['default_phone'] ?? ''); ?>"> 
                <small id="phone_error" class="text-red-600 text-xs mt-1 hidden"></small> 
            </div>
            
            <div> 
                <label for="modal_address" class="block text-sm font-medium text-gray-700 mb-1">Purok / Street / Building</label> 
                <input type="text" id="modal_address" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent" value="<?php echo htmlspecialchars($user['default_address'] ?? ''); ?>"> 
                <small id="address_error" class="text-red-600 text-xs mt-1 hidden">Address details must be at least 5 characters.</small> 
            </div>
            
            <div> 
                <label for="modal_barangay" class="block text-sm font-medium text-gray-700 mb-1">Barangay</label> 
                <select id="modal_barangay" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"> 
                    <option value="">Select Barangay</option> 
                    <?php foreach ($barangays_guimba as $barangay_option): ?> 
                        <option value="<?php echo htmlspecialchars($barangay_option); ?>" <?php echo ($barangay_option === $saved_barangay) ? 'selected' : ''; ?>> 
                            <?php echo htmlspecialchars($barangay_option); ?> 
                        </option> 
                    <?php endforeach; ?> 
                </select> 
                <small id="barangay_error" class="text-red-600 text-xs mt-1 hidden">Please select a valid barangay.</small> 
            </div>
        </div>

        <div class="flex gap-3 mt-6">
            <!-- Added Save button with proper styling and Cancel button for better UX -->
            <button onclick="updateAddress()" class="flex-1 bg-green-600 text-white py-2.5 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-opacity-75 transition duration-150 font-semibold"> 
                <i class="fas fa-save mr-2"></i>Save Address
            </button>
            <button onclick="closeEditModal()" class="flex-1 bg-gray-300 text-gray-800 py-2.5 rounded-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-opacity-75 transition duration-150 font-semibold"> 
                <i class="fas fa-times mr-2"></i>Cancel
            </button>
        </div>
    </div>
</div>
</main>

    <footer>
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-sm">
                <div> <h3 class="text-base font-semibold mb-3 uppercase tracking-wider">About Wastewise</h3> <p class="text-gray-300 leading-relaxed">Committed to promoting sustainability through innovative recycling solutions and an eco-friendly marketplace.</p> </div>
                <div> <h3 class="text-base font-semibold mb-3 uppercase tracking-wider">Quick Links</h3> <ul class="space-y-2"> <li><a href="home.php" class="text-gray-300 hover:text-white hover:underline">Home</a></li> <li><a href="about.php" class="text-gray-300 hover:text-white hover:underline">About Us</a></li> <li><a href="contact-uss.php" class="text-gray-300 hover:text-white hover:underline">Contact</a></li> <li><a href="faq.php" class="text-gray-300 hover:text-white hover:underline">FAQ</a></li> </ul> </div>
                <div> <h3 class="text-base font-semibold mb-3 uppercase tracking-wider">Connect With Us</h3> <div class="flex space-x-4"> <a href="#" aria-label="Facebook" class="text-gray-300 hover:text-white text-xl"><i class="fab fa-facebook-f"></i></a> <a href="#" aria-label="Twitter" class="text-gray-300 hover:text-white text-xl"><i class="fab fa-twitter"></i></a> <a href="#" aria-label="Instagram" class="text-gray-300 hover:text-white text-xl"><i class="fab fa-instagram"></i></a> <a href="#" aria-label="LinkedIn" class="text-gray-300 hover:text-white text-xl"><i class="fab fa-linkedin-in"></i></a> </div> </div>
            </div>
            <div class="border-t border-green-600 mt-6 pt-6 text-center text-xs text-gray-300"> <p>&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p> </div>
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

    function showEditAddressModal() {
        console.log("[v0] showEditAddressModal called");
        
        // Hide all error messages first
        document.getElementById('phone_error').classList.add('hidden');
        document.getElementById('address_error').classList.add('hidden');
        document.getElementById('barangay_error').classList.add('hidden');
        
        // Get display elements
        const displayFullName = document.getElementById('display_full_name');
        const displayPhone = document.getElementById('display_phone');
        const displayFullAddress = document.getElementById('display_full_address');
        
        console.log("[v0] Display values - Name:", displayFullName?.textContent, "Phone:", displayPhone?.textContent, "Address:", displayFullAddress?.textContent);
        
        // Populate modal fields with current values
        const fullNameInput = document.getElementById('modal_full_name');
        const phoneInput = document.getElementById('modal_phone');
        const addressInput = document.getElementById('modal_address');
        
        if (fullNameInput) fullNameInput.value = displayFullName?.textContent || '';
        if (phoneInput) phoneInput.value = displayPhone?.textContent || '';
        
        // Extract purok/street from the full address (before the first comma)
        if (addressInput && displayFullAddress) {
            const addressText = displayFullAddress.textContent;
            const purok = addressText.split(',')[0].trim();
            addressInput.value = purok;
            console.log("[v0] Extracted purok:", purok);
        }
        
        // Show the modal
        const modal = document.getElementById('editAddressModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            console.log("[v0] Modal shown successfully");
        } else {
            console.error("[v0] Modal element not found!");
        }
    }
    
    function closeEditModal() {
        console.log("[v0] closeEditModal called");
        const modal = document.getElementById('editAddressModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
        document.getElementById('phone_error').classList.add('hidden');
        document.getElementById('address_error').classList.add('hidden');
        document.getElementById('barangay_error').classList.add('hidden');
    }

    // Define valid prefixes globally
    const validPrefixes = [ '905', '906', '915', '916', '917', '926', '927', '935', '936', '937', '945', '955', '956', '965', '966', '967', '975', '976', '977', '995', '996', '997', '907', '908', '909', '910', '911', '912', '913', '914', '918', '919', '920', '921', '922', '923', '924', '925', '928', '929', '930', '931', '932', '933', '934', '938', '939', '942', '943', '946', '947', '948', '949', '950', '951', '960', '961', '963', '964', '968', '969', '970', '981', '989', '998', '999', '991', '992', '993', '994' ];

    // ======================================================
    // UPDATED PHONE oninput VALIDATION (Shows '09' error immediately)
    // ======================================================
     function validatePhoneInput(input) {
         let value = input.value;
         const finalError = document.getElementById('phone_error');

         finalError.classList.add('hidden'); // Hide error initially

         value = value.replace(/[^0-9]/g, ''); // Remove non-digits

         if (value.length > 0 && value.charAt(0) !== '0') { value = '0'; } // Force start with '0'
         if (value.length > 11) { value = value.substring(0, 11); } // Limit length

         // Real-time '09' Check
         if (value.length >= 2 && !value.startsWith('09')) {
             finalError.textContent = "Phone number must start with 09."; // Set the specific message
             finalError.classList.remove('hidden'); // Show the error immediately
         }

         input.value = value; // Update input field

         // Optional: Final validation feedback when 11 digits typed
         if (value.length === 11) {
             const finalPrefix = value.substring(1, 4);
              if (!value.startsWith('09')) {
                   finalError.textContent = "Phone number must start with 09.";
                   finalError.classList.remove('hidden');
              } else if (!validPrefixes.includes(finalPrefix)) {
                  finalError.textContent = "Invalid phone number. Please enter a valid number.";
                  finalError.classList.remove('hidden');
             }
         }
     }
    // ======================================================
    // END OF UPDATED PHONE oninput VALIDATION
    // ======================================================

    // ======================================================
    // START OF STRICT FINAL VALIDATION (Address Modal) with AJAX
    // ADDED ADDRESS LENGTH CHECK
    // ======================================================
    function updateAddress() {
        console.log("[v0] updateAddress called");
        
        const fullName = document.getElementById('modal_full_name').value.trim();
        const phone = document.getElementById('modal_phone').value.trim();
        const address = document.getElementById('modal_address').value.trim(); // Purok/Street
        const barangaySelect = document.getElementById('modal_barangay');
        const barangay = barangaySelect.value;
        const selectedBarangayText = barangay ? barangaySelect.options[barangaySelect.selectedIndex].text : '';

        const phoneError = document.getElementById('phone_error');
        const addressError = document.getElementById('address_error');
        const barangayError = document.getElementById('barangay_error');

        phoneError.classList.add('hidden');
        addressError.classList.add('hidden');
        barangayError.classList.add('hidden');

        let isValid = true;

        // --- Client-Side Validation ---
        const phonePrefix = phone.substring(1, 4);
        if (phone.length !== 11) {
            phoneError.textContent = "Phone number must be exactly 11 digits long.";
            phoneError.classList.remove('hidden'); isValid = false;
        } else if (!phone.startsWith('09')) {
            phoneError.textContent = "Phone number must start with 09.";
            phoneError.classList.remove('hidden'); isValid = false;
        } else if (!validPrefixes.includes(phonePrefix)) {
             phoneError.textContent = "Invalid phone number. Please enter a valid number.";
             phoneError.classList.remove('hidden'); isValid = false;
        }

        if (address.length < 5) {
            addressError.textContent = "Address details must be at least 5 characters.";
            addressError.classList.remove('hidden'); isValid = false;
        }

        if (barangay === "") {
            barangayError.classList.remove('hidden'); isValid = false;
        }

        // --- If Client-Side Validation Passes, Send to Server ---
        if (isValid) {
            console.log("[v0] Validation passed, sending to server");
            
            const formData = new FormData();
            formData.append('fullName', fullName);
            formData.append('phone', phone);
            formData.append('address', address); // Purok
            formData.append('barangay', barangay);

            // Make sure update_address.php exists in the same directory or adjust the path
            fetch('update_address.php', { method: 'POST', body: formData })
            .then(response => {
                 if (!response.ok) { // Check for HTTP errors (like 404 Not Found)
                      throw new Error(`HTTP error! status: ${response.status}`);
                 }
                 return response.json(); // Expect a JSON response
            })
            .then(data => {
                console.log("[v0] Server response:", data);
                
                if (data.success) {
                    document.getElementById('display_full_name').textContent = fullName;
                    document.getElementById('display_phone').textContent = phone;
                    document.getElementById('display_full_address').textContent = `${address}, ${selectedBarangayText}, Guimba, Nueva Ecija 3115`;
                    document.getElementById('form_full_name').value = fullName;
                    document.getElementById('form_phone').value = phone;
                    document.getElementById('form_address').value = address;
                    document.getElementById('form_barangay').value = barangay;
                    alert(data.message || 'Address updated successfully!');
                    closeEditModal();
                } else {
                    alert('Error: ' + (data.message || 'Could not update address.'));
                    // Optionally handle specific server-side validation errors here
                }
            })
            .catch(error => {
                console.error('Update Address Fetch Error:', error);
                alert('An error occurred while updating the address. Please ensure update_address.php exists and check the console.');
            });
        }
    }
    // ======================================================
    // END OF STRICT FINAL VALIDATION with AJAX
    // ======================================================

    // Dropdown toggle for user menu
    function toggleDropdown() {
        const dropdown = document.getElementById('userMenuDropdown');
        const button = document.querySelector('button[onclick="toggleDropdown()"]');
        const isHidden = dropdown.getAttribute('aria-hidden') === 'true';
        
        dropdown.setAttribute('aria-hidden', !isHidden);
        button.setAttribute('aria-expanded', !isHidden);
        
        if (!isHidden) { // If it was visible and is now being hidden
            // Add event listener to close on outside click when it's visible
            document.addEventListener('click', closeDropdownOnClickOutside);
        } else {
            // Remove the listener if it's already hidden to prevent duplicates
            document.removeEventListener('click', closeDropdownOnClickOutside);
        }
    }

    function closeDropdownOnClickOutside(event) {
        const dropdown = document.getElementById('userMenuDropdown');
        const button = document.querySelector('button[onclick="toggleDropdown()"]');
        
        // Check if the click was outside the dropdown menu AND the button
        if (!dropdown.contains(event.target) && !button.contains(event.target)) {
            dropdown.setAttribute('aria-hidden', 'true');
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdownOnClickOutside);
        }
    }

    // Close modals on outside click
    window.addEventListener('click', function(event) {
      const profileModal = document.getElementById('profileModal');
      const logoutModal = document.getElementById('logoutModal');
      const editAddressModal = document.getElementById('editAddressModal');
      if (event.target === profileModal) { closeModal(); }
      if (event.target === logoutModal) { closeLogoutModal(); }
      if (event.target === editAddressModal) { closeEditModal(); }
    });

     // Add loading state to Place Order button
     const checkoutForm = document.getElementById('checkoutForm');
     const placeOrderBtn = document.getElementById('placeOrderBtn');
     if(checkoutForm && placeOrderBtn) {
         checkoutForm.addEventListener('submit', function(e) {
             if (placeOrderBtn.disabled) { e.preventDefault(); return; }
             placeOrderBtn.disabled = true;
             placeOrderBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Placing Order...';
         });
     }

     // Ensure payment method reflects initial checked state on load
     document.addEventListener('DOMContentLoaded', function() {
          const checkedPaymentMethod = document.querySelector('input[name="payment_method_option"]:checked');
          if (checkedPaymentMethod) {
               // This assumes there's a hidden input for payment method that needs to be updated
               // If not, this line might need adjustment or removal
               // document.getElementById('form_payment_method').value = checkedPaymentMethod.value;
          }
     });

function toggleSidebar() {
    const sidebar = document.getElementById('settingsSidebar');
    sidebar.classList.toggle('translate-x-full');
  }

  function openProfileModal(event) {
    event.preventDefault();
    document.getElementById('profileModal').classList.remove('hidden');
    // Ensure sidebar is closed if profile modal is opened from it
    const sidebar = document.getElementById('settingsSidebar');
    if (!sidebar.classList.contains('translate-x-full')) {
        toggleSidebar();
    }
  }

  function closeModal() {
    document.getElementById('profileModal').classList.add('hidden');
  }

  function openLogoutModal() {
    document.getElementById('logoutModal').classList.remove('hidden');
    // Ensure sidebar is closed if logout modal is opened from it
    const sidebar = document.getElementById('settingsSidebar');
    if (!sidebar.classList.contains('translate-x-full')) {
        toggleSidebar();
    }
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }

    </script>
</body>
</html>
