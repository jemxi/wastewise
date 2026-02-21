<?php
session_start();
require 'db_connection.php';
require 'email_functions.php'; // For email functionality

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is already logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_data = null;

if ($is_logged_in) {
  // Get user data
  try {
      $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->execute([$_SESSION['user_id']]);
      $user_data = $stmt->fetch();
      
      // Check if user is already a seller
      $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
      $stmt->execute([$_SESSION['user_id']]);
      $existing_seller = $stmt->fetch();
      
      if ($existing_seller) {
          // Redirect to seller dashboard if already registered as seller
          header("Location: seller-dashboard.php");
          exit;
      }
  } catch (PDOException $e) {
      $error = "Database error: " . $e->getMessage();
  }
}

// Get business types for dropdown
try {
  $stmt = $pdo->prepare("SELECT * FROM business_types ORDER BY name");
  $stmt->execute();
  $business_types = $stmt->fetchAll();
} catch (PDOException $e) {
  $business_types = [];
  $error = "Failed to load business types: " . $e->getMessage();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  try {
      // Get form data
      $username = isset($_POST['username']) ? trim($_POST['username']) : '';
      $email = isset($_POST['email']) ? trim($_POST['email']) : '';
      $password = isset($_POST['password']) ? $_POST['password'] : '';
      $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
      
      // Business information
      $business_name = trim($_POST['business_name']);
      $business_type = trim($_POST['business_type']);
      $tax_id = trim($_POST['tax_id']);
      $business_address = trim($_POST['business_address']);
      $city = trim($_POST['city']);
      $state = trim($_POST['state']);
      $postal_code = trim($_POST['postal_code']);
      $country = trim($_POST['country'] ?? 'Philippines');
      $phone_number = trim($_POST['phone_number']);
      $website = trim($_POST['website'] ?? '');
      $description = trim($_POST['description'] ?? '');
      
      // Validation
      $validation_errors = [];
      
      // If not logged in, validate user registration fields
      if (!$is_logged_in) {
          if (empty($username)) {
              $validation_errors[] = "Username is required.";
          }
          
          if (empty($email)) {
              $validation_errors[] = "Email is required.";
          } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
              $validation_errors[] = "Please provide a valid email address.";
          }
          
          if (empty($password)) {
              $validation_errors[] = "Password is required.";
          } elseif (strlen($password) < 8) {
              $validation_errors[] = "Password must be at least 8 characters long.";
          }
          
          if ($password !== $confirm_password) {
              $validation_errors[] = "Passwords do not match.";
          }
          
          // Check if username or email already exists
          $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
          $stmt->execute([$username, $email]);
          $existing_user = $stmt->fetch();
          
          if ($existing_user) {
              $validation_errors[] = "Username or email already exists.";
          }
      }
      
      // Validate business information
      if (empty($business_name)) {
          $validation_errors[] = "Business name is required.";
      }
      
      if (empty($business_type)) {
          $validation_errors[] = "Business type is required.";
      }
      
      if (empty($business_address)) {
          $validation_errors[] = "Business address is required.";
      }
      
      if (empty($city)) {
          $validation_errors[] = "City is required.";
      }
      
      if (empty($state)) {
          $validation_errors[] = "State/Province is required.";
      }
      
      if (empty($postal_code)) {
          $validation_errors[] = "Postal code is required.";
      }
      
      // Enhanced phone number validation
      if (empty($phone_number)) {
          $validation_errors[] = "Phone number is required.";
      } else {
          // Remove any non-digit characters for validation
          $clean_phone = preg_replace('/\D/', '', $phone_number);
          
          // Check if it's exactly 10 digits and starts with 9
          if (!preg_match('/^9[0-9]{9}$/', $clean_phone)) {
              $validation_errors[] = "Please enter a valid Philippine mobile number with exactly 10 digits, starting with 9 (e.g., 9XXXXXXXXX). Only numbers are allowed. Must be 10 digits and start with 9.";
          } else {
              // Use the cleaned phone number
              $phone_number = $clean_phone;
          }
      }
      
      // Check if phone number is verified
      if (!isset($_SESSION['phone_verified']) || $_SESSION['phone_verified'] !== true || 
          !isset($_SESSION['verified_phone']) || $_SESSION['verified_phone'] !== $phone_number) {
          $validation_errors[] = "Phone number must be verified before submitting the form.";
      }
      
      if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
          $validation_errors[] = "Please provide a valid website URL.";
      }
      
      // Check if there are any validation errors
      if (!empty($validation_errors)) {
          $error = implode("<br>", $validation_errors);
      } else {
          // Begin transaction
          $pdo->beginTransaction();
          
          $user_id = null;
          
          // If not logged in, create a new user account
          if (!$is_logged_in) {
              // Hash the password
              $hashed_password = password_hash($password, PASSWORD_DEFAULT);
              
              // Get current timestamp
              $created_at = date('Y-m-d H:i:s');
              
              // Insert user directly into users table with role = 'seller'
              $stmt = $pdo->prepare("
                  INSERT INTO users (
                      username, 
                      email, 
                      password, 
                      created_at,
                      is_verified,
                      name,
                      role
                  ) VALUES (?, ?, ?, ?, 1, ?, 'seller')
              ");
              
              $result = $stmt->execute([
                  $username,
                  $email,
                  $hashed_password,
                  $created_at,
                  $username // Using username as name for simplicity
              ]);
              
              if (!$result) {
                  throw new Exception("Failed to create user account.");
              }
              
              // Get the new user ID
              $user_id = $pdo->lastInsertId();
              
              // Send welcome email
              $welcome_email_sent = sendWelcomeEmail($email, $username);
              
              if (!$welcome_email_sent) {
                  // Just log the error, don't stop the process
                  error_log("Failed to send welcome email to $email");
              }
          } else {
              // Use existing user ID
              $user_id = $_SESSION['user_id'];
              
              // Update existing user's role to 'seller'
              $stmt = $pdo->prepare("UPDATE users SET role = 'seller' WHERE id = ?");
              $stmt->execute([$user_id]);
          }
          
          // Format phone number with country code for storage
          $formatted_phone = "+63" . $phone_number;
          
          // Insert seller information
          $stmt = $pdo->prepare("
              INSERT INTO sellers (
                  user_id,
                  business_name,
                  business_type,
                  tax_id,
                  business_address,
                  city,
                  state,
                  postal_code,
                  country,
                  phone_number,
                  website,
                  description,
                  status
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
          ");
          
          $result = $stmt->execute([
              $user_id,
              $business_name,
              $business_type,
              $tax_id,
              $business_address,
              $city,
              $state,
              $postal_code,
              $country,
              $formatted_phone,
              $website,
              $description
          ]);
          
          if (!$result) {
              throw new Exception("Failed to create seller account.");
          }
          
          // Get the seller ID
          $seller_id = $pdo->lastInsertId();
          
          if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
              $upload_dir = 'uploads/seller_documents/';
              
              // Create directory if it doesn't exist
              if (!file_exists($upload_dir)) {
                  mkdir($upload_dir, 0755, true);
              }
              
              $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
              $max_size = 5 * 1024 * 1024; // 5MB
              
              $tmp_name = $_FILES['valid_id']['tmp_name'];
              $type = $_FILES['valid_id']['type'];
              $size = $_FILES['valid_id']['size'];
              
              // Validate file type and size
              if (!in_array($type, $allowed_types)) {
                  throw new Exception("Invalid file type. Only JPEG, PNG, and PDF files are allowed.");
              }
              
              if ($size > $max_size) {
                  throw new Exception("File size exceeds the limit of 5MB.");
              }
              
              // Generate unique filename
              $filename = uniqid('valid_id_') . '_' . basename($_FILES['valid_id']['name']);
              $destination = $upload_dir . $filename;
              
              // Move uploaded file
              if (move_uploaded_file($tmp_name, $destination)) {
                  $document_url = str_replace('\\', '/', $destination); // Normalize path separators
                  
                  // Save document information to database with document_type = 'Valid ID'
                  $stmt = $pdo->prepare("
                      INSERT INTO seller_documents (
                          seller_id,
                          document_type,
                          document_url
                      ) VALUES (?, ?, ?)
                  ");
                  
                  $stmt->execute([
                      $seller_id,
                      'Valid ID',
                      $document_url
                  ]);
              } else {
                  throw new Exception("Failed to upload Valid ID document.");
              }
          } else {
              throw new Exception("Valid ID document is required to complete your seller registration.");
          }
          
          // Commit transaction
          $pdo->commit();
          
          // Clear phone verification session
          unset($_SESSION['phone_verified']);
          unset($_SESSION['verified_phone']);
          
          // Set success message
          $success = "Thank you for submitting your seller application! Your application is now pending admin approval. This process typically takes 1-3 days. We will send you an email once your account has been verified.";
          
          // If not logged in, log the user in automatically
          if (!$is_logged_in) {
              $_SESSION['user_id'] = $user_id;
              $_SESSION['username'] = $username;
              $_SESSION['email'] = $email;
              $_SESSION['role'] = 'seller'; // Set the role in the session
          } else {
              // Update the role in the session for existing users
              $_SESSION['role'] = 'seller';
          }
          
          header("Location: seller-dashboard.php");
          exit;
      }
  } catch (PDOException $e) {
      // Rollback transaction on error
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      $error = "Database error: " . $e->getMessage();
  } catch (Exception $e) {
      // Rollback transaction on error
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      $error = $e->getMessage();
  }
}

// Helper function to send welcome email
function sendWelcomeEmail($email, $username) {
  // This is a placeholder. You should implement this in your email_functions.php file
  // or replace with your actual email sending logic
  return true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seller Registration - Wastewise</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
      .bg-wastewise {
          background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
      }
      .text-wastewise {
          color: #4CAF50;
      }
      .password-toggle-icon {
          cursor: pointer;
          color: #9CA3AF;
      }
      .password-toggle-icon:hover {
          color: #4B5563;
      }
      .password-strength {
          height: 5px;
          transition: all 0.3s ease;
      }
      .password-requirements li {
          margin-bottom: 4px;
          display: flex;
          align-items: center;
      }
      .password-requirements li i {
          margin-right: 5px;
      }
      .requirement-met {
          color: #10B981;
      }
      .requirement-not-met {
          color: #9CA3AF;
      }
      .step-indicator {
          display: flex;
          justify-content: center;
          margin-bottom: 2rem;
      }
      .step {
          display: flex;
          flex-direction: column;
          align-items: center;
          width: 33.333%;
      }
      .step-circle {
          width: 30px;
          height: 30px;
          border-radius: 50%;
          background-color: #E5E7EB;
          display: flex;
          align-items: center;
          justify-content: center;
          font-weight: bold;
          color: #6B7280;
          margin-bottom: 0.5rem;
          position: relative;
          z-index: 10;
      }
      .step-title {
          font-size: 0.875rem;
          color: #6B7280;
      }
      .step.active .step-circle {
          background-color: #4CAF50;
          color: white;
      }
      .step.active .step-title {
          color: #4CAF50;
          font-weight: bold;
      }
      .step.completed .step-circle {
          background-color: #4CAF50;
          color: white;
      }
      .step-connector {
          flex-grow: 1;
          height: 2px;
          background-color: #E5E7EB;
          margin-top: 15px;
      }
      .step-connector.active {
          background-color: #4CAF50;
      }
      /* Philippines flag CSS */
      .ph-flag {
          position: relative;
          width: 20px;
          height: 10px;
          background: linear-gradient(to bottom, #0038A8 50%, #CE1126 50%);
          overflow: hidden;
      }
      .ph-flag::before {
          content: '';
          position: absolute;
          left: 0;
          top: 0;
          width: 0;
          height: 0;
          border-style: solid;
          border-width: 5px 0 5px 10px;
          border-color: transparent transparent transparent #FFFFFF;
      }
      .ph-flag::after {
          content: '';
          position: absolute;
          left: 3px;
          top: 2px;
          width: 6px;
          height: 6px;
          background: #FCD116;
          border-radius: 50%;
      }
      /* Phone validation styles */
      .phone-validation {
          font-size: 0.75rem;
          margin-top: 0.25rem;
      }
      .validation-item {
          display: flex;
          align-items: center;
          margin-bottom: 0.25rem;
      }
      .validation-item i {
          margin-right: 0.5rem;
          width: 12px;
      }
      .validation-valid {
          color: #10B981;
      }
      .validation-invalid {
          color: #EF4444;
      }
      /* Loading spinner */
      .spinner {
          border: 2px solid #f3f3f3;
          border-top: 2px solid #3498db;
          border-radius: 50%;
          width: 16px;
          height: 16px;
          animation: spin 1s linear infinite;
          display: inline-block;
          margin-right: 8px;
      }
      @keyframes spin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
      }
       footer {
          background-color: #2f855a;
          color: white;
          text-align: center;
          padding: 1rem 0;
          z-index: 30;
      }
      /* Truck driving animation */
@keyframes drive {
  0%   { transform: translateX(-120px); }
  100% { transform: translateX(120px); }
}

.animate-truck {
  animation: drive 3s linear infinite;
}

/* Road movement */
@keyframes roadMove {
  0%   { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

.animate-road {
  animation: roadMove 1s linear infinite;
  background: repeating-linear-gradient(
    to right,
    #9ca3af 0 20px,
    #374151 20px 40px
  );
}
  </style>
</head>
<body class="bg-gray-100 min-h-screen">
    
    <!-- Waste Truck Preloader Modal -->
<div id="preloader-modal" class="fixed inset-0 bg-white bg-opacity-90 flex items-center justify-center z-50">
  <!-- Truck + Road -->
  <div id="truck-scene" class="flex flex-col items-center">
    <!-- Truck with waste icon -->
    <div id="preloader-truck" class="relative text-green-600 text-7xl animate-truck">
      <i class="fas fa-truck-moving"></i>
      <!-- Waste/Recycling Logo overlay -->
      <i class="fas fa-recycle absolute text-white text-2xl left-6 top-2"></i>
    </div>

    <!-- Road -->
    <div id="road" class="w-64 h-1 bg-gray-700 mt-2 relative overflow-hidden">
      <div class="line absolute top-0 left-0 w-full h-full bg-gray-400 opacity-60 animate-road"></div>
    </div>
  </div>

  <!-- Check (hidden at start) -->
  <div id="preloader-check" class="hidden text-green-600 text-6xl">
    <i class="fas fa-check-circle"></i>
  </div>
</div>
    
  <!-- Header -->
 <header class="bg-green-700 text-white py-6">
<div class="container mx-auto px-4">
  <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4">
    <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
      <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
      <h1 class="text-2xl font-bold">Wastewise</h1>
    </div>
          <div>
              <?php if ($is_logged_in): ?>
                  <span class="text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                  <a href="logout.php" class="text-red-600 hover:text-red-800">
                      <i class="fas fa-sign-out-alt mr-1"></i> Logout
                  </a>
              <?php else: ?>
                  <a href="seller-login.php" class="text-white-600 hover:text-green-800 mr-4">
                      <i class="fas fa-sign-in-alt mr-1"></i> Login
                  </a>
               
                  </a>
              <?php endif; ?>
          </div>
      </div>
  </header>

  <!-- Main Content -->
  <main class="container mx-auto px-4 py-8 max-w-5xl">
      <div class="bg-white p-8 rounded-lg shadow-lg">
          <div class="text-center mb-8">
              <h1 class="text-4xl font-bold text-gray-800">Become a Seller</h1>
              <p class="text-gray-600 mt-2">Join Wastewise as a seller and start growing your business</p>
          </div>
          
          <?php if ($error): ?>
              <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                  <p><?php echo $error; ?></p>
              </div>
          <?php endif; ?>

          <?php if ($success): ?>
              <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                  <div class="flex items-center mb-4">
                      <div class="flex-shrink-0">
                          <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                      </div>
                      <div class="ml-4">
                          <h3 class="text-xl font-medium text-green-800">Application Submitted Successfully!</h3>
                      </div>
                  </div>
                  <p class="mb-3"><?php echo $success; ?></p>
                  <div class="bg-white p-4 rounded-lg border border-green-200 mb-4">
                      <h4 class="font-medium text-gray-800 mb-2">What happens next?</h4>
                      <ol class="list-decimal list-inside text-sm space-y-2 text-gray-700">
                          <li>Our admin team will review your application (1-3 business days)</li>
                          <li>You'll receive an email notification once your account is approved</li>
                          <li>After approval, you can log in and start setting up your seller profile</li>
                      </ol>
                  </div>
                  <div class="mt-4">
                      <a href="home.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                          <i class="fas fa-home mr-2"></i> Go to Homepage
                      </a>
                  </div>
              </div>
          <?php else: ?>
              <!-- Multi-step form -->
              <div class="step-indicator mb-8">
                  <div class="step active" id="step-indicator-1">
                      <div class="step-circle">1</div>
                      <div class="step-title">Account Information</div>
                  </div>
                  <div class="step-connector" id="connector-1-2"></div>
                  <div class="step" id="step-indicator-2">
                      <div class="step-circle">2</div>
                      <div class="step-title">Business Details</div>
                  </div>
                  <div class="step-connector" id="connector-2-3"></div>
                  <div class="step" id="step-indicator-3">
                      <div class="step-circle">3</div>
                      <div class="step-title">Valid ID & Review</div>
                  </div>
              </div>

              <form action="" method="POST" class="space-y-6" enctype="multipart/form-data" id="seller-registration-form">
                  <!-- Step 1: Account Information -->
                  <div id="step-1" class="form-step">
                      <h2 class="text-2xl font-semibold text-gray-800 mb-4">Account Information</h2>
                      

                      <?php if (!$is_logged_in): ?>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                              <div>
                                  <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                                  <div class="relative">
                                      <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                          <i class="fas fa-user text-gray-400"></i>
                                      </span>
                                      <input type="text" id="username" name="username" 
                                             class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                             required>
                                  </div>
                              </div>

                              <div>
                                  <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                                  <div class="relative">
                                      <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                          <i class="fas fa-envelope text-gray-400"></i>
                                      </span>
                                      <input type="email" id="email" name="email" 
                                             class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                             required>
                                  </div>
                                  <p class="text-xs text-gray-500 mt-1">We'll use this email to notify you about your application</p>
                              </div>

                              <div>
                                  <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                                  <div class="relative">
                                      <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                          <i class="fas fa-lock text-gray-400"></i>
                                      </span>
                                      <input type="password" id="password" name="password" 
                                             class="w-full pl-10 pr-10 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                             required minlength="8"
                                             oninput="checkPasswordStrength(this.value)">
                                      <span class="absolute inset-y-0 right-0 flex items-center pr-3 password-toggle-icon" onclick="togglePasswordVisibility('password')">
                                          <i class="fas fa-eye text-gray-400" id="password-toggle-icon"></i>
                                      </span>
                                  </div>
                                  <div class="mt-1">
                                      <div class="password-strength bg-gray-200 rounded-full" id="password-strength-meter"></div>
                                  </div>
                                  <ul class="text-xs text-gray-500 mt-2 password-requirements" id="password-requirements">
                                      <li id="req-length"><i class="fas fa-circle-check requirement-not-met"></i> At least 8 characters</li>
                                  </ul>
                              </div>

                              <div>
                                  <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Confirm Password</label>
                                  <div class="relative">
                                      <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                          <i class="fas fa-lock text-gray-400"></i>
                                      </span>
                                      <input type="password" id="confirm_password" name="confirm_password" 
                                             class="w-full pl-10 pr-10 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                             required minlength="8"
                                             oninput="checkPasswordMatch()">
                                      <span class="absolute inset-y-0 right-0 flex items-center pr-3 password-toggle-icon" onclick="togglePasswordVisibility('confirm_password')">
                                          <i class="fas fa-eye text-gray-400" id="confirm_password-toggle-icon"></i>
                                      </span>
                                  </div>
                                  <p class="text-xs text-red-500 mt-1 hidden" id="password-match-error">Passwords do not match</p>
                              </div>
                          </div>
                      <?php else: ?>
                          <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-4">
                              <div class="flex items-start">
                                  <div class="flex-shrink-0">
                                      <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                                  </div>
                                  <div class="ml-3">
                                      <h3 class="text-sm font-medium text-blue-800">You're already logged in</h3>
                                      <div class="mt-2 text-sm text-blue-700">
                                          <p>You're currently logged in as <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>. Your seller account will be linked to this user account.</p>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      <?php endif; ?>
                      

                      <div class="flex justify-end mt-6">
                          <button type="button" class="px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="nextStep(1)">
                              Next <i class="fas fa-arrow-right ml-2"></i>
                          </button>
                      </div>
                  </div>
                  
                  <!-- Step 2: Business Details -->
                  <div id="step-2" class="form-step hidden">
                      <h2 class="text-2xl font-semibold text-gray-800 mb-4">Business Details</h2>
                      

                      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                          <div class="md:col-span-2">
                              <label for="business_name" class="block text-gray-700 text-sm font-bold mb-2">Business Name</label>
                              <input type="text" id="business_name" name="business_name" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                     required>
                          </div>
                          
                          <div>
                              <label for="business_type" class="block text-gray-700 text-sm font-bold mb-2">Business Type</label>
                              <select id="business_type" name="business_type" 
                                      class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                      required>
                                  <option value="">Select Business Type</option>
                                  <?php foreach ($business_types as $type): ?>
                                      <option value="<?php echo htmlspecialchars($type['name']); ?>">
                                          <?php echo htmlspecialchars($type['name']); ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          
                          <div>
                              <label for="tax_id" class="block text-gray-700 text-sm font-bold mb-2">Tax ID / Business Registration Number</label>
                              <input type="text" id="tax_id" name="tax_id" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500">
                              <p class="text-xs text-gray-500 mt-1">Optional, but required for invoice generation</p>
                          </div>
                          
                          <div class="md:col-span-2">
                              <label for="business_address" class="block text-gray-700 text-sm font-bold mb-2">Business Address</label>
                              <textarea id="business_address" name="business_address" rows="2"
                                        class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                        required></textarea>
                          </div>
                          
                          <div>
                              <label for="city" class="block text-gray-700 text-sm font-bold mb-2">City</label>
                              <input type="text" id="city" name="city" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                     required>
                          </div>
                          
                          <div>
                              <label for="state" class="block text-gray-700 text-sm font-bold mb-2">State/Province</label>
                              <input type="text" id="state" name="state" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                     required>
                          </div>
                          
                          <div>
                              <label for="postal_code" class="block text-gray-700 text-sm font-bold mb-2">Postal Code</label>
                              <input type="text" id="postal_code" name="postal_code" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                     required>
                          </div>
                          
                          <div>
                              <label for="country" class="block text-gray-700 text-sm font-bold mb-2">Country</label>
                              <input type="text" id="country" name="country" 
                                     class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                     value="Philippines" required>
                          </div>
                          
                          <div>
                              <label for="phone_number" class="block text-gray-700 text-sm font-bold mb-2">Phone Number</label>
                              <div class="relative flex items-center">
                                  <div class="absolute left-0 pl-3 flex items-center pointer-events-none">
                                      <div class="ph-flag mr-1"></div>
                                      <span class="text-gray-500">+63</span>
                                  </div>
                                  <input type="tel" id="phone_number" name="phone_number" 
                                         class="w-full pl-16 pr-24 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                         placeholder="9XXXXXXXXX"
                                         maxlength="10"
                                         oninput="validatePhoneNumber(this)"
                                         required>
                                  <button type="button" id="verify-phone-btn" 
                                          class="absolute right-2 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                      Verify
                                  </button>
                              </div>
                              
                              <!-- Real-time validation feedback -->
                              <div class="phone-validation mt-2">
                                  <div class="validation-item" id="validation-digits">
                                      <i class="fas fa-times validation-invalid"></i>
                                      <span>Exactly 10 digits (0/10)</span>
                                  </div>
                                  <div class="validation-item" id="validation-start">
                                      <i class="fas fa-times validation-invalid"></i>
                                      <span>Must start with 9</span>
                                  </div>
                                  <div class="validation-item" id="validation-numbers">
                                      <i class="fas fa-check validation-valid"></i>
                                      <span>Only numbers allowed</span>
                                  </div>
                              </div>
                              
                              <div id="phone-verification-section" class="hidden mt-2">
                                  <div class="bg-blue-50 p-3 rounded-lg border border-blue-200">
                                      <p id="verification-message" class="text-sm text-blue-800 mb-2"></p>
                                      <div class="flex items-center gap-2">
                                          <input type="text" id="verification_code" name="verification_code" 
                                                 class="w-32 px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500 text-center"
                                                 placeholder="000000" maxlength="6">
                                          <button type="button" id="submit-code-btn" 
                                                  class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                                              Verify Code
                                          </button>
                                          <button type="button" id="resend-code-btn" 
                                                  class="px-4 py-2 bg-gray-300 text-gray-700 text-sm rounded hover:bg-gray-400">
                                              Resend
                                          </button>
                                      </div>
                                      <p class="text-xs text-gray-600 mt-2">Enter the 6-digit code sent to your phone.</p>
                                  </div>
                              </div>
                              
                              <p class="text-xs text-gray-500 mt-1">Enter your mobile number to receive verification code.</p>
                              <input type="hidden" id="phone_verified" name="phone_verified" value="0">
                          </div>
                          
                          <div>
                              <label for="website" class="block text-gray-700 text-sm font-bold mb-2">Website</label>
                              <div class="relative">
                                  <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                      <i class="fas fa-globe text-gray-400"></i>
                                  </span>
                                  <input type="url" id="website" name="website" 
                                         class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                         placeholder="https://example.com">
                              </div>
                              <p class="text-xs text-gray-500 mt-1">Optional</p>
                          </div>
                          
                          <div class="md:col-span-2">
                              <label for="description" class="block text-gray-700 text-sm font-bold mb-2">Business Description</label>
                              <textarea id="description" name="description" rows="4"
                                        class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                        placeholder="Tell us about your business..."></textarea>
                              <p class="text-xs text-gray-500 mt-1">Optional</p>
                          </div>
                      </div>
                      

                      <div class="flex justify-between mt-6">
                          <button type="button" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="prevStep(2)">
                              <i class="fas fa-arrow-left mr-2"></i> Previous
                          </button>
                          <button type="button" class="px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="nextStep(2)">
                              Next <i class="fas fa-arrow-right ml-2"></i>
                          </button>
                      </div>
                  </div>
                  
                  <!-- Step 3: Valid ID & Review -->
                  <div id="step-3" class="form-step hidden">
                      <h2 class="text-2xl font-semibold text-gray-800 mb-4">Valid ID & Review</h2>
                      

                      <div class="mb-6">
                          <h3 class="text-lg font-medium text-gray-800 mb-2">Upload Valid ID</h3>
                          <p class="text-gray-600 mb-4">Please upload a government-issued ID to verify your identity. This is required to complete your seller registration. Accepted formats: JPG, PNG, PDF (Max 5MB)</p>
                          
                          <div class="space-y-4">
                              <!-- Only Valid ID upload, removed Business Registration and Additional Document -->
                              <div class="document-upload-container">
                                  <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg border border-gray-200">
                                      <div>
                                          <label class="block text-gray-700 font-medium">Valid ID *</label>
                                          <p class="text-sm text-gray-500">Government-issued ID of the business owner (Passport, Driver's License, National ID, etc.)</p>
                                      </div>
                                      <div class="flex items-center">
                                          <input type="file" name="valid_id" id="valid_id" class="hidden document-input" accept=".jpg,.jpeg,.png,.pdf" required>
                                          <label for="valid_id" class="cursor-pointer px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                              <i class="fas fa-upload mr-2"></i> Choose File
                                          </label>
                                          <span class="ml-3 text-sm text-gray-500 file-name">No file chosen</span>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      </div>
                      

                      <div class="mb-6">
                          <h3 class="text-lg font-medium text-gray-800 mb-2">Terms and Conditions</h3>
                          <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 h-40 overflow-y-auto mb-4">
                              <h4 class="font-bold">Wastewise Seller Agreement</h4>
                              <p class="text-sm">By registering as a seller on Wastewise, you agree to the following terms and conditions:</p>
                              <ol class="list-decimal list-inside text-sm mt-2 space-y-2">
                                  <li>You confirm that all information provided during registration is accurate and complete.</li>
                                  <li>You agree to maintain accurate and up-to-date information about your business and products.</li>
                                  <li>You understand that Wastewise may review your application and request additional information before approval.</li>
                                  <li>You agree to comply with all applicable laws and regulations related to your business operations.</li>
                                  <li>You understand that Wastewise charges a commission fee on sales as outlined in our fee structure.</li>
                                  <li>You agree to maintain a professional standard of service for all customers.</li>
                                  <li>You understand that Wastewise reserves the right to suspend or terminate your seller account for violations of our policies.</li>
                              </ol>
                          </div>
                          <div class="flex items-start">
                              <input type="checkbox" id="terms_agreement" name="terms_agreement" class="mt-1" required>
                              <label for="terms_agreement" class="ml-2 text-gray-700">
                                  I have read and agree to the Wastewise Seller Terms and Conditions
                              </label>
                          </div>
                      </div>
                      

                      <div class="flex justify-between mt-6">
                          <button type="button" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="prevStep(3)">
                              <i class="fas fa-arrow-left mr-2"></i> Previous
                          </button>
                          <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                              <i class="fas fa-check mr-2"></i> Submit Application
                          </button>
                      </div>
                  </div>
              </form>
          <?php endif; ?>
      </div>
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
<script src="preload.js"></script>
  <script>
      // Phone number validation function
      function validatePhoneNumber(input) {
          const value = input.value;
          const cleanValue = value.replace(/\D/g, ''); // Remove non-digits
          
          // Update input with only numbers
          if (value !== cleanValue) {
              input.value = cleanValue;
          }
          
          // Limit to 10 digits
          if (cleanValue.length > 10) {
              input.value = cleanValue.substring(0, 10);
              return;
          }
          
          // Update validation indicators
          updatePhoneValidation(input.value);
          
          // Reset verification if phone changes
          resetPhoneVerification();
      }
      
      function updatePhoneValidation(phoneNumber) {
          const digitsValidation = document.getElementById('validation-digits');
          const startValidation = document.getElementById('validation-start');
          const numbersValidation = document.getElementById('validation-numbers');
          
          // Check digits count
          const digitCount = phoneNumber.length;
          const digitsIcon = digitsValidation.querySelector('i');
          const digitsText = digitsValidation.querySelector('span');
          
          if (digitCount === 10) {
              digitsIcon.className = 'fas fa-check validation-valid';
              digitsText.textContent = 'Exactly 10 digits (10/10)';
          } else {
              digitsIcon.className = 'fas fa-times validation-invalid';
              digitsText.textContent = `Exactly 10 digits (${digitCount}/10)`;
          }
          
          // Check if starts with 9
          const startIcon = startValidation.querySelector('i');
          if (phoneNumber.startsWith('9')) {
              startIcon.className = 'fas fa-check validation-valid';
          } else {
              startIcon.className = 'fas fa-times validation-invalid';
          }
          
          // Numbers only is always valid since we filter in real-time
          const numbersIcon = numbersValidation.querySelector('i');
          numbersIcon.className = 'fas fa-check validation-valid';
      }
      
      function resetPhoneVerification() {
          const phoneVerificationSection = document.getElementById('phone-verification-section');
          const verifyPhoneBtn = document.getElementById('verify-phone-btn');
          const phoneVerifiedInput = document.getElementById('phone_verified');
          
          phoneVerificationSection.classList.add('hidden');
          phoneVerifiedInput.value = '0';
          
          // Reset verify button
          verifyPhoneBtn.innerHTML = 'Verify';
          verifyPhoneBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
          verifyPhoneBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
          verifyPhoneBtn.disabled = false;
      }

      // SMS Verification API functions (Updated for Twilio)
      async function sendVerificationCode(phoneNumber) {
          try {
              const response = await fetch('sms_verification.php', { // Pointing to sms_verification.php
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                      action: 'send_code',
                      phone_number: phoneNumber
                  })
              });
              
              const result = await response.json();
              return result;
          } catch (error) {
              return {
                  success: false,
                  message: 'Network error. Please try again.'
              };
          }
      }
      
      async function verifyCode(phoneNumber, code) {
          try {
              const response = await fetch('sms_verification.php', { // Pointing to sms_verification.php
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                      action: 'verify_code',
                      phone_number: phoneNumber,
                      code: code
                  })
              });
              
              const result = await response.json();
              return result;
          } catch (error) {
              return {
                  success: false,
                  message: 'Network error. Please try again.'
              };
          }
      }

      // Password visibility toggle
      function togglePasswordVisibility(inputId) {
          const passwordInput = document.getElementById(inputId);
          const toggleIcon = document.getElementById(inputId + '-toggle-icon');
          
          if (passwordInput.type === 'password') {
              passwordInput.type = 'text';
              toggleIcon.classList.remove('fa-eye');
              toggleIcon.classList.add('fa-eye-slash');
          } else {
              passwordInput.type = 'password';
              toggleIcon.classList.remove('fa-eye-slash');
              toggleIcon.classList.add('fa-eye');
          }
      }

      // Password strength checker
      function checkPasswordStrength(password) {
          const strengthMeter = document.getElementById('password-strength-meter');
          const lengthReq = document.getElementById('req-length');
          
          // Check length requirement
          if (password.length >= 8) {
              lengthReq.querySelector('i').classList.remove('requirement-not-met');
              lengthReq.querySelector('i').classList.add('requirement-met');
          } else {
              lengthReq.querySelector('i').classList.remove('requirement-met');
              lengthReq.querySelector('i').classList.add('requirement-not-met');
          }
          
          // Calculate strength
          let strength = 0;
          
          if (password.length >= 8) {
              strength += 25;
          }
          
          if (password.length >= 10) {
              strength += 25;
          }
          
          if (/[A-Z]/.test(password)) {
              strength += 25;
          }
          
          if (/[0-9]/.test(password)) {
              strength += 25;
          }
          
          // Update strength meter
          strengthMeter.style.width = strength + '%';
          
          // Update color based on strength
          if (strength < 25) {
              strengthMeter.style.backgroundColor = '#EF4444'; // red
          } else if (strength < 50) {
              strengthMeter.style.backgroundColor = '#F59E0B'; // amber
          } else if (strength < 75) {
              strengthMeter.style.backgroundColor = '#10B981'; // green
          } else {
              strengthMeter.style.backgroundColor = '#059669'; // emerald
          }
          
          // Check password match if confirm password has value
          if (document.getElementById('confirm_password') && document.getElementById('confirm_password').value) {
              checkPasswordMatch();
          }
      }

      // Password match checker
      function checkPasswordMatch() {
          if (!document.getElementById('password') || !document.getElementById('confirm_password')) {
              return;
          }
          
          const password = document.getElementById('password').value;
          const confirmPassword = document.getElementById('confirm_password').value;
          const errorMsg = document.getElementById('password-match-error');
          
          if (confirmPassword && password !== confirmPassword) {
              errorMsg.classList.remove('hidden');
          } else {
              errorMsg.classList.add('hidden');
          }
      }

      // Multi-step form navigation
      let currentStep = 1;
      
      function showStep(step) {
          // Hide all steps
          document.querySelectorAll('.form-step').forEach(el => {
              el.classList.add('hidden');
          });
          
          // Show current step
          document.getElementById('step-' + step).classList.remove('hidden');
          
          // Update step indicators
          for (let i = 1; i <= 3; i++) {
              const indicator = document.getElementById('step-indicator-' + i);
              
              if (i < step) {
                  indicator.classList.remove('active');
                  indicator.classList.add('completed');
              } else if (i === step) {
                  indicator.classList.add('active');
                  indicator.classList.remove('completed');
              } else {
                  indicator.classList.remove('active');
                  indicator.classList.remove('completed');
              }
              
              // Update connectors
              if (i < 3) {
                  const connector = document.getElementById('connector-' + i + '-' + (i + 1));
                  if (i < step) {
                      connector.classList.add('active');
                  } else {
                      connector.classList.remove('active');
                  }
              }
          }
      }
      
      function validateStep(step) {
          if (step === 1) {
              <?php if (!$is_logged_in): ?>
              // Validate account information
              const username = document.getElementById('username').value;
              const email = document.getElementById('email').value;
              const password = document.getElementById('password').value;
              const confirmPassword = document.getElementById('confirm_password').value;
              
              if (!username) {
                  alert('Please enter a username');
                  return false;
              }
              
              if (!email || !email.includes('@')) {
                  alert('Please enter a valid email address');
                  return false;
              }
              
              if (!password || password.length < 8) {
                  alert('Password must be at least 8 characters long');
                  return false;
              }
              
              if (password !== confirmPassword) {
                  alert('Passwords do not match');
                  return false;
              }
              <?php endif; ?>
              
              return true;
          } else if (step === 2) {
              // Validate business details
              const businessName = document.getElementById('business_name').value;
              const businessType = document.getElementById('business_type').value;
              const businessAddress = document.getElementById('business_address').value;
              const city = document.getElementById('city').value;
              const state = document.getElementById('state').value;
              const postalCode = document.getElementById('postal_code').value;
              const phoneNumber = document.getElementById('phone_number').value;
              const phoneVerified = document.getElementById('phone_verified').value;
              
              if (!businessName) {
                  alert('Please enter your business name');
                  return false;
              }
              
              if (!businessType) {
                  alert('Please select your business type');
                  return false;
              }
              
              if (!businessAddress) {
                  alert('Please enter your business address');
                  return false;
              }
              
              if (!city) {
                  alert('Please enter your city');
                  return false;
              }
              
              if (!state) {
                  alert('Please enter your state/province');
                  return false;
              }
              
              if (!postalCode) {
                  alert('Please enter your postal code');
                  return false;
              }
              
              // Enhanced phone validation
              if (!phoneNumber.match(/^9[0-9]{9}$/)) {
                  alert('Please enter a valid Philippine mobile number with exactly 10 digits, starting with 9 (e.g., 9XXXXXXXXX). Only numbers are allowed.');
                  return false;
              }
              
              if (phoneVerified !== '1') {
                  alert('Please verify your phone number before proceeding');
                  return false;
              }
              
              return true;
          }
          
          return true;
      }
      
      function nextStep(step) {
          if (validateStep(step)) {
              currentStep = step + 1;
              showStep(currentStep);
              window.scrollTo(0, 0);
          }
      }
      
      function prevStep(step) {
          currentStep = step - 1;
          showStep(currentStep);
          window.scrollTo(0, 0);
      }
      
      // File upload handling
      document.querySelectorAll('.document-input').forEach(input => {
          input.addEventListener('change', function() {
              const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
              this.closest('.document-upload-container').querySelector('.file-name').textContent = fileName;
          });
      });
      
      // Form submission validation
      document.getElementById('seller-registration-form').addEventListener('submit', function(e) {
          const termsCheckbox = document.getElementById('terms_agreement');
          
          if (!termsCheckbox.checked) {
              e.preventDefault();
              alert('You must agree to the terms and conditions to continue');
              return;
          }
          
          // Check if phone is verified
          const phoneVerified = document.getElementById('phone_verified').value;
          if (phoneVerified !== '1') {
              e.preventDefault();
              alert('Please verify your phone number before submitting');
              return;
          }
          
          // Final phone validation
          const phoneNumber = document.getElementById('phone_number').value;
          if (!phoneNumber.match(/^9[0-9]{9}$/)) {
              e.preventDefault();
              alert('Please enter a valid Philippine mobile number with exactly 10 digits, starting with 9 (e.g., 9XXXXXXXXX). Only numbers are allowed.');
              return;
          }
          
          const validIdInput = document.getElementById('valid_id');
          if (!validIdInput.files || validIdInput.files.length === 0) {
              e.preventDefault();
              alert('Please upload a Valid ID document to complete your registration.');
              return;
          }
      });
      
      // Phone verification functionality
      document.addEventListener('DOMContentLoaded', function() {
          const verifyPhoneBtn = document.getElementById('verify-phone-btn');
          const phoneVerificationSection = document.getElementById('phone-verification-section');
          const submitCodeBtn = document.getElementById('submit-code-btn');
          const resendCodeBtn = document.getElementById('resend-code-btn');
          const verificationMessage = document.getElementById('verification-message');
          const phoneInput = document.getElementById('phone_number');
          const phoneVerifiedInput = document.getElementById('phone_verified');
          const verificationCodeInput = document.getElementById('verification_code');
          
          let verificationInProgress = false;
          let phoneVerified = false;
          
          verifyPhoneBtn.addEventListener('click', async function() {
              const phoneNumber = phoneInput.value.trim();
              
              // Enhanced validation
              if (!phoneNumber || !phoneNumber.match(/^9[0-9]{9}$/)) {
                  alert('Please enter a valid Philippine mobile number with exactly 10 digits, starting with 9 (e.g., 9XXXXXXXXX). Only numbers are allowed.');
                  return;
              }
              
              // Show loading state
              verifyPhoneBtn.innerHTML = '<div class="spinner"></div>Sending on your phone number you provided...'; // Changed from Semaphore
              verifyPhoneBtn.disabled = true;
              
              // Send verification code
              const result = await sendVerificationCode(phoneNumber);
              
              if (result.success) {
                  // Show verification section
                  phoneVerificationSection.classList.remove('hidden');
                  verifyPhoneBtn.innerHTML = 'Code Sent!'; // Changed from Semaphore
                  verifyPhoneBtn.disabled = false;
                  verificationInProgress = true;
                  
                  // Show message
                  verificationMessage.textContent = result.message;
                  verificationMessage.className = 'text-sm text-green-800 mb-2';
                  
                  // Disable phone input during verification
                  phoneInput.readOnly = true;
              } else {
                  // Show error
                  alert('Error: ' + result.message);
                  verifyPhoneBtn.innerHTML = 'Verify';
                  verifyPhoneBtn.disabled = false;
              }
          });
          
          submitCodeBtn.addEventListener('click', async function() {
              const code = verificationCodeInput.value.trim();
              const phoneNumber = phoneInput.value.trim();
              
              if (!code || code.length !== 6) {
                  alert('Please enter the 6-digit verification code');
                  return;
              }
              
              // Show loading state
              submitCodeBtn.innerHTML = '<div class="spinner"></div>Verifying...';
              submitCodeBtn.disabled = true;
              
              // Verify code
              const result = await verifyCode(phoneNumber, code);
              
              if (result.success) {
                  verificationMessage.textContent = result.message;
                  verificationMessage.className = 'text-sm text-green-800 mb-2';
                  phoneVerified = true;
                  phoneVerifiedInput.value = '1';
                  
                  // Update UI to show verified state
                  verifyPhoneBtn.innerHTML = '✓ Verified on your phone number'; // Changed from Semaphore
                  verifyPhoneBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                  verifyPhoneBtn.classList.add('bg-green-600', 'hover:bg-green-700');
                  verifyPhoneBtn.disabled = true;
                  
                  // Hide verification section after a delay
                  setTimeout(function() {
                      phoneVerificationSection.classList.add('hidden');
                  }, 3000);
              } else {
                  verificationMessage.textContent = result.message;
                  verificationMessage.className = 'text-sm text-red-800 mb-2';
              }
              
              submitCodeBtn.innerHTML = 'Verify Code';
              submitCodeBtn.disabled = false; // Corrected variable name
          });
          
          resendCodeBtn.addEventListener('click', async function() {
              const phoneNumber = phoneInput.value.trim();
              
              // Show loading state
              resendCodeBtn.innerHTML = '<div class="spinner"></div>Sending...';
              resendCodeBtn.disabled = true;
              
              // Resend verification code
              const result = await sendVerificationCode(phoneNumber);
              
              if (result.success) {
                  verificationMessage.textContent = 'New verification code sent!'; // Changed from Semaphore
                  verificationMessage.className = 'text-sm text-green-800 mb-2';
                  verificationCodeInput.value = ''; // Clear previous code
              } else {
                  verificationMessage.textContent = 'Error: ' + result.message;
                  verificationMessage.className = 'text-sm text-red-800 mb-2';
              }
              
              resendCodeBtn.innerHTML = 'Resend';
              resendCodeBtn.disabled = false;
          });
          
          // Reset verification if phone number changes
          phoneInput.addEventListener('input', function() {
              if (verificationInProgress || phoneVerified) {
                  phoneInput.readOnly = false;
                  phoneVerified = false;
                  verificationInProgress = false;
                  phoneVerificationSection.classList.add('hidden');
                  phoneVerifiedInput.value = '0';
                  verificationCodeInput.value = '';
                  
                  // Reset verify button
                  verifyPhoneBtn.innerHTML = 'Verify';
                  verifyPhoneBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                  verifyPhoneBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                  verifyPhoneBtn.disabled = false;
              }
          });
          
          // Initialize phone validation on page load
          updatePhoneValidation('');
      });
      // Show truck first
function showPreloader() {
  document.getElementById("truck-scene").classList.remove("hidden");
  document.getElementById("preloader-check").classList.add("hidden");
  document.getElementById("preloader-modal").classList.remove("hidden");

  // Example only: simulate loading then success
  setTimeout(showSuccess, 3000);
}

// Show success (check only)
function showSuccess() {
  document.getElementById("truck-scene").classList.add("hidden"); // Hide truck + road
  document.getElementById("preloader-check").classList.remove("hidden"); // Show check

  // Optional: close modal after delay
  setTimeout(() => {
    document.getElementById("preloader-modal").classList.add("hidden");
  }, 1500);
}

// Auto-run demo
showPreloader();
  </script>
</body>
</html>
