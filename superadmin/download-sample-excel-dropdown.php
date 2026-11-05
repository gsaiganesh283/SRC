<?php
// Enhanced Excel Sample Generator with REAL Dropdowns
// URL: superadmin/download-sample-excel-dropdown.php

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
    $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName");
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY StartDate DESC");
    $years = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename=sample_' . $sampleType . '_with_dropdowns.xls');
header('Pragma: no-cache');
header('Expires: 0');

// Generate Excel XML with data validation
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
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
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
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="Instruction">
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Font ss:Bold="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#F59E0B"/>
   </Borders>
  </Style>
  <Style ss:ID="Date">
   <NumberFormat ss:Format="Short Date"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
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
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="150"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="9">
     <Data ss:Type="String">📋 Transaction Import - Select values from dropdowns. MemberID and YearID dropdowns are in columns A &amp; B. Payment Mode in column G, Month in column I</Data>
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
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Available Member IDs:</B><br/>
       <?php foreach (array_slice($members, 0, 10) as $m): ?>
       <?php echo $m['MemberID']; ?>: <?php echo htmlspecialchars($m['MemberName']); ?><br/>
       <?php endforeach; ?>
       <?php if (count($members) > 10): ?>...(<?php echo count($members) - 10; ?> more)<br/><?php endif; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Available Year IDs:</B><br/>
       <?php foreach ($years as $y): ?>
       <?php echo $y['YearID']; ?>: <?php echo htmlspecialchars($y['YearName']); ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Date"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Available Payment Modes:</B><br/>
       <?php foreach ($paymentModes as $mode): ?>
       - <?php echo $mode; ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Available Months:</B><br/>
       <?php foreach ($months as $month): ?>
       - <?php echo $month; ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Common Remarks:</B><br/>
       <?php foreach ($remarksOptions as $remark): ?>
       - <?php echo $remark; ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
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
     <Data ss:Type="String">📋 Custom Fields - FieldType and EntityType have tooltips. IsRequired: 1=Yes, 0=No</Data>
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
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Valid Field Types:</B><br/>
       <?php foreach ($fieldTypes as $type): ?>
       - <?php echo $type; ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Data">
     <Data ss:Type="String"></Data>
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Valid Entity Types:</B><br/>
       <?php foreach ($entityTypes as $entity): ?>
       - <?php echo $entity; ?><br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
    </Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">0</Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>

<?php elseif ($sampleType === 'custom_values'): ?>
 <?php
 $fieldStmt = $pdo->query("SELECT FieldID, FieldLabel, EntityType FROM custom_field_definitions ORDER BY FieldID");
 $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);
 ?>
 <Worksheet ss:Name="Custom Values">
  <Table>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="200"/>
   
   <Row>
    <Cell ss:StyleID="Instruction" ss:MergeAcross="3">
     <Data ss:Type="String">📋 Custom Field Values - Hover over FieldID cell for available fields</Data>
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
     <Comment>
      <ss:Data xmlns="http://www.w3.org/TR/REC-html40">
       <B>Available Fields:</B><br/>
       <?php foreach ($fields as $field): ?>
       <?php echo $field['FieldID']; ?>: <?php echo htmlspecialchars($field['FieldLabel']); ?> (<?php echo $field['EntityType']; ?>)<br/>
       <?php endforeach; ?>
      </ss:Data>
     </Comment>
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
