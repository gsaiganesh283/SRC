<?php
// Data Export Handler with Colorful Excel
// URL: superadmin/export-data.php

require_once 'session.php';
require_once '../config/config.php';

$exportType = $_GET['type'] ?? 'members';
$format = $_GET['format'] ?? 'excel'; // excel or csv

if ($format === 'csv') {
    // Original CSV export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=export_' . $exportType . '_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
} else {
    // Excel export with HTML-based formatting
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=export_' . $exportType . '_' . date('Y-m-d_His') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
}

try {
    if ($format === 'excel') {
        // Excel export with colorful formatting
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; }
        .header-row {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            font-weight: bold;
            text-align: center;
            padding: 12px;
            font-size: 14px;
        }
        .title-row {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            font-weight: bold;
            font-size: 18px;
            padding: 15px;
            text-align: center;
        }
        .data-row-even {
            background-color: #f9fafb;
        }
        .data-row-odd {
            background-color: #ffffff;
        }
        .numeric-cell {
            text-align: right;
            background-color: #fef3c7;
            font-weight: 600;
        }
        .date-cell {
            background-color: #dbeafe;
            text-align: center;
        }
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
            font-weight: bold;
            text-align: center;
        }
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
            font-weight: bold;
            text-align: center;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        td, th {
            border: 1px solid #d1d5db;
            padding: 8px;
        }
    </style>
</head>
<body>';
    }
    
    if ($exportType === 'members') {
        if ($format === 'csv') {
            fputcsv($output, ['MemberID', 'MemberName', 'AadharNumber', 'Phone', 'Email', 'Address', 'CreatedAt']);
            $stmt = $pdo->query("SELECT MemberID, MemberName, AadharNumber, Phone, Email, Address, CreatedAt FROM members ORDER BY MemberName ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        } else {
            // Colorful Excel export for members
            echo '<table>
                <tr class="title-row">
                    <td colspan="7">📊 MEMBERS DATA EXPORT - ' . date('F d, Y') . '</td>
                </tr>
                <tr class="header-row">
                    <th>Member ID</th>
                    <th>Member Name</th>
                    <th>Aadhar Number</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>Created At</th>
                </tr>';
            
            $stmt = $pdo->query("SELECT MemberID, MemberName, AadharNumber, Phone, Email, Address, CreatedAt FROM members ORDER BY MemberID DESC");
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . htmlspecialchars($row['MemberID']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($row['MemberName']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($row['AadharNumber']) . '</td>';
                echo '<td>' . htmlspecialchars($row['Phone']) . '</td>';
                echo '<td>' . htmlspecialchars($row['Email']) . '</td>';
                echo '<td>' . htmlspecialchars($row['Address']) . '</td>';
                echo '<td class="date-cell">' . htmlspecialchars($row['CreatedAt']) . '</td>';
                echo '</tr>';
                $rowNum++;
            }
            echo '</table>';
        }
        
    } elseif ($exportType === 'transactions') {
        if ($format === 'csv') {
            fputcsv($output, ['TransactionID', 'MemberID', 'MemberName', 'YearID', 'YearName', 'TransactionDate', 'CollectionAmount', 'DistributedAmount', 'InterestAmount', 'PaymentMode', 'ReferenceNumber', 'MonthName', 'Remarks', 'CreatedAt']);
            $stmt = $pdo->query("
                SELECT t.TransactionID, t.MemberID, m.MemberName, t.YearID, ay.YearName, t.TransactionDate, 
                       t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, 
                       t.ReferenceNumber, t.MonthName, t.Remarks, t.CreatedAt
                FROM transactions t
                LEFT JOIN members m ON t.MemberID = m.MemberID
                LEFT JOIN academic_years ay ON t.YearID = ay.YearID
                ORDER BY t.TransactionDate DESC
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        } else {
            // Colorful Excel export for transactions
            echo '<table>
                <tr class="title-row">
                    <td colspan="14">💰 TRANSACTIONS DATA EXPORT - ' . date('F d, Y') . '</td>
                </tr>
                <tr class="header-row">
                    <th>Transaction ID</th>
                    <th>Member ID</th>
                    <th>Member Name</th>
                    <th>Year ID</th>
                    <th>Year Name</th>
                    <th>Date</th>
                    <th>Collection</th>
                    <th>Distribution</th>
                    <th>Interest</th>
                    <th>Payment Mode</th>
                    <th>Reference</th>
                    <th>Month</th>
                    <th>Remarks</th>
                    <th>Created At</th>
                </tr>';
            
            $stmt = $pdo->query("
                SELECT t.TransactionID, t.MemberID, m.MemberName, t.YearID, ay.YearName, t.TransactionDate, 
                       t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, 
                       t.ReferenceNumber, t.MonthName, t.Remarks, t.CreatedAt
                FROM transactions t
                LEFT JOIN members m ON t.MemberID = m.MemberID
                LEFT JOIN academic_years ay ON t.YearID = ay.YearID
                ORDER BY t.TransactionDate DESC
            ");
            
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . htmlspecialchars($row['TransactionID']) . '</td>';
                echo '<td>' . htmlspecialchars($row['MemberID']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($row['MemberName']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($row['YearID']) . '</td>';
                echo '<td>' . htmlspecialchars($row['YearName']) . '</td>';
                echo '<td class="date-cell">' . htmlspecialchars($row['TransactionDate']) . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['CollectionAmount'], 2) . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['DistributedAmount'], 2) . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['InterestAmount'], 2) . '</td>';
                echo '<td style="background-color: #e0e7ff; text-align: center;"><strong>' . htmlspecialchars($row['PaymentMode']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($row['ReferenceNumber']) . '</td>';
                echo '<td>' . htmlspecialchars($row['MonthName']) . '</td>';
                echo '<td>' . htmlspecialchars($row['Remarks']) . '</td>';
                echo '<td class="date-cell">' . htmlspecialchars($row['CreatedAt']) . '</td>';
                echo '</tr>';
                $rowNum++;
            }
            echo '</table>';
        }
        
    } elseif ($exportType === 'years') {
        if ($format === 'csv') {
            fputcsv($output, ['YearID', 'YearName', 'StartDate', 'EndDate', 'InterestRate', 'IsActive']);
            $stmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate, InterestRate, IsActive FROM academic_years ORDER BY StartDate DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        } else {
            // Colorful Excel export for academic years
            echo '<table>
                <tr class="title-row">
                    <td colspan="6">📅 ACADEMIC YEARS DATA EXPORT - ' . date('F d, Y') . '</td>
                </tr>
                <tr class="header-row">
                    <th>Year ID</th>
                    <th>Year Name</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Interest Rate (%)</th>
                    <th>Status</th>
                </tr>';
            
            $stmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate, InterestRate, IsActive FROM academic_years ORDER BY StartDate DESC");
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                $statusClass = ($row['IsActive'] == 1) ? 'status-active' : 'status-inactive';
                $statusText = ($row['IsActive'] == 1) ? '✓ ACTIVE' : '✗ INACTIVE';
                
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . htmlspecialchars($row['YearID']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($row['YearName']) . '</strong></td>';
                echo '<td class="date-cell">' . htmlspecialchars($row['StartDate']) . '</td>';
                echo '<td class="date-cell">' . htmlspecialchars($row['EndDate']) . '</td>';
                echo '<td class="numeric-cell">' . number_format($row['InterestRate'], 2) . ' %</td>';
                echo '<td class="' . $statusClass . '">' . $statusText . '</td>';
                echo '</tr>';
                $rowNum++;
            }
            echo '</table>';
        }
        
    } elseif ($exportType === 'custom_fields') {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'custom_field_definitions'");
        if ($tableCheck->rowCount() == 0) {
            if ($format === 'csv') {
                fputcsv($output, ['Error: Custom fields table does not exist. Please run CREATE_CUSTOM_FIELDS_TABLE.sql']);
            } else {
                echo '<h2 style="color: red; padding: 20px;">⚠️ Error: Custom fields table does not exist</h2>';
                echo '<p style="padding: 20px;">Please run the CREATE_CUSTOM_FIELDS_TABLE.sql script to create the required tables.</p>';
            }
        } else {
            if ($format === 'csv') {
                fputcsv($output, ['FieldID', 'FieldName', 'FieldLabel', 'FieldType', 'EntityType', 'FieldOptions', 'DefaultValue', 'IsRequired', 'IsActive', 'DisplayOrder', 'CreatedAt']);
                $stmt = $pdo->query("SELECT FieldID, FieldName, FieldLabel, FieldType, EntityType, FieldOptions, DefaultValue, IsRequired, IsActive, DisplayOrder, CreatedAt FROM custom_field_definitions ORDER BY EntityType, DisplayOrder");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
            } else {
                // Colorful Excel export for custom fields
                echo '<table>
                    <tr class="title-row">
                        <td colspan="11">⚙️ CUSTOM FIELD DEFINITIONS EXPORT - ' . date('F d, Y') . '</td>
                    </tr>
                    <tr class="header-row">
                        <th>Field ID</th>
                        <th>Field Name</th>
                        <th>Field Label</th>
                        <th>Type</th>
                        <th>Entity Type</th>
                        <th>Options</th>
                        <th>Default Value</th>
                        <th>Required</th>
                        <th>Active</th>
                        <th>Display Order</th>
                        <th>Created At</th>
                    </tr>';
                
                $stmt = $pdo->query("SELECT FieldID, FieldName, FieldLabel, FieldType, EntityType, FieldOptions, DefaultValue, IsRequired, IsActive, DisplayOrder, CreatedAt FROM custom_field_definitions ORDER BY EntityType, DisplayOrder");
                $rowNum = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                    $requiredClass = ($row['IsRequired'] == 1) ? 'status-active' : 'status-inactive';
                    $requiredText = ($row['IsRequired'] == 1) ? '✓ Yes' : '✗ No';
                    $activeClass = ($row['IsActive'] == 1) ? 'status-active' : 'status-inactive';
                    $activeText = ($row['IsActive'] == 1) ? '✓ Active' : '✗ Inactive';
                    
                    echo '<tr class="' . $rowClass . '">';
                    echo '<td>' . htmlspecialchars($row['FieldID']) . '</td>';
                    echo '<td><strong>' . htmlspecialchars($row['FieldName']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($row['FieldLabel']) . '</td>';
                    echo '<td style="background-color: #e0e7ff; text-align: center;"><strong>' . htmlspecialchars($row['FieldType']) . '</strong></td>';
                    echo '<td style="background-color: #fef3c7; text-align: center;"><strong>' . htmlspecialchars($row['EntityType']) . '</strong></td>';
                    echo '<td style="font-size: 11px;">' . htmlspecialchars($row['FieldOptions']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['DefaultValue']) . '</td>';
                    echo '<td class="' . $requiredClass . '">' . $requiredText . '</td>';
                    echo '<td class="' . $activeClass . '">' . $activeText . '</td>';
                    echo '<td class="numeric-cell">' . $row['DisplayOrder'] . '</td>';
                    echo '<td class="date-cell">' . htmlspecialchars($row['CreatedAt']) . '</td>';
                    echo '</tr>';
                    $rowNum++;
                }
                echo '</table>';
            }
        }
        
    } elseif ($exportType === 'custom_values') {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'custom_field_values'");
        if ($tableCheck->rowCount() == 0) {
            if ($format === 'csv') {
                fputcsv($output, ['Error: Custom field values table does not exist. Please run CREATE_CUSTOM_FIELDS_TABLE.sql']);
            } else {
                echo '<h2 style="color: red; padding: 20px;">⚠️ Error: Custom field values table does not exist</h2>';
                echo '<p style="padding: 20px;">Please run the CREATE_CUSTOM_FIELDS_TABLE.sql script to create the required tables.</p>';
            }
        } else {
            if ($format === 'csv') {
                fputcsv($output, ['ValueID', 'FieldID', 'FieldName', 'EntityType', 'EntityID', 'FieldValue', 'CreatedAt']);
                $stmt = $pdo->query("
                    SELECT cv.ValueID, cv.FieldID, cfd.FieldName, cv.EntityType, cv.EntityID, cv.FieldValue, cv.CreatedAt 
                    FROM custom_field_values cv
                    LEFT JOIN custom_field_definitions cfd ON cv.FieldID = cfd.FieldID
                    ORDER BY cv.EntityType, cv.EntityID, cv.FieldID
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
            } else {
                // Colorful Excel export for custom values
                echo '<table>
                    <tr class="title-row">
                        <td colspan="7">💾 CUSTOM FIELD VALUES EXPORT - ' . date('F d, Y') . '</td>
                    </tr>
                    <tr class="header-row">
                        <th>Value ID</th>
                        <th>Field ID</th>
                        <th>Field Name</th>
                        <th>Entity Type</th>
                        <th>Entity ID</th>
                        <th>Field Value</th>
                        <th>Created At</th>
                    </tr>';
                
                $stmt = $pdo->query("
                    SELECT cv.ValueID, cv.FieldID, cfd.FieldName, cv.EntityType, cv.EntityID, cv.FieldValue, cv.CreatedAt 
                    FROM custom_field_values cv
                    LEFT JOIN custom_field_definitions cfd ON cv.FieldID = cfd.FieldID
                    ORDER BY cv.EntityType, cv.EntityID, cv.FieldID
                ");
                
                $rowNum = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                    echo '<tr class="' . $rowClass . '">';
                    echo '<td>' . htmlspecialchars($row['ValueID']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['FieldID']) . '</td>';
                    echo '<td><strong>' . htmlspecialchars($row['FieldName']) . '</strong></td>';
                    echo '<td style="background-color: #fef3c7; text-align: center;"><strong>' . htmlspecialchars($row['EntityType']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($row['EntityID']) . '</td>';
                    echo '<td style="background-color: #dbeafe;">' . htmlspecialchars($row['FieldValue']) . '</td>';
                    echo '<td class="date-cell">' . htmlspecialchars($row['CreatedAt']) . '</td>';
                    echo '</tr>';
                    $rowNum++;
                }
                echo '</table>';
            }
        }
        
    } elseif ($exportType === 'all') {
        if ($format === 'csv') {
            fputcsv($output, ['=== MEMBERS ===']);
            fputcsv($output, ['MemberID', 'MemberName', 'AadharNumber', 'Phone', 'Email', 'Address', 'CreatedAt']);
            $stmt = $pdo->query("SELECT MemberID, MemberName, AadharNumber, Phone, Email, Address, CreatedAt FROM members ORDER BY MemberName ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            fputcsv($output, []);
            fputcsv($output, ['=== TRANSACTIONS ===']);
            fputcsv($output, ['TransactionID', 'MemberID', 'YearID', 'TransactionDate', 'CollectionAmount', 'DistributedAmount', 'InterestAmount', 'PaymentMode', 'ReferenceNumber', 'MonthName', 'Remarks']);
            $stmt = $pdo->query("SELECT TransactionID, MemberID, YearID, TransactionDate, CollectionAmount, DistributedAmount, InterestAmount, PaymentMode, ReferenceNumber, MonthName, Remarks FROM transactions ORDER BY TransactionDate DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            fputcsv($output, []);
            fputcsv($output, ['=== ACADEMIC YEARS ===']);
            fputcsv($output, ['YearID', 'YearName', 'StartDate', 'EndDate', 'InterestRate', 'IsActive']);
            $stmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate, InterestRate, IsActive FROM academic_years ORDER BY StartDate DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        } else {
            // Colorful Excel export for all data
            echo '<h1 style="text-align: center; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: white; padding: 20px; border-radius: 10px;">🎯 COMPLETE DATABASE EXPORT - ' . date('F d, Y') . '</h1>';
            
            // Members section
            echo '<br><table>
                <tr style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white;">
                    <td colspan="7" style="font-size: 16px; font-weight: bold; padding: 12px;">📊 MEMBERS DATA</td>
                </tr>
                <tr class="header-row">
                    <th>ID</th><th>Name</th><th>Aadhar</th><th>Phone</th><th>Email</th><th>Address</th><th>Created</th>
                </tr>';
            $stmt = $pdo->query("SELECT MemberID, MemberName, AadharNumber, Phone, Email, Address, CreatedAt FROM members ORDER BY MemberName ASC LIMIT 100");
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . $row['MemberID'] . '</td><td><strong>' . htmlspecialchars($row['MemberName']) . '</strong></td>';
                echo '<td>' . $row['AadharNumber'] . '</td><td>' . $row['Phone'] . '</td>';
                echo '<td>' . $row['Email'] . '</td><td>' . $row['Address'] . '</td>';
                echo '<td class="date-cell">' . $row['CreatedAt'] . '</td></tr>';
                $rowNum++;
            }
            echo '</table><br><br>';
            
            // Transactions section
            echo '<table>
                <tr style="background: linear-gradient(135deg, #ec4899 0%, #f43f5e 100%); color: white;">
                    <td colspan="11" style="font-size: 16px; font-weight: bold; padding: 12px;">💰 TRANSACTIONS DATA</td>
                </tr>
                <tr class="header-row">
                    <th>ID</th><th>Member</th><th>Year</th><th>Date</th><th>Collection</th><th>Distribution</th><th>Interest</th><th>Mode</th><th>Reference</th><th>Month</th><th>Remarks</th>
                </tr>';
            $stmt = $pdo->query("SELECT t.TransactionID, m.MemberName, ay.YearName, t.TransactionDate, t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, t.ReferenceNumber, t.MonthName, t.Remarks FROM transactions t LEFT JOIN members m ON t.MemberID = m.MemberID LEFT JOIN academic_years ay ON t.YearID = ay.YearID ORDER BY t.TransactionDate DESC LIMIT 100");
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . $row['TransactionID'] . '</td><td><strong>' . htmlspecialchars($row['MemberName']) . '</strong></td>';
                echo '<td>' . $row['YearName'] . '</td><td class="date-cell">' . $row['TransactionDate'] . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['CollectionAmount'], 2) . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['DistributedAmount'], 2) . '</td>';
                echo '<td class="numeric-cell">₹ ' . number_format($row['InterestAmount'], 2) . '</td>';
                echo '<td style="background-color: #e0e7ff; text-align: center;"><strong>' . $row['PaymentMode'] . '</strong></td>';
                echo '<td>' . $row['ReferenceNumber'] . '</td><td>' . $row['MonthName'] . '</td>';
                echo '<td>' . $row['Remarks'] . '</td></tr>';
                $rowNum++;
            }
            echo '</table><br><br>';
            
            // Academic Years section
            echo '<table>
                <tr style="background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); color: white;">
                    <td colspan="6" style="font-size: 16px; font-weight: bold; padding: 12px;">📅 ACADEMIC YEARS DATA</td>
                </tr>
                <tr class="header-row">
                    <th>ID</th><th>Year Name</th><th>Start Date</th><th>End Date</th><th>Interest Rate</th><th>Status</th>
                </tr>';
            $stmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate, InterestRate, IsActive FROM academic_years ORDER BY StartDate DESC");
            $rowNum = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowClass = ($rowNum % 2 == 0) ? 'data-row-even' : 'data-row-odd';
                $statusClass = ($row['IsActive'] == 1) ? 'status-active' : 'status-inactive';
                $statusText = ($row['IsActive'] == 1) ? '✓ ACTIVE' : '✗ INACTIVE';
                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . $row['YearID'] . '</td><td><strong>' . $row['YearName'] . '</strong></td>';
                echo '<td class="date-cell">' . $row['StartDate'] . '</td><td class="date-cell">' . $row['EndDate'] . '</td>';
                echo '<td class="numeric-cell">' . number_format($row['InterestRate'], 2) . ' %</td>';
                echo '<td class="' . $statusClass . '">' . $statusText . '</td></tr>';
                $rowNum++;
            }
            echo '</table>';
        }
    }
    
    if ($format === 'excel') {
        echo '</body></html>';
    }
    
} catch (Exception $e) {
    if ($format === 'csv') {
        fputcsv($output, ['Error exporting data: ' . $e->getMessage()]);
    } else {
        echo '<p style="color: red; font-weight: bold;">Error: ' . $e->getMessage() . '</p>';
    }
}

if ($format === 'csv') {
    fclose($output);
}
exit;
?>
