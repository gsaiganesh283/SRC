# Deferred Principal Payment System

## Overview
The chit fund operates on a **deferred principal payment** model where members pay only interest monthly and return the principal amount in the last month of the academic year.

## Business Logic

### Core Principle
- **First Month Distribution (September):** Deferred to LAST month of academic year
- **All Other Distributions:** Paid back in the NEXT month
- **Interest:** Calculated monthly on ALL outstanding principal

### Payment Rules
1. **September (First Month):** Principal stays until August (last month), but interest is paid monthly
2. **October onwards:** Each distribution is returned NEXT month along with its interest
3. **Standing Interest:** Always paid monthly on all outstanding amounts

### Example Scenario

#### Academic Year: September 2024 - August 2025

**Month 1 - September 2024:**
- Member receives: ₹410 (first distribution)
- Member pays: ₹0 (no payment in distribution month)

**Month 2 - October 2024:**
- Member receives: ₹650 (second distribution)
- Interest on ₹410 (from Sept): ₹100 (20% monthly)
- Member pays: ₹100 (interest only)

**Month 3 - November 2024:**
- Member receives: ₹500 (third distribution)
- Interest on ₹410: ₹100
- Interest on ₹650: ₹130
- **Principal repayment:** ₹650 (October distribution must be returned)
- Member pays: ₹880 (₹100 + ₹130 + ₹650)

**Month 4 - December 2024:**
- Member receives: ₹0 (no distribution)
- Interest on ₹410: ₹100
- Interest on ₹500: ₹100
- **Principal repayment:** ₹500 (November distribution must be returned)
- Member pays: ₹700 (₹100 + ₹100 + ₹500)

**Month 5 - January 2025:**
- Member receives: ₹0 (no distribution)
- Interest on ₹410: ₹100 (only ₹410 remains as principal)
- Member pays: ₹100 (interest only)

... continues monthly with ₹100 until last month ...

**Month 12 - August 2025 (LAST MONTH):**
- Member pays: ₹410 + ₹100 = **₹510**
  - ₹410 (September principal - the only remaining amount)
  - ₹100 (September final interest)

## Mathematical Formula

### Regular Month (Not Last Month)
```
Total Amount = New Distribution + Interest on New Distribution + Standing Monthly Interest

Where:
- New Distribution = Amount given this month
- Interest on New Distribution = New Distribution × Interest Rate
- Standing Monthly Interest = Sum of all previous distributions × Interest Rate
```

### Last Month
```
Total Amount = All Principal Amounts + All Accumulated Interest

Where:
- All Principal Amounts = Sum of all distributions throughout the year
- All Accumulated Interest = (Each distribution × Interest Rate) summed up
```

## Detailed Example

### Given:
- Interest Rate: 20% monthly
- Academic Year: Sept 2024 - Aug 2025 (12 months)

### Transactions:

| Month | Distribution | Standing Interest | Principal Due | Monthly Payment | Remaining Principal |
|-------|--------------|-------------------|---------------|-----------------|---------------------|
| Sep   | ₹410        | ₹0               | ₹0           | ₹0             | ₹410                |
| Oct   | ₹650        | ₹100 (410×20%)   | ₹0           | ₹100           | ₹1,060              |
| Nov   | ₹500        | ₹230 (1060×20%)  | ₹650         | ₹880           | ₹910                |
| Dec   | ₹0          | ₹200 (910×20%)   | ₹500         | ₹700           | ₹410                |
| Jan   | ₹0          | ₹100 (410×20%)   | ₹0           | ₹100           | ₹410                |
| Feb   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| Mar   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| Apr   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| May   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| Jun   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| Jul   | ₹0          | ₹100             | ₹0           | ₹100           | ₹410                |
| **Aug** | ₹0      | ₹100             | **₹410**     | **₹510**       | **₹0**              |

**Key Rule:** 
- First month distribution (Sept ₹410) is paid in LAST month
- All subsequent distributions (Oct ₹650, Nov ₹500) are paid NEXT month

## Interest Calculator Logic

### Current Month Check
```javascript
function isLastMonth() {
    const endDate = new Date(academicEndDate);  // e.g., "2025-08-31"
    const endMonth = endDate.getMonth();        // 7 (August = month 7)
    const endYear = endDate.getFullYear();      // 2025
    
    const currentDate = new Date();
    const currentMonth = currentDate.getMonth();
    const currentYear = currentDate.getFullYear();
    
    return (endMonth === currentMonth && endYear === currentYear);
}
```

### Calculation Logic
```javascript
if (isLastMonthOfYear) {
    // LAST MONTH: Include all principal
    total = distributed                    // New distribution this month
          + existingDistributed           // All previous principal
          + interestOnNewDistribution     // Interest on new
          + standingMonthlyInterest;      // Interest on previous
} else {
    // REGULAR MONTH: Interest only
    total = distributed                    // New distribution this month
          + interestOnNewDistribution     // Interest on new
          + standingMonthlyInterest;      // Interest on previous (NO PRINCIPAL)
}
```

## Payment Schedule Example

### Scenario: Member takes ₹410 in Sept, ₹650 in Oct

**September 2024:**
```
Distribution: ₹410
Interest on New Distribution: ₹82 (410 × 0.20)
Standing Monthly Interest: ₹0
Total Amount to Collect: ₹410 + ₹82 + ₹0 = ₹492
```

**October 2024:**
```
Distribution: ₹650
Interest on New Distribution: ₹130 (650 × 0.20)
Standing Monthly Interest: ₹82 (from ₹410)
Total Amount to Collect: ₹650 + ₹130 + ₹82 = ₹862
```

**November 2024 - July 2025 (NOT last month):**
```
Distribution: ₹0
Interest on New Distribution: ₹0
Standing Monthly Interest: ₹212 (₹410 + ₹650) × 0.20
Total Amount to Collect: ₹0 + ₹0 + ₹212 = ₹212
```

**August 2025 (LAST MONTH):**
```
Distribution: ₹0
Interest on New Distribution: ₹0
Standing Monthly Interest: ₹212
Principal Amount Due: ₹410 + ₹650 = ₹1,060
Total Amount to Collect: ₹0 + ₹0 + ₹212 + ₹1,060 = ₹1,272
```

## Display Updates

### Interest Calculator Shows:

#### Regular Month (e.g., November):
```
┌─────────────────────────────────────────┐
│ Interest on New Distribution     ₹0.00 │
│ Standing Monthly Interest      ₹212.00 │
│ Next Month Due                 ₹212.00 │
│ Total Amount to Collect        ₹212.00 │
└─────────────────────────────────────────┘
```

#### Last Month (August):
```
┌─────────────────────────────────────────┐
│ Interest on New Distribution     ₹0.00 │
│ Standing Monthly Interest      ₹212.00 │
│ Next Month Due                 ₹212.00 │
│ Total Amount to Collect      ₹1,272.00 │ ← Includes principal!
│ (Principal: ₹1,060 + Interest: ₹212)   │
└─────────────────────────────────────────┘
```

## Console Output

### Regular Month:
```
🟢 REGULAR MONTH - Interest only, principal deferred
New Distribution: 0
Existing Distributed: 1060
Interest on New: 0
Standing Interest: 212
Next Due: 212
Total: 212
```

### Last Month:
```
🔴 LAST MONTH - Including principal in total
New Distribution: 0
Existing Distributed: 1060
Interest on New: 0
Standing Interest: 212
Next Due: 212
Total: 1272
```

## Benefits

### For Members:
- Lower monthly burden (interest only)
- Predictable monthly payments
- Lump sum principal payment at year-end

### For Organization:
- Consistent monthly interest income
- Full principal recovery at year-end
- Clear payment structure

## Implementation Details

### Database Fields Used:
- `academic_years.EndDate` - Determines last month
- `TotalDistributed` - Tracks accumulated principal
- `InterestRate` - Monthly interest rate

### JavaScript Variables:
```javascript
const academicEndDate = '2025-08-31';        // From database
const isLastMonthOfYear = isLastMonth();     // Boolean check
const existingDistributed = 1060;            // From member data
const standingMonthlyInterest = 212;         // Calculated
```

## Testing

### Test Case 1: Regular Month (November 2024)
```
Given:
- Academic Year: Sept 2024 - Aug 2025
- Current Month: November 2024
- Existing Distributed: ₹1,060
- New Distribution: ₹0

Expected:
- Is Last Month: false
- Total Amount: ₹212 (interest only)
```

### Test Case 2: Last Month (August 2025)
```
Given:
- Academic Year: Sept 2024 - Aug 2025
- Current Month: August 2025
- Existing Distributed: ₹1,060
- New Distribution: ₹0

Expected:
- Is Last Month: true
- Total Amount: ₹1,272 (₹1,060 principal + ₹212 interest)
```

### Test Case 3: New Distribution in Last Month
```
Given:
- Current Month: August 2025 (last month)
- Existing Distributed: ₹1,060
- New Distribution: ₹500

Expected:
- Interest on New: ₹100
- Standing Interest: ₹212
- Total Amount: ₹1,872 (₹500 + ₹1,060 + ₹100 + ₹212)
```

## Notes

1. **First Month:** Member receives money but pays nothing
2. **Second Month Onwards:** Member pays interest on all previous distributions
3. **Last Month:** Member returns ALL principal + final interest
4. **No Partial Principal Payments:** Principal is never paid until last month

## Date: October 17, 2025
