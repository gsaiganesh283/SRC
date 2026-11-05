# Clarification: Payment System Rules

## Question for Confirmation

Based on your example, I see this pattern:

### Given Scenario:
- **September:** Member receives ₹410
- **October:** Member receives ₹650  
- **November:** Member receives ₹500

### Payments:
- **October:** Pay ₹100 (interest on Sept ₹410) → Sept principal NOT returned
- **November:** Pay ₹880 (₹100 + ₹130 + ₹650) → Oct principal ₹650 IS returned
- **December:** Pay ₹700 (₹100 + ₹100 + ₹500) → Nov principal ₹500 IS returned
- **Jan-July:** Pay ₹100 each month (interest on Sept ₹410 only)
- **August (LAST):** Pay ₹510 (₹410 + ₹100) → Sept principal finally returned

## The Pattern I See:

1. **FIRST DISTRIBUTION (September ₹410):**
   - Deferred to LAST MONTH
   - Interest paid every month
   - Principal paid only in August

2. **ALL SUBSEQUENT DISTRIBUTIONS (Oct ₹650, Nov ₹500, etc.):**
   - Paid back NEXT MONTH
   - Principal + Interest returned together next month

## Questions:

### Q1: Is September ALWAYS the first month, or is it the first DISTRIBUTION month?
- **Option A:** September is always the special "first month" (calendar-based)
- **Option B:** Whichever month a member first receives money is their "first distribution"

### Q2: What if a member's first distribution is in October?
**Example:** New member joins in October, receives ₹500

- **Option A:** Still defer to last month (no special first month)
- **Option B:** Defer October amount to last month, pay back future distributions next month

### Q3: What if the academic year starts in April instead of September?
- **Option A:** Always defer first month of academic year (April in this case)
- **Option B:** Always defer September regardless of academic year start

## My Understanding:

I believe the rule is:

```
IF distribution_month == academic_year_first_month:
    # Defer to last month
    monthly_payment = interest_only
    principal_payment_in_last_month = yes
ELSE:
    # Pay back next month
    monthly_payment = interest
    next_month_payment = principal + interest
    principal_payment_in_last_month = no
```

## Please Confirm:

Which rule is correct?

### Rule A: "First Calendar Month Rule"
- September distributions are always deferred to last month
- All other month distributions are paid next month

### Rule B: "Academic Year First Month Rule"  
- Whatever month the academic year STARTS (could be April, Sept, etc.)
- First month distributions are deferred
- All other months paid next month

### Rule C: "First Distribution Rule"
- Each member's FIRST EVER distribution (regardless of month)
- That specific amount is deferred to last month
- All future distributions for that member are paid next month

Please let me know which rule applies, and I'll implement it correctly!
