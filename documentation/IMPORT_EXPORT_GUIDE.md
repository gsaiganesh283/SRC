# Data Import/Export Feature Guide

## Overview
The Data Import/Export module allows Super Admins to bulk import and export data using Excel/CSV files.

## Location
**Super Admin Portal**: `superadmin/data-import-export.php`

## Features

### 1. Data Export
Export data to CSV format for:
- **Members** - All member records with contact information
- **Transactions** - Complete transaction history with member and year details
- **Academic Years** - Year configurations with interest rates
- **All Data** - Combined export of all tables

#### Export File Format
- File type: CSV (Comma-Separated Values)
- Encoding: UTF-8
- Filename pattern: `export_{type}_{date}_{time}.csv`
- Example: `export_members_2025-11-23_143052.csv`

### 2. Data Import
Import data from CSV or Excel files:

#### Supported Formats
- CSV (.csv)
- Microsoft Excel (.xlsx, .xls)

#### Import Types

**A. Members Import**
- Columns Required:
  1. MemberName
  2. AadharNumber
  3. Phone
  4. Email
  5. Address
- Default password: `member123` (users should change on first login)
- Duplicate checking: Based on AadharNumber

**B. Transactions Import**
- Columns Required:
  1. MemberID (must exist in members table)
  2. YearID (must exist in academic_years table)
  3. TransactionDate (YYYY-MM-DD format)
  4. CollectionAmount (numeric)
  5. DistributedAmount (numeric)
  6. InterestAmount (numeric)
  7. PaymentMode (Cash/Online/Cheque/Bank Transfer)
  8. ReferenceNumber (optional)
  9. MonthName (optional)
  10. Remarks (optional)

**C. Academic Years Import**
- Columns Required:
  1. YearName (e.g., "2024-2025")
  2. StartDate (YYYY-MM-DD)
  3. EndDate (YYYY-MM-DD)
  4. InterestRate (numeric, e.g., 5.0 for 5%)

### 3. Sample Templates
Download pre-formatted sample CSV files for each import type:
- `sample_members.csv` - 3 sample member records
- `sample_transactions.csv` - 3 sample transaction records
- `sample_years.csv` - 3 sample academic year records

## Usage Instructions

### Exporting Data

1. Navigate to **Data Import/Export** page
2. In the "Export Data" section, click the appropriate button:
   - **Export Members** - Downloads all member data
   - **Export Transactions** - Downloads all transaction data
   - **Export Academic Years** - Downloads all year data
   - **Export All Data** - Downloads combined data file
3. File will download automatically in CSV format
4. Open in Excel, Google Sheets, or any spreadsheet application

### Importing Data

1. **Download Sample Template**
   - Click "Download Sample" for the data type you want to import
   - This ensures correct column structure

2. **Prepare Your Data**
   - Open the sample template in Excel
   - Replace sample data with your actual data
   - Maintain exact column headers
   - Follow date format: YYYY-MM-DD
   - Ensure all required fields are filled

3. **Upload File**
   - Select import type from dropdown
   - Click upload area or drag & drop file
   - Supported formats: CSV, XLSX, XLS
   - Max file size: 10MB
   - Click "Import Data" button

4. **Review Results**
   - Success message shows number of records imported
   - Warning message indicates partial success with errors
   - Error message indicates complete failure
   - Review any error messages for troubleshooting

## Data Validation

### Members Import
- ✅ AadharNumber must be unique (12 digits)
- ✅ Phone numbers validated for format
- ✅ Email addresses validated for format
- ✅ All required fields must be filled

### Transactions Import
- ✅ MemberID must exist in members table
- ✅ YearID must exist in academic_years table
- ✅ Numeric fields validated (amounts)
- ✅ Date format validated (YYYY-MM-DD)
- ✅ PaymentMode must be valid option

### Academic Years Import
- ✅ Date ranges validated
- ✅ StartDate must be before EndDate
- ✅ InterestRate must be numeric
- ✅ YearName should follow standard format

## Error Handling

### Common Import Errors

1. **"Invalid file format"**
   - Solution: Ensure file is CSV, XLS, or XLSX

2. **"Row error: Duplicate entry"**
   - Solution: Check for duplicate AadharNumbers or other unique fields

3. **"Row error: Foreign key constraint"**
   - Solution: Ensure MemberID and YearID exist before importing transactions

4. **"Could not open file"**
   - Solution: Check file permissions and format

5. **"Error uploading file"**
   - Solution: Verify file size is under 10MB

## Best Practices

### Before Import
1. ✅ Always download and use sample templates
2. ✅ Validate data in Excel before uploading
3. ✅ Remove any empty rows
4. ✅ Ensure dates are in YYYY-MM-DD format
5. ✅ Backup database before large imports
6. ✅ Test with small dataset first

### Data Preparation
1. ✅ Keep column headers exactly as in sample
2. ✅ Use consistent date format
3. ✅ Validate numeric fields (no text in amount columns)
4. ✅ Check for special characters that might cause issues
5. ✅ Ensure required foreign keys exist (MemberID, YearID)

### After Import
1. ✅ Verify record count matches expectations
2. ✅ Spot-check imported data for accuracy
3. ✅ Review any error messages
4. ✅ Test with sample data queries
5. ✅ Keep original import file for reference

## Security Considerations

1. **Access Control**
   - Only Super Admins can access import/export
   - Session validation required
   - Audit logs track all import/export operations

2. **File Validation**
   - Only CSV and Excel formats accepted
   - File size limited to 10MB
   - Files stored in secure uploads directory

3. **Data Protection**
   - Passwords hashed on member import
   - Sensitive data encrypted in database
   - Export files should be handled securely

## Technical Details

### File Processing
- CSV parsing uses PHP's native `fgetcsv()` function
- Excel files currently require CSV conversion
- Batch processing with transaction support
- Error reporting for each failed row

### Database Operations
- Prepared statements prevent SQL injection
- Foreign key constraints enforced
- Transactions used for data integrity
- Duplicate checking on unique fields

### Performance
- Recommended batch size: 1000 records
- For larger datasets, split into multiple files
- Import process provides progress feedback
- Failed rows logged with specific error messages

## Statistics Display

The Import/Export page displays:
- 📊 Total Members count
- 💰 Total Transactions count
- 📅 Total Academic Years count

These update automatically after successful imports.

## File Storage

### Import Files
- Location: `uploads/imports/`
- Naming: `import_{type}_{timestamp}.{ext}`
- Retention: Manual cleanup recommended
- Permissions: Read/write for web server

### Export Files
- Generated dynamically on request
- Not stored on server
- Direct download to user's browser
- No server-side storage required

## Future Enhancements

Planned features:
- 📊 Excel file direct import (without CSV conversion)
- 🔄 Scheduled automatic exports
- 📧 Email export files
- 🔍 Data validation preview before import
- 📈 Import progress bar for large files
- 🗂️ Template builder for custom imports
- 🔄 Automatic old file cleanup
- 📊 Import history tracking

## Troubleshooting

### Import Not Working
1. Check file format (CSV recommended)
2. Verify column headers match sample
3. Ensure data types are correct
4. Check for special characters
5. Validate foreign key references

### Export Not Downloading
1. Check browser download settings
2. Verify sufficient disk space
3. Disable popup blockers
4. Try different browser

### Partial Import Success
1. Review error messages
2. Fix problematic rows in source file
3. Re-import corrected data
4. Check database constraints

## Support

For technical assistance:
- Review error messages carefully
- Check sample templates for correct format
- Consult database schema for field requirements
- Contact system administrator for database issues

---

**Last Updated**: November 23, 2025  
**Version**: 1.0  
**Module**: Super Admin Data Import/Export
