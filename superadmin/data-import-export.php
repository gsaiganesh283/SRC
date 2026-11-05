<?php
// Data Import/Export Module - Super Admin
// URL: superadmin/data-import-export.php

require_once 'session.php';
require_once '../config/config.php';

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';

// Handle file upload
$uploadMessage = '';
$uploadType = '';
$errorDetails = [];
$previewData = null;

// Handle preview request (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    header('Content-Type: application/json');
    
    if (isset($_FILES['preview_file']) && $_FILES['preview_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['preview_file'];
        $importType = $_POST['import_type'] ?? 'members';
        
        $tempFile = $file['tmp_name'];
        $preview = previewCSVData($tempFile, $importType);
        
        echo json_encode($preview);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

// Handle confirm import (with preview data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $importType = $_POST['import_type'] ?? 'members';
        $file = $_FILES['import_file'];
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, ['csv', 'xlsx', 'xls'])) {
            $uploadDir = '../uploads/imports/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = 'import_' . $importType . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                try {
                    if ($fileExtension === 'csv') {
                        $result = processCSVImport($filePath, $importType, $pdo);
                    } else {
                        $uploadMessage = "Excel files uploaded successfully. Please convert to CSV format for import.";
                        $uploadType = "warning";
                    }
                    
                    if (isset($result)) {
                        $uploadMessage = $result['message'];
                        $uploadType = $result['type'];
                        $errorDetails = $result['errors'] ?? [];
                    }
                } catch (Exception $e) {
                    $uploadMessage = "Error processing file: " . $e->getMessage();
                    $uploadType = "error";
                }
            } else {
                $uploadMessage = "Error uploading file.";
                $uploadType = "error";
            }
        } else {
            $uploadMessage = "Invalid file format. Please upload CSV or Excel files only.";
            $uploadType = "error";
        }
    }
}

// Original file upload handling (for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !isset($_POST['confirm_import'])) {
    $importType = $_POST['import_type'] ?? 'members';
    $file = $_FILES['import_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, ['csv', 'xlsx', 'xls'])) {
            $uploadDir = '../uploads/imports/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = 'import_' . $importType . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Process the file based on type
                try {
                    if ($fileExtension === 'csv') {
                        $result = processCSVImport($filePath, $importType, $pdo);
                    } else {
                        // For Excel files, we'll use a simple CSV conversion approach
                        $uploadMessage = "Excel files uploaded successfully. Please convert to CSV format for import.";
                        $uploadType = "warning";
                    }
                    
                    if (isset($result)) {
                        $uploadMessage = $result['message'];
                        $uploadType = $result['type'];
                        $errorDetails = $result['errors'] ?? [];
                    }
                } catch (Exception $e) {
                    $uploadMessage = "Error processing file: " . $e->getMessage();
                    $uploadType = "error";
                }
            } else {
                $uploadMessage = "Error uploading file.";
                $uploadType = "error";
            }
        } else {
            $uploadMessage = "Invalid file format. Please upload CSV or Excel files only.";
            $uploadType = "error";
        }
    } else {
        $uploadMessage = "Error uploading file: " . $file['error'];
        $uploadType = "error";
    }
}

// CSV Preview Function
function previewCSVData($filePath, $type) {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Could not open file'];
    }
    
    $headers = fgetcsv($handle);
    $rows = [];
    $rowCount = 0;
    $maxPreview = 10; // Preview first 10 rows
    
    while (($data = fgetcsv($handle)) !== false && $rowCount < $maxPreview) {
        $rows[] = $data;
        $rowCount++;
    }
    
    // Count total rows
    $totalRows = $rowCount;
    while (fgetcsv($handle) !== false) {
        $totalRows++;
    }
    
    fclose($handle);
    
    return [
        'success' => true,
        'headers' => $headers,
        'rows' => $rows,
        'totalRows' => $totalRows,
        'previewRows' => $rowCount,
        'type' => $type
    ];
}

// CSV Import Processing Function
function processCSVImport($filePath, $type, $pdo) {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['message' => 'Could not open file', 'type' => 'error'];
    }
    
    $headers = fgetcsv($handle);
    $imported = 0;
    $errors = 0;
    $errorMessages = [];
    
    if ($type === 'members') {
        while (($data = fgetcsv($handle)) !== false) {
            try {
                // Expected columns: MemberName, AadharNumber, Phone, Email, Address
                if (count($data) >= 5) {
                    $stmt = $pdo->prepare("
                        INSERT INTO members (MemberName, AadharNumber, Phone, Email, Address, Password, CreatedAt)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $defaultPassword = password_hash('member123', PASSWORD_DEFAULT);
                    
                    $stmt->execute([
                        $data[0], // MemberName
                        $data[1], // AadharNumber
                        $data[2], // Phone
                        $data[3], // Email
                        $data[4], // Address
                        $defaultPassword
                    ]);
                    $imported++;
                }
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Row error: " . $e->getMessage();
            }
        }
    } elseif ($type === 'transactions') {
        // Validate that academic years exist
        $yearCheck = $pdo->query("SELECT COUNT(*) as count FROM academic_years");
        $yearCount = $yearCheck->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($yearCount == 0) {
            return [
                'message' => 'Cannot import transactions: No academic years found in the database.',
                'type' => 'error',
                'errors' => [
                    'Please import Academic Years first before importing transactions.',
                    'Go to Import section → Select "Academic Years" → Upload years CSV file.',
                    'Sample format: YearName, StartDate, EndDate, InterestRate'
                ]
            ];
        }
        
        // Validate that members exist
        $memberCheck = $pdo->query("SELECT COUNT(*) as count FROM members");
        $memberCount = $memberCheck->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($memberCount == 0) {
            return [
                'message' => 'Cannot import transactions: No members found in the database.',
                'type' => 'error',
                'errors' => [
                    'Please import Members first before importing transactions.',
                    'Go to Import section → Select "Members" → Upload members CSV file.',
                    'Sample format: MemberName, AadharNumber, Phone, Email, Address'
                ]
            ];
        }
        
        $rowNumber = 1; // Start from 1 (header is already read)
        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            try {
                // Expected columns: MemberID, YearID, TransactionDate, CollectionAmount, DistributedAmount, InterestAmount, PaymentMode, ReferenceNumber, MonthName, Remarks
                if (count($data) >= 7) {
                    // Parse date properly - handle both DD-MM-YYYY and YYYY-MM-DD formats
                    $transactionDate = $data[2];
                    if (strpos($transactionDate, '-') !== false) {
                        $dateParts = explode('-', $transactionDate);
                        if (count($dateParts) === 3) {
                            // Check if it's DD-MM-YYYY format
                            if (strlen($dateParts[0]) === 2) {
                                // Convert DD-MM-YYYY to YYYY-MM-DD
                                $transactionDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                            }
                        }
                    }
                    
                    // Extract month-year from transaction date for TransactionMonth field
                    $transactionMonth = date('Y-m', strtotime($transactionDate));
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions (MemberID, YearID, TransactionDate, TransactionTime, TransactionMonth, CollectionAmount, DistributedAmount, InterestAmount, FixedAmount, PaymentMode, ReferenceNumber, MonthName, Remarks, CreatedBy, CreatedAt)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    
                    $stmt->execute([
                        intval($data[0]), // MemberID
                        intval($data[1]), // YearID
                        $transactionDate, // TransactionDate (properly formatted)
                        date('H:i:s'), // TransactionTime
                        $transactionMonth, // TransactionMonth (Y-m format)
                        floatval($data[3] ?? 0), // CollectionAmount
                        floatval($data[4] ?? 0), // DistributedAmount
                        floatval($data[5] ?? 0), // InterestAmount
                        floatval($data[6] ?? 0), // FixedAmount (if column exists, otherwise 0)
                        $data[7] ?? 'Cash', // PaymentMode
                        $data[8] ?? '', // ReferenceNumber
                        $data[9] ?? '', // MonthName
                        $data[10] ?? '' // Remarks
                    ]);
                    $imported++;
                } else {
                    $errors++;
                    $errorMessages[] = "Row $rowNumber: Insufficient columns (" . count($data) . " found, minimum 7 required). Data: " . implode(', ', $data);
                }
            } catch (PDOException $e) {
                $errors++;
                $errorMsg = $e->getMessage();
                
                // Check if it's a foreign key error for YearID
                if (strpos($errorMsg, 'fk_transaction_year') !== false) {
                    $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearID");
                    $availableYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
                    $yearList = array_map(function($y) { return "ID: {$y['YearID']} ({$y['YearName']})"; }, $availableYears);
                    $errorMessages[] = "Row $rowNumber: YearID '{$data[1]}' not found. Available years: " . implode(', ', $yearList) . " | Data: MemberID=" . ($data[0] ?? 'N/A');
                } elseif (strpos($errorMsg, 'fk_transaction_member') !== false) {
                    $errorMessages[] = "Row $rowNumber: MemberID '{$data[0]}' not found in database. Please create this member first.";
                } else {
                    $errorMessages[] = "Row $rowNumber: " . $errorMsg . " | Data: MemberID=" . ($data[0] ?? 'N/A') . ", YearID=" . ($data[1] ?? 'N/A') . ", Date=" . ($data[2] ?? 'N/A');
                }
            }
        }
    } elseif ($type === 'years') {
        while (($data = fgetcsv($handle)) !== false) {
            try {
                // Expected columns: YearName, StartDate, EndDate, InterestRate
                if (count($data) >= 4) {
                    $stmt = $pdo->prepare("
                        INSERT INTO academic_years (YearName, StartDate, EndDate, InterestRate, IsActive)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    
                    $stmt->execute([
                        $data[0], // YearName
                        $data[1], // StartDate
                        $data[2], // EndDate
                        floatval($data[3]) // InterestRate
                    ]);
                    $imported++;
                }
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Row error: " . $e->getMessage();
            }
        }
    } elseif ($type === 'custom_fields') {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'custom_field_definitions'");
        if ($tableCheck->rowCount() == 0) {
            return [
                'message' => 'Custom fields table does not exist. Please run the CREATE_CUSTOM_FIELDS_TABLE.sql script first.',
                'type' => 'error',
                'errors' => ['Database table custom_field_definitions not found']
            ];
        }
        
        while (($data = fgetcsv($handle)) !== false) {
            try {
                // Expected columns: FieldName, FieldLabel, FieldType, EntityType, FieldOptions, IsRequired
                if (count($data) >= 4) {
                    $stmt = $pdo->prepare("
                        INSERT INTO custom_field_definitions (FieldName, FieldLabel, FieldType, EntityType, FieldOptions, IsRequired, IsActive)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE FieldLabel = VALUES(FieldLabel), FieldType = VALUES(FieldType), FieldOptions = VALUES(FieldOptions)
                    ");
                    
                    $stmt->execute([
                        $data[0], // FieldName
                        $data[1], // FieldLabel
                        $data[2], // FieldType
                        $data[3], // EntityType
                        $data[4] ?? null, // FieldOptions (JSON)
                        isset($data[5]) ? intval($data[5]) : 0 // IsRequired
                    ]);
                    $imported++;
                }
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Row error: " . $e->getMessage();
            }
        }
    } elseif ($type === 'custom_values') {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'custom_field_values'");
        if ($tableCheck->rowCount() == 0) {
            return [
                'message' => 'Custom field values table does not exist. Please run the CREATE_CUSTOM_FIELDS_TABLE.sql script first.',
                'type' => 'error',
                'errors' => ['Database table custom_field_values not found']
            ];
        }
        
        while (($data = fgetcsv($handle)) !== false) {
            try {
                // Expected columns: FieldID, EntityType, EntityID, FieldValue
                if (count($data) >= 4) {
                    $stmt = $pdo->prepare("
                        INSERT INTO custom_field_values (FieldID, EntityType, EntityID, FieldValue)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE FieldValue = VALUES(FieldValue)
                    ");
                    
                    $stmt->execute([
                        intval($data[0]), // FieldID
                        $data[1], // EntityType
                        intval($data[2]), // EntityID
                        $data[3] // FieldValue
                    ]);
                    $imported++;
                }
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Row error: " . $e->getMessage();
            }
        }
    }
    
    fclose($handle);
    
    $message = "Import completed: $imported records imported successfully";
    if ($errors > 0) {
        $message .= ", $errors errors occurred.";
    }
    
    return [
        'message' => $message,
        'type' => $errors > 0 ? 'warning' : 'success',
        'errors' => $errorMessages
    ];
}

// Get statistics
$stats = [
    'members' => 0,
    'transactions' => 0,
    'years' => 0,
    'custom_fields' => 0
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM members");
    $stats['members'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
    $stats['transactions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM academic_years");
    $stats['years'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Check if custom_field_definitions table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'custom_field_definitions'");
    if ($tableCheck->rowCount() > 0) {
        // Check if IsActive column exists
        $columnCheck = $pdo->query("SHOW COLUMNS FROM custom_field_definitions LIKE 'IsActive'");
        if ($columnCheck->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM custom_field_definitions WHERE IsActive = 1");
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM custom_field_definitions");
        }
        $stats['custom_fields'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
} catch (Exception $e) {
    // Ignore errors
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Import/Export - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f3f4f6;
        }
        
        .import-export-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            padding: 40px;
            border-radius: 24px;
            color: white;
            margin-bottom: 35px;
            box-shadow: 0 20px 50px rgba(79, 70, 229, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .page-title {
            font-size: 36px;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
            letter-spacing: -0.5px;
        }
        
        .page-title i {
            font-size: 40px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 28px;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.12);
        }
        
        .stat-card.blue::before { background: linear-gradient(180deg, #4f46e5 0%, #7c3aed 100%); }
        .stat-card.green::before { background: linear-gradient(180deg, #059669 0%, #10b981 100%); }
        .stat-card.orange::before { background: linear-gradient(180deg, #f59e0b 0%, #f97316 100%); }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 18px;
        }
        
        .stat-card.blue .stat-icon { background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); color: #4f46e5; }
        .stat-card.green .stat-icon { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
        .stat-card.orange .stat-icon { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #f59e0b; }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -1px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: white;
            padding: 35px;
            border-radius: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            transition: all 0.3s;
        }
        
        .action-card:hover {
            box-shadow: 0 12px 28px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        
        .action-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .action-icon {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .action-card.import .action-icon {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
        }
        
        .action-card.export .action-icon {
            background: linear-gradient(135deg, #ec4899 0%, #f43f5e 100%);
            color: white;
        }
        
        .action-title {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.3px;
        }
        
        .action-description {
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            color: #111827;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .form-group select,
        .form-group input[type="file"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f9fafb;
        }
        
        .form-group select:focus,
        .form-group input[type="file"]:focus {
            border-color: #4f46e5;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(5, 150, 105, 0.4);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .export-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        .error-details-box {
            background: white;
            border: 2px solid #fca5a5;
            border-radius: 12px;
            margin-top: 15px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);
        }
        
        .error-details-header {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            padding: 12px 20px;
            border-bottom: 2px solid #fca5a5;
            color: #991b1b;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .error-details-list {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
        }
        
        .error-detail-item {
            display: flex;
            gap: 12px;
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #fef2f2;
            border-left: 3px solid #ef4444;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .error-number {
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        
        .error-message {
            color: #7f1d1d;
            flex-grow: 1;
            word-break: break-word;
        }
        
        .sample-section {
            background: white;
            padding: 35px;
            border-radius: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
        }
        
        .sample-header {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.3px;
        }
        
        .sample-header i {
            font-size: 28px;
        }
        
        .sample-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .sample-item {
            background: #fafbfc;
            padding: 20px;
            border-radius: 16px;
            border: 2px solid #e5e7eb;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .sample-item:hover {
            border-color: #4f46e5;
            background: #f5f3ff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
            transform: translateY(-2px);
        }
        
        .sample-title {
            font-weight: 700;
            color: #111827;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }
        
        .sample-title i {
            font-size: 20px;
        }
        
        .sample-description {
            font-size: 13px;
            color: #6b7280;
            margin: 0;
            line-height: 1.5;
            flex-grow: 1;
        }
        
        .sample-download-btn {
            display: flex;
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #111827;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            box-sizing: border-box;
        }
        
        .sample-download-btn:hover {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            border-color: #d1d5db;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
        }
        
        .sample-download-btn i {
            font-size: 16px;
        }
        
        .member-preview-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 20px;
            margin-top: 10px;
            animation: slideIn 0.3s ease;
        }
        
        .member-preview-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #bae6fd;
        }
        
        .member-preview-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .member-preview-name {
            flex-grow: 1;
        }
        
        .member-preview-name h4 {
            margin: 0;
            color: #0c4a6e;
            font-size: 18px;
            font-weight: 700;
        }
        
        .member-preview-name p {
            margin: 4px 0 0 0;
            color: #0369a1;
            font-size: 13px;
        }
        
        .member-preview-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        
        .member-detail-item {
            background: white;
            padding: 10px 12px;
            border-radius: 8px;
            border-left: 3px solid #0ea5e9;
        }
        
        .member-detail-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .member-detail-value {
            color: #0f172a;
            font-size: 14px;
            font-weight: 600;
        }
        
        .member-preview-error {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 2px solid #ef4444;
            border-radius: 12px;
            padding: 15px;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .member-preview-loading {
            background: linear-gradient(135deg, #fefce8 0%, #fef3c7 100%);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 15px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Preview Modal Styles */
        #previewModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        
        .preview-modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }
        
        .preview-modal-content {
            position: relative;
            background: white;
            border-radius: 16px;
            max-width: 90%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .preview-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            border-bottom: 2px solid #e5e7eb;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px 16px 0 0;
        }
        
        .preview-modal-header h2 {
            margin: 0;
            color: white;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .preview-modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 28px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .preview-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .preview-modal-body {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }
        
        .preview-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .preview-summary-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 12px;
            border-left: 4px solid #0ea5e9;
        }
        
        .preview-summary-item i {
            font-size: 24px;
            color: #0ea5e9;
        }
        
        .preview-table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .preview-table thead {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        }
        
        .preview-table th {
            padding: 12px;
            text-align: left;
            font-weight: 700;
            color: #111827;
            border-bottom: 2px solid #d1d5db;
            white-space: nowrap;
        }
        
        .preview-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            color: #374151;
        }
        
        .preview-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .preview-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .preview-table tbody tr:nth-child(even):hover {
            background: #f3f4f6;
        }
        
        .preview-note {
            margin-top: 20px;
            padding: 12px 15px;
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .preview-modal-footer {
            padding: 20px 30px;
            border-top: 2px solid #e5e7eb;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        
        .file-upload-area {
            border: 3px dashed #d1d5db;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            background: linear-gradient(135deg, #fafbfc 0%, #f9fafb 100%);
        }
        
        .file-upload-area:hover {
            border-color: #4f46e5;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
        }
        
        .file-upload-area.dragover {
            border-color: #4f46e5;
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.2);
        }
        
        .upload-icon {
            font-size: 56px;
            color: #4f46e5;
            margin-bottom: 18px;
            opacity: 0.7;
        }
        
        .upload-text {
            font-weight: 700;
            color: #111827;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .upload-hint {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="import-export-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-exchange-alt"></i>
                Data Import / Export
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Manage bulk data operations with Excel/CSV files</p>
        </div>
        
        <?php if ($uploadMessage): ?>
            <div class="alert alert-<?php echo $uploadType; ?>">
                <i class="fas fa-<?php echo $uploadType === 'success' ? 'check-circle' : ($uploadType === 'error' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                <?php echo htmlspecialchars($uploadMessage); ?>
            </div>
            
            <?php if (!empty($errorDetails)): ?>
                <div class="error-details-box">
                    <div class="error-details-header">
                        <i class="fas fa-list"></i>
                        <strong>Error Details (<?php echo count($errorDetails); ?> errors)</strong>
                    </div>
                    <div class="error-details-list">
                        <?php foreach ($errorDetails as $index => $error): ?>
                            <div class="error-detail-item">
                                <span class="error-number"><?php echo $index + 1; ?></span>
                                <span class="error-message"><?php echo htmlspecialchars($error); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Members</div>
                <div class="stat-value"><?php echo number_format($stats['members']); ?></div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="stat-label">Total Transactions</div>
                <div class="stat-value"><?php echo number_format($stats['transactions']); ?></div>
            </div>
            
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-label">Academic Years</div>
                <div class="stat-value"><?php echo number_format($stats['years']); ?></div>
            </div>
            
            <div class="stat-card" style="border-left-color: #8b5cf6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #8b5cf6;"><i class="fas fa-cogs"></i></div>
                <div class="stat-label">Custom Fields</div>
                <div class="stat-value"><?php echo number_format($stats['custom_fields']); ?></div>
            </div>
        </div>
        
        <!-- Import/Export Actions -->
        <div class="action-grid">
            <!-- Import Section -->
            <div class="action-card import">
                <div class="action-header">
                    <div class="action-icon">
                        <i class="fas fa-file-upload"></i>
                    </div>
                    <div>
                        <div class="action-title">Import Data</div>
                    </div>
                </div>
                
                <p class="action-description">
                    Upload CSV or Excel files to bulk import members, transactions, or academic years into the system.
                </p>
                
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="form-group">
                        <label>Select Import Type</label>
                        <select name="import_type" id="import_type" required>
                            <option value="members">Members</option>
                            <option value="transactions">Transactions</option>
                            <option value="years">Academic Years</option>
                            <option value="custom_fields">Custom Field Definitions</option>
                            <option value="custom_values">Custom Field Values</option>
                        </select>
                    </div>
                    
                    <!-- Member Lookup Preview (for transactions) -->
                    <div id="memberLookupSection" style="display: none;">
                        <div class="form-group">
                            <label>Preview Member (Optional)</label>
                            <input type="number" id="previewMemberID" class="form-control" placeholder="Enter Member ID to preview" style="padding: 12px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 14px; width: 100%; box-sizing: border-box;">
                        </div>
                        <div id="memberPreview" style="margin-top: 15px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Choose File</label>
                        <div class="file-upload-area" onclick="document.getElementById('import_file').click()">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-text">Click to upload or drag and drop</div>
                            <div class="upload-hint">CSV, XLS, XLSX (Max 10MB)</div>
                        </div>
                        <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx,.xls" style="display: none;" required onchange="updateFileName(this)">
                        <div id="fileName" style="margin-top: 10px; font-size: 13px; color: #6b7280;"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-upload"></i>
                        Import Data
                    </button>
                </form>
            </div>
            
            <!-- Export Section -->
            <div class="action-card export">
                <div class="action-header">
                    <div class="action-icon">
                        <i class="fas fa-file-download"></i>
                    </div>
                    <div>
                        <div class="action-title">Export Data</div>
                    </div>
                </div>
                
                <p class="action-description">
                    Download your data in CSV format for backup, analysis, or migration purposes.
                </p>
                
                <div class="export-buttons">
                    <a href="export-data.php?type=members" class="btn btn-success">
                        <i class="fas fa-users"></i>
                        Export Members
                    </a>
                    
                    <a href="export-data.php?type=transactions" class="btn btn-success">
                        <i class="fas fa-exchange-alt"></i>
                        Export Transactions
                    </a>
                    
                    <a href="export-data.php?type=years" class="btn btn-success">
                        <i class="fas fa-calendar-alt"></i>
                        Export Academic Years
                    </a>
                    
                    <a href="export-data.php?type=custom_fields" class="btn btn-success">
                        <i class="fas fa-cogs"></i>
                        Export Custom Fields
                    </a>
                    
                    <a href="export-data.php?type=custom_values" class="btn btn-success">
                        <i class="fas fa-database"></i>
                        Export Custom Values
                    </a>
                    
                    <a href="export-data.php?type=all" class="btn btn-secondary">
                        <i class="fas fa-archive"></i>
                        Export All Data
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Sample Files Section -->
        <div class="sample-section">
            <div class="sample-header">
                <i class="fas fa-file-excel" style="color: #10b981;"></i>
                Sample Excel Templates
            </div>
            <p style="color: #6b7280; margin-bottom: 20px;">
                Download these sample templates to ensure your import files have the correct format and column structure.
            </p>
            
            <div class="sample-grid">
                <div class="sample-item">
                    <div class="sample-title">
                        <i class="fas fa-users" style="color: #667eea;"></i>
                        Members Template
                    </div>
                    <div class="sample-description">
                        Excel template with instructions. Columns: MemberName, AadharNumber, Phone, Email, Address
                    </div>
                    <a href="online-excel-editor.php?type=members" class="sample-download-btn">
                        <i class="fas fa-edit"></i>
                        Open Online Editor
                    </a>
                </div>
                
                <div class="sample-item">
                    <div class="sample-title">
                        <i class="fas fa-exchange-alt" style="color: #11998e;"></i>
                        Transactions Template
                    </div>
                    <div class="sample-description">
                        Excel with reference sheets: Member IDs, Year IDs, Payment Modes, Months, and common Remarks
                    </div>
                    <a href="online-excel-editor.php?type=transactions" class="sample-download-btn">
                        <i class="fas fa-edit"></i>
                        Open Online Editor
                    </a>
                </div>
                
                <div class="sample-item">
                    <div class="sample-title">
                        <i class="fas fa-calendar-alt" style="color: #f2994a;"></i>
                        Academic Years Template
                    </div>
                    <div class="sample-description">
                        Excel template with date format guide. Columns: YearName, StartDate, EndDate, InterestRate
                    </div>
                    <a href="online-excel-editor.php?type=years" class="sample-download-btn">
                        <i class="fas fa-edit"></i>
                        Open Online Editor
                    </a>
                </div>
                
                <div class="sample-item">
                    <div class="sample-title">
                        <i class="fas fa-cogs" style="color: #8b5cf6;"></i>
                        Custom Fields Template
                    </div>
                    <div class="sample-description">
                        Excel with reference sheets for Field Types and Entity Types. Includes validation options
                    </div>
                    <a href="online-excel-editor.php?type=custom_fields" class="sample-download-btn">
                        <i class="fas fa-edit"></i>
                        Open Online Editor
                    </a>
                </div>
                
                <div class="sample-item">
                    <div class="sample-title">
                        <i class="fas fa-database" style="color: #06b6d4;"></i>
                        Custom Values Template
                    </div>
                    <div class="sample-description">
                        Excel with reference sheet showing available Custom Fields and their IDs
                    </div>
                    <a href="online-excel-editor.php?type=custom_values" class="sample-download-btn">
                        <i class="fas fa-edit"></i>
                        Open Online Editor
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Import type change handler - show member lookup for transactions
        document.getElementById('import_type').addEventListener('change', function() {
            const memberLookupSection = document.getElementById('memberLookupSection');
            if (this.value === 'transactions') {
                memberLookupSection.style.display = 'block';
            } else {
                memberLookupSection.style.display = 'none';
                document.getElementById('memberPreview').innerHTML = '';
                document.getElementById('previewMemberID').value = '';
            }
        });
        
        // Member lookup on input
        let lookupTimeout;
        document.getElementById('previewMemberID').addEventListener('input', function() {
            clearTimeout(lookupTimeout);
            const memberId = this.value.trim();
            const previewDiv = document.getElementById('memberPreview');
            
            if (!memberId) {
                previewDiv.innerHTML = '';
                return;
            }
            
            // Show loading
            previewDiv.innerHTML = `
                <div class="member-preview-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Looking up member...</span>
                </div>
            `;
            
            lookupTimeout = setTimeout(() => {
                fetch('api-member-lookup.php?member_id=' + memberId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const member = data.member;
                            const stats = data.stats;
                            previewDiv.innerHTML = `
                                <div class="member-preview-card">
                                    <div class="member-preview-header">
                                        <div class="member-preview-icon">
                                            <i class="fas fa-user-check"></i>
                                        </div>
                                        <div class="member-preview-name">
                                            <h4>${member.MemberName}</h4>
                                            <p>Member ID: ${member.MemberID} | ${stats.transaction_count} transactions</p>
                                        </div>
                                    </div>
                                    <div class="member-preview-details">
                                        <div class="member-detail-item">
                                            <div class="member-detail-label">Aadhar Number</div>
                                            <div class="member-detail-value">${member.AadharNumber}</div>
                                        </div>
                                        <div class="member-detail-item">
                                            <div class="member-detail-label">Phone</div>
                                            <div class="member-detail-value">${member.Phone}</div>
                                        </div>
                                        <div class="member-detail-item">
                                            <div class="member-detail-label">Email</div>
                                            <div class="member-detail-value">${member.Email}</div>
                                        </div>
                                        <div class="member-detail-item">
                                            <div class="member-detail-label">Total Collection</div>
                                            <div class="member-detail-value">₹${stats.total_collection}</div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        } else {
                            previewDiv.innerHTML = `
                                <div class="member-preview-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>${data.message}</span>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        previewDiv.innerHTML = `
                            <div class="member-preview-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Error fetching member details</span>
                            </div>
                        `;
                    });
            }, 500);
        });
        
        function updateFileName(input) {
            const fileName = document.getElementById('fileName');
            if (input.files && input.files[0]) {
                fileName.textContent = '📎 Selected: ' + input.files[0].name;
                fileName.style.color = '#10b981';
                fileName.style.fontWeight = '600';
            }
        }
        
        // Preview Modal Functionality
        document.getElementById('importForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'preview');
            formData.set('preview_file', document.getElementById('import_file').files[0]);
            
            // Show loading
            showPreviewModal('Loading preview...');
            
            fetch('data-import-export.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPreviewData(data);
                } else {
                    alert('Error: ' + (data.message || 'Failed to preview file'));
                    closePreviewModal();
                }
            })
            .catch(error => {
                alert('Error loading preview: ' + error);
                closePreviewModal();
            });
        });
        
        function showPreviewModal(content) {
            let modal = document.getElementById('previewModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'previewModal';
                modal.innerHTML = `
                    <div class="preview-modal-backdrop" onclick="closePreviewModal()"></div>
                    <div class="preview-modal-content">
                        <div class="preview-modal-header">
                            <h2><i class="fas fa-eye"></i> Import Preview</h2>
                            <button onclick="closePreviewModal()" class="preview-modal-close">&times;</button>
                        </div>
                        <div class="preview-modal-body" id="previewModalBody">
                            ${content}
                        </div>
                        <div class="preview-modal-footer" id="previewModalFooter"></div>
                    </div>
                `;
                document.body.appendChild(modal);
            }
            modal.style.display = 'flex';
            document.getElementById('previewModalBody').innerHTML = content;
        }
        
        function closePreviewModal() {
            const modal = document.getElementById('previewModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        function displayPreviewData(data) {
            const typeLabels = {
                members: 'Members',
                transactions: 'Transactions',
                years: 'Academic Years',
                custom_fields: 'Custom Fields',
                custom_values: 'Custom Values'
            };
            
            let tableHTML = `
                <div class="preview-summary">
                    <div class="preview-summary-item">
                        <i class="fas fa-file-csv"></i>
                        <div>
                            <strong>Type:</strong> ${typeLabels[data.type] || data.type}
                        </div>
                    </div>
                    <div class="preview-summary-item">
                        <i class="fas fa-list"></i>
                        <div>
                            <strong>Total Rows:</strong> ${data.totalRows}
                        </div>
                    </div>
                    <div class="preview-summary-item">
                        <i class="fas fa-eye"></i>
                        <div>
                            <strong>Showing:</strong> First ${data.previewRows} rows
                        </div>
                    </div>
                </div>
                
                <div class="preview-table-container">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
            `;
            
            // Add headers
            data.headers.forEach(header => {
                tableHTML += `<th>${header || 'Column'}</th>`;
            });
            
            tableHTML += `</tr></thead><tbody>`;
            
            // Add rows
            data.rows.forEach((row, index) => {
                tableHTML += `<tr><td>${index + 1}</td>`;
                row.forEach(cell => {
                    tableHTML += `<td>${cell || '<em>empty</em>'}</td>`;
                });
                tableHTML += `</tr>`;
            });
            
            tableHTML += `</tbody></table></div>`;
            
            if (data.totalRows > data.previewRows) {
                tableHTML += `<p class="preview-note"><i class="fas fa-info-circle"></i> Showing first ${data.previewRows} of ${data.totalRows} rows. All rows will be imported.</p>`;
            }
            
            showPreviewModal(tableHTML);
            
            // Add confirm button
            document.getElementById('previewModalFooter').innerHTML = `
                <button onclick="closePreviewModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button onclick="confirmImport()" class="btn btn-primary">
                    <i class="fas fa-check"></i> Confirm & Import ${data.totalRows} Rows
                </button>
            `;
        }
        
        function confirmImport() {
            const form = document.getElementById('importForm');
            const formData = new FormData(form);
            formData.append('confirm_import', '1');
            
            // Show loading
            document.getElementById('previewModalBody').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #667eea;"></i>
                    <p style="margin-top: 20px; font-size: 18px; color: #6b7280;">Importing data...</p>
                </div>
            `;
            document.getElementById('previewModalFooter').innerHTML = '';
            
            // Submit form
            fetch('data-import-export.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Reload page to show results
                window.location.reload();
            })
            .catch(error => {
                alert('Error importing data: ' + error);
                closePreviewModal();
            });
        }
        
        // Drag and drop functionality
        const uploadArea = document.querySelector('.file-upload-area');
        const fileInput = document.getElementById('import_file');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.add('dragover');
            });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.remove('dragover');
            });
        });
        
        uploadArea.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files;
                updateFileName(fileInput);
            }
        });
    </script>
</body>
</html>
