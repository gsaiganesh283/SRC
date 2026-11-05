// Admin Panel JavaScript Functions

// View Toggle Functionality
function initViewToggle() {
    const tableViewBtn = document.getElementById('tableViewBtn');
    const cardViewBtn = document.getElementById('cardViewBtn');
    const tableView = document.getElementById('tableView');
    const cardView = document.getElementById('cardView');

    if (tableViewBtn && cardViewBtn && tableView && cardView) {
        tableViewBtn.addEventListener('click', function() {
            tableViewBtn.classList.add('active');
            cardViewBtn.classList.remove('active');
            tableView.classList.add('active');
            cardView.classList.remove('active');
        });

        cardViewBtn.addEventListener('click', function() {
            cardViewBtn.classList.add('active');
            tableViewBtn.classList.remove('active');
            tableView.classList.remove('active');
            cardView.classList.add('active');
        });
    }
}

// Search Functionality
function initSearch() {
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            
            // Search in table view
            const tableRows = document.querySelectorAll('#membersTable tbody tr');
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchValue) ? '' : 'none';
            });
            
            // Search in card view
            const cards = document.querySelectorAll('.member-card');
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchValue) ? '' : 'none';
            });
        });
    }
}

// Filter Functionality
function initFilter() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active class from all buttons
            filterButtons.forEach(b => b.classList.remove('active'));
            // Add active class to clicked button
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            
            // Filter table rows
            const tableRows = document.querySelectorAll('#membersTable tbody tr');
            tableRows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    row.style.display = row.dataset.status === filter ? '' : 'none';
                }
            });
            
            // Filter cards
            const cards = document.querySelectorAll('.member-card');
            cards.forEach(card => {
                if (filter === 'all') {
                    card.style.display = '';
                } else {
                    card.style.display = card.dataset.status === filter ? '' : 'none';
                }
            });
        });
    });
}

// Confirmation Dialog for Delete Actions
function initDeleteConfirmation() {
    const deleteLinks = document.querySelectorAll('.btn-delete');
    
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this member? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
}

// Form Validation
function initFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#dc3545';
                } else {
                    field.style.borderColor = '#e0e0e0';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });
}

// Password Toggle Functionality for Login Page
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    
    const button = field.nextElementSibling;
    
    if (field.type === 'password') {
        field.type = 'text';
        if (button) button.textContent = '🙈';
    } else {
        field.type = 'password';
        if (button) button.textContent = '👁️';
    }
}

// Auto-hide success/error messages after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.querySelector('.success-message');
    const errorMessage = document.querySelector('.error-message');
    
    if (successMessage) {
        setTimeout(() => {
            successMessage.style.transition = 'opacity 0.5s ease';
            successMessage.style.opacity = '0';
            setTimeout(() => successMessage.remove(), 500);
        }, 5000);
    }
    
    if (errorMessage) {
        setTimeout(() => {
            errorMessage.style.transition = 'opacity 0.5s ease';
            errorMessage.style.opacity = '0';
            setTimeout(() => errorMessage.remove(), 500);
        }, 5000);
    }
});

// Initialize all functions when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initViewToggle();
    initSearch();
    initFilter();
    initDeleteConfirmation();
    initFormValidation();
});