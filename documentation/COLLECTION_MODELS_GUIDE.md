# Collection Models Feature - Complete Guide

## 🎯 Overview
This feature allows you to choose different payment collection models when creating academic years, giving you flexibility to handle different types of lending/savings scenarios.

## 📊 Three Collection Models

### 1. Interest Only Model (Your Requested Feature) ⭐

**Perfect for:** Rotating savings, chit funds, community lending

**How it works:**
```
Month 1 (September):
- Give Member 1: ₹410 (principal)
- Calculate Interest: ₹410 × 0.20 = ₹100
- Outstanding: ₹510

Month 2 (October):
- Collect from Member 1: ₹100 (interest only)
- Outstanding after payment: ₹410 (principal remains)

Month 3 (November):
- Give Member 1: ₹650 (new distribution)
- Calculate Interest: ₹650 × 0.20 = ₹150
- Collect: ₹100 (old interest) + ₹650 (new principal) + ₹150 (new interest)
- Total Collection: ₹900
- Outstanding after payment: ₹410 (original principal remains)

Month 4 (December):
- Collect from Member 1: ₹250 (₹100 + ₹150 interest on both amounts)
- Outstanding: ₹410 (original principal still remains)
```

**Key Features:**
- ✅ Principal amount stays in the system
- ✅ Members pay interest monthly
- ✅ New distributions are collected immediately (principal + interest)
- ✅ After new distribution collection, back to interest-only
- ✅ Ideal for revolving funds

---

### 2. Full Amount Model 💯

**Perfect for:** Short-term loans, microfinance, quick repayment

**How it works:**
```
Month 1 (September):
- Give Member 1: ₹410
- Calculate Interest: ₹100
- Outstanding: ₹510

Month 2 (October):
- Collect from Member 1: ₹510 (full amount)
- Outstanding: ₹0 (account cleared)

If member takes new loan:
Month 3 (November):
- Give Member 1: ₹650
- Calculate Interest: ₹150
- Outstanding: ₹800

Month 4 (December):
- Collect from Member 1: ₹800 (full amount)
- Outstanding: ₹0
```

**Key Features:**
- ✅ Clear repayment expectations
- ✅ No running balances
- ✅ Simple to track
- ✅ Quick turnover

---

### 3. Flexible Payment Model 🔄

**Perfect for:** Mixed groups, varying financial capabilities

**How it works:**
```
Month 1: Give ₹410, Interest ₹100, Total Outstanding: ₹510

Month 2 Options:
- Pay ₹100 (interest only) - Minimum
- Pay ₹300 (partial) - Medium
- Pay ₹510 (full) - Maximum
- Admin/Member decides each month

System tracks whatever is paid and adjusts outstanding balance
```

**Key Features:**
- ✅ Most flexible
- ✅ Members can pay what they can afford
- ✅ Auto-suggests but doesn't force amounts
- ✅ Good for welfare-oriented groups

---

## 🔧 Database Setup

### Step 1: Run Migration
```sql
-- Run this SQL file
database/add_collection_model.sql
```

This adds the `CollectionModel` field to your `academic_years` table with options:
- `InterestOnly`
- `FullAmount`
- `FlexiblePayment`

### Step 2: Verify Installation
```sql
SELECT YearName, CollectionModel FROM academic_years;
```

---

## 📝 How to Use

### Creating New Academic Year with Interest Only Model

1. **Go to:** Admin → Manage Academic Years
2. **Click:** "Add New Year"
3. **Fill in:**
   - Year Name: `2024-2025`
   - Start Date: `2024-04-01`
   - End Date: `2025-03-31`
   - Interest Rate: `0.20` (for 20%)
   - Interest Type: `Monthly`
   - **Collection Model:** `Interest Only` ⭐
4. **Save**

### Visual Indicators

The system shows clear badges in the table:
- 📊 **Interest Only** (Orange badge)
- 💯 **Full Amount** (Green badge)
- 🔄 **Flexible** (Blue badge)

---

## 💡 Real-World Example: Interest Only Model

### Scenario: 15-Member Chit Fund

**Setup:**
- Academic Year: 2024-2025
- Interest Rate: 0.20 (20% monthly)
- Collection Model: **Interest Only**

**Month 1 (September 2024):**
```
Member 1 wins the chit
- Distribute: ₹410
- Interest: ₹100
- Member 1 owes: ₹510 (but will pay in installments)
```

**Month 2 (October 2024):**
```
Collect from ALL members:
- Member 1 pays: ₹100 (interest only)
- Members 2-15 pay: ₹410 each (contribution)

Member 5 wins the chit
- Distribute to Member 5: ₹650
- Interest: ₹150
```

**Month 3 (November 2024):**
```
Collect from members:
- Member 1 pays: ₹100 (interest only on ₹410)
- Member 5 pays: ₹650 + ₹150 = ₹800 (principal + interest from winning)
- Members 2-4, 6-15 pay: ₹410 each (contribution)
```

**Month 4 (December 2024):**
```
Collect from members:
- Member 1 pays: ₹100 (interest only)
- Member 5 pays: ₹150 (interest only on ₹650)
- Members 2-4, 6-15 pay: ₹410 each (contribution)
```

---

## 🔀 Switching Between Models

You can edit an academic year to change its collection model:

1. Click **Edit** on the year
2. Change **Collection Model** dropdown
3. Save

**Note:** This will affect how future collections are calculated. Past transactions remain unchanged.

---

## 📊 Auto-Fill Behavior

### Interest Only Model:
- **First month after distribution:** Auto-fills with full amount (principal + interest)
- **Subsequent months:** Auto-fills with interest only
- **Example:** Member with ₹410 principal
  - First month: Shows ₹510 (₹410 + ₹100)
  - Next months: Shows ₹100 (interest only)

### Full Amount Model:
- Always auto-fills with full outstanding balance
- **Example:** Shows ₹510 every month until paid

### Flexible Model:
- Auto-fills with outstanding balance
- Shows minimum payment (interest only)
- Admin can change to any amount

---

## 🧪 Testing Your Setup

### Test Interest Only Model:

1. **Create test year with Interest Only model**
2. **Add transaction for Member 1:**
   - Type: Distribution
   - Amount: ₹410
   - Interest: ₹100 (auto-calculated)
3. **Next month, add collection:**
   - Type: Collection
   - Amount should auto-fill: ₹100 (interest only)
4. **Add another distribution same month:**
   - Type: Distribution
   - Amount: ₹650
   - Interest: ₹150
5. **Next month, check auto-fill:**
   - Should show: ₹250 (₹100 + ₹150 interest from both)

---

## 📁 Files Modified

1. **database/updates_academic_years.sql**
   - Added `CollectionModel` to CREATE TABLE

2. **database/add_collection_model.sql** (NEW)
   - Migration script with detailed examples

3. **admin/manage-years.php**
   - Added Collection Model dropdown
   - Added visual badges for models
   - Added JavaScript to show model explanations
   - Added CSS for new badge colors

4. **admin/add-transaction.php**
   - Smart calculation based on collection model
   - Auto-fill respects collection model
   - Shows collection model in year info

---

## 🎓 Best Practices

### Use Interest Only When:
- ✅ Running a chit fund or rotating savings
- ✅ Members win/receive distributions periodically
- ✅ Need to keep principal amounts circulating
- ✅ Want steady monthly interest income

### Use Full Amount When:
- ✅ Providing short-term loans
- ✅ Need quick repayment
- ✅ Want clear closure of accounts
- ✅ Simple repayment structure needed

### Use Flexible Payment When:
- ✅ Members have varying income levels
- ✅ Community welfare focus
- ✅ Want to avoid defaults
- ✅ Need maximum flexibility

---

## ❓ FAQ

**Q: Can I change the collection model mid-year?**
A: Yes, but it only affects new transactions. Past transactions remain unchanged.

**Q: What happens to existing transactions when I change the model?**
A: Nothing. They stay as-is. The new model only affects how future collections are calculated.

**Q: Can a member have different models for different distributions?**
A: No, the model is set at the academic year level and applies to all members for that year.

**Q: What if a member doesn't pay on time in Interest Only model?**
A: The interest keeps accumulating. You can track overdue amounts in the transaction history.

**Q: Can I mix collection models in the same year?**
A: No, but you can use Flexible Payment model which allows per-transaction flexibility.

---

## 🚀 Quick Start Guide

1. **Run migration:** `database/add_collection_model.sql`
2. **Edit your 2024-2025 year:** Set Collection Model to "Interest Only"
3. **Test with one member:**
   - Give them ₹410
   - Next month collect ₹100
   - Give them ₹650
   - Next month collect ₹900
4. **Roll out to all members**

---

## 📞 Support

If you have questions or need customization:
1. Check the inline help in the form (shows examples for each model)
2. Review transaction history to understand calculations
3. Use the flexible model for complex scenarios

---

**Version:** 2.0  
**Date:** October 17, 2025  
**Status:** ✅ Fully Implemented  
**Feature Requested By:** User (Interest Only monthly collection model)
