/**
 * SR Colony Chitti Management System
 * Main JavaScript File
 */

// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.parentElement.querySelector('.password-toggle-btn');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.textContent = '🙈';
    } else {
        field.type = 'password';
        button.textContent = '👁️';
    }
}

// Form validation for phone numbers
document.addEventListener('DOMContentLoaded', function() {
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            // Remove non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Limit to 10 digits
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }
        });
    });
    
    // Auto-dismiss success messages after 5 seconds
    const successMessages = document.querySelectorAll('.success-message');
    successMessages.forEach(msg => {
        setTimeout(() => {
            msg.style.transition = 'opacity 0.5s';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 500);
        }, 5000);
    });
    
    // Confirm logout
    const logoutLinks = document.querySelectorAll('a[href*="logout"]');
    logoutLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to logout?')) {
                e.preventDefault();
            }
        });
    });
    
    // File upload preview
    const photoInput = document.querySelector('input[type="file"][name="photo"]');
    if (photoInput) {
        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Check file size (5MB max)
                if (file.size > 5242880) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG and GIF files are allowed');
                    this.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    let preview = document.getElementById('photoPreview');
                    if (!preview) {
                        preview = document.createElement('img');
                        preview.id = 'photoPreview';
                        preview.style.cssText = 'width: 100px; height: 100px; object-fit: cover; border-radius: 10px; margin-top: 10px;';
                        photoInput.parentElement.appendChild(preview);
                    }
                    preview.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Particles animation
    createParticles();
});

// Create animated particles
function createParticles() {
    const particlesContainer = document.querySelector('.particles');
    if (!particlesContainer) return;
    
    for (let i = 0; i < 50; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.cssText = `
            position: absolute;
            width: ${Math.random() * 5 + 2}px;
            height: ${Math.random() * 5 + 2}px;
            background: rgba(255, 255, 255, ${Math.random() * 0.5 + 0.2});
            border-radius: 50%;
            left: ${Math.random() * 100}%;
            top: ${Math.random() * 100}%;
            animation: float ${Math.random() * 10 + 5}s ease-in-out infinite;
            animation-delay: ${Math.random() * 5}s;
        `;
        particlesContainer.appendChild(particle);
    }
}

// Password strength indicator
function checkPasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/\d/.test(password)) strength++;
    if (/[^a-zA-Z\d]/.test(password)) strength++;
    
    const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const colors = ['#ff4757', '#ff6b6b', '#ffa801', '#2ed573', '#26de81'];
    
    return {
        score: strength,
        label: labels[strength - 1] || 'Very Weak',
        color: colors[strength - 1] || '#ff4757'
    };
}

// Add password strength indicator to password fields
document.addEventListener('DOMContentLoaded', function() {
    const newPasswordFields = document.querySelectorAll('input[name="new_password"]');
    
    newPasswordFields.forEach(field => {
        const indicator = document.createElement('div');
        indicator.className = 'password-strength';
        indicator.style.cssText = 'margin-top: 8px; font-size: 12px; transition: all 0.3s;';
        field.parentElement.parentElement.appendChild(indicator);
        
        field.addEventListener('input', function() {
            const strength = checkPasswordStrength(this.value);
            indicator.textContent = `Password Strength: ${strength.label}`;
            indicator.style.color = strength.color;
        });
    });
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Loading spinner for forms
function showLoadingSpinner(button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = '⏳ Processing...';
    button.dataset.originalText = originalText;
}

function hideLoadingSpinner(button) {
    button.disabled = false;
    button.textContent = button.dataset.originalText || 'Submit';
}

// Add loading spinner to form submissions
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton && !submitButton.disabled) {
                showLoadingSpinner(submitButton);
            }
        });
    });
});

// Session timeout warning
let sessionTimeout;
let warningTimeout;

function resetSessionTimer() {
    clearTimeout(sessionTimeout);
    clearTimeout(warningTimeout);
    
    // Warning 2 minutes before timeout
    warningTimeout = setTimeout(() => {
        if (confirm('Your session will expire in 2 minutes due to inactivity. Click OK to stay logged in.')) {
            fetch(window.location.href).then(() => {
                resetSessionTimer();
            });
        }
    }, 28 * 60 * 1000); // 28 minutes
    
    // Auto logout after 30 minutes
    sessionTimeout = setTimeout(() => {
        alert('Your session has expired. You will be redirected to the login page.');
        window.location.href = 'auth/member-login.php?timeout=1';
    }, 30 * 60 * 1000); // 30 minutes
}

// Track user activity for session management
if (document.querySelector('.dashboard-container') || document.querySelector('.admin-container')) {
    resetSessionTimer();
    
    document.addEventListener('mousemove', resetSessionTimer);
    document.addEventListener('keypress', resetSessionTimer);
    document.addEventListener('click', resetSessionTimer);
    document.addEventListener('scroll', resetSessionTimer);
}

// Print functionality
function printTable() {
    window.print();
}

// Export table to CSV
function exportTableToCSV(filename = 'export.csv') {
    const table = document.querySelector('.payments-table');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const csvRow = [];
        cols.forEach(col => {
            csvRow.push('"' + col.textContent.trim() + '"');
        });
        csv.push(csvRow.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
}

// Search functionality for tables
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

// Initialize tooltips
document.querySelectorAll('[data-tooltip]').forEach(element => {
    element.addEventListener('mouseenter', function() {
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = this.dataset.tooltip;
        tooltip.style.cssText = `
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
            white-space: nowrap;
        `;
        document.body.appendChild(tooltip);
        
        const rect = this.getBoundingClientRect();
        tooltip.style.left = rect.left + 'px';
        tooltip.style.top = (rect.bottom + 5) + 'px';
        
        this.tooltipElement = tooltip;
    });
    
    element.addEventListener('mouseleave', function() {
        if (this.tooltipElement) {
            this.tooltipElement.remove();
            this.tooltipElement = null;
        }
    });
});
