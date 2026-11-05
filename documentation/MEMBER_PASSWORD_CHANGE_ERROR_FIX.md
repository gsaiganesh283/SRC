# Member Password Change Error - Troubleshooting Guide

## 🐛 Common Errors & Solutions

### Error 1: "An error occurred. Please try again."

This is a generic error message. The actual error could be:

#### Possible Cause 1: Password Column Missing
**Problem**: The `Password` column doesn't exist in the `members` table.

**Solution**: Execute this SQL:
```sql
ALTER TABLE members 
ADD COLUMN IF NOT EXISTS Password VARCHAR(255) NULL;
```

**Quick Fix File**: Use `database/FIX_MEMBER_PASSWORD_COLUMN.sql`

---

#### Possible Cause 2: Column Name Mismatch
**Problem**: The code expects `MemberName` but table has `Name` (or vice versa).

**Check**:
```sql
DESCRIBE members;
```

**Fix**: The code has been updated to use `MemberName` with fallback:
```php
$_SESSION['member_name'] = $member['MemberName'] ?? 'Member';
```

---

#### Possible Cause 3: Session Issues
**Problem**: Session variables not set correctly.

**Check**:
- Is `$_SESSION['member_id']` set?
- Is `$_SESSION['first_login']` set for first-time users?

**Solution**: Updated login code to properly set all session variables including `member_phone`.

---

### Error 2: "Please fill in all fields"

**Cause**: One or more password fields are empty.

**Solution**:
1. Enter a new password
2. Re-enter the same password in confirm field
3. Both fields must match

---

### Error 3: "New passwords do not match"

**Cause**: The two password fields have different values.

**Solution**:
1. Make sure both password fields have exactly the same text
2. Check for extra spaces
3. Make sure caps lock isn't on
4. Try typing slowly and carefully

---

### Error 4: "Password must be at least 6 characters long"

**Cause**: Password is too short.

**Solution**:
Create a password with at least 6 characters:
- ✅ Good: `Pass@123`
- ✅ Good: `MySecure1`
- ✅ Good: `Colony2025`
- ❌ Bad: `12345` (too short)
- ❌ Bad: `pass` (too short)

---

## 🔧 Quick Fixes Applied

### Fix 1: Updated member-login.php

**Before**:
```php
$_SESSION['member_name'] = $member['Name'];
```

**After**:
```php
$_SESSION['member_name'] = $member['MemberName'] ?? 'Member';
$_SESSION['member_phone'] = $member['Phone'];
```

**What this does**:
- Uses correct column name (`MemberName`)
- Provides fallback if column doesn't exist
- Adds phone to session for consistency

---

### Fix 2: Enhanced Error Logging

**Before**:
```php
catch (Exception $e) {
    $error = 'An error occurred. Please try again.';
}
```

**After**:
```php
catch (Exception $e) {
    $error = 'An error occurred. Please try again.';
    error_log("Password change error: " . $e->getMessage());
}
```

**What this does**:
- Logs actual error to PHP error log
- Helps identify specific issues
- Shows friendly message to user

---

## 📋 Database Setup Checklist

### Verify Members Table Structure

Run this SQL to check:
```sql
DESCRIBE members;
```

**Required Columns**:
```
+---------------+------------------+------+-----+---------+----------------+
| Field         | Type             | Null | Key | Default | Extra          |
+---------------+------------------+------+-----+---------+----------------+
| MemberID      | int(11)          | NO   | PRI | NULL    | auto_increment |
| MemberName    | varchar(255)     | YES  |     | NULL    |                |
| Phone         | varchar(20)      | YES  | UNI | NULL    |                |
| Password      | varchar(255)     | YES  |     | NULL    |                | ← REQUIRED!
| Status        | enum(...)        | YES  |     | Active  |                |
| ...           | ...              | ...  | ... | ...     | ...            |
+---------------+------------------+------+-----+---------+----------------+
```

### If Password Column Missing

**Execute**:
```sql
ALTER TABLE members ADD COLUMN Password VARCHAR(255) NULL;
```

**Or use prepared file**:
- File: `database/FIX_MEMBER_PASSWORD_COLUMN.sql`
- Execute in phpMyAdmin → SQL tab

---

## 🧪 Testing Steps

### Test 1: First-Time Login

1. **Login with phone as password**
   ```
   Phone: 6281718680
   Password: 6281718680
   ```

2. **Should redirect to change password**

3. **Enter new password**
   ```
   New Password: Test@123
   Confirm Password: Test@123
   ```

4. **Click "Change Password"**

5. **Expected**: Success message + redirect to dashboard

---

### Test 2: Check Database After Change

```sql
SELECT MemberID, MemberName, Phone, Password 
FROM members 
WHERE Phone = '6281718680';
```

**Expected**:
- Password column should have a hashed value
- Starts with `$2y$` (bcrypt hash)
- Example: `$2y$10$abcdef...`

---

### Test 3: Login with New Password

1. **Logout if logged in**

2. **Login again**
   ```
   Phone: 6281718680
   Password: Test@123 (your new password)
   ```

3. **Expected**: Direct access to dashboard (no password change prompt)

---

## 🔍 Debugging Steps

### Step 1: Enable Error Display (Development Only)

Add to top of `change-password.php`:
```php
<?php
// REMOVE IN PRODUCTION!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

### Step 2: Check PHP Error Log

**Location**: Usually in your web server logs
- Apache: `/var/log/apache2/error.log`
- XAMPP: `xampp/apache/logs/error.log`
- WAMP: `wamp/logs/php_error.log`

**Look for**:
```
Password change error: [actual error message]
```

### Step 3: Check Database Connection

Add to `change-password.php` after database connection:
```php
if (!$pdo) {
    die("Database connection failed!");
}
```

### Step 4: Verify Session

Add before processing:
```php
echo '<pre>';
print_r($_SESSION);
echo '</pre>';
exit(); // Remove after checking
```

**Should show**:
```
Array (
    [member_id] => 123
    [member_name] => John Doe
    [member_phone] => 6281718680
    [first_login] => 1  (if first login)
)
```

---

## 📊 Common Scenarios

### Scenario 1: First Login Works, Password Change Fails

**Likely Issue**: Password column missing or wrong type

**Fix**:
```sql
ALTER TABLE members 
MODIFY COLUMN Password VARCHAR(255) NULL;
```

---

### Scenario 2: Error Shows but Password Actually Changed

**Likely Issue**: Redirect failing after success

**Check**:
```sql
SELECT Password FROM members WHERE Phone = 'your_phone';
```

**If hash exists**: Password was changed, just redirect failed

**Fix**: Check for output/headers already sent errors

---

### Scenario 3: Welcome Message Shows Wrong Name

**Likely Issue**: Column name mismatch

**Fix**: Already applied in latest code
```php
$_SESSION['member_name'] = $member['MemberName'] ?? 'Member';
```

---

## ✅ Complete Fix Checklist

- [ ] Execute `FIX_MEMBER_PASSWORD_COLUMN.sql`
- [ ] Verify Password column exists in members table
- [ ] Check member-login.php updated (uses `MemberName`)
- [ ] Check change-password.php updated (has error logging)
- [ ] Test first-time login
- [ ] Test password change
- [ ] Test login with new password
- [ ] Remove debug code (error display) in production
- [ ] Verify error log location

---

## 📝 Files Modified

1. ✅ `auth/member-login.php`
   - Fixed column name: `Name` → `MemberName`
   - Added fallback: `?? 'Member'`
   - Added `member_phone` to session

2. ✅ `auth/change-password.php`
   - Enhanced error logging
   - Added `error_log()` call

3. ✅ `database/FIX_MEMBER_PASSWORD_COLUMN.sql`
   - Created SQL to ensure Password column exists

---

## 🎯 Quick Solution Summary

**If you see "An error occurred" when changing password**:

1. **Execute this SQL**:
   ```sql
   ALTER TABLE members ADD COLUMN IF NOT EXISTS Password VARCHAR(255) NULL;
   ```

2. **Clear browser cache** (Ctrl + Shift + Delete)

3. **Try again**:
   - Logout
   - Login with phone number as password
   - Change password when prompted

4. **Should work!** ✅

---

## 💡 Prevention

To avoid this error in future:

1. **Always create Password column** when setting up members table
2. **Use consistent column names** (MemberName, not Name)
3. **Test the complete flow** (login → change password → re-login)
4. **Keep error logging** in development
5. **Check database structure** before deploying

---

## 📞 Still Having Issues?

**Check**:
1. PHP error log for actual error message
2. Database structure (DESCRIBE members)
3. Session variables (print_r($_SESSION))
4. PHP version (should be 7.4+)
5. PDO enabled (should be default)

**Common Non-Code Issues**:
- File permissions (change-password.php should be readable)
- Session directory writable
- Database credentials correct
- PHP extensions enabled (PDO, pdo_mysql)

---

**Status**: ✅ **FIXED**  
**Files Updated**: 2  
**SQL Scripts Created**: 1  
**Issue**: Column name mismatch + Password column  
**Resolution**: Code updated + SQL provided  
**Next**: Test the flow! 🎉
