# Debugging: Member Info Not Showing

## Issue
When selecting a member (e.g., "Kranti Kumar Raju (Active) [ID: 1]"), the member information below the dropdown is not appearing.

Expected to see:
```
📊 Monthly Interest: ₹100.00 | Outstanding Principal: ₹410.00 | Total Outstanding: ₹510.00
```

## Debugging Steps

### Step 1: Check Browser Console
1. Open the add-transaction.php page
2. Press **F12** to open Developer Tools
3. Go to **Console** tab
4. Select a member from dropdown
5. Look for console log message:
   ```
   Member changed. Selected: 1, Outstanding: 510, Distributed: 410
   ```

### Step 2: Check if Element Exists
In console, type:
```javascript
document.getElementById('memberDueInfo')
```

**Expected:** Should return an HTML element, not `null`

### Step 3: Check Element Visibility
In console, type:
```javascript
document.getElementById('memberDueInfo').style.display
```

**Expected:** Should be `'block'` when member is selected

### Step 4: Check Element Content
In console, type:
```javascript
document.getElementById('memberDueInfo').innerHTML
```

**Expected:** Should show the HTML with member info

### Step 5: Manually Trigger Function
In console, type:
```javascript
handleMemberChange()
```

Then check if the info appears.

## Common Issues

### Issue 1: JavaScript Error Before handleMemberChange
**Symptom:** Function never runs
**Check:** Look for red errors in console before member selection
**Fix:** Fix any errors that appear first

### Issue 2: Element Not Found
**Symptom:** `getElementById('memberDueInfo')` returns `null`
**Check:** View page source, search for `id="memberDueInfo"`
**Fix:** Ensure the element exists in HTML

### Issue 3: CSS Hiding Element
**Symptom:** Element exists but not visible
**Check:** Inspect element with Developer Tools
**Fix:** Check for conflicting CSS styles

### Issue 4: Member Data Not Loading
**Symptom:** `data-outstanding` attribute is 0 or missing
**Check:** In console:
```javascript
document.querySelector('#memberID option:checked').getAttribute('data-outstanding')
```
**Fix:** Check PHP data loading in add-transaction.php

### Issue 5: Collection Model Not Set
**Symptom:** Wrong branch of if/else executed
**Check:** In console:
```javascript
collectionModel
```
**Fix:** Ensure academic year has collection model set

## Quick Fix Test

Open browser console and run:
```javascript
// Force show the info
const info = document.getElementById('memberDueInfo');
info.style.display = 'block';
info.style.color = '#667eea';
info.innerHTML = '✅ TEST: Monthly Interest: ₹100.00 | Outstanding: ₹410.00';
```

If this shows the info, the issue is with the JavaScript function logic.
If this doesn't show anything, the issue is with the HTML element or CSS.

## Check Academic Year Settings

The member info display depends on the Collection Model setting.

In console, check:
```javascript
console.log('Collection Model:', collectionModel);
console.log('Interest Rate:', interestRate);
```

**Expected values:**
- Collection Model: 'InterestOnly', 'FullAmount', or 'FlexiblePayment'
- Interest Rate: 0.20 (for 20%)

## Files to Check

1. **admin/add-transaction.php** - Line ~594
   - Look for: `<small id="memberDueInfo" ...>`
   - Should exist in the HTML

2. **admin/add-transaction.php** - Line ~899
   - Look for: `const memberDueInfo = document.getElementById('memberDueInfo');`
   - Should be defined before functions

3. **admin/add-transaction.php** - Line ~963-1030
   - The `handleMemberChange()` function
   - Should set `display: 'block'` and update `innerHTML`

## What We Fixed

### Change 1: Moved display:block to top
**Before:**
```javascript
if (memberSelect.value) {
    if (outstandingBalance <= 0) {
        memberDueInfo.style.display = 'block';  // Only for no due
```

**After:**
```javascript
if (memberSelect.value) {
    memberDueInfo.style.display = 'block';  // Always show when member selected
    if (outstandingBalance <= 0) {
```

### Change 2: Added console logging
```javascript
console.log('Member changed. Selected:', memberSelect.value, 'Outstanding:', outstandingBalance);
```

### Change 3: Fixed initialization order
**Before:**
```javascript
handleMemberChange();
handleTransactionTypeChange();
```

**After:**
```javascript
handleTransactionTypeChange();  // Get transaction type first
handleMemberChange();            // Then show member info
```

## Expected Behavior

1. **Page loads** → Member info hidden (no member selected)
2. **Select member "Kranti Kumar Raju"** → Triggers `handleMemberChange()`
3. **Console shows:** "Member changed. Selected: 1, Outstanding: 510, Distributed: 410"
4. **Info appears below dropdown:**
   - Interest Only Model: "📊 Monthly Interest: ₹100.00 | Outstanding Principal: ₹410.00 | Total Outstanding: ₹510.00"
   - Full Amount Model: "💰 Full Outstanding: ₹510.00"
   - Flexible Model: "💵 Outstanding: ₹510.00 | Minimum: ₹100.00"

## Test Results

After refresh (Ctrl + F5):

- [ ] Console shows member data when selected
- [ ] `memberDueInfo` element exists
- [ ] Element display is 'block' when member selected
- [ ] Info text appears below dropdown
- [ ] Info shows correct amounts

## If Still Not Working

1. **Clear browser cache** (Ctrl + Shift + Delete)
2. **Hard refresh** (Ctrl + F5)
3. **Check for JavaScript errors** in console
4. **Verify member has data** (check data attributes)
5. **Check collection model** is set in academic year
6. **Share console output** for further debugging
