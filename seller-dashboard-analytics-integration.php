<?php
// Start session if not already started
if (session_status() == PHP_SESSION_INACTIVE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Define constants
define('SELLER_PANEL', true);

// Redirect to the analytics page
header('Location: seller_analytics.php');
exit();
?>
