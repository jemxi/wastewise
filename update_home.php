<?php
// Add this to the header section of home.php, inside the navigation links div
// This goes in the Center: Navigation Buttons section

// Replace:
// <div class="flex justify-center w-full md:w-1/3 gap-24 text-base">
//   <a href="home.php" data-title="Home" class="hover:text-gray-300">
//     <i class="fas fa-home"></i>
//   </a>
//   <a href="my_orders.php" data-title="My Orders" class="hover:text-gray-300">
//     <i class="fas fa-shopping-bag"></i>
//   </a>
//   <a href="cart.php" data-title="Cart" class="hover:text-gray-300">
//     <i class="fas fa-shopping-cart"></i>
//   </a>
// </div>

// With:
?>
<div class="flex justify-center w-full md:w-1/3 gap-16 text-base">
  <a href="home.php" data-title="Home" class="hover:text-gray-300">
    <i class="fas fa-home"></i>
  </a>
  <a href="my_orders.php" data-title="My Orders" class="hover:text-gray-300">
    <i class="fas fa-shopping-bag"></i>
  </a>
  <a href="cart.php" data-title="Cart" class="hover:text-gray-300">
    <i class="fas fa-shopping-cart"></i>
  </a>
  <a href="seller-search.php" data-title="Find Sellers" class="hover:text-gray-300">
    <i class="fas fa-store"></i>
  </a>
</div>
<?php
// Also add this to the product display section to show seller information
// Add this inside the product card, before the price display:

// <p class="text-gray-600 mb-2"><?= htmlspecialchars(substr($product['description'], 0, 100)) . '...'; ?></p>

// Add this after that line:
?>
<?php if (isset($product['seller_id']) && $product['seller_id']): ?>
  <?php 
    $seller_stmt = $db->prepare("SELECT business_name FROM sellers WHERE id = ?");
    $seller_stmt->bind_param("i", $product['seller_id']);
    $seller_stmt->execute();
    $seller_result = $seller_stmt->get_result();
    $seller = $seller_result->fetch_assoc();
  ?>
  <?php if ($seller): ?>
    <p class="text-sm text-blue-600 mb-2">
      <a href="seller-shop.php?id=<?= $product['seller_id'] ?>" class="hover:underline">
        <i class="fas fa-store mr-1"></i>Sold by: <?= htmlspecialchars($seller['business_name']) ?>
      </a>
    </p>
  <?php endif; ?>
<?php endif; ?>
