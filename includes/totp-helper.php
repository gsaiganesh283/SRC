<?php
/**
 * TOTP (Time-based One-Time Password) Helper
 * Implements RFC 6238 TOTP for Google Authenticator
 * Pure PHP implementation - no external dependencies
 */

class TOTPHelper {
    
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    
    /**
     * Generate a random secret key (Base32 encoded)
     * @param int $length Length of the secret (default 16 characters = 80 bits)
     * @return string Base32 encoded secret
     */
    public static function generateSecret($length = 16) {
        $secret = '';
        $chars = self::$base32Chars;
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }
    
    /**
     * Decode a Base32 encoded string
     * @param string $base32 Base32 encoded string
     * @return string Binary data
     */
    private static function base32Decode($base32) {
        $base32 = strtoupper($base32);
        $base32 = str_replace('=', '', $base32);
        
        $lookup = array_flip(str_split(self::$base32Chars));
        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;
        
        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            if (!isset($lookup[$char])) continue;
            
            $buffer = ($buffer << 5) | $lookup[$char];
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
                $buffer &= (1 << $bitsLeft) - 1;
            }
        }
        
        return $binary;
    }
    
    /**
     * Generate a TOTP code for the given secret and time
     * @param string $secret Base32 encoded secret
     * @param int|null $timestamp Unix timestamp (null for current time)
     * @param int $period Time period in seconds (default 30)
     * @param int $digits Number of digits (default 6)
     * @return string TOTP code
     */
    public static function getCode($secret, $timestamp = null, $period = 30, $digits = 6) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $timeCounter = floor($timestamp / $period);
        $binaryTime = pack('N*', 0) . pack('N*', $timeCounter);
        
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $binaryTime, $key, true);
        
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, $digits);
        
        return str_pad($code, $digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * Verify a TOTP code with time window tolerance
     * @param string $secret Base32 encoded secret
     * @param string $code Code to verify
     * @param int $window Number of periods to check before and after (default 1)
     * @param int $period Time period in seconds (default 30)
     * @return bool True if code is valid
     */
    public static function verifyCode($secret, $code, $window = 1, $period = 30) {
        $timestamp = time();
        
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $timestamp + ($i * $period);
            $expectedCode = self::getCode($secret, $checkTime, $period);
            
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate the provisioning URI for Google Authenticator
     * @param string $secret Base32 encoded secret
     * @param string $accountName Account name (e.g., username)
     * @param string $issuer Issuer name (e.g., application name)
     * @return string otpauth:// URI
     */
    public static function getProvisioningUri($secret, $accountName, $issuer = 'SR Colony') {
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ];
        
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        return 'otpauth://totp/' . $label . '?' . http_build_query($params);
    }
    
    /**
     * Generate QR code URL using QR Server API (free, reliable alternative to deprecated Google Charts)
     * @param string $provisioningUri The otpauth:// URI
     * @param int $size Size of the QR code image
     * @return string URL to QR code image
     */
    public static function getQRCodeUrl($provisioningUri, $size = 250) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size 
            . '&ecc=M&format=png&data=' . urlencode($provisioningUri);
    }
    
    /**
     * Check if 2FA is enabled for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return bool True if 2FA is enabled
     */
    public static function is2FAEnabled($pdo, $superAdminId) {
        try {
            self::ensureColumnsExist($pdo);
            $stmt = $pdo->prepare("SELECT totp_enabled FROM super_admins WHERE SuperAdminID = ?");
            $stmt->execute([$superAdminId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['totp_enabled'] == 1;
        } catch (Exception $e) {
            error_log("TOTPHelper::is2FAEnabled error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the TOTP secret for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return string|null The TOTP secret or null
     */
    public static function getSecret($pdo, $superAdminId) {
        try {
            $stmt = $pdo->prepare("SELECT totp_secret FROM super_admins WHERE SuperAdminID = ?");
            $stmt->execute([$superAdminId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['totp_secret'] : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Ensure the totp_secret and totp_enabled columns exist in super_admins table
     * @param PDO $pdo Database connection
     * @return bool Success
     */
    public static function ensureColumnsExist($pdo) {
        try {
            // Check if totp_secret column exists
            $stmt = $pdo->prepare("SHOW COLUMNS FROM super_admins LIKE 'totp_secret'");
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE super_admins ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL");
            }
            
            // Check if totp_enabled column exists
            $stmt = $pdo->prepare("SHOW COLUMNS FROM super_admins LIKE 'totp_enabled'");
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE super_admins ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
            }
            
            return true;
        } catch (Exception $e) {
            error_log("TOTPHelper::ensureColumnsExist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enable 2FA for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @param string $secret Base32 encoded TOTP secret
     * @return bool|string True on success, error message string on failure
     */
    public static function enable2FA($pdo, $superAdminId, $secret) {
        try {
            self::ensureColumnsExist($pdo);
            $stmt = $pdo->prepare("UPDATE super_admins SET totp_secret = ?, totp_enabled = 1 WHERE SuperAdminID = ?");
            $result = $stmt->execute([$secret, $superAdminId]);
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                error_log("TOTPHelper::enable2FA SQL error: " . ($errorInfo[2] ?? 'Unknown'));
                return $errorInfo[2] ?? 'Database update failed';
            }
            if ($stmt->rowCount() === 0) {
                error_log("TOTPHelper::enable2FA: No rows updated for SuperAdminID=$superAdminId");
                return 'No matching super admin record found (ID: ' . $superAdminId . ')';
            }
            return true;
        } catch (Exception $e) {
            error_log("TOTPHelper::enable2FA exception: " . $e->getMessage());
            return $e->getMessage();
        }
    }
    
    /**
     * Disable 2FA for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return bool Success
     */
    public static function disable2FA($pdo, $superAdminId) {
        try {
            self::ensureColumnsExist($pdo);
            $stmt = $pdo->prepare("UPDATE super_admins SET totp_secret = NULL, totp_enabled = 0 WHERE SuperAdminID = ?");
            return $stmt->execute([$superAdminId]);
        } catch (Exception $e) {
            error_log("TOTPHelper::disable2FA exception: " . $e->getMessage());
            return false;
        }
    }
}
?>
