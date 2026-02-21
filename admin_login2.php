<?php
function handle_admin_login($db, $testMode = false) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = $db->real_escape_string($_POST['username']);
        $password = $_POST['password'];

        if (empty($username) || empty($password)) {
            $error = "Both username and password are required.";
        } else {
            $hashed_password = hash('sha256', $password);
            $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ? AND is_admin = 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            $rows = $result->fetch_all(MYSQLI_ASSOC);
            if (count($rows) === 1) {
                $user = $rows[0];
                if ($hashed_password === $user['password']) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = 1;

                    if (!$testMode) {
                        header("Location: admin_panel.php");
                        exit();
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        }
    }

    return $error;
}
