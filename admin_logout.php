<?php
session_start();

session_unset();
session_destroy();

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login page
header("Location: admin_login.php");
exit();