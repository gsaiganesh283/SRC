<?php
// Enhanced Excel Sample Generator with Dropdowns
// URL: superadmin/download-sample-excel.php

require_once 'session.php';
require_once '../config/config.php';

$sampleType = $_GET['type'] ?? 'members';

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename=sample_' . $sampleType . '.xls');
header('Pragma: no-cache');
header('Expires: 0');

?>
<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th {
            background-color: #4f46e5;
            color: white;
            font-weight: bold;
            padding: 10px;
            border: 1px solid #000;
            text-align: left;
        }
        td {
            border: 1px solid #ccc;
            padding: 8px;
        }
        .header-row {
            background-color: #e0e7ff;
            font-weight: bold;
        }
        .sample-row {
            background-color: #f9fafb;
        }
        .instruction {
            background-color: #fef3c7;
            padding: 15px;
            margin-bottom: 20px;
            border: 2px solid #f59e0b;
        }
    </style>
</head>
<body>

<?php if ($sampleType === 'members'): ?>
    <div class="instruction">
        <h2>📋 Member Import Instructions</h2>
        <p><strong>Required Columns:</strong> MemberName, AadharNumber, Phone, Email, Address</p>
        <p><strong>Note:</strong> Fill in the data below the header row. Delete sample rows before importing.</p>
    </div>
    
    <table>
        <tr class="header-row">
            <th>MemberName</th>
            <th>AadharNumber</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Address</th>
        </tr>
        <tr class="sample-row">
            <td>John Doe</td>
            <td>123456789012</td>
            <td>9876543210</td>
            <td>john@example.com</td>
            <td>123 Main Street, City</td>
        </tr>
        <tr class="sample-row">
            <td>Jane Smith</td>
            <td>234567890123</td>
            <td>9876543211</td>
            <td>jane@example.com</td>
            <td>456 Oak Avenue, Town</td>
        </tr>
        <tr class="sample-row">
            <td>Robert Johnson</td>
            <td>345678901234</td>
            <td>9876543212</td>
            <td>robert@example.com</td>
            <td>789 Pine Road, Village</td>
        </tr>
        <!-- Empty rows for data entry -->
        <?php for ($i = 0; $i < 20; $i++): ?>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </table>

<?php elseif ($sampleType === 'transactions'): ?>
    <?php
    // Fetch members for dropdown
    $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName");
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch academic years
    $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
    $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $paymentModes = ['Cash', 'Online', 'Cheque', 'NEFT/RTGS', 'UPI'];
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $remarksOptions = ['Monthly collection', 'Distribution with interest', 'Penalty payment', 'Bonus payment', 'Adjustment', 'Special collection'];
    ?>
    
    <div class="instruction">
        <h2>📋 Transaction Import Instructions</h2>
        <p><strong>Required Columns:</strong> MemberID, YearID, TransactionDate, CollectionAmount, DistributedAmount, InterestAmount, PaymentMode, ReferenceNumber, MonthName, Remarks</p>
        <p><strong>Date Format:</strong> YYYY-MM-DD (e.g., 2025-01-15)</p>
        <p><strong>Important:</strong> Use the reference sheets below for valid IDs and options</p>
    </div>
    
    <h3>Main Transaction Data</h3>
    <table>
        <tr class="header-row">
            <th>MemberID</th>
            <th>YearID</th>
            <th>TransactionDate</th>
            <th>CollectionAmount</th>
            <th>DistributedAmount</th>
            <th>InterestAmount</th>
            <th>PaymentMode</th>
            <th>ReferenceNumber</th>
            <th>MonthName</th>
            <th>Remarks</th>
        </tr>
        <tr class="sample-row">
            <td>1</td>
            <td>1</td>
            <td>2025-01-15</td>
            <td>1000</td>
            <td>0</td>
            <td>0</td>
            <td>Cash</td>
            <td></td>
            <td>January</td>
            <td>Monthly collection</td>
        </tr>
        <tr class="sample-row">
            <td>2</td>
            <td>1</td>
            <td>2025-01-15</td>
            <td>1500</td>
            <td>0</td>
            <td>0</td>
            <td>Online</td>
            <td>TXN123456</td>
            <td>January</td>
            <td>Online payment</td>
        </tr>
        <tr class="sample-row">
            <td>1</td>
            <td>1</td>
            <td>2025-02-15</td>
            <td>0</td>
            <td>500</td>
            <td>50</td>
            <td>Cash</td>
            <td></td>
            <td>February</td>
            <td>Distribution with interest</td>
        </tr>
        <!-- Empty rows for data entry -->
        <?php for ($i = 0; $i < 20; $i++): ?>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Member IDs</h3>
    <table>
        <tr class="header-row">
            <th>MemberID</th>
            <th>Member Name</th>
        </tr>
        <?php foreach ($members as $member): ?>
        <tr>
            <td><?php echo $member['MemberID']; ?></td>
            <td><?php echo htmlspecialchars($member['MemberName']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Year IDs</h3>
    <table>
        <tr class="header-row">
            <th>YearID</th>
            <th>Year Name</th>
        </tr>
        <?php foreach ($years as $year): ?>
        <tr>
            <td><?php echo $year['YearID']; ?></td>
            <td><?php echo htmlspecialchars($year['YearName']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Payment Modes</h3>
    <table>
        <tr class="header-row">
            <th>Payment Mode</th>
        </tr>
        <?php foreach ($paymentModes as $mode): ?>
        <tr>
            <td><?php echo $mode; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Months</h3>
    <table>
        <tr class="header-row">
            <th>Month Name</th>
        </tr>
        <?php foreach ($months as $month): ?>
        <tr>
            <td><?php echo $month; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Common Remarks</h3>
    <table>
        <tr class="header-row">
            <th>Remark</th>
        </tr>
        <?php foreach ($remarksOptions as $remark): ?>
        <tr>
            <td><?php echo $remark; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

<?php elseif ($sampleType === 'years'): ?>
    <div class="instruction">
        <h2>📋 Academic Year Import Instructions</h2>
        <p><strong>Required Columns:</strong> YearName, StartDate, EndDate, InterestRate</p>
        <p><strong>Date Format:</strong> YYYY-MM-DD</p>
        <p><strong>Interest Rate:</strong> Enter as decimal (e.g., 5.0 for 5%)</p>
    </div>
    
    <table>
        <tr class="header-row">
            <th>YearName</th>
            <th>StartDate</th>
            <th>EndDate</th>
            <th>InterestRate</th>
        </tr>
        <tr class="sample-row">
            <td>2024-2025</td>
            <td>2024-04-01</td>
            <td>2025-03-31</td>
            <td>5.0</td>
        </tr>
        <tr class="sample-row">
            <td>2025-2026</td>
            <td>2025-04-01</td>
            <td>2026-03-31</td>
            <td>5.5</td>
        </tr>
        <tr class="sample-row">
            <td>2026-2027</td>
            <td>2026-04-01</td>
            <td>2027-03-31</td>
            <td>6.0</td>
        </tr>
        <!-- Empty rows for data entry -->
        <?php for ($i = 0; $i < 10; $i++): ?>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </table>

<?php elseif ($sampleType === 'custom_fields'): ?>
    <?php
    $fieldTypes = ['text', 'number', 'email', 'phone', 'date', 'dropdown', 'textarea', 'checkbox'];
    $entityTypes = ['member', 'transaction'];
    ?>
    
    <div class="instruction">
        <h2>📋 Custom Fields Import Instructions</h2>
        <p><strong>Required Columns:</strong> FieldName, FieldLabel, FieldType, EntityType, FieldOptions, IsRequired</p>
        <p><strong>FieldOptions:</strong> For dropdown type, use JSON format: ["Option1", "Option2"]</p>
        <p><strong>IsRequired:</strong> Use 1 for required, 0 for optional</p>
    </div>
    
    <table>
        <tr class="header-row">
            <th>FieldName</th>
            <th>FieldLabel</th>
            <th>FieldType</th>
            <th>EntityType</th>
            <th>FieldOptions</th>
            <th>IsRequired</th>
        </tr>
        <tr class="sample-row">
            <td>occupation</td>
            <td>Occupation</td>
            <td>text</td>
            <td>member</td>
            <td></td>
            <td>0</td>
        </tr>
        <tr class="sample-row">
            <td>blood_group</td>
            <td>Blood Group</td>
            <td>dropdown</td>
            <td>member</td>
            <td>["A+", "A-", "B+", "B-", "O+", "O-", "AB+", "AB-"]</td>
            <td>0</td>
        </tr>
        <tr class="sample-row">
            <td>emergency_contact</td>
            <td>Emergency Contact</td>
            <td>phone</td>
            <td>member</td>
            <td></td>
            <td>0</td>
        </tr>
        <!-- Empty rows for data entry -->
        <?php for ($i = 0; $i < 15; $i++): ?>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Field Types</h3>
    <table>
        <tr class="header-row">
            <th>Field Type</th>
        </tr>
        <?php foreach ($fieldTypes as $type): ?>
        <tr>
            <td><?php echo $type; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <br><br>
    <h3>📖 Reference: Valid Entity Types</h3>
    <table>
        <tr class="header-row">
            <th>Entity Type</th>
        </tr>
        <?php foreach ($entityTypes as $entity): ?>
        <tr>
            <td><?php echo $entity; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

<?php elseif ($sampleType === 'custom_values'): ?>
    <?php
    // Fetch custom fields for reference
    $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
    $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
    <div class="instruction">
        <h2>📋 Custom Field Values Import Instructions</h2>
        <p><strong>Required Columns:</strong> FieldID, EntityType, EntityID, FieldValue</p>
        <p><strong>EntityType:</strong> Either 'member' or 'transaction'</p>
        <p><strong>EntityID:</strong> The ID of the member or transaction</p>
    </div>
    
    <table>
        <tr class="header-row">
            <th>FieldID</th>
            <th>EntityType</th>
            <th>EntityID</th>
            <th>FieldValue</th>
        </tr>
        <tr class="sample-row">
            <td>1</td>
            <td>member</td>
            <td>1</td>
            <td>Software Engineer</td>
        </tr>
        <tr class="sample-row">
            <td>2</td>
            <td>member</td>
            <td>1</td>
            <td>A+</td>
        </tr>
        <tr class="sample-row">
            <td>3</td>
            <td>member</td>
            <td>2</td>
            <td>9876543210</td>
        </tr>
        <!-- Empty rows for data entry -->
        <?php for ($i = 0; $i < 20; $i++): ?>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </table>
    
    <?php if (!empty($fields)): ?>
    <br><br>
    <h3>📖 Reference: Available Custom Fields</h3>
    <table>
        <tr class="header-row">
            <th>FieldID</th>
            <th>Field Label</th>
            <th>Entity Type</th>
        </tr>
        <?php foreach ($fields as $field): ?>
        <tr>
            <td><?php echo $field['FieldID']; ?></td>
            <td><?php echo htmlspecialchars($field['FieldLabel']); ?></td>
            <td><?php echo $field['EntityType']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
