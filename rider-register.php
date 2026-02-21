<?php
session_start();
require 'db_connection.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$is_logged_in = isset($_SESSION['user_id']);
$user_data = null;
$error = '';
$success = '';

// Check if user is logged in
if ($is_logged_in) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT * FROM riders WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $existing_rider = $stmt->fetch();
        
        if ($existing_rider) {
            header("Location: rider-dashboard.php");
            exit;
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Account info
        $username = !$is_logged_in ? trim($_POST['username'] ?? '') : '';
        // Note: email_input is used if logged in, otherwise 'email' directly
        $email = !$is_logged_in ? trim($_POST['email'] ?? '') : (isset($_POST['email_input']) ? trim($_POST['email_input']) : '');
        $password = !$is_logged_in ? ($_POST['password'] ?? '') : '';
        $confirm_password = !$is_logged_in ? ($_POST['confirm_password'] ?? '') : '';
        
        // Personal info
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Vehicle info
        $vehicle_type = trim($_POST['vehicle_type'] ?? '');
        $license_plate = trim($_POST['license_plate'] ?? '');
        $orcr_number = trim($_POST['orcr_number'] ?? '');
        $driver_license_number = trim($_POST['driver_license_number'] ?? '');
        
        // Address info
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        
        $validation_errors = [];
        
        if (!$is_logged_in) {
            if (empty($username)) {
                $validation_errors[] = "Username is required.";
            }
            
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = "Valid email is required.";
            }
            
            if (empty($password) || strlen($password) < 8) {
                $validation_errors[] = "Password must be at least 8 characters.";
            }
            
            if ($password !== $confirm_password) {
                $validation_errors[] = "Passwords do not match.";
            }
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $validation_errors[] = "Username or email already exists.";
            }
        } else {
            // If logged in, only validate email
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = "Valid email is required.";
            }
        }
        
        if (empty($first_name)) $validation_errors[] = "First name is required.";
        if (empty($last_name)) $validation_errors[] = "Last name is required.";
        if (empty($vehicle_type)) $validation_errors[] = "Vehicle type is required.";
        if (empty($orcr_number)) $validation_errors[] = "ORCR number is required.";
        if (empty($driver_license_number)) $validation_errors[] = "Driver license number is required.";
        if (empty($address)) $validation_errors[] = "Address is required.";
        if (empty($city)) $validation_errors[] = "City is required.";
        if (empty($province)) $validation_errors[] = "Province is required.";
        
        $clean_phone = preg_replace('/\D/', '', $phone);
        if (empty($clean_phone) || !preg_match('/^9[0-9]{9}$/', $clean_phone)) {
            $validation_errors[] = "Valid Philippine phone number required (10 digits starting with 9).";
        }
        
        // This check ensures that the phone number was successfully verified via SMS
        if (!isset($_SESSION['phone_verified']) || $_SESSION['phone_verified'] !== true) {
            $validation_errors[] = "Phone number must be verified.";
        }
        
        if (!isset($_FILES['orcr_file']) || $_FILES['orcr_file']['error'] !== UPLOAD_ERR_OK) {
            $validation_errors[] = "ORCR document is required.";
        }
        
        if (!isset($_FILES['driver_license_file']) || $_FILES['driver_license_file']['error'] !== UPLOAD_ERR_OK) {
            $validation_errors[] = "Driver license document is required.";
        }
        
        // If there are any validation errors, set the error message and stop processing
        if (!empty($validation_errors)) {
            $error = implode("<br>", $validation_errors);
        } else {
            $pdo->beginTransaction(); // Start a transaction for database operations
            
            $user_id = null;
            
            // Create or update user record
            if (!$is_logged_in) {
                // If not logged in, create a new user account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT); // Hash the password
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password, created_at, is_verified, name, role)
                    VALUES (?, ?, ?, NOW(), 1, ?, 'rider')
                ");
                
                // Execute the insert statement for users table
                if (!$stmt->execute([$username, $email, $hashed_password, "$first_name $last_name"])) {
                    throw new Exception("Failed to create user account."); // Throw exception if insert fails
                }
                
                $user_id = $pdo->lastInsertId(); // Get the newly inserted user ID
            } else {
                // If logged in, update the existing user's role and email
                $user_id = $_SESSION['user_id'];
                $stmt = $pdo->prepare("UPDATE users SET role = 'rider', email = ? WHERE id = ?");
                $stmt->execute([$email, $user_id]);
            }
            
            $upload_dir = 'uploads/rider_documents/'; // Directory to store uploaded files
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true); // Create the directory if it doesn't exist
            }
            
            $allowed_types = ['image/jpeg', 'image/png', 'application/pdf']; // Allowed file types
            $max_size = 5 * 1024 * 1024; // Maximum file size (5MB)
            
            $orcr_path = null; // Path for ORCR file
            $lic_path = null;  // Path for driver license file
            
            // Handle ORCR file upload
            if ($_FILES['orcr_file']['size'] > $max_size) {
                throw new Exception("ORCR file exceeds 5MB limit.");
            }
            if (!in_array($_FILES['orcr_file']['type'], $allowed_types)) {
                throw new Exception("ORCR must be JPG, PNG, or PDF.");
            }
            
            // Generate a unique filename for the ORCR file
            $orcr_name = 'orcr_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['orcr_file']['name'], PATHINFO_EXTENSION);
            // Move the uploaded file to the destination directory
            if (move_uploaded_file($_FILES['orcr_file']['tmp_name'], $upload_dir . $orcr_name)) {
                $orcr_path = $upload_dir . $orcr_name; // Store the path
            } else {
                throw new Exception("Failed to upload ORCR file.");
            }
            
            // Handle Driver License file upload
            if ($_FILES['driver_license_file']['size'] > $max_size) {
                throw new Exception("Driver license file exceeds 5MB limit.");
            }
            if (!in_array($_FILES['driver_license_file']['type'], $allowed_types)) {
                throw new Exception("Driver license must be JPG, PNG, or PDF.");
            }
            
            // Generate a unique filename for the driver license file
            $lic_name = 'driver_lic_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['driver_license_file']['name'], PATHINFO_EXTENSION);
            // Move the uploaded file to the destination directory
            if (move_uploaded_file($_FILES['driver_license_file']['tmp_name'], $upload_dir . $lic_name)) {
                $lic_path = $upload_dir . $lic_name; // Store the path
            } else {
                throw new Exception("Failed to upload driver license file.");
            }
            
            $formatted_phone = "+63" . $clean_phone; // Format phone number with country code
            $stmt = $pdo->prepare("
                INSERT INTO riders (
                    user_id, first_name, last_name, phone, email,
                    address, city, province, vehicle_type, license_plate,
                    orcr_number, orcr_file, driver_license_number, driver_license_file, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            // Execute the insert statement for riders table
            if (!$stmt->execute([
                $user_id, $first_name, $last_name, $formatted_phone, $email,
                $address, $city, $province, $vehicle_type, $license_plate,
                $orcr_number, $orcr_path, $driver_license_number, $lic_path
            ])) {
                throw new Exception("Failed to create rider account.");
            }
            
            $pdo->commit(); // Commit the transaction if all operations were successful
            
            // Clear session variables related to phone verification
            unset($_SESSION['phone_verified']);
            unset($_SESSION['verified_phone']);
            
            // Set success message
            $success = "Application submitted! We'll review your documents within 1-3 business days and notify you via email.";
            
            // Set session variables for logged-in user
            if (!$is_logged_in) {
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'rider';
            } else {
                $_SESSION['role'] = 'rider'; // Ensure role is set for logged-in users too
            }
            
            // Redirect to dashboard upon successful submission
            header("Location: rider-dashboard.php");
            exit;
        }
    } catch (PDOException $e) {
        // Rollback transaction if a PDO error occurs
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Rollback transaction if any other error occurs
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Registration - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; }
        .step { display: flex; flex-direction: column; align-items: center; width: 50%; }
        .step-circle { width: 30px; height: 30px; border-radius: 50%; background-color: #E5E7EB; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #6B7280; margin-bottom: 0.5rem; }
        .step-title { font-size: 0.875rem; color: #6B7280; }
        .step.active .step-circle { background-color: #4CAF50; color: white; }
        .step.active .step-title { color: #4CAF50; font-weight: bold; }
        .step.completed .step-circle { background-color: #4CAF50; color: white; }
        .step-connector { flex-grow: 1; height: 2px; background-color: #E5E7EB; margin-top: 15px; }
        .step-connector.active { background-color: #4CAF50; }
        .ph-flag { position: relative; width: 20px; height: 10px; background: linear-gradient(to bottom, #0038A8 50%, #CE1126 50%); }
        .ph-flag::before { content: ''; position: absolute; left: 0; top: 0; width: 0; height: 0; border-style: solid; border-width: 5px 0 5px 10px; border-color: transparent transparent transparent #FFFFFF; }
        .ph-flag::after { content: ''; position: absolute; left: 3px; top: 2px; width: 6px; height: 6px; background: #FCD116; border-radius: 50%; }
        .validation-valid { color: #10B981; }
        .validation-invalid { color: #EF4444; }
        footer { background-color: #2f855a; color: white; text-align: center; padding: 1rem 0; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    
    <header class="bg-green-700 text-white py-6">
        <div class="container mx-auto px-4 flex justify-between items-center">
            <div class="flex items-center space-x-2">
                <img src="logo.png" alt="Logo" class="h-8 w-8">
                <h1 class="text-2xl font-bold">Wastewise</h1>
            </div>
            <div>
                <?php if ($is_logged_in): ?>
                    <a href="logout.php" class="text-red-400 hover:text-red-300">
                        <i class="fas fa-sign-out-alt mr-1"></i> Logout
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8 max-w-4xl flex-grow">
        <div class="bg-white p-8 rounded-lg shadow-lg">
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-gray-800">Become a Rider</h1>
                <p class="text-gray-600 mt-2">Join Wastewise and start earning today</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-check-circle text-3xl mr-3"></i>
                        <h3 class="text-lg font-semibold">Success!</h3>
                    </div>
                    <p><?php echo $success; ?></p>
                    <div class="mt-4">
                        <a href="index.php" class="inline-block bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded">
                            Back to Home
                        </a>
                    </div>
                </div>
            <?php else: ?>

            <div class="step-indicator mb-8">
                <div class="step active" id="step-1-ind">
                    <div class="step-circle">1</div>
                    <div class="step-title">Account</div>
                </div>
                <div class="step-connector" id="conn-1"></div>
                <div class="step" id="step-2-ind">
                    <div class="step-circle">2</div>
                    <div class="step-title">Details & Docs</div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="riderForm">
                
                <!-- Step 1 -->
                <div id="step-1" class="form-step">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-6">Account Information</h2>
                    
                    <?php if (!$is_logged_in): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Username *</label>
                            <input type="text" name="username" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Email *</label>
                            <input type="email" name="email" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Password *</label>
                            <input type="password" name="password" id="password" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" minlength="8" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Confirm Password *</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" minlength="8" required>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6">
                        <p class="text-blue-800">Logged in as <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
                    </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">First Name *</label>
                            <input type="text" name="first_name" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Last Name *</label>
                            <input type="text" name="last_name" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Email Address *</label>
                            <input type="email" name="email_input" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Phone Number *</label>
                            <div class="relative flex items-center">
                                <div class="absolute left-3 flex items-center">
                                    <div class="ph-flag mr-2"></div>
                                    <span class="text-gray-600">+63</span>
                                </div>
                                <input type="tel" name="phone" id="phone" class="w-full pl-16 pr-20 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" placeholder="9XXXXXXXXX" maxlength="10" oninput="validatePhone(this)" required>
                                <button type="button" id="verifyBtn" class="absolute right-2 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">Verify</button>
                            </div>
                            <div id="phoneStatus" class="text-sm mt-2 space-y-1"></div>
                            <div id="verifySection" class="hidden mt-3 bg-blue-50 p-3 rounded border border-blue-200">
                                <input type="text" id="verifyCode" placeholder="6-digit code" maxlength="6" class="w-20 px-2 py-1 border rounded">
                                <button type="button" id="submitCodeBtn" class="ml-2 px-3 py-1 bg-green-600 text-white text-sm rounded hover:bg-green-700">Submit</button>
                            </div>
                            <input type="hidden" name="phone_verified" id="phoneVerified" value="0">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" onclick="nextStep()" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            Next <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 2 -->
                <div id="step-2" class="form-step hidden">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-6">Vehicle & Documents</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Vehicle Type *</label>
                            <select name="vehicle_type" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                                <option value="">Select Vehicle Type</option>
                                <option value="Motorcycle">Motorcycle</option>
                                <option value="Bicycle">Bicycle</option>
                                <option value="Tricycle">Tricycle</option>
                                <option value="Car">Car</option>
                                <option value="Van">Van</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">License Plate</label>
                            <input type="text" name="license_plate" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" placeholder="e.g., ABC 1234">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">ORCR Number *</label>
                            <input type="text" name="orcr_number" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Driver License Number *</label>
                            <input type="text" name="driver_license_number" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">City *</label>
                            <input type="text" name="city" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Province *</label>
                            <input type="text" name="province" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-semibold mb-2">Address *</label>
                        <textarea name="address" rows="3" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-green-500 outline-none" required></textarea>
                    </div>

                    <div class="mb-6 border-t pt-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Upload Documents</h3>
                        
                        <div class="space-y-4 mb-4">
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex justify-between items-center">
                                <div>
                                    <label class="block text-gray-700 font-semibold">ORCR Document *</label>
                                    <p class="text-sm text-gray-500">Upload your vehicle registration</p>
                                </div>
                                <div class="flex items-center">
                                    <input type="file" name="orcr_file" id="orcrFile" class="hidden" accept=".jpg,.jpeg,.png,.pdf" required>
                                    <label for="orcrFile" class="cursor-pointer px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                        <i class="fas fa-upload mr-2"></i>Choose
                                    </label>
                                    <span id="orcrName" class="ml-3 text-sm text-gray-600">No file</span>
                                </div>
                            </div>

                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex justify-between items-center">
                                <div>
                                    <label class="block text-gray-700 font-semibold">Driver License *</label>
                                    <p class="text-sm text-gray-500">Upload your driver license copy</p>
                                </div>
                                <div class="flex items-center">
                                    <input type="file" name="driver_license_file" id="licenseFile" class="hidden" accept=".jpg,.jpeg,.png,.pdf" required>
                                    <label for="licenseFile" class="cursor-pointer px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                        <i class="fas fa-upload mr-2"></i>Choose
                                    </label>
                                    <span id="licenseName" class="ml-3 text-sm text-gray-600">No file</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6">
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 h-40 overflow-y-auto mb-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Terms and Conditions</h4>
                            <p class="text-sm text-gray-700 mb-2">By registering as a rider, you agree to:</p>
                            <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                                <li>Provide accurate and valid documents</li>
                                <li>Maintain valid driver's license and vehicle registration</li>
                                <li>Follow traffic laws and delivery guidelines</li>
                                <li>Maintain professional conduct</li>
                                <li>Comply with Wastewise terms of service</li>
                            </ul>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" name="terms_agreement" id="terms" required>
                            <label for="terms" class="ml-2 text-gray-700">I agree to the Terms and Conditions</label>
                        </div>
                    </div>

                    <div class="flex justify-between">
                        <button type="button" onclick="prevStep()" class="px-6 py-2 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100">
                            <i class="fas fa-arrow-left mr-2"></i>Previous
                        </button>
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <i class="fas fa-check mr-2"></i>Submit Application
                        </button>
                    </div>
                </div>
            </form>

            <?php endif; ?>
        </div>
    </main>

    <footer class="bg-green-800 text-white py-6 mt-auto">
        <p>&copy; <?= date('Y') ?> Wastewise. All rights reserved.</p>
    </footer>

    <script>
        let currentStep = 1;

        function nextStep() {
            if (currentStep === 1) {
                // Validate phone number before proceeding
                const phone = document.getElementById('phone').value;
                const verified = document.getElementById('phoneVerified').value;
                if (phone.length !== 10 || !phone.startsWith('9')) {
                    alert('Please enter valid 10-digit phone');
                    return;
                }
                if (verified !== '1') {
                    alert('Please verify your phone number');
                    return;
                }
            }
            currentStep = 2;
            updateSteps();
        }

        function prevStep() {
            currentStep = 1;
            updateSteps();
        }

        function updateSteps() {
            // Show/hide the correct step
            document.getElementById('step-1').classList.toggle('hidden', currentStep !== 1);
            document.getElementById('step-2').classList.toggle('hidden', currentStep !== 2);
            
            // Update step indicators
            document.getElementById('step-1-ind').classList.toggle('active', currentStep === 1);
            document.getElementById('step-1-ind').classList.toggle('completed', currentStep > 1);
            document.getElementById('step-2-ind').classList.toggle('active', currentStep === 2);
            document.getElementById('conn-1').classList.toggle('active', currentStep > 1);
            
            window.scrollTo(0, 0); // Scroll to top when changing steps
        }

        function validatePhone(input) {
            // Allow only digits and limit to 10 characters
            input.value = input.value.replace(/\D/g, '').slice(0, 10);
        }

        // Event listener for Verify Phone button
        document.getElementById('verifyBtn').addEventListener('click', async () => {
            const phone = document.getElementById('phone').value;
            // Basic validation for phone number format
            if (phone.length !== 10 || !phone.startsWith('9')) {
                alert('Enter valid 10-digit phone number starting with 9.');
                return;
            }
            
            // Send request to SMS verification script
            const response = await fetch('sms_verification.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'send_code', phone_number: phone})
            }).then(r => r.json()).catch(() => ({success: false, message: 'Network error.'}));
            
            if (response.success) {
                document.getElementById('verifySection').classList.remove('hidden'); // Show code input section
            } else {
                alert(response.message || 'Failed to send verification code.');
            }
        });

        // Event listener for Submit Code button
        document.getElementById('submitCodeBtn').addEventListener('click', async () => {
            const code = document.getElementById('verifyCode').value;
            const phone = document.getElementById('phone').value;
            
            // Validate code length
            if (code.length !== 6) {
                alert('Please enter the 6-digit code.');
                return;
            }
            
            // Send verification code to server
            const response = await fetch('sms_verification.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'verify_code', phone_number: phone, code: code})
            }).then(r => r.json()).catch(() => ({success: false, message: 'Network error.'}));
            
            if (response.success) {
                document.getElementById('phoneVerified').value = '1'; // Mark phone as verified
                document.getElementById('verifyBtn').innerHTML = '✓ Verified'; // Update button text
                document.getElementById('verifyBtn').disabled = true; // Disable verify button
                document.getElementById('verifySection').classList.add('hidden'); // Hide code input section
            } else {
                alert(response.message || 'Invalid verification code.');
            }
        });

        // Update file name display when files are selected
        document.getElementById('orcrFile').addEventListener('change', (e) => {
            document.getElementById('orcrName').textContent = e.target.files[0]?.name || 'No file';
        });

        document.getElementById('licenseFile').addEventListener('change', (e) => {
            document.getElementById('licenseName').textContent = e.target.files[0]?.name || 'No file';
        });

        // Form submission validation for terms and conditions
        document.getElementById('riderForm').addEventListener('submit', (e) => {
            if (!document.getElementById('terms').checked) {
                e.preventDefault();
                alert('Please agree to the terms and conditions');
            }
        });
    </script>
</body>
</html>
