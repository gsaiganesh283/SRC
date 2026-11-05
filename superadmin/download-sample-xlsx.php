<?php
// Excel Sample Generator with Dropdown Support
// URL: superadmin/download-sample-xlsx.php

require_once 'session.php';
require_once '../config/config.php';

$sampleType = $_GET['type'] ?? 'members';

// Fetch data for dropdowns
$members = [];
$years = [];
$paymentModes = ['Cash', 'Online', 'Cheque', 'NEFT/RTGS', 'UPI'];
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$remarksOptions = ['Monthly collection', 'Distribution with interest', 'Penalty payment', 'Bonus payment', 'Adjustment', 'Special collection'];

if ($sampleType === 'transactions') {
    try {
        $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName");
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
        $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $members = [];
        $years = [];
    }
}

// Build dropdown lists
$memberList = [];
foreach ($members as $m) {
    $memberList[] = $m['MemberID'] . ' ' . $m['MemberName'];
}

$yearList = [];
foreach ($years as $y) {
    $yearList[] = $y['YearID'] . ' ' . $y['YearName'];
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename=sample_' . $sampleType . '.xls');
header('Pragma: no-cache');
header('Expires: 0');

// Start Excel XML
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 
 <Styles>
  <Style ss:ID="Header">
   <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
   <Interior ss:Color="#4F46E5" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="Data">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="Instruction">
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Size="10"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#F59E0B"/>
   </Borders>
  </Style>
  <Style ss:ID="Date">
   <NumberFormat ss:Format="Short Date"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
 </Styles>

<?php if ($sampleType === 'members'): ?>
 <Worksheet ss:Name="Members Data">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="150"/>
   <Column ss:Width="200"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="4">
     <Data ss:Type="String">Member Import Template - Fill data below the header row</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">AadharNumber</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Phone</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Email</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Address</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 50; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'transactions'): ?>
 <Worksheet ss:Name="Transaction Data">
  <Table>
   <Column ss:Width="200"/>
   <Column ss:Width="150"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="120"/>
   <Column ss:Width="110"/>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="180"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="9">
     <Data ss:Type="String">Transaction Import - See reference sheets for Member IDs and Year IDs</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">TransactionDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">CollectionAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">DistributedAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">InterestAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">PaymentMode</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">ReferenceNumber</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MonthName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Remarks</Data></Cell>
   </Row>
   <?php for ($i = 3; $i <= 52; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>See Member Reference sheet</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>See Year Reference sheet</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>Cash, Online, Cheque, NEFT/RTGS, UPI</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>January-December</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>Monthly collection, Distribution with interest, etc.</B></ss:Data></Comment>
    </Cell>
   </Row>
   <?php endfor; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <FreezePanes/>
   <FrozenNoScroll/>
   <SplitHorizontal>2</SplitHorizontal>
   <TopRowBottomPane>2</TopRowBottomPane>
  </WorksheetOptions>
 </Worksheet>
 
 <!-- Reference Sheet for Members -->
 <Worksheet ss:Name="Member Reference">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="150"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Member Name</Data></Cell>
   </Row>
   <?php foreach ($members as $member): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $member['MemberID']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo htmlspecialchars($member['MemberName']); ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Years -->
 <Worksheet ss:Name="Year Reference">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="120"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Year Name</Data></Cell>
   </Row>
   <?php foreach ($years as $year): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $year['YearID']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo htmlspecialchars($year['YearName']); ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Payment Modes -->
 <Worksheet ss:Name="Payment Modes">
  <Table>
   <Column ss:Width="120"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Payment Mode</Data></Cell>
   </Row>
   <?php foreach ($paymentModes as $mode): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $mode; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Months -->
 <Worksheet ss:Name="Months">
  <Table>
   <Column ss:Width="100"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Month Name</Data></Cell>
   </Row>
   <?php foreach ($months as $month): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $month; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Remarks -->
 <Worksheet ss:Name="Common Remarks">
  <Table>
   <Column ss:Width="180"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Remark</Data></Cell>
   </Row>
   <?php foreach ($remarksOptions as $remark): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $remark; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'years'): ?>
 <Worksheet ss:Name="Academic Years">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="3">
     <Data ss:Type="String">Academic Year Import - Date format: YYYY-MM-DD (e.g., 2025-01-15)</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">StartDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EndDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">InterestRate</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 20; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'custom_fields'): ?>
 <?php
 $fieldTypes = ['text', 'number', 'email', 'phone', 'date', 'dropdown', 'textarea', 'checkbox'];
 $entityTypes = ['member', 'transaction'];
 ?>
 <Worksheet ss:Name="Custom Fields">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="200"/>
   <Column ss:Width="80"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="5">
     <Data ss:Type="String">Custom Fields - See reference sheets for valid FieldType and EntityType values</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldLabel</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldOptions</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">IsRequired</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 30; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>text, number, email, phone, date, dropdown, textarea, checkbox</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>member, transaction</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Field Types -->
 <Worksheet ss:Name="Field Types">
  <Table>
   <Column ss:Width="100"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Field Type</Data></Cell>
   </Row>
   <?php foreach ($fieldTypes as $type): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $type; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Entity Types -->
 <Worksheet ss:Name="Entity Types">
  <Table>
   <Column ss:Width="100"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Entity Type</Data></Cell>
   </Row>
   <?php foreach ($entityTypes as $entity): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $entity; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'custom_values'): ?>
 <?php
 $fields = [];
 try {
     $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
     $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
 } catch (Exception $e) {
     $fields = [];
 }
 ?>
 <Worksheet ss:Name="Custom Values">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="200"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="3">
     <Data ss:Type="String">Custom Field Values - See Available Fields sheet for valid FieldID values</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldValue</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 30; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data>
     <Comment><ss:Data xmlns="http://www.w3.org/TR/REC-html40"><B>See Available Fields sheet</B></ss:Data></Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Available Fields -->
 <Worksheet ss:Name="Available Fields">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Field Label</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Entity Type</Data></Cell>
   </Row>
   <?php foreach ($fields as $field): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $field['FieldID']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo htmlspecialchars($field['FieldLabel']); ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo $field['EntityType']; ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

</Workbook>

$sampleType = $_GET['type'] ?? 'members';

// Fetch data for dropdowns
$members = [];
$years = [];
$paymentModes = ['Cash', 'Online', 'Cheque', 'NEFT/RTGS', 'UPI'];
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$remarksOptions = ['Monthly collection', 'Distribution with interest', 'Penalty payment', 'Bonus payment', 'Adjustment', 'Special collection'];

if ($sampleType === 'transactions') {
    try {
        $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName");
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
        $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $members = [];
        $years = [];
    }
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename=sample_' . $sampleType . '.xls');
header('Pragma: no-cache');
header('Expires: 0');

// Start Excel XML with namespaces
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 
 <Styles>
  <Style ss:ID="Header">
   <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
   <Interior ss:Color="#4F46E5" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="Data">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="Instruction">
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Size="10"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#F59E0B"/>
   </Borders>
  </Style>
  <Style ss:ID="Date">
   <NumberFormat ss:Format="Short Date"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
 </Styles>

<?php if ($sampleType === 'members'): ?>
 <Worksheet ss:Name="Members Data">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="150"/>
   <Column ss:Width="200"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="4">
     <Data ss:Type="String">📋 Member Import Template - Fill data below the header row</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">AadharNumber</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Phone</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Email</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Address</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 50; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'transactions'): ?>
 <Worksheet ss:Name="Transaction Data">
  <Table>
   <Column ss:Width="200"/>
   <Column ss:Width="150"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="120"/>
   <Column ss:Width="110"/>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="180"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="9">
     <Data ss:Type="String">📋 Transaction Import - Use dropdowns to select values. Click cells to see dropdown arrow.</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">TransactionDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">CollectionAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">DistributedAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">InterestAmount</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">PaymentMode</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">ReferenceNumber</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MonthName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Remarks</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 50; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <?php if (!empty($members)): ?>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', array_map(function($m) { return $m['MemberID'] . ' ' . htmlspecialchars($m['MemberName']); }, $members)); ?></Value>
     </DataValidation>
     <?php endif; ?>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <?php if (!empty($years)): ?>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', array_map(function($y) { return $y['YearID'] . ' ' . htmlspecialchars($y['YearName']); }, $years)); ?></Value>
     </DataValidation>
     <?php endif; ?>
    </Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', $paymentModes); ?></Value>
     </DataValidation>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', $months); ?></Value>
     </DataValidation>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', $remarksOptions); ?></Value>
     </DataValidation>
    </Cell>
   </Row>
   <?php endfor; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <FreezePanes/>
   <FrozenNoScroll/>
   <SplitHorizontal>2</SplitHorizontal>
   <TopRowBottomPane>2</TopRowBottomPane>
  </WorksheetOptions>
 </Worksheet>
 
 <!-- Reference Sheet for Members -->
 <Worksheet ss:Name="Member Reference">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="150"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">MemberID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Member Name</Data></Cell>
   </Row>
   <?php foreach ($members as $member): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $member['MemberID']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo htmlspecialchars($member['MemberName']); ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
 
 <!-- Reference Sheet for Years -->
 <Worksheet ss:Name="Year Reference">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="120"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Year Name</Data></Cell>
   </Row>
   <?php foreach ($years as $year): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number"><?php echo $year['YearID']; ?></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"><?php echo htmlspecialchars($year['YearName']); ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'years'): ?>
 <Worksheet ss:Name="Academic Years">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="3">
     <Data ss:Type="String">📋 Academic Year Import - Date format: YYYY-MM-DD (e.g., 2025-01-15)</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">YearName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">StartDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EndDate</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">InterestRate</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 20; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'custom_fields'): ?>
 <?php
 $fieldTypes = ['text', 'number', 'email', 'phone', 'date', 'dropdown', 'textarea', 'checkbox'];
 $entityTypes = ['member', 'transaction'];
 ?>
 <Worksheet ss:Name="Custom Fields">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="200"/>
   <Column ss:Width="80"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="5">
     <Data ss:Type="String">📋 Custom Fields - Use dropdowns for FieldType and EntityType. IsRequired: 1=Yes, 0=No</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldName</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldLabel</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldOptions</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">IsRequired</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 30; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', $fieldTypes); ?></Value>
     </DataValidation>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', $entityTypes); ?></Value>
     </DataValidation>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'custom_values'): ?>
 <?php
 $fields = [];
 try {
     $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
     $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
 } catch (Exception $e) {
     $fields = [];
 }
 ?>
 <Worksheet ss:Name="Custom Values">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="200"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="3">
     <Data ss:Type="String">📋 Custom Field Values - Use dropdown for FieldID</Data>
    </Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityType</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">EntityID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">FieldValue</Data></Cell>
   </Row>
   <?php for ($i = 0; $i < 30; $i++): ?>
   <Row>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <?php if (!empty($fields)): ?>
     <DataValidation xmlns="urn:schemas-microsoft-com:office:excel">
      <Type>List</Type>
      <Value><?php echo implode(',', array_map(function($f) { return $f['FieldID'] . ' - ' . htmlspecialchars($f['FieldLabel']) . ' (' . $f['EntityType'] . ')'; }, $fields)); ?></Value>
     </DataValidation>
     <?php endif; ?>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

</Workbook>

$sampleType = $_GET['type'] ?? 'members';

// Fetch data for dropdowns
$members = [];
$years = [];
$paymentModes = ['Cash', 'Online', 'Cheque', 'NEFT/RTGS', 'UPI'];
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$remarksOptions = ['Monthly collection', 'Distribution with interest', 'Penalty payment', 'Bonus payment', 'Adjustment', 'Special collection'];

if ($sampleType === 'transactions') {
    try {
        $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName");
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
        $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // If tables don't exist, continue with empty arrays
        $members = [];
        $years = [];
    }
}

$excel = new SimpleExcelWriter();

if ($sampleType === 'members') {
    $excel->addSheet('Members Data');
    
    // Headers
    $excel->writeRow(['MemberName', 'AadharNumber', 'Phone', 'Email', 'Address'], 'header');
    
    // Add 50 empty rows
    for ($i = 0; $i < 50; $i++) {
        $excel->writeRow(['', '', '', '', '']);
    }
    
    $filename = 'sample_members.xlsx';
    
} elseif ($sampleType === 'transactions') {
    $excel->addSheet('Transaction Data');
    
    // Headers
    $excel->writeRow([
        'MemberID', 'YearID', 'TransactionDate', 'CollectionAmount', 
        'DistributedAmount', 'InterestAmount', 'PaymentMode', 
        'ReferenceNumber', 'MonthName', 'Remarks'
    ], 'header');
    
    // Create dropdown lists
    $memberList = [];
    foreach ($members as $m) {
        $memberList[] = $m['MemberID'] . ' ' . $m['MemberName'];
    }
    
    $yearList = [];
    foreach ($years as $y) {
        $yearList[] = $y['YearID'] . ' ' . $y['YearName'];
    }
    
    // Add rows with dropdowns
    for ($i = 2; $i <= 51; $i++) { // Rows 2-51 (row 1 is header)
        $excel->writeRow(['', '', '', 0, 0, 0, '', '', '', '']);
        
        // Add dropdown validation for MemberID (column A)
        $excel->addDropdown("A{$i}", $memberList);
        
        // Add dropdown validation for YearID (column B)
        $excel->addDropdown("B{$i}", $yearList);
        
        // Add dropdown validation for PaymentMode (column G)
        $excel->addDropdown("G{$i}", $paymentModes);
        
        // Add dropdown validation for MonthName (column I)
        $excel->addDropdown("I{$i}", $months);
        
        // Add dropdown validation for Remarks (column J)
        $excel->addDropdown("J{$i}", $remarksOptions);
    }
    
    // Add reference sheets
    $excel->addSheet('Member Reference');
    $excel->writeRow(['MemberID', 'Member Name'], 'header');
    foreach ($members as $member) {
        $excel->writeRow([$member['MemberID'], $member['MemberName']]);
    }
    
    $excel->addSheet('Year Reference');
    $excel->writeRow(['YearID', 'Year Name'], 'header');
    foreach ($years as $year) {
        $excel->writeRow([$year['YearID'], $year['YearName']]);
    }
    
    $filename = 'sample_transactions.xlsx';
    
} elseif ($sampleType === 'years') {
    $excel->addSheet('Academic Years');
    
    $excel->writeRow(['YearName', 'StartDate', 'EndDate', 'InterestRate'], 'header');
    
    for ($i = 0; $i < 20; $i++) {
        $excel->writeRow(['', '', '', 0]);
    }
    
    $filename = 'sample_academic_years.xlsx';
    
} elseif ($sampleType === 'custom_fields') {
    $excel->addSheet('Custom Fields');
    
    $fieldTypes = ['text', 'number', 'email', 'phone', 'date', 'dropdown', 'textarea', 'checkbox'];
    $entityTypes = ['member', 'transaction'];
    
    $excel->writeRow(['FieldName', 'FieldLabel', 'FieldType', 'EntityType', 'FieldOptions', 'IsRequired'], 'header');
    
    for ($i = 2; $i <= 31; $i++) {
        $excel->writeRow(['', '', '', '', '', 0]);
        $excel->addDropdown("C{$i}", $fieldTypes);
        $excel->addDropdown("D{$i}", $entityTypes);
    }
    
    $filename = 'sample_custom_fields.xlsx';
    
} elseif ($sampleType === 'custom_values') {
    $excel->addSheet('Custom Values');
    
    $fields = [];
    try {
        $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
        $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table doesn't exist, continue with empty array
        $fields = [];
    }
    
    $fieldList = [];
    foreach ($fields as $field) {
        $fieldList[] = $field['FieldID'] . ' - ' . $field['FieldLabel'] . ' (' . $field['EntityType'] . ')';
    }
    
    $excel->writeRow(['FieldID', 'EntityType', 'EntityID', 'FieldValue'], 'header');
    
    for ($i = 2; $i <= 31; $i++) {
        $excel->writeRow(['', '', '', '']);
        if (!empty($fieldList)) {
            $excel->addDropdown("A{$i}", $fieldList);
        }
    }
    
    $filename = 'sample_custom_values.xlsx';
}

$excel->download($filename);
