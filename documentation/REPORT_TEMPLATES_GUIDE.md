# Report Templates System - User Guide

## Overview
The Report Templates system allows Super Admins to create, manage, and customize PDF/Print report templates that can be used across all portals (Super Admin, Admin, and Member).

## Features

### 1. **Template Management** (Super Admin Only)
- **Location**: `superadmin/manage-templates.php`
- **Access**: Dashboard → Report Templates
- Create unlimited custom report templates
- Configure sections, charts, colors, and layout
- Set default templates for each portal
- Activate/deactivate templates
- Preview templates before using

### 2. **Template Selection** (All Portals)
- **Super Admin Reports**: `superadmin/reports.php`
- **Admin Reports**: `admin/reports.php`
- **Member Reports**: `member/reports.php`

Each portal shows only templates applicable to that user type:
- **All**: Available in all portals
- **SuperAdmin**: Super Admin portal only
- **Admin**: Admin portal only
- **Member**: Member portal only

### 3. **Template Configuration**

#### Sections
- ✅ Summary: Overview statistics and KPIs
- ✅ Charts: Visual analytics and graphs
- ✅ Tables: Detailed data tables
- ✅ Analysis: Advanced analytics and insights
- ✅ Recommendations: AI-powered suggestions

#### Charts Available
- 📊 Monthly Trend: Collection over time
- 💳 Payment Mode: Distribution by payment method
- 🏆 Top Collectors: Highest performing members
- 📈 Growth Rate: Month-over-month growth
- ⚖️ Collection Ratio: Collection vs Distribution
- ⏰ Time Distribution: Peak transaction hours

#### Page Settings
- **Page Size**: A4, Letter, Legal
- **Orientation**: Portrait, Landscape
- **Colors**: Customizable primary and accent colors
- **Logo**: Include/exclude company logo
- **Cover Page**: Optional cover sheet
- **Table of Contents**: Auto-generated TOC

## How to Use

### Creating a New Template

1. **Navigate to Templates**
   ```
   Super Admin Dashboard → Report Templates
   ```

2. **Click "Create Template"**

3. **Fill in Basic Information**
   - Template Name (e.g., "Executive Summary")
   - Template Type (PDF, Print, or Both)
   - Applicable To (All, SuperAdmin, Admin, or Member)
   - Description

4. **Configure Template**
   - Select sections to include
   - Choose charts to display
   - Set page size and orientation
   - Pick color theme
   - Toggle additional options

5. **Save Template**
   - Click "Save Template"
   - Optionally set as default

### Using a Template

1. **Go to Reports Page**
   ```
   Any Portal → Reports & Analytics
   ```

2. **Select Template**
   - Find "Report Template" dropdown
   - Choose desired template (⭐ = default)

3. **Generate Report**
   - Set date range and filters
   - Click "Export PDF" or "Print"
   - Report generates using selected template

### Managing Templates

#### Set Default Template
- Click "Set Default" on any template card
- Default templates are pre-selected in reports
- Each portal can have its own default

#### Edit Template
- Click "Edit" button on template card
- Modify configuration
- Save changes

#### Activate/Deactivate
- Click pause/play icon
- Inactive templates don't appear in dropdowns
- Can reactivate anytime

#### Delete Template
- Click trash icon (only non-default templates)
- Confirm deletion

#### Preview Template
- Click "Preview" button
- See sample report with template applied
- No data is generated (preview only)

## Default Templates

The system comes with 5 pre-configured templates:

### 1. **Comprehensive Report** ⭐ (Default for All)
- All sections included
- 6 chart types
- Navy (#1E293E) and Gold (#EAB308) theme
- A4 Portrait with cover page and TOC
- Best for: Complete analysis reports

### 2. **Executive Summary** (All Portals)
- Summary and charts only
- 3 essential charts
- Quick overview format
- No cover page
- Best for: Quick briefings

### 3. **Financial Report** (All Portals)
- Summary, tables, and analysis
- Financial metrics focus
- Teal (#0F766E) and Orange (#F59E0B) theme
- Best for: Accounting and finance teams

### 4. **Member Statement** (Member Portal Only)
- Summary and transaction tables
- Purple (#7C3AED) and Pink (#EC4899) theme
- Member-specific data only
- Best for: Individual member reports

### 5. **Admin Dashboard Print** (Admin Portal Only)
- Summary, charts, and tables
- Landscape A4 for better printing
- Navy (#1E293B) and Blue (#3B82F6) theme
- Best for: Admin dashboard printouts

## Template Color Themes

### Professional Navy (Default)
- Primary: #1E293B (Dark Navy)
- Accent: #EAB308 (Gold)
- Use for: Official reports, presentations

### Financial Teal
- Primary: #0F766E (Teal)
- Accent: #F59E0B (Orange)
- Use for: Financial statements, accounting

### Member Purple
- Primary: #7C3AED (Purple)
- Accent: #EC4899 (Pink)
- Use for: Member-facing documents

### Admin Blue
- Primary: #1E293B (Navy)
- Accent: #3B82F6 (Blue)
- Use for: Internal admin reports

## Database Structure

### Table: `report_templates`
```sql
- TemplateID (Primary Key)
- TemplateName (VARCHAR 100)
- TemplateType (ENUM: 'PDF', 'Print', 'Both')
- Description (TEXT)
- TemplateConfig (JSON)
- ApplicableTo (ENUM: 'All', 'SuperAdmin', 'Admin', 'Member')
- IsDefault (TINYINT 1/0)
- IsActive (TINYINT 1/0)
- CreatedBy (INT - SuperAdmin ID)
- CreatedAt (TIMESTAMP)
- UpdatedAt (TIMESTAMP)
```

### Configuration JSON Structure
```json
{
  "sections": ["summary", "charts", "tables", "analysis"],
  "charts": ["monthly", "payment", "topCollectors"],
  "colors": {
    "primary": "#1e293b",
    "accent": "#eab308"
  },
  "logo": true,
  "header": "company",
  "footer": "page_numbers",
  "pageSize": "A4",
  "orientation": "portrait",
  "includeCover": true,
  "includeTableOfContents": false
}
```

## Troubleshooting

### Template Not Showing in Dropdown
- Check if template is Active (not paused)
- Verify ApplicableTo matches your portal
- Refresh the page

### Export Fails with Template
- Ensure all required sections have data
- Check date range has transactions
- Try default template first

### Colors Not Applying
- Clear browser cache
- Verify hex color format (#RRGGBB)
- Re-save template

### Permission Denied
- Template management is SuperAdmin only
- Check session authentication
- Re-login if needed

## Best Practices

1. **Naming Convention**
   - Use descriptive names: "Monthly Financial Summary"
   - Avoid generic names: "Template 1"

2. **Template Organization**
   - Create portal-specific templates when needed
   - Use "All" only for truly universal templates
   - Set meaningful defaults

3. **Color Selection**
   - Match company branding
   - Ensure good contrast for readability
   - Test print output

4. **Performance**
   - Fewer charts = faster generation
   - Large date ranges take longer
   - Consider template complexity

5. **Testing**
   - Preview before deploying
   - Test with different date ranges
   - Check on different screen sizes

## Technical Notes

- Templates stored in database, not files
- JSON configuration allows future extensibility
- FPDF library renders all PDFs
- Chart.js generates chart images
- Base64 encoding embeds charts in PDF

## Future Enhancements

Planned features:
- Template import/export
- Template versioning
- Custom headers/footers
- Dynamic watermarks
- Multi-language support
- Scheduled report generation
- Email templates integration

## Support

For issues or questions:
1. Check this guide first
2. Review audit logs in SuperAdmin
3. Contact system administrator
4. Check database for template data

---

**Version**: 1.0  
**Last Updated**: November 2025  
**Created By**: SR Colony Management System Team
