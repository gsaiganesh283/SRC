# Deferred Principal Collection Feature

## Overview
This feature allows administrators to configure when deferred principal amounts should be collected, and automatically adds that amount to the "Next Month Due" calculation when appropriate.

## Database Changes

### New Fields in `academic_years` Table
1. **LastMonthToCollect** (VARCHAR(20))
   - Stores the month name when principal should be collected (e.g., "August")
   - Optional field (can be NULL)

2. **DeferredPrincipalAmount** (DECIMAL(10,2))
   - The principal amount that was deferred from the first month
   - Default: 0.00
   - Example: 410.00

### Migration SQL
Run this SQL to add the fields:
```sql
ALTER TABLE academic_years 
ADD COLUMN LastMonthToCollect VARCHAR(20) DEFAULT NULL COMMENT 'Last month name to collect (e.g., August)',
ADD COLUMN DeferredPrincipalAmount DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Principal amount deferred to last month';
```

## UI Changes

### Manage Academic Years Form
Added two new fields in the form:

1. **Last Month to Collect Principal**
   - Dropdown select with all 12 months
   - Optional field
   - Indicates when the deferred principal should be collected

2. **Deferred Principal Amount**
   - Number input field
   - Accepts decimal values (e.g., 410.00)
   - Represents the amount deferred from first month

## Calculation Logic

### Interest Calculator Behavior

#### Standing Monthly Interest
- **Fixed at ₹100** (as per your requirement)
- No longer calculated based on total distributed
- Represents interest on deferred amounts

#### Next Month Due Calculation
**Base Formula:**
```
Next Month Due = Distributed Amount + Standing Interest (₹100) + Interest on New Distribution
```

**When to Add Deferred Principal:**
- If current month is **one month before** the "Last Month to Collect"
- AND if "Deferred Principal Amount" is set (> 0)
- Then add the deferred principal amount to Next Month Due

**Example Scenario:**
- Academic Year: September 2024 - August 2025
- Last Month to Collect: August
- Deferred Principal Amount: ₹410
- Current Month: July (one month before August)
- Distribution in July: ₹500

**July Distribution - Next Month Due:**
```
= ₹500 (distributed) + ₹100 (standing interest) + ₹120 (24% interest on ₹500) + ₹410 (deferred principal)
= ₹1,130
```

This tells the member that in August (next month), they need to pay:
- ₹500 (July's distribution)
- ₹120 (Interest on July's distribution)
- ₹100 (Standing monthly interest)
- ₹410 (Deferred principal from September)

## Payment Method Default

### Default Changed to UPI
- Payment mode now defaults to **UPI** instead of Cash
- Both in form HTML (checked attribute) and PHP backend (fallback value)

## JavaScript Functions

### New Functions

#### `isMonthBeforeLastCollection()`
```javascript
function isMonthBeforeLastCollection() {
    if (!lastMonthToCollect) return false;
    
    const monthNames = ['January', 'February', ...];
    const lastCollectionMonthIndex = monthNames.indexOf(lastMonthToCollect);
    const currentMonth = new Date().getMonth();
    const monthBefore = (lastCollectionMonthIndex === 0) ? 11 : lastCollectionMonthIndex - 1;
    
    return currentMonth === monthBefore;
}
```

Returns `true` if current month is exactly one month before the configured last collection month.

## Testing Steps

1. **Run the migration SQL** to add new fields to database
2. **Edit Active Academic Year**:
   - Set "Last Month to Collect Principal" to "August"
   - Set "Deferred Principal Amount" to 410.00
   - Save changes

3. **Test in July** (one month before August):
   - Go to Add Transaction
   - Select a member
   - Select "Distribution" transaction type
   - Enter distribution amount (e.g., 500)
   - Observe "Next Month Due" should show: ₹500 + ₹100 + interest + ₹410

4. **Test in other months**:
   - In months other than July, Next Month Due should NOT include the ₹410
   - Only includes: distributed amount + ₹100 + interest

## Console Logging
Enhanced console logging shows:
- Last Month to Collect configuration
- Deferred Principal Amount
- Whether deferred principal should be added
- Detailed breakdown of calculations

Example output:
```
Last Month to Collect: August
Deferred Principal: 410
Should Add Deferred Principal: true
⚠️ Adding deferred principal to next month due: ₹410.00
```

## Benefits
1. **Automated Tracking**: System automatically knows when to collect deferred principal
2. **Accurate Forecasting**: Members see exactly what they'll owe next month
3. **Flexible Configuration**: Can be changed per academic year
4. **Business Logic Clarity**: Deferred principal collection is now explicit in the system

## Files Modified
1. `database/add_principal_collection_fields.sql` - New migration file
2. `admin/manage-years.php` - Form fields and handling for new configuration
3. `admin/add-transaction.php` - Calculator logic to use new fields
