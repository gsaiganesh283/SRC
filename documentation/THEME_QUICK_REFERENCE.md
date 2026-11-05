# Enhanced Theme Quick Reference Guide

A quick reference for developers working with the enhanced theme system.

---

## 🎨 CSS Variables Quick Reference

### Colors

```css
/* Light Mode */
--bg-primary: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%)
--bg-secondary: #ffffff
--bg-tertiary: #f8f9fa
--text-primary: #2c3e50
--text-secondary: #7f8c8d
--border-color: #e1e4e8
--border-light: #f0f1f3

/* Dark Mode */
--bg-primary: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)
--bg-secondary: #2a2d3a
--bg-tertiary: #363a47
--text-primary: #e8eaed
--text-secondary: #a0a4a8
--border-color: #404552
--border-light: #35383f
```

### Gradients

```css
--gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%)
--gradient-success: linear-gradient(135deg, #11998e 0%, #38ef7d 100%)
--gradient-danger: linear-gradient(135deg, #eb3349 0%, #f45c43 100%)
--gradient-warning: linear-gradient(135deg, #f2994a 0%, #f2c94c 100%)
--gradient-info: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)
```

### Shadows

```css
/* Light Mode */
--shadow: 0 2px 8px rgba(0, 0, 0, 0.08)
--shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12)
--shadow-lg: 0 12px 32px rgba(0, 0, 0, 0.15)

/* Dark Mode */
--shadow: 0 4px 16px rgba(0, 0, 0, 0.4)
--shadow-hover: 0 12px 32px rgba(0, 0, 0, 0.5)
--shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.6)
```

---

## 🧩 Component Classes

### Buttons

```html
<!-- Primary button with gradient -->
<button class="btn btn-primary">
    <i class="fas fa-save"></i> Save
</button>

<!-- Success button -->
<button class="btn btn-success">
    <i class="fas fa-check"></i> Confirm
</button>

<!-- Danger button -->
<button class="btn btn-danger">
    <i class="fas fa-trash"></i> Delete
</button>

<!-- Warning button -->
<button class="btn btn-warning">
    <i class="fas fa-exclamation"></i> Warning
</button>

<!-- Info button -->
<button class="btn btn-info">
    <i class="fas fa-info"></i> Info
</button>

<!-- Secondary button -->
<button class="btn btn-secondary">
    <i class="fas fa-times"></i> Cancel
</button>
```

### Cards

```html
<!-- Standard card with gradient accent on hover -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-chart-bar"></i> Card Title
        </h3>
    </div>
    <div class="card-body">
        <!-- Content here -->
    </div>
</div>

<!-- Card with footer -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Title</h3>
    </div>
    <div class="card-body">
        Content
    </div>
    <div class="card-footer">
        <button class="btn btn-primary">Action</button>
    </div>
</div>
```

### Badges

```html
<!-- Success badge with gradient -->
<span class="badge badge-success">Active</span>

<!-- Danger badge -->
<span class="badge badge-danger">Inactive</span>

<!-- Warning badge -->
<span class="badge badge-warning">Pending</span>

<!-- Info badge -->
<span class="badge badge-info">New</span>
```

### Alerts

```html
<!-- Success alert -->
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <div>Operation successful!</div>
</div>

<!-- Danger alert -->
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    <div>Error occurred!</div>
</div>

<!-- Warning alert -->
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <div>Warning message</div>
</div>

<!-- Info alert -->
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <div>Information message</div>
</div>
```

### Forms

```html
<div class="form-group">
    <label class="form-label">Input Label</label>
    <input type="text" class="form-control" placeholder="Enter text">
</div>

<div class="form-group">
    <label class="form-label">Select Label</label>
    <select class="form-control">
        <option>Option 1</option>
        <option>Option 2</option>
    </select>
</div>

<div class="form-group">
    <label class="form-label">Textarea Label</label>
    <textarea class="form-control" rows="4"></textarea>
</div>
```

### Modals

```html
<!-- Modal trigger -->
<button class="btn btn-primary" onclick="openModal()">Open Modal</button>

<!-- Modal structure -->
<div class="modal" id="myModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-edit"></i> Modal Title
            </h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Modal content goes here</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary">Save</button>
        </div>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('myModal').classList.add('active');
}

function closeModal() {
    document.getElementById('myModal').classList.remove('active');
}
</script>
```

### Tables

```html
<table class="table">
    <thead>
        <tr>
            <th>Column 1</th>
            <th>Column 2</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Data 1</td>
            <td>Data 2</td>
            <td>
                <button class="btn btn-sm btn-primary">Edit</button>
                <button class="btn btn-sm btn-danger">Delete</button>
            </td>
        </tr>
    </tbody>
</table>
```

---

## 🎭 Theme Integration

### Page Setup

```php
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Shared Theme Styles -->
    <link rel="stylesheet" href="shared-styles.css">
    
    <!-- Page-specific styles (optional) -->
    <style>
        /* Custom styles here */
    </style>
</head>
<body>
    <!-- Content -->
    
    <!-- Theme Manager Script -->
    <script src="theme-manager.js"></script>
    
    <!-- Page scripts -->
    <script>
        // Listen for theme changes
        document.addEventListener('themeChanged', function(e) {
            console.log('Theme changed to:', e.detail.theme);
            // Update any theme-dependent elements
        });
    </script>
</body>
</html>
```

### Theme Toggle Button

```html
<button class="theme-toggle" onclick="window.ThemeManager.toggleTheme()">
    <i class="fas fa-moon"></i>
    <span id="themeText">Dark</span>
</button>
```

### JavaScript Theme Control

```javascript
// Get current theme
const currentTheme = window.ThemeManager.getCurrentTheme();

// Set specific theme
window.ThemeManager.setTheme('dark');
window.ThemeManager.setTheme('light');

// Toggle theme
window.ThemeManager.toggleTheme();

// Get chart colors
const chartColors = window.ThemeManager.getChartColors();

// Update charts on theme change
document.addEventListener('themeChanged', function(e) {
    window.ThemeManager.updateChartTheme();
});
```

---

## 🎨 Custom Styling Patterns

### Creating Hover Effects

```css
.my-element {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.my-element:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}
```

### Adding Gradient Backgrounds

```css
.my-gradient-box {
    background: var(--gradient-primary);
    color: white;
    padding: 20px;
    border-radius: 12px;
}

/* Custom gradient */
.my-custom-gradient {
    background: linear-gradient(135deg, #color1 0%, #color2 100%);
}
```

### Creating Ripple Effects

```css
.my-button {
    position: relative;
    overflow: hidden;
}

.my-button::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.my-button:hover::after {
    width: 300px;
    height: 300px;
}
```

### Adding Gradient Accent Bars

```css
.my-card {
    position: relative;
    overflow: hidden;
}

.my-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient-primary);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.my-card:hover::before {
    opacity: 1;
}
```

---

## 🔧 Utility Classes

### Text Colors

```html
<p style="color: var(--text-primary)">Primary text</p>
<p style="color: var(--text-secondary)">Secondary text</p>
```

### Backgrounds

```html
<div style="background: var(--bg-primary)">Primary background</div>
<div style="background: var(--bg-secondary)">Secondary background</div>
<div style="background: var(--bg-tertiary)">Tertiary background</div>
```

### Borders

```html
<div style="border: 1px solid var(--border-color)">Border</div>
<div style="border: 1px solid var(--border-light)">Light border</div>
```

### Shadows

```html
<div style="box-shadow: var(--shadow)">Default shadow</div>
<div style="box-shadow: var(--shadow-hover)">Hover shadow</div>
<div style="box-shadow: var(--shadow-lg)">Large shadow</div>
```

---

## 📱 Responsive Design

### Breakpoints

```css
/* Mobile */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
}

/* Tablet */
@media (min-width: 769px) and (max-width: 1024px) {
    /* Tablet styles */
}

/* Desktop */
@media (min-width: 1025px) {
    /* Desktop styles */
}
```

---

## ⚡ Performance Tips

1. **Use CSS Variables**: `var(--gradient-primary)` instead of hard-coded gradients
2. **Hardware Acceleration**: Use `transform` and `opacity` for animations
3. **Avoid Layout Shifts**: Use `transform: translateY()` instead of `top`
4. **Debounce Events**: Debounce scroll/resize listeners
5. **Lazy Load**: Defer non-critical CSS/JS

---

## 🐛 Common Issues & Solutions

### Issue: Theme not applying
```javascript
// Solution: Ensure theme-manager.js is loaded
<script src="theme-manager.js"></script>

// And HTML has data-theme attribute
<html data-theme="light">
```

### Issue: Gradient not showing
```css
/* Wrong: Missing gradient function */
background: #667eea 0%, #764ba2 100%;

/* Correct: Use linear-gradient */
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
```

### Issue: Hover effect not working
```css
/* Wrong: Missing position/overflow */
.element::after {
    content: '';
}

/* Correct: Add positioning */
.element {
    position: relative;
    overflow: hidden;
}

.element::after {
    content: '';
    position: absolute;
}
```

### Issue: Modal not closing
```javascript
// Ensure modal close function removes 'active' class
function closeModal() {
    document.getElementById('myModal').classList.remove('active');
}

// Click outside to close
modal.addEventListener('click', function(e) {
    if (e.target === modal) {
        closeModal();
    }
});
```

---

## 📚 Code Snippets

### Complete Page Template

```php
<?php
session_start();
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
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-crown"></i>
                <span>Super Admin</span>
            </a>
            <div class="navbar-user">
                <button class="theme-toggle" onclick="window.ThemeManager.toggleTheme()">
                    <i class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo $_SESSION['username']; ?></div>
                    <div class="user-role">Super Administrator</div>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1>Page Title</h1>
            <p>Page description</p>
        </div>

        <!-- Content goes here -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-table"></i> Card Title
                </h3>
            </div>
            <div class="card-body">
                <!-- Content -->
            </div>
        </div>
    </div>

    <script src="theme-manager.js"></script>
    <script>
        // Page-specific JavaScript
    </script>
</body>
</html>
```

---

## ✅ Best Practices

1. **Always use CSS variables** for colors/shadows
2. **Include Font Awesome** for icons
3. **Add theme-manager.js** for theme support
4. **Use semantic HTML** (card, modal, alert structures)
5. **Test both themes** (light and dark)
6. **Follow naming conventions** (btn-primary, badge-success)
7. **Add transitions** for smooth interactions
8. **Use cubic-bezier** for natural animations
9. **Include icons** in buttons/titles for clarity
10. **Make responsive** with media queries

---

**Version**: 1.0  
**Last Updated**: 2024  
**Related Docs**: [THEME_SYSTEM_GUIDE.md](./THEME_SYSTEM_GUIDE.md), [ENHANCED_THEME_UPDATE.md](./ENHANCED_THEME_UPDATE.md)
