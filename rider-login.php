<?php
session_start();
require 'db_connection.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: rider-dashboard.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember_me = isset($_POST['remember_me']) ? true : false;

        // Validation
        if (empty($email)) {
            $error = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please provide a valid email address.";
        } elseif (empty($password)) {
            $error = "Password is required.";
        } else {
            // Check if user exists with role 'rider'
            $stmt = $pdo->prepare("
                SELECT u.*, r.status 
                FROM users u 
                LEFT JOIN riders r ON u.id = r.user_id 
                WHERE u.email = ? AND u.role = 'rider'
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Check if rider is approved
                if ($user['status'] === 'approved') {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = 'rider';

                    // Remember me functionality
                    if ($remember_me) {
                        setcookie('user_email', $email, time() + (30 * 24 * 60 * 60), '/'); // 30 days
                    }

                    header("Location: rider-dashboard.php");
                    exit;
                } elseif ($user['status'] === 'pending') {
                    $error = "Your rider account is pending approval. Please check your email for updates.";
                } else {
                    $error = "Your rider account has been rejected. Please contact support for more information.";
                }
            } else {
                $error = "Invalid email or password. Please try again.";
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Check for remember me cookie
$remembered_email = isset($_COOKIE['user_email']) ? $_COOKIE['user_email'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Login - Wastewise</title>
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
        /* Responsive background animation */
        .animated-bg {
            background: linear-gradient(-45deg, #4CAF50, #2E7D32, #388E3C, #1B5E20);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        /* Card shadow effect */
        .card-shadow {
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        footer {
            background-color: #2f855a;
            color: white;
            text-align: center;
            padding: 1rem 0;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-green-700 text-white py-4 md:py-6">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4">
                <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
                    <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
                    <h1 class="text-xl md:text-2xl font-bold">Wastewise</h1>
                </div>
                <div class="text-sm md:text-base">
                    <p class="text-gray-200 mb-2">Don't have a rider account yet?</p>
                    <a href="rider_register.php" class="text-green-200 hover:text-white font-semibold transition">
                        <i class="fas fa-user-plus mr-1"></i> Register as Rider
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow container mx-auto px-4 py-8 md:py-12">
        <div class="max-w-md mx-auto">
            <!-- Login Card -->
            <div class="bg-white rounded-lg card-shadow overflow-hidden">
                <!-- Card Header -->
                <div class="bg-gradient-to-r from-green-600 to-green-700 p-6 md:p-8 text-white text-center">
                    <div class="mb-4">
                        <i class="fas fa-motorcycle text-4xl md:text-5xl"></i>
                    </div>
                    <h2 class="text-2xl md:text-3xl font-bold mb-2">Rider Login</h2>
                    <p class="text-green-100 text-sm md:text-base">Log in to manage your deliveries</p>
                </div>

                <!-- Card Body -->
                <div class="p-6 md:p-8">
                    <?php if ($error): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm md:text-base"><?php echo $error; ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-5">
                        <!-- Email Field -->
                        <div>
                            <label for="email" class="block text-gray-700 text-sm font-bold mb-2">
                                <i class="fas fa-envelope mr-2 text-green-600"></i>Email Address
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4">
                                    <i class="fas fa-at text-gray-400"></i>
                                </span>
                                <input type="email" id="email" name="email" 
                                       class="w-full pl-12 pr-4 py-3 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500 transition text-sm md:text-base"
                                       placeholder="your@email.com"
                                       value="<?php echo htmlspecialchars($remembered_email); ?>"
                                       required>
                            </div>
                        </div>

                        <!-- Password Field -->
                        <div>
                            <label for="password" class="block text-gray-700 text-sm font-bold mb-2">
                                <i class="fas fa-lock mr-2 text-green-600"></i>Password
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4">
                                    <i class="fas fa-key text-gray-400"></i>
                                </span>
                                <input type="password" id="password" name="password" 
                                       class="w-full pl-12 pr-12 py-3 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500 transition text-sm md:text-base"
                                       placeholder="••••••••"
                                       required>
                                <span class="absolute inset-y-0 right-0 flex items-center pr-4 password-toggle-icon cursor-pointer" 
                                      onclick="togglePasswordVisibility('password')">
                                    <i class="fas fa-eye text-gray-400" id="password-toggle-icon"></i>
                                </span>
                            </div>
                        </div>

                        <!-- Remember Me & Forgot Password -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 text-sm md:text-base">
                            <label class="flex items-center text-gray-700">
                                <input type="checkbox" name="remember_me" class="w-4 h-4 text-green-600 rounded focus:ring-green-500">
                                <span class="ml-2">Remember me</span>
                            </label>
                            <a href="rider_forgot_password.php" class="text-green-600 hover:text-green-700 font-semibold transition">
                                Forgot Password?
                            </a>
                        </div>

                        <!-- Login Button -->
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200 transform hover:scale-105 flex items-center justify-center gap-2 text-sm md:text-base">
                            <i class="fas fa-sign-in-alt"></i>
                            <span>Login to Your Account</span>
                        </button>
                    </form>

                    <!-- Divider -->
                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-white text-gray-500">OR</span>
                        </div>
                    </div>

                    <!-- Register Link -->
                    <div class="text-center">
                        <p class="text-gray-600 text-sm md:text-base">
                            Don't have a rider account?
                            <a href="rider_register.php" class="text-green-600 hover:text-green-700 font-bold transition">
                                Register Here
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Card Footer -->
                <div class="bg-gray-50 border-t border-gray-200 px-6 md:px-8 py-4">
                    <p class="text-gray-600 text-xs md:text-sm text-center">
                        <i class="fas fa-shield-alt text-green-600 mr-1"></i>
                        Your account is secure and protected by industry-standard encryption.
                    </p>
                </div>
            </div>

            <!-- Additional Info -->
            <div class="mt-6 md:mt-8">
                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-900 mb-1">New to Wastewise?</h3>
                            <p class="text-xs md:text-sm text-blue-800">
                                Join our network of riders and start earning while helping the environment. 
                                <a href="rider_register.php" class="font-semibold hover:text-blue-700 transition">Register Now</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-green-800 text-white py-6 md:py-8 mt-auto">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8 mb-6">
                <div>
                    <h3 class="text-lg font-semibold mb-4">About Wastewise</h3>
                    <p class="text-sm text-green-100">Committed to a sustainable future through recycling and eco-friendly delivery services.</p>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="index.php" class="text-green-100 hover:text-white transition">Home</a></li>
                        <li><a href="rider_register.php" class="text-green-100 hover:text-white transition">Join as Rider</a></li>
                        <li><a href="about.php" class="text-green-100 hover:text-white transition">About Us</a></li>
                        <li><a href="contact-us.php" class="text-green-100 hover:text-white transition">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Connect With Us</h3>
                    <div class="flex space-x-4">
                        <a href="#" class="text-green-100 hover:text-white transition text-lg"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-green-100 hover:text-white transition text-lg"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-green-100 hover:text-white transition text-lg"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-green-100 hover:text-white transition text-lg"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
            </div>
            <div class="border-t border-green-700 pt-6 text-center">
                <p class="text-sm text-green-100">&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
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

        // Auto-focus email field
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById('email');
            if (emailInput && !emailInput.value) {
                emailInput.focus();
            }
        });
    </script>
</body>
</html>
