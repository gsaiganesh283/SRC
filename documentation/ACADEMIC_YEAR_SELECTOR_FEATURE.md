# Academic Year Selector on Dashboard

## Overview
Added an academic year selector dropdown to the admin dashboard, allowing administrators to view statistics for different academic years without changing the active year.

## Features

### 1. **Year Selection Dropdown**
- Displays all available academic years in the system
- Shows the currently active year with a ⭐ (Active) indicator
- Automatically submits the form when a year is selected
- Persists selection via URL parameter (`?year_id=X`)

### 2. **Smart Default Selection**
The system intelligently selects which year to display:
1. **User Selection**: If `year_id` is in the URL, use that year
2. **Active Year**: If no selection, default to the active year
3. **Most Recent**: If no active year exists, use the most recent year
4. **Fallback**: If no years exist, show a warning message

### 3. **Visual Indicators**
- **Active Year**: Shows "Active Academic Year" heading with green indicator
- **Non-Active Year**: Shows "Academic Year" heading with warning "⚠️ This year is not currently active"
- **Year Dropdown**: Active year marked with ⭐ (Active) in the dropdown

### 4. **Reset to Active Year Button**
- Appears when viewing a non-active year
- Quick link to return to the active year view
- Styled with hover effects for better UX
- Only shows when there are multiple years available

### 5. **No Year Available Handling**
If no academic years exist in the system:
- Displays a prominent warning banner
- Shows helpful message to create an academic year
- Provides direct link to the "Manage Years" page

## Technical Implementation

### PHP Logic

#### Year Selection Query:
```php
// Get all academic years
$stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
$allYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine selected year
$selectedYearID = $_GET['year_id'] ?? [Active Year] ?? [Most Recent Year];
```

#### Statistics Query:
All statistics queries now use the selected year:
```php
$stmt = $pdo->prepare("SELECT ... FROM transactions WHERE YearID = ?");
$stmt->execute([$selectedYearID]);
```

### HTML Structure

#### Year Selector Form:
```html
<form method="GET" action="dashboard.php" class="year-selector-form">
    <select name="year_id" onchange="this.form.submit()">
        <option value="1">2024-2025 ⭐ (Active)</option>
        <option value="2">2023-2024</option>
    </select>
</form>
```

#### Reset Button (conditional):
```html
<a href="dashboard.php" class="reset-year-btn">
    <i class="fas fa-undo"></i> Reset to Active Year
</a>
```

### CSS Styling

#### Dropdown Styles:
```css
.year-selector-form select {
    background: rgba(255,255,255,0.2);
    color: white;
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}
```

#### Reset Button:
```css
.reset-year-btn {
    padding: 6px 15px;
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    transition: all 0.3s ease;
}
```

## User Experience

### Workflow:
1. **View Current Stats**: Dashboard loads with active year statistics
2. **Select Different Year**: Click dropdown and choose another year
3. **Auto-Reload**: Page refreshes with selected year's statistics
4. **Compare Years**: Switch between years to compare performance
5. **Return to Active**: Click "Reset to Active Year" button

### Benefits:
- ✅ **Non-Destructive**: View past years without changing active status
- ✅ **Quick Comparison**: Easy to compare statistics across years
- ✅ **Historical Analysis**: Review performance of previous years
- ✅ **Data Validation**: Verify historical data and trends
- ✅ **Audit Trail**: Check past financial records

## Responsive Design

### Desktop (>768px):
- Three-column layout in banner
- Year selector on the right side
- All elements in a single row

### Mobile (<768px):
- Stacked vertical layout
- Full-width year selector
- Centered alignment
- Touch-friendly dropdown

## URL Parameters

### Format:
```
dashboard.php?year_id=5
```

### Usage:
- **No Parameter**: Shows active year
- **With Parameter**: Shows specified year
- **Bookmarkable**: Can bookmark specific year views
- **Shareable**: Share links to specific year statistics

## Integration with Existing Features

### Statistics Affected:
All dashboard statistics now filter by selected year:
- Total Collection
- Total Distribution
- Total Interest
- Total Transactions
- Outstanding Balances
- This Month's Collection
- Collection Rate
- All averages and percentages

### Member Statistics:
Member counts remain global (not year-specific):
- Total Members
- Active Members
- Inactive Members

## Security Considerations

1. **Input Validation**: Year ID is validated as integer
2. **SQL Injection**: Uses prepared statements
3. **Access Control**: Requires admin authentication
4. **Data Isolation**: Only shows data for selected year

## Error Handling

### Scenarios:
1. **Invalid Year ID**: Defaults to active year
2. **No Years Exist**: Shows warning message
3. **Database Error**: Graceful fallback to empty data
4. **No Active Year**: Uses most recent year

## Future Enhancements

### Potential Additions:
1. **Year Comparison View**: Side-by-side comparison of two years
2. **Date Range Filter**: Custom date range selection
3. **Export Data**: Download statistics for selected year
4. **Charts & Graphs**: Visual representation of year data
5. **Quarter/Month Filter**: Further drill-down within a year
6. **Year-over-Year Growth**: Calculate percentage changes

## Testing Checklist

- [ ] Dropdown displays all years correctly
- [ ] Active year is marked with ⭐
- [ ] Selecting a year refreshes with correct data
- [ ] Statistics update based on selected year
- [ ] Reset button appears for non-active years
- [ ] Reset button redirects to active year
- [ ] No year scenario shows warning
- [ ] Mobile layout is responsive
- [ ] URL parameter persists selection
- [ ] Bookmarking works correctly

## Date Implemented
October 20, 2025

## Files Modified
- `admin/dashboard.php` - Main implementation
- `documentation/ACADEMIC_YEAR_SELECTOR_FEATURE.md` - This documentation
