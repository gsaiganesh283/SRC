# Navigation Components Guide

## Overview
Reusable navigation components for the Super Admin Portal to ensure consistent navigation across all pages.

---

## 📁 Components Created

### 1. **Navbar Component**
**File**: `superadmin/includes/navbar.php`

A consistent navigation bar with:
- Super Admin branding
- Back to Dashboard button (auto-hides on dashboard page)
- Theme toggle
- User info display
- Logout button

### 2. **Quick Navigation Menu**
**File**: `superadmin/includes/quick-nav.php`

A horizontal menu with icon-based navigation to all major sections:
- Dashboard
- Manage Admins
- Transactions
- Reports
- Database Backup
- Settings
- Audit Logs

---

## 🚀 Usage

### Including the Navbar

Add this to any super admin page after the `<body>` tag:

```php
<?php include 'includes/navbar.php'; ?>
```

**Features**:
- Automatically shows "Back to Dashboard" button on all pages except dashboard.php
- Theme toggle works with the centralized theme manager
- Shows current user's name from session
- Fully responsive

### Including Quick Navigation

Add this inside your container, before the main content:

```php
<div class="container">
    <?php include 'includes/quick-nav.php'; ?>
    
    <!-- Your page content here -->
</div>
```

**Features**:
- Highlights the current active page
- Icon-based navigation for easy identification
- Hover effects with gradient accents
- Responsive design (stacks on mobile)

---

## 📋 Complete Page Template

```php
<?php
require_once 'session.php';
require_once '../config/config.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
</head>
<body>
    <!-- Navigation Bar -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="container">
        <!-- Quick Navigation Menu -->
        <?php include 'includes/quick-nav.php'; ?>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1>Page Title</h1>
            <p>Page description</p>
        </div>

        <!-- Page Content -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-icon"></i> Section Title
                </h3>
            </div>
            <div class="card-body">
                <!-- Your content here -->
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="theme-manager.js"></script>
</body>
</html>
```

---

## 🎨 Customization

### Navbar Brand

Change the icon and text in `includes/navbar.php`:

```php
<a href="dashboard.php" class="navbar-brand">
    <i class="fas fa-your-icon"></i>
    <span>Your Text</span>
</a>
```

### Adding Navigation Items

Edit `includes/quick-nav.php` to add new items:

```php
<a href="your-page.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'your-page.php' ? 'active' : ''; ?>">
    <i class="fas fa-your-icon"></i>
    <span>Your Label</span>
</a>
```

---

## 🔄 Migration Guide

### Existing Pages

To add navigation to existing pages:

1. **Add navbar include** after `<body>` tag:
   ```php
   <?php include 'includes/navbar.php'; ?>
   ```

2. **Add quick-nav** inside container (optional):
   ```php
   <?php include 'includes/quick-nav.php'; ?>
   ```

3. **Remove old navbar code** if exists

### Example Migration

**Before**:
```php
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <!-- Custom navbar code -->
        </div>
    </nav>
    
    <div class="container">
        <!-- Content -->
    </div>
</body>
```

**After**:
```php
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <?php include 'includes/quick-nav.php'; ?>
        
        <!-- Content -->
    </div>
</body>
```

---

## ✨ Features

### Navbar Component

| Feature | Description |
|---------|-------------|
| **Auto Dashboard Link** | Shows "Back to Dashboard" on all pages except dashboard |
| **Theme Toggle** | Integrated with ThemeManager |
| **User Display** | Shows username and role |
| **Responsive** | Mobile-friendly design |
| **Gradient Styling** | Uses theme gradient colors |

### Quick Navigation

| Feature | Description |
|---------|-------------|
| **Active Highlighting** | Current page is highlighted |
| **Icon Navigation** | Visual icons for each section |
| **Hover Effects** | Gradient accents on hover |
| **Responsive** | Wraps on smaller screens |
| **Theme Compatible** | Works in light and dark modes |

---

## 🎯 Benefits

### Consistency
- ✅ Same navigation across all pages
- ✅ Uniform user experience
- ✅ Centralized updates

### Maintainability
- ✅ Edit once, update everywhere
- ✅ No duplicate code
- ✅ Easy to add new pages

### User Experience
- ✅ Always know where you are
- ✅ Easy navigation between sections
- ✅ Quick access to all features

---

## 🔧 Technical Details

### Session Variables Required

Both components expect these session variables:
- `$superadmin_username` - For display in navbar
- Session must be started with `require_once 'session.php'`

### Dependencies

- **CSS**: `shared-styles.css` (for navbar styling)
- **JavaScript**: `theme-manager.js` (for theme toggle)
- **Font Awesome**: For icons

### File Structure

```
superadmin/
├── includes/
│   ├── navbar.php          # Navigation bar component
│   └── quick-nav.php       # Quick navigation menu
├── shared-styles.css       # Shared styles
├── theme-manager.js        # Theme management
└── *.php                   # Your pages
```

---

## 📱 Responsive Behavior

### Desktop (> 768px)
- Full navigation with all items visible
- Large icons and text
- Horizontal layout

### Mobile (≤ 768px)
- Navbar stacks vertically
- Quick nav items wrap
- Smaller fonts and icons
- Touch-friendly sizing

---

## 🎨 Styling Variables

Both components use CSS variables from `shared-styles.css`:

```css
--bg-secondary      /* Card backgrounds */
--bg-tertiary       /* Hover states */
--text-primary      /* Main text */
--text-secondary    /* Secondary text */
--border-color      /* Borders */
--gradient-1        /* Accent color */
--gradient-primary  /* Active state */
--shadow            /* Shadows */
--shadow-hover      /* Hover shadows */
```

---

## 🐛 Troubleshooting

### Back to Dashboard button not showing
- Check if `basename($_SERVER['PHP_SELF'])` returns correct filename
- Ensure page filename is exactly `dashboard.php`

### Active state not highlighting
- Verify filename matches exactly in quick-nav.php
- Check if page is using the include correctly

### Theme toggle not working
- Ensure `theme-manager.js` is included
- Check browser console for JavaScript errors

### User info not displaying
- Verify session is started
- Check `$superadmin_username` is set in session

---

## 📚 Related Documentation

- [THEME_SYSTEM_GUIDE.md](./THEME_SYSTEM_GUIDE.md) - Theme integration
- [THEME_QUICK_REFERENCE.md](./THEME_QUICK_REFERENCE.md) - CSS reference
- [SUPER_ADMIN_ENHANCED_FEATURES.md](./SUPER_ADMIN_ENHANCED_FEATURES.md) - Feature guide

---

## 🎯 Best Practices

1. **Always include navbar** on authenticated pages
2. **Use quick-nav** for main super admin pages
3. **Keep navigation consistent** across all pages
4. **Test responsive** behavior on mobile
5. **Update quick-nav** when adding new major features

---

**Version**: 1.0  
**Last Updated**: October 20, 2025  
**Status**: ✅ Production Ready
