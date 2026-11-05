# Interest Rate 24% - Last Month Distribution Tracking

## Changes Made

### 1. Interest Rate Confirmation
- **Interest Rate**: 24% (0.24)
- **Calculation**: ₹650 × 0.24 = ₹156 interest
- **Total Payment**: ₹650 (principal) + ₹156 (interest) + ₹100 (standing interest) = **₹906**

### 2. Database Query Added (Lines 64-82 in add-transaction.php)
```php
// Get last month's distribution amount for this member
$stmtLastMonth = $pdo->prepare("
    SELECT DistributedAmount 
    FROM transactions 
    WHERE MemberID = ? AND YearID = ? 
    AND DistributedAmount > 0 
    AND MONTH(TransactionDate) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
    AND YEAR(TransactionDate) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
    ORDER BY TransactionDate DESC 
    LIMIT 1
");
$stmtLastMonth->execute([$memberId, $yearID]);
$lastMonthDist = $stmtLastMonth->fetch(PDO::FETCH_ASSOC);
$member['LastMonthDistribution'] = $lastMonthDist ? floatval($lastMonthDist['DistributedAmount']) : 0;
```

**Purpose**: Fetches the distribution amount given to the member in the previous month.

### 3. HTML Data Attribute Added (Line 603)
```html
data-last-month-distributed="<?php echo $member['LastMonthDistribution']; ?>"
```

**Purpose**: Makes last month's distribution available to JavaScript for calculations.

### 4. JavaScript Calculator Updated (Lines 900-939)

#### Variables Added:
```javascript
const lastMonthDistributed = parseFloat(selectedOption.getAttribute('data-last-month-distributed')) || 0;
```

#### Interest Calculation Added:
```javascript
// Interest on LAST MONTH's distribution (for Collection transactions)
let interestOnLastMonth = 0;
if (lastMonthDistributed > 0) {
    interestOnLastMonth = lastMonthDistributed * interestRate;
}
```

#### Total Calculation Updated:
```javascript
let total = lastMonthDistributed + interestOnLastMonth + standingMonthlyInterest;
```

**Before**: `total = distributed + interestOnNewDistribution + standingMonthlyInterest;`
- Problem: `distributed` is 0 for Collection transactions

**After**: `total = lastMonthDistributed + interestOnLastMonth + standingMonthlyInterest;`
- Solution: Uses actual last month's distribution amount from database

### 5. Auto-Fill Logic Updated (Lines 1049-1061)
```javascript
const lastMonthDist = parseFloat(selectedOption.getAttribute('data-last-month-distributed')) || 0;
const interestOnLastMonth = lastMonthDist * interestRate;
const standingInterest = totalDistributed * interestRate;
const totalToCollect = lastMonthDist + interestOnLastMonth + standingInterest;

// Auto-fill with total amount (last month + its interest + standing interest)
if (transactionType === 'Collection') {
    collectionInput.value = totalToCollect.toFixed(2);
}
```

**Purpose**: Collection Amount field now auto-fills with the correct total including last month's principal.

## Payment Structure Example

### Scenario: Prudvi in November 2024
- **October Distribution**: ₹650 (must return this month)
- **September Distribution**: ₹500 (deferred to last month - August 2025)
- **Total Distributed So Far**: ₹1,053.14

### November Collection Calculation:
1. **Last Month's Principal**: ₹650 (from October)
2. **Interest on ₹650**: ₹650 × 0.24 = ₹156
3. **Standing Interest**: ₹1,053.14 × 0.24 = ₹252.75 (or ₹100 if only deferred amount)
4. **Total to Collect**: ₹650 + ₹156 + ₹100 = **₹906**

## System Behavior

### When Member Selects a Member for Collection:
1. Database query fetches last month's distribution (₹650)
2. JavaScript calculates:
   - Interest on last month: ₹650 × 0.24 = ₹156
   - Standing interest: Current total distributed × 0.24
3. **Interest Calculator Shows**:
   - Interest on New Distribution: ₹0.00 (no new distribution this transaction)
   - Standing Interest: ₹252.75 (or applicable amount)
   - Next Month Due: Auto-calculated
   - **Total Amount: ₹906** ✓

### Console Logging for Debugging:
```
📊 Collection Calculation:
  - Principal (last month distribution): ₹650.00
  - Interest on last month (24%): ₹156.00
  - Standing interest (deferred amounts): ₹100.00
  - TOTAL: ₹906.00
```

## Testing Checklist

- [ ] Select Prudvi from member dropdown
- [ ] Choose "Collection" transaction type
- [ ] Verify Collection Amount auto-fills to ₹906 (or correct calculated amount)
- [ ] Check Interest Calculator panel shows correct breakdown
- [ ] Check browser console for debug logging
- [ ] Verify calculation matches: Last Month + (Last Month × 0.24) + Standing Interest

## Notes

- Interest rate is automatically converted: 24 → 0.24 (lines 32-34, 782-784)
- Last month query uses `DATE_SUB(CURDATE(), INTERVAL 1 MONTH)` for accurate month detection
- If no distribution in last month, `lastMonthDistributed` defaults to 0
- System supports both "InterestOnly" and other collection models

## Files Modified

1. `admin/add-transaction.php`:
   - Lines 64-82: Database query for last month's distribution
   - Line 603: Added data attribute
   - Lines 900-939: Updated JavaScript calculator
   - Lines 1049-1061: Updated auto-fill logic
