<?php
session_start();
header('Content-Type: application/json');

// iProg Tech SMS API configuration
define('IPROG_API_TOKEN', 'b4e284eb0815285f8b151808a523ab3f3c12a34b'); // Your iProg Tech API Token
define('IPROG_BASE_URL', 'https://sms.iprogtech.com/api/v1');

class SMSVerification {
    
    // Function to initiate sending a verification code using iProg Tech OTP API
    public function sendVerificationCode($phoneNumber, $isResend = false) {
        if ($isResend) {
            $this->clearVerificationSession();
        }
        
        // Format phone number for Philippines (+63)
        $formattedPhone = '09' . substr($phoneNumber, 1); // Convert 9xxxxxxxxx to 09xxxxxxxxx
        
        $url = IPROG_BASE_URL . "/otp/send_otp";
        
        $data = [
            'api_token' => IPROG_API_TOKEN,
            'phone_number' => $formattedPhone,
            'message' => 'WasteWise: Your OTP code is :otp. It is valid for 5 minutes. Do not share this code with anyone.',
            'sender_name' => 'WasteWise'
        ];
        
        if ($isResend) {
            $data['resend'] = true;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error
            ];
        }
        
        $responseData = json_decode($response, true);
        
        // Log the full response for debugging
        error_log("iProg Tech OTP Send Response (HTTP Code: $httpCode): " . print_r($responseData, true));
        
        if ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success') {
            // Store the phone number in session to link verification check
            $_SESSION['verification_phone'] = $phoneNumber;
            $_SESSION['last_otp_sent'] = time();
            
            $message = $isResend ? 'New verification code sent to ' . $formattedPhone : 'Verification code sent to ' . $formattedPhone;
            
            return [
                'success' => true,
                'message' => $message,
                'expires_in' => 300 // 5 minutes as per iProg Tech default
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to send verification code. iProg Tech API Error: ' . ($responseData['message'] ?? 'Unknown error'),
                'error' => $responseData['message'] ?? 'Unknown error'
            ];
        }
    }
    
    // Function to verify the code using iProg Tech OTP API
    public function verifyCode($phoneNumber, $code) {
        // Check if a verification attempt was initiated for this number
        if (!isset($_SESSION['verification_phone']) || $_SESSION['verification_phone'] !== $phoneNumber) {
            return [
                'success' => false,
                'message' => 'No pending verification for this phone number. Please request a new code.'
            ];
        }

        $formattedPhone = '09' . substr($phoneNumber, 1); // Convert 9xxxxxxxxx to 09xxxxxxxxx
        $url = IPROG_BASE_URL . "/otp/verify_otp";
        
        $data = [
            'api_token' => IPROG_API_TOKEN,
            'phone_number' => $formattedPhone,
            'otp' => $code
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error
            ];
        }
        
        $responseData = json_decode($response, true);
        
        // Log the full response for debugging
        error_log("iProg Tech OTP Verify Response (HTTP Code: $httpCode): " . print_r($responseData, true));
        
        if ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success') {
            // Mark as verified
            $_SESSION['phone_verified'] = true;
            $_SESSION['verified_phone'] = $phoneNumber;
            $this->clearVerificationSession(); // Clear the pending verification phone
            
            return [
                'success' => true,
                'message' => 'Phone number verified successfully!'
            ];
        } else {
            // iProg Tech provides specific error messages for invalid codes, expired codes, etc.
            $errorMessage = $responseData['message'] ?? 'Wrong code. Please try again.';
            return [
                'success' => false,
                'message' => 'Verification failed: ' . $errorMessage
            ];
        }
    }
    
    // Clear the verification session
    private function clearVerificationSession() {
        unset($_SESSION['verification_phone']);
        unset($_SESSION['last_otp_sent']);
    }
    
    public function checkVerificationStatus($phoneNumber) {
        return [
            'verified' => isset($_SESSION['phone_verified']) && 
                       $_SESSION['phone_verified'] === true && 
                       isset($_SESSION['verified_phone']) && 
                       $_SESSION['verified_phone'] === $phoneNumber
        ];
    }

    public function checkCredits() {
        $url = IPROG_BASE_URL . "/account/sms_credits?api_token=" . IPROG_API_TOKEN;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error
            ];
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success') {
            return [
                'success' => true,
                'credits' => $responseData['data']['load_balance']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to check credits: ' . ($responseData['message'] ?? 'Unknown error')
            ];
        }
    }
    
    public function canResend() {
        if (!isset($_SESSION['last_otp_sent'])) {
            return true;
        }
        
        // Allow resend after 60 seconds
        return (time() - $_SESSION['last_otp_sent']) >= 60;
    }
}

// Handle API requests
$smsVerification = new SMSVerification();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'send_code':
            $phoneNumber = $input['phone_number'] ?? '';
            $isResend = $input['is_resend'] ?? false;
            
            // Validate phone number
            if (!preg_match('/^9[0-9]{9}$/', $phoneNumber)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid phone number format'
                ]);
                exit;
            }
            
            if ($isResend && !$smsVerification->canResend()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Please wait 60 seconds before requesting a new code'
                ]);
                exit;
            }
            
            $result = $smsVerification->sendVerificationCode($phoneNumber, $isResend);
            echo json_encode($result);
            break;
            
        case 'verify_code':
            $phoneNumber = $input['phone_number'] ?? '';
            $code = $input['code'] ?? '';
            
            // Validate inputs
            if (!preg_match('/^9[0-9]{9}$/', $phoneNumber)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid phone number format'
                ]);
                exit;
            }
            
            if (!preg_match('/^[0-9]{6}$/', $code)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid verification code format'
                ]);
                exit;
            }
            
            $result = $smsVerification->verifyCode($phoneNumber, $code);
            echo json_encode($result);
            break;
            
        case 'check_status':
            $phoneNumber = $input['phone_number'] ?? '';
            $result = $smsVerification->checkVerificationStatus($phoneNumber);
            echo json_encode($result);
            break;

        case 'check_credits':
            $result = $smsVerification->checkCredits();
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests allowed'
    ]);
}
?>
