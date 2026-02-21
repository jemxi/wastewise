<?php
require 'db_connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Token Debugging Tool</h1>";

// Check if we have a token to debug
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    echo "<h2>Token Information</h2>";
    echo "<p>Token: " . htmlspecialchars($token) . "</p>";
    echo "<p>Length: " . strlen($token) . "</p>";
    
    // Check if this token exists in the database
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token = ?");
        $stmt->execute([$token]);
        $exact_match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exact_match) {
            echo "<div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0;'>";
            echo "<h3>Exact Match Found!</h3>";
            echo "<p>Token ID: " . $exact_match['id'] . "</p>";
            echo "<p>User ID: " . $exact_match['user_id'] . "</p>";
            echo "<p>Created: " . $exact_match['created_at'] . "</p>";
            echo "<p>Expires: " . $exact_match['expires_at'] . "</p>";
            echo "<p>Used: " . ($exact_match['is_used'] ? 'Yes' : 'No') . "</p>";
            echo "</div>";
        } else {
            echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
            echo "<h3>No Exact Match Found</h3>";
            echo "</div>";
            
            // Check if this might be a verification code (last part of token)
            $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token LIKE ?");
            $stmt->execute(['%' . $token]);
            $partial_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($partial_matches) > 0) {
                echo "<div style='background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; margin: 10px 0;'>";
                echo "<h3>Partial Matches Found (" . count($partial_matches) . ")</h3>";
                
                foreach ($partial_matches as $match) {
                    echo "<div style='margin: 10px 0; padding: 5px; border-bottom: 1px solid #ddd;'>";
                    echo "<p>Token: " . $match['token'] . "</p>";
                    echo "<p>User ID: " . $match['user_id'] . "</p>";
                    echo "<p>Expires: " . $match['expires_at'] . "</p>";
                    echo "<p>Used: " . ($match['is_used'] ? 'Yes' : 'No') . "</p>";
                    echo "</div>";
                }
                
                echo "</div>";
            }
        }
        
        // Show all recent tokens for reference
        $stmt = $pdo->prepare("SELECT * FROM email_verifications ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $recent_tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recent_tokens) > 0) {
            echo "<h2>Recent Tokens</h2>";
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>ID</th><th>User ID</th><th>Token</th><th>Created</th><th>Expires</th><th>Used</th></tr>";
            
            foreach ($recent_tokens as $token) {
                echo "<tr>";
                echo "<td>" . $token['id'] . "</td>";
                echo "<td>" . $token['user_id'] . "</td>";
                echo "<td>" . substr($token['token'], 0, 10) . "..." . substr($token['token'], -10) . "</td>";
                echo "<td>" . $token['created_at'] . "</td>";
                echo "<td>" . $token['expires_at'] . "</td>";
                echo "<td>" . ($token['is_used'] ? 'Yes' : 'No') . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        }
        
    } catch (PDOException $e) {
        echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
        echo "<h3>Database Error</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
} else {
    echo "<p>No token provided. Add ?token=your_token to the URL to debug a specific token.</p>";
    
    // Show form to enter a token
    echo "<form method='get' action=''>";
    echo "<input type='text' name='token' placeholder='Enter token to debug' style='padding: 5px; width: 300px;'>";
    echo "<button type='submit' style='padding: 5px 10px; background-color: #007bff; color: white; border: none;'>Debug Token</button>";
    echo "</form>";
    
    // Show all recent tokens
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_verifications ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $recent_tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recent_tokens) > 0) {
            echo "<h2>Recent Tokens</h2>";
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>ID</th><th>User ID</th><th>Token</th><th>Created</th><th>Expires</th><th>Used</th></tr>";
            
            foreach ($recent_tokens as $token) {
                echo "<tr>";
                echo "<td>" . $token['id'] . "</td>";
                echo "<td>" . $token['user_id'] . "</td>";
                echo "<td>" . substr($token['token'], 0, 10) . "..." . substr($token['token'], -10) . "</td>";
                echo "<td>" . $token['created_at'] . "</td>";
                echo "<td>" . $token['expires_at'] . "</td>";
                echo "<td>" . ($token['is_used'] ? 'Yes' : 'No') . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        } else {
            echo "<p>No verification tokens found in the database.</p>";
        }
    } catch (PDOException $e) {
        echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
        echo "<h3>Database Error</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
}
?>

<div style="margin-top: 20px; padding: 10px; background-color: #f8f9fa; border: 1px solid #ddd;">
    <h3>Troubleshooting Tips</h3>
    <ul>
        <li>Make sure the token in the URL is exactly the same as in the database</li>
        <li>Check if the token has expired (compare current time with expiry time)</li>
        <li>Check if the token has already been used</li>
        <li>If using just the verification code, make sure it's the last part of the token after the dash</li>
    </ul>
</div>

