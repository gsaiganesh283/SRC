<?php
// Sample File Generator
// URL: superadmin/download-sample.php

require_once 'session.php';

$sampleType = $_GET['type'] ?? 'members';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=sample_' . $sampleType . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

if ($sampleType === 'members') {
    // Members sample
    fputcsv($output, ['MemberName', 'AadharNumber', 'Phone', 'Email', 'Address']);
    fputcsv($output, ['John Doe', '123456789012', '9876543210', 'john@example.com', '123 Main Street, City']);
    fputcsv($output, ['Jane Smith', '234567890123', '9876543211', 'jane@example.com', '456 Oak Avenue, Town']);
    fputcsv($output, ['Robert Johnson', '345678901234', '9876543212', 'robert@example.com', '789 Pine Road, Village']);
    
} elseif ($sampleType === 'transactions') {
    // Transactions sample
    fputcsv($output, ['MemberID', 'YearID', 'TransactionDate', 'CollectionAmount', 'DistributedAmount', 'InterestAmount', 'PaymentMode', 'ReferenceNumber', 'MonthName', 'Remarks']);
    fputcsv($output, ['1', '1', '2025-01-15', '1000', '0', '0', 'Cash', '', 'January', 'Monthly collection']);
    fputcsv($output, ['2', '1', '2025-01-15', '1500', '0', '0', 'Online', 'TXN123456', 'January', 'Online payment']);
    fputcsv($output, ['1', '1', '2025-02-15', '0', '500', '50', 'Cash', '', 'February', 'Distribution with interest']);
    
} elseif ($sampleType === 'years') {
    // Academic years sample
    fputcsv($output, ['YearName', 'StartDate', 'EndDate', 'InterestRate']);
    fputcsv($output, ['2024-2025', '2024-04-01', '2025-03-31', '5.0']);
    fputcsv($output, ['2025-2026', '2025-04-01', '2026-03-31', '5.5']);
    fputcsv($output, ['2026-2027', '2026-04-01', '2027-03-31', '6.0']);
    
} elseif ($sampleType === 'custom_fields') {
    // Custom field definitions sample
    fputcsv($output, ['FieldName', 'FieldLabel', 'FieldType', 'EntityType', 'FieldOptions', 'IsRequired']);
    fputcsv($output, ['occupation', 'Occupation', 'text', 'member', '', '0']);
    fputcsv($output, ['blood_group', 'Blood Group', 'dropdown', 'member', '["A+", "A-", "B+", "B-", "O+", "O-", "AB+", "AB-"]', '0']);
    fputcsv($output, ['emergency_contact', 'Emergency Contact Number', 'phone', 'member', '', '0']);
    fputcsv($output, ['annual_income', 'Annual Income', 'number', 'member', '', '0']);
    fputcsv($output, ['payment_category', 'Payment Category', 'dropdown', 'transaction', '["Regular", "Bonus", "Penalty", "Adjustment"]', '0']);
    
} elseif ($sampleType === 'custom_values') {
    // Custom field values sample
    fputcsv($output, ['FieldID', 'EntityType', 'EntityID', 'FieldValue']);
    fputcsv($output, ['1', 'member', '1', 'Software Engineer']);
    fputcsv($output, ['2', 'member', '1', 'A+']);
    fputcsv($output, ['3', 'member', '1', '9876543210']);
    fputcsv($output, ['4', 'member', '1', '500000']);
    fputcsv($output, ['7', 'transaction', '1', 'Regular']);
}

fclose($output);
exit;
?>
