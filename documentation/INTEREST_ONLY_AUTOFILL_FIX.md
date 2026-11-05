# Interest Only Model - Auto-Fill Logic Test

## Test Scenario

**Academic Year:** 2024-2025  
**Collection Model:** Interest Only  
**Interest Rate:** 0.20 (20%)  

## Member: Kranti Kumar Raju (ID: 1)

### Transaction History:
```
Month 1 (September 9, 2024):
- Distribution: ₹410
- Interest: ₹100
- Outstanding Balance: ₹510

Database State:
- TotalDistributed: ₹410
- TotalInterest: ₹100
- TotalCollected: ₹0
```

### Current Collection (October 17, 2025):

**Expected Auto-Fill Calculation:**

1. **Check Collection Model:** Interest Only ✓

2. **Check if First Collection:**
   - TotalCollected (₹0) < TotalInterest (₹100)? **YES**
   - This means member hasn't paid the interest from the first distribution yet

3. **Auto-Fill Amount:**
   - Since it's first collection: Show Outstanding Balance
   - **Amount: ₹510** ❌ (WRONG - Should be ₹100 for interest only)

### Issue Identified:

The logic treats "first collection after distribution" as needing the full outstanding balance. But in Interest Only model, we should ONLY collect interest monthly, unless it's the SAME MONTH as a new distribution.

## Corrected Logic:

For **Interest Only Model:**

1. **Regular Monthly Collection:** 
   - Auto-fill = TotalDistributed × InterestRate
   - Example: ₹410 × 0.20 = ₹100

2. **Same Month as New Distribution:**
   - Auto-fill = Previous Interest + New Principal + New Interest
   - Example (Month 3 with new ₹650):
     - ₹100 (old interest) + ₹650 (new principal) + ₹150 (new interest) = ₹900

3. **Next Month After New Distribution Collection:**
   - Back to interest only
   - ₹410 × 0.20 + ₹650 × 0.20 = ₹100 + ₹150 = ₹250

## Solution:

The auto-fill should ALWAYS be interest-only (₹100) for regular collections.

Only show outstanding balance when:
- User is making a distribution in the same month
- User explicitly changes transaction type

The "first payment" check should be removed for Interest Only model.

---

## Updated Logic Flow:

```javascript
if (collectionModel === 'InterestOnly') {
    // ALWAYS calculate interest only for regular collections
    const interestOnlyAmount = totalDistributed * interestRate;
    
    // Auto-fill with interest only
    amountToCollect = interestOnlyAmount;
    infoMessage = 'Monthly Interest: ₹' + interestOnlyAmount.toFixed(2);
    
    // Show outstanding balance as INFO only
    if (outstandingBalance > totalDistributed * interestRate) {
        infoMessage += ' | Outstanding: ₹' + outstandingBalance.toFixed(2);
    }
}
```

**Result:** Collection amount will auto-fill as ₹100 (interest only) ✅
