# PDF Export Setup Guide

The PDF export feature currently uses a **browser-based fallback** that works immediately but requires manual save.

For **automatic PDF generation**, you can install one of these libraries:

## Option 1: Install mPDF (Recommended - Easy)

1. **Install Composer** (if not already installed):
   - Download from: https://getcomposer.org/download/
   - Run the installer

2. **Install mPDF**:
   ```bash
   cd "C:\Users\saiga\OneDrive - Ridge point\Documents\GitHub\SRCOLONY-VIEWS"
   composer require mpdf/mpdf
   ```

3. **Done!** PDF export will now generate actual PDF files automatically.

## Option 2: Install wkhtmltopdf (Alternative)

1. **Download wkhtmltopdf**:
   - Get from: https://wkhtmltopdf.org/downloads.html
   - Download the Windows installer (64-bit recommended)

2. **Install to default location**:
   - Install to: `C:\Program Files\wkhtmltopdf\`

3. **Done!** The system will automatically detect and use it.

## Option 3: Keep Browser-Based Export (No Installation)

The current system works fine as-is:
- Click export button
- Browser opens print dialog
- Select "Save as PDF" or "Microsoft Print to PDF"
- Save the file

**No installation needed**, works on all systems!

## Current Status

✅ **Currently Active**: Browser-based PDF (Print to PDF)
- Works immediately with no setup
- User saves PDF through browser print dialog
- Professional formatting preserved

📦 **Optional Upgrade**: Install mPDF or wkhtmltopdf
- Automatic PDF generation
- No user interaction needed
- Direct download of PDF files

## Testing

After installing a PDF library, test by:
1. Go to Admin Portal → Reports
2. Click any "Export ... (PDF)" button
3. PDF should download automatically (if library installed)
4. OR print dialog opens (browser fallback)

Both methods produce identical, professional PDF reports!
