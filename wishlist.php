<?php
session_start();
$db = new mysqli('localhost', 'root', '', 'wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Function to get wishlist items
function getWishlistItems($user_id) {
    global $db;
    $query = "SELECT w.*, p.name, p.price, p.image, p.stock
              FROM wishlist w 
              JOIN products p ON w.product_id = p.id 
              WHERE w.user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Handle wishlist updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        
        if ($_POST['action'] === 'remove') {
            $stmt = $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
        }
        
        // Redirect to prevent form resubmission
        header("Location: wishlist.php");
        exit();
    }
}

$wishlist_items = getWishlistItems($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Wishlist - Wastewise E-commerce</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans">
    <!-- Sidebar Toggle Button -->
    <button class="fixed top-4 left-4 z-50 bg-green-600 text-white p-2 rounded-full shadow-lg" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <nav class="fixed top-0 left-0 h-full w-64 bg-green-800 text-white p-5 transform -translate-x-full transition-transform duration-200 ease-in-out z-40" id="sidebar">
        <div class="flex flex-col h-full">
            <div class="flex-grow">
                <a href="home.php" class="block py-2 px-4 hover:bg-green-700 rounded transition duration-200">
                    <i class="fas fa-home mr-2"></i>
                    <span>Home</span>
                </a>
                <a href="wishlist.php" class="block py-2 px-4 hover:bg-green-700 rounded transition duration-200">
                    <i class="fas fa-heart mr-2"></i>
                    <span>Wishlist</span>
                </a>
                <a href="cart.php" class="block py-2 px-4 hover:bg-green-700 rounded transition duration-200">
                    <i class="fas fa-shopping-cart mr-2"></i>
                    <span>Cart</span>
                </a>
            </div>
            <div>
                <span class="block py-2 px-4">
                    <i class="fas fa-user mr-2"></i>
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                </span>
                <a href="logout.php" class="block py-2 px-4 hover:bg-green-700 rounded transition duration-200">
                    <i class="fas fa-sign-out-alt mr-2"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-8 text-center text-green-800">Your Wishlist</h1>
        <?php if (empty($wishlist_items)): ?>
            <div class="text-center">
                <p class="text-xl text-gray-600 mb-4">Your wishlist is empty.</p>
                <a href="home.php" class="bg-green-500 text-white px-6 py-2 rounded-full hover:bg-green-600 transition duration-300">Continue Shopping</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($wishlist_items as $item): ?>
                    <div class="bg-white rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow duration-300">
                        <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-48 object-cover">
                        <div class="p-4">
                            <h3 class="text-xl font-semibold mb-2"><?= htmlspecialchars($item['name']) ?></h3>
                            <p class="text-gray-600 mb-4">₱<?= number_format($item['price'], 2) ?></p>
                            <div class="flex justify-between items-center">
                                <?php if ($item['stock'] > 0): ?>
                                    <form action="add_to_cart.php" method="POST">
                                        <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded-full hover:bg-green-600 transition duration-300">Add to Cart</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-red-600">Out of Stock</span>
                                <?php endif; ?>
                                <form action="wishlist.php" method="POST">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Remove</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="bg-green-800 text-white py-8 mt-12">
        <div class="container mx-auto px-4 text-center">
            <p>&copy; 2023 Wastewise E-commerce. All rights reserved.</p>
            <p class="mt-2">Committed to a sustainable future through recycling and eco-friendly shopping.</p>
        </div>
    </footer>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>

