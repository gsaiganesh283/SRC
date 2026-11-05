<?php
// Online Excel Editor for Data Entry
// URL: superadmin/online-excel-editor.php

require_once 'session.php';
require_once '../config/config.php';

$sampleType = $_GET['type'] ?? 'members';

// Fetch data for dropdowns
$members = [];
$years = [];
try {
    $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC");
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
    $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $members = [];
    $years = [];
}

$paymentModes = ['Cash', 'Online', 'Cheque', 'NEFT/RTGS', 'UPI'];
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$remarksOptions = ['Monthly collection', 'Distribution with interest', 'Penalty payment', 'Bonus payment', 'Adjustment', 'Special collection'];
$fieldTypes = ['text', 'number', 'email', 'phone', 'date', 'dropdown', 'textarea', 'checkbox'];
$entityTypes = ['member', 'transaction'];

$fields = [];
try {
    $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
    $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fields = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Excel Editor - <?php echo ucfirst($sampleType); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: white;
            color: #4f46e5;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        .toolbar {
            padding: 20px 30px;
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .toolbar label {
            font-weight: 600;
            color: #374151;
        }

        .toolbar input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            width: 100px;
        }

        .table-container {
            padding: 30px;
            overflow-x: auto;
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .excel-table th {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .excel-table td {
            padding: 12px;
            border: 1px solid #e5e7eb;
        }

        .excel-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .excel-table tbody tr:hover {
            background: #f3f4f6;
        }

        .excel-table input, .excel-table select {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }

        .excel-table input:focus, .excel-table select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .row-number {
            background: #e5e7eb;
            text-align: center;
            font-weight: 600;
            color: #6b7280;
            width: 50px;
        }

        .action-buttons {
            padding: 0 30px 30px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        .info-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 0 30px 20px;
            border-radius: 4px;
        }

        .info-box strong {
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-table"></i>
                Online Excel Editor - <?php echo ucfirst($sampleType); ?>
            </h1>
            <button class="btn btn-primary" onclick="window.location.href='data-import-export.php'">
                <i class="fas fa-arrow-left"></i> Back to Import/Export
            </button>
        </div>

        <div class="toolbar">
            <button class="btn btn-success" onclick="addSingleRow()">
                <i class="fas fa-plus"></i> Add New Row
            </button>
            <button class="btn btn-success" onclick="downloadCSV()">
                <i class="fas fa-download"></i> Download as CSV
            </button>
            <?php if ($sampleType === 'transactions'): ?>
            <button class="btn btn-success" onclick="applyDefaultsToAll()" style="background: #f59e0b;">
                <i class="fas fa-magic"></i> Apply Defaults to All Rows
            </button>
            <?php endif; ?>
        </div>

        <?php if ($sampleType === 'transactions'): ?>
        <div class="info-box">
            <strong>💡 Tip:</strong> Use the dropdowns to select Member IDs and Year IDs. Reference sheets show all available values.
        </div>

        <div class="toolbar" style="background: white; border-bottom: 2px solid #e5e7eb;">
            <h3 style="color: #374151; width: 100%; margin-bottom: 15px;">
                <i class="fas fa-magic" style="color: #7c3aed;"></i> Default Value Selector
            </h3>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; width: 100%;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Year ID</label>
                    <select id="defaultYearID" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">None</option>
                        <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year['YearID']; ?>"><?php echo $year['YearID']; ?> - <?php echo htmlspecialchars($year['YearName']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Payment Mode</label>
                    <select id="defaultPaymentMode" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">None</option>
                        <?php foreach ($paymentModes as $mode): ?>
                        <option value="<?php echo $mode; ?>"><?php echo $mode; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Month</label>
                    <select id="defaultMonth" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">None</option>
                        <?php foreach ($months as $month): ?>
                        <option value="<?php echo $month; ?>"><?php echo $month; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Transaction Date</label>
                    <input type="date" id="defaultDate" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Collection Amount</label>
                    <input type="number" id="defaultCollection" value="0" step="0.01" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Distributed Amount</label>
                    <input type="number" id="defaultDistributed" value="0" step="0.01" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Interest Amount</label>
                    <input type="number" id="defaultInterest" value="0" step="0.01" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Fixed Amount</label>
                    <input type="number" id="defaultFixedAmount" value="0" step="0.01" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Reference Number</label>
                    <input type="text" id="defaultReference" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; color: #6b7280;">Default Remarks</label>
                    <select id="defaultRemarks" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">None</option>
                        <?php foreach ($remarksOptions as $remark): ?>
                        <option value="<?php echo $remark; ?>"><?php echo $remark; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <table class="excel-table" id="dataTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($sampleType === 'members'): ?>
                            <th>Member Name</th>
                            <th>Aadhar Number</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>Action</th>
                        <?php elseif ($sampleType === 'transactions'): ?>
                            <th>Member ID</th>
                            <th>Year ID</th>
                            <th>Transaction Date</th>
                            <th>Collection Amount</th>
                            <th>Distributed Amount</th>
                            <th>Interest Amount</th>
                            <th>Payment Mode</th>
                            <th>Reference Number</th>
                            <th>Month Name</th>
                            <th>Remarks</th>
                            <th>Action</th>
                        <?php elseif ($sampleType === 'years'): ?>
                            <th>Year Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Interest Rate</th>
                            <th>Action</th>
                        <?php elseif ($sampleType === 'custom_fields'): ?>
                            <th>Field Name</th>
                            <th>Field Label</th>
                            <th>Field Type</th>
                            <th>Entity Type</th>
                            <th>Field Options</th>
                            <th>Is Required</th>
                            <th>Action</th>
                        <?php elseif ($sampleType === 'custom_values'): ?>
                            <th>Field ID</th>
                            <th>Entity Type</th>
                            <th>Entity ID</th>
                            <th>Field Value</th>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tableBody">
                </tbody>
            </table>
        </div>

        <div class="action-buttons">
            <button class="btn btn-success" onclick="downloadCSV()">
                <i class="fas fa-download"></i> Download CSV for Import
            </button>
        </div>
    </div>

    <script>
        const sampleType = '<?php echo $sampleType; ?>';
        const members = <?php echo json_encode($members); ?>;
        const years = <?php echo json_encode($years); ?>;
        const paymentModes = <?php echo json_encode($paymentModes); ?>;
        const months = <?php echo json_encode($months); ?>;
        const remarksOptions = <?php echo json_encode($remarksOptions); ?>;
        const fieldTypes = <?php echo json_encode($fieldTypes); ?>;
        const entityTypes = <?php echo json_encode($entityTypes); ?>;
        const fields = <?php echo json_encode($fields); ?>;

        let rowCounter = 0;
        let usedMemberIDs = new Set();

        function addSingleRow() {
            rowCounter++;
            const tbody = document.getElementById('tableBody');
            const row = createRow(rowCounter);
            tbody.appendChild(row);
        }

        function addRowAfter(button) {
            rowCounter++;
            const currentRow = button.closest('tr');
            const newRow = createRow(rowCounter);
            currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
            renumberRows();
        }

        function deleteRow(button) {
            const row = button.closest('tr');
            
            // Remove member ID from used set if this is a transaction row
            if (sampleType === 'transactions') {
                const memberSelect = row.querySelector('select[data-field="memberid"]');
                if (memberSelect && memberSelect.value) {
                    usedMemberIDs.delete(parseInt(memberSelect.value));
                }
            }
            
            row.remove();
            renumberRows();
            
            if (sampleType === 'transactions') {
                updateAllMemberDropdowns();
            }
        }

        function renumberRows() {
            const rows = document.querySelectorAll('#tableBody tr');
            rows.forEach((row, index) => {
                const numberCell = row.querySelector('.row-number');
                if (numberCell) {
                    numberCell.textContent = index + 1;
                }
            });
            rowCounter = rows.length;
        }

        function getAvailableMembers() {
            return members.filter(m => !usedMemberIDs.has(m.MemberID));
        }

        function onMemberChange(select) {
            const oldValue = select.getAttribute('data-old-value');
            const newValue = select.value;
            
            // Remove old value from used set
            if (oldValue) {
                usedMemberIDs.delete(parseInt(oldValue));
            }
            
            // Add new value to used set
            if (newValue) {
                usedMemberIDs.add(parseInt(newValue));
                select.setAttribute('data-old-value', newValue);
            }
            
            // Update all member dropdowns
            updateAllMemberDropdowns();
        }

        function updateAllMemberDropdowns() {
            const allSelects = document.querySelectorAll('select[data-field="memberid"]');
            allSelects.forEach(select => {
                const currentValue = select.value;
                const availableMembers = getAvailableMembers();
                
                // Clear and rebuild options
                select.innerHTML = '<option value="">Select Member</option>';
                
                // Add current value first if it exists
                if (currentValue) {
                    const currentMember = members.find(m => m.MemberID == currentValue);
                    if (currentMember) {
                        const option = document.createElement('option');
                        option.value = currentMember.MemberID;
                        option.textContent = currentMember.MemberID + ' - ' + currentMember.MemberName;
                        option.selected = true;
                        select.appendChild(option);
                    }
                }
                
                // Add available members
                availableMembers.forEach(m => {
                    if (m.MemberID != currentValue) {
                        const option = document.createElement('option');
                        option.value = m.MemberID;
                        option.textContent = m.MemberID + ' - ' + m.MemberName;
                        select.appendChild(option);
                    }
                });
            });
        }

        function getDefaultValue(field) {
            if (sampleType !== 'transactions') return '';
            
            const defaults = {
                yearid: document.getElementById('defaultYearID')?.value || '',
                paymentmode: document.getElementById('defaultPaymentMode')?.value || '',
                month: document.getElementById('defaultMonth')?.value || '',
                date: document.getElementById('defaultDate')?.value || '',
                collection: document.getElementById('defaultCollection')?.value || '0',
                distributed: document.getElementById('defaultDistributed')?.value || '0',
                interest: document.getElementById('defaultInterest')?.value || '0',
                fixedamount: document.getElementById('defaultFixedAmount')?.value || '0',
                reference: document.getElementById('defaultReference')?.value || '',
                remarks: document.getElementById('defaultRemarks')?.value || ''
            };
            
            return defaults[field] || '';
        }

        function applyDefaultsToAll() {
            const rows = document.querySelectorAll('#tableBody tr');
            const defaultYearID = getDefaultValue('yearid');
            const defaultPaymentMode = getDefaultValue('paymentmode');
            const defaultMonth = getDefaultValue('month');
            const defaultDate = getDefaultValue('date');
            const defaultCollection = getDefaultValue('collection');
            const defaultDistributed = getDefaultValue('distributed');
            const defaultInterest = getDefaultValue('interest');
            const defaultFixedAmount = getDefaultValue('fixedamount');
            const defaultReference = getDefaultValue('reference');
            const defaultRemarks = getDefaultValue('remarks');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                
                // Year ID (column index 2)
                if (defaultYearID && cells[2]) {
                    const select = cells[2].querySelector('select');
                    if (select) select.value = defaultYearID;
                }
                
                // Transaction Date (column index 3)
                if (defaultDate && cells[3]) {
                    const input = cells[3].querySelector('input');
                    if (input) input.value = defaultDate;
                }
                
                // Collection Amount (column index 4)
                if (defaultCollection && cells[4]) {
                    const input = cells[4].querySelector('input');
                    if (input) input.value = defaultCollection;
                }
                
                // Distributed Amount (column index 5)
                if (defaultDistributed && cells[5]) {
                    const input = cells[5].querySelector('input');
                    if (input) input.value = defaultDistributed;
                }
                
                // Interest Amount (column index 6)
                if (defaultInterest && cells[6]) {
                    const input = cells[6].querySelector('input');
                    if (input) input.value = defaultInterest;
                }
                
                // Payment Mode (column index 7)
                if (defaultPaymentMode && cells[7]) {
                    const select = cells[7].querySelector('select');
                    if (select) select.value = defaultPaymentMode;
                }
                
                // Month Name (column index 9)
                if (defaultMonth && cells[9]) {
                    const select = cells[9].querySelector('select');
                    if (select) select.value = defaultMonth;
                }
                
                // Remarks (column index 10)
                if (defaultRemarks && cells[10]) {
                    const select = cells[10].querySelector('select');
                    if (select) select.value = defaultRemarks;
                }
            });
            
            // Show success message
            alert('Default values applied to all existing rows!');
        }

        function createRow(num) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td class="row-number">' + num + '</td>';
            
            if (sampleType === 'members') {
                tr.innerHTML += `
                    <td><input type="text" placeholder="Full Name"></td>
                    <td><input type="text" placeholder="12 digit Aadhar"></td>
                    <td><input type="text" placeholder="10 digit phone"></td>
                    <td><input type="email" placeholder="email@example.com"></td>
                    <td><input type="text" placeholder="Full Address"></td>
                    <td style="text-align: center;">
                        <button onclick="addRowAfter(this)" class="btn" style="background: #10b981; color: white; padding: 6px 12px; margin-right: 5px;" title="Add row below">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button onclick="deleteRow(this)" class="btn" style="background: #ef4444; color: white; padding: 6px 12px;" title="Delete this row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            } else if (sampleType === 'transactions') {
                const availableMembers = getAvailableMembers();
                const defaultYearID = getDefaultValue('yearid');
                const defaultPaymentMode = getDefaultValue('paymentmode');
                const defaultMonth = getDefaultValue('month');
                const defaultDate = getDefaultValue('date');
                const defaultCollection = getDefaultValue('collection');
                const defaultDistributed = getDefaultValue('distributed');
                const defaultInterest = getDefaultValue('interest');
                const defaultFixedAmount = getDefaultValue('fixedamount');
                const defaultReference = getDefaultValue('reference');
                const defaultRemarks = getDefaultValue('remarks');
                
                tr.innerHTML += `
                    <td>
                        <select data-field="memberid" onchange="onMemberChange(this)">
                            <option value="">Select Member</option>
                            ${availableMembers.map(m => `<option value="${m.MemberID}">${m.MemberID} - ${m.MemberName}</option>`).join('')}
                        </select>
                    </td>
                    <td>
                        <select>
                            <option value="">Select Year</option>
                            ${years.map(y => `<option value="${y.YearID}" ${y.YearID == defaultYearID ? 'selected' : ''}>${y.YearID} - ${y.YearName}</option>`).join('')}
                        </select>
                    </td>
                    <td><input type="date" value="${defaultDate}"></td>
                    <td><input type="number" value="${defaultCollection}" step="0.01"></td>
                    <td><input type="number" value="${defaultDistributed}" step="0.01"></td>
                    <td><input type="number" value="${defaultInterest}" step="0.01"></td>
                    <td>
                        <select>
                            <option value="">Select Mode</option>
                            ${paymentModes.map(mode => `<option value="${mode}" ${mode === defaultPaymentMode ? 'selected' : ''}>${mode}</option>`).join('')}
                        </select>
                    </td>
                    <td><input type="text" value="${defaultReference}" placeholder="Reference #"></td>
                    <td>
                        <select>
                            <option value="">Select Month</option>
                            ${months.map(month => `<option value="${month}" ${month === defaultMonth ? 'selected' : ''}>${month}</option>`).join('')}
                        </select>
                    </td>
                    <td>
                        <select>
                            <option value="">Select Remark</option>
                            ${remarksOptions.map(remark => `<option value="${remark}" ${remark === defaultRemarks ? 'selected' : ''}>${remark}</option>`).join('')}
                        </select>
                    </td>
                    <td style="text-align: center;">
                        <button onclick="addRowAfter(this)" class="btn" style="background: #10b981; color: white; padding: 6px 12px; margin-right: 5px;" title="Add row below">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button onclick="deleteRow(this)" class="btn" style="background: #ef4444; color: white; padding: 6px 12px;" title="Delete this row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            } else if (sampleType === 'years') {
                tr.innerHTML += `
                    <td><input type="text" placeholder="e.g., 2025-2026"></td>
                    <td><input type="date"></td>
                    <td><input type="date"></td>
                    <td><input type="number" value="0" step="0.01"></td>
                    <td style="text-align: center;">
                        <button onclick="addRowAfter(this)" class="btn" style="background: #10b981; color: white; padding: 6px 12px; margin-right: 5px;" title="Add row below">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button onclick="deleteRow(this)" class="btn" style="background: #ef4444; color: white; padding: 6px 12px;" title="Delete this row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            } else if (sampleType === 'custom_fields') {
                tr.innerHTML += `
                    <td><input type="text" placeholder="field_name"></td>
                    <td><input type="text" placeholder="Field Label"></td>
                    <td>
                        <select>
                            <option value="">Select Type</option>
                            ${fieldTypes.map(type => `<option value="${type}">${type}</option>`).join('')}
                        </select>
                    </td>
                    <td>
                        <select>
                            <option value="">Select Entity</option>
                            ${entityTypes.map(entity => `<option value="${entity}">${entity}</option>`).join('')}
                        </select>
                    </td>
                    <td><input type="text" placeholder="option1,option2"></td>
                    <td><input type="number" value="0" min="0" max="1"></td>
                    <td style="text-align: center;">
                        <button onclick="addRowAfter(this)" class="btn" style="background: #10b981; color: white; padding: 6px 12px; margin-right: 5px;" title="Add row below">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button onclick="deleteRow(this)" class="btn" style="background: #ef4444; color: white; padding: 6px 12px;" title="Delete this row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            } else if (sampleType === 'custom_values') {
                tr.innerHTML += `
                    <td>
                        <select>
                            <option value="">Select Field</option>
                            ${fields.map(f => `<option value="${f.FieldID}">${f.FieldID} - ${f.FieldLabel} (${f.EntityType})</option>`).join('')}
                        </select>
                    </td>
                    <td><input type="text" placeholder="member or transaction"></td>
                    <td><input type="number" placeholder="Entity ID"></td>
                    <td><input type="text" placeholder="Field Value"></td>
                    <td style="text-align: center;">
                        <button onclick="addRowAfter(this)" class="btn" style="background: #10b981; color: white; padding: 6px 12px; margin-right: 5px;" title="Add row below">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button onclick="deleteRow(this)" class="btn" style="background: #ef4444; color: white; padding: 6px 12px;" title="Delete this row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            }
            
            return tr;
        }

        function downloadCSV() {
            const table = document.getElementById('dataTable');
            let csv = [];
            
            // Get headers
            const headers = [];
            const headerCells = table.querySelectorAll('thead th');
            for (let i = 1; i < headerCells.length; i++) { // Skip row number column
                headers.push(headerCells[i].textContent);
            }
            csv.push(headers.join(','));
            
            // Get data rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const rowData = [];
                const cells = row.querySelectorAll('td');
                
                for (let i = 1; i < cells.length; i++) { // Skip row number column
                    const input = cells[i].querySelector('input, select');
                    if (input) {
                        let value = input.value || '';
                        // Escape commas and quotes
                        if (value.includes(',') || value.includes('"')) {
                            value = '"' + value.replace(/"/g, '""') + '"';
                        }
                        rowData.push(value);
                    }
                }
                
                // Only add row if it has at least one non-empty value
                if (rowData.some(val => val !== '' && val !== '0')) {
                    csv.push(rowData.join(','));
                }
            });
            
            // Create and download file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'sample_' + sampleType + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize with 1 row
        window.onload = function() {
            addSingleRow();
        };
    </script>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
