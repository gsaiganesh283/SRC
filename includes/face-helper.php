<?php
/**
 * Face Recognition Helper
 * Handles face descriptor storage and comparison for superadmin authentication
 * Uses client-side face-api.js for detection, PHP for storage/matching
 * Compatible with profreehost (PHP-only hosting)
 */

class FaceHelper {
    
    /** @var float Maximum Euclidean distance for a face match (lower = stricter) */
    const MATCH_THRESHOLD = 0.55;
    
    /** @var int Expected dimension of face descriptor vector from face-api.js */
    const DESCRIPTOR_SIZE = 128;
    
    /** @var bool Cache flag to avoid repeated ALTER TABLE checks */
    private static $columnsChecked = false;
    
    /**
     * Ensure face_enabled, face_descriptor and face_name columns exist in super_admins table
     * @param PDO $pdo Database connection
     * @return bool Success
     */
    public static function ensureColumnsExist($pdo) {
        if (self::$columnsChecked) return true;
        try {
            $cols = ['face_enabled', 'face_descriptor', 'face_name'];
            $defs = [
                'face_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'face_descriptor' => 'TEXT DEFAULT NULL',
                'face_name' => 'VARCHAR(100) DEFAULT NULL'
            ];
            foreach ($cols as $col) {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM super_admins LIKE ?");
                $stmt->execute([$col]);
                if ($stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE super_admins ADD COLUMN $col {$defs[$col]};");
                }
            }
            self::$columnsChecked = true;
            return true;
        } catch (Exception $e) {
            error_log("FaceHelper::ensureColumnsExist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if face authentication is enabled for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return bool
     */
    public static function isFaceEnabled($pdo, $superAdminId) {
        try {
            self::ensureColumnsExist($pdo);
            $stmt = $pdo->prepare("SELECT face_enabled FROM super_admins WHERE SuperAdminID = ?");
            $stmt->execute([$superAdminId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['face_enabled'] == 1;
        } catch (Exception $e) {
            error_log("FaceHelper::isFaceEnabled error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get stored face descriptor for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return array|null The face descriptor array or null
     */
    public static function getDescriptor($pdo, $superAdminId) {
        try {
            $stmt = $pdo->prepare("SELECT face_descriptor FROM super_admins WHERE SuperAdminID = ?");
            $stmt->execute([$superAdminId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['face_descriptor'])) {
                $descriptor = json_decode($result['face_descriptor'], true);
                if (is_array($descriptor) && count($descriptor) === self::DESCRIPTOR_SIZE) {
                    return $descriptor;
                }
            }
            return null;
        } catch (Exception $e) {
            error_log("FaceHelper::getDescriptor error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Enable face authentication and store the face descriptor + name
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @param array $descriptor 128-dimensional face descriptor
     * @param string|null $faceName Display name for the registered face
     * @return bool|string True on success, error message on failure
     */
    public static function enableFaceAuth($pdo, $superAdminId, $descriptor, $faceName = null) {
        try {
            // Validate descriptor
            if (!is_array($descriptor) || count($descriptor) !== self::DESCRIPTOR_SIZE) {
                return 'Invalid face descriptor: expected ' . self::DESCRIPTOR_SIZE . ' dimensions, got ' . (is_array($descriptor) ? count($descriptor) : 'non-array');
            }
            
            // Validate all values are numeric
            foreach ($descriptor as $val) {
                if (!is_numeric($val)) {
                    return 'Invalid face descriptor: contains non-numeric values';
                }
            }
            
            self::ensureColumnsExist($pdo);
            
            $stmt = $pdo->prepare("UPDATE super_admins SET face_enabled = 1, face_descriptor = ?, face_name = ? WHERE SuperAdminID = ?");
            $result = $stmt->execute([json_encode($descriptor), $faceName, $superAdminId]);
            
            return $result ? true : 'Database update failed';
        } catch (Exception $e) {
            error_log("FaceHelper::enableFaceAuth error: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
    
    /**
     * Disable face authentication
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return bool
     */
    public static function disableFaceAuth($pdo, $superAdminId) {
        try {
            $stmt = $pdo->prepare("UPDATE super_admins SET face_enabled = 0, face_descriptor = NULL, face_name = NULL WHERE SuperAdminID = ?");
            return $stmt->execute([$superAdminId]);
        } catch (Exception $e) {
            error_log("FaceHelper::disableFaceAuth error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the registered face name for a super admin
     * @param PDO $pdo Database connection
     * @param int $superAdminId Super admin ID
     * @return string|null
     */
    public static function getFaceName($pdo, $superAdminId) {
        try {
            $stmt = $pdo->prepare("SELECT face_name FROM super_admins WHERE SuperAdminID = ?");
            $stmt->execute([$superAdminId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['face_name'] : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Calculate Euclidean distance between two face descriptors
     * @param array $desc1 First descriptor (128-dimensional)
     * @param array $desc2 Second descriptor (128-dimensional)
     * @return float Euclidean distance
     */
    public static function euclideanDistance($desc1, $desc2) {
        if (count($desc1) !== count($desc2)) {
            return PHP_FLOAT_MAX;
        }
        
        $sum = 0.0;
        for ($i = 0; $i < count($desc1); $i++) {
            $diff = (float)$desc1[$i] - (float)$desc2[$i];
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }
    
    /**
     * Find a matching superadmin by face descriptor
     * @param PDO $pdo Database connection
     * @param array $inputDescriptor 128-dimensional face descriptor from login attempt
     * @return array|null ['superadmin' => row, 'distance' => float] or null if no match
     */
    public static function findMatchingAdmin($pdo, $inputDescriptor) {
        try {
            if (!is_array($inputDescriptor) || count($inputDescriptor) !== self::DESCRIPTOR_SIZE) {
                return null;
            }
            
            self::ensureColumnsExist($pdo);
            
            // Get all superadmins with face auth enabled
            $stmt = $pdo->prepare("
                SELECT SuperAdminID, Username, FullName, face_descriptor, face_name 
                FROM super_admins 
                WHERE face_enabled = 1 
                  AND face_descriptor IS NOT NULL 
                  AND Status = 'Active'
            ");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $bestMatch = null;
            $bestDistance = PHP_FLOAT_MAX;
            
            foreach ($admins as $admin) {
                $storedDescriptor = json_decode($admin['face_descriptor'], true);
                if (!is_array($storedDescriptor) || count($storedDescriptor) !== self::DESCRIPTOR_SIZE) {
                    continue;
                }
                
                $distance = self::euclideanDistance($inputDescriptor, $storedDescriptor);
                
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestMatch = $admin;
                }
            }
            
            if ($bestMatch && $bestDistance < self::MATCH_THRESHOLD) {
                return [
                    'superadmin' => $bestMatch,
                    'distance' => $bestDistance
                ];
            }
            
            return null;
        } catch (Exception $e) {
            error_log("FaceHelper::findMatchingAdmin error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if any superadmin has face auth enabled (for showing face login option)
     * @param PDO $pdo Database connection
     * @return bool
     */
    public static function anyFaceAuthEnabled($pdo) {
        try {
            self::ensureColumnsExist($pdo);
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt 
                FROM super_admins 
                WHERE face_enabled = 1 
                  AND face_descriptor IS NOT NULL 
                  AND Status = 'Active'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['cnt'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate a face descriptor from client input
     * @param mixed $input Raw input (string or array)
     * @return array|false Validated descriptor array or false
     */
    public static function validateDescriptor($input) {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        
        if (!is_array($input) || count($input) !== self::DESCRIPTOR_SIZE) {
            return false;
        }
        
        $descriptor = [];
        foreach ($input as $val) {
            if (!is_numeric($val)) {
                return false;
            }
            $descriptor[] = (float)$val;
        }
        
        return $descriptor;
    }
}
