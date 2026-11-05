/**
 * Admin Theme Manager
 * Centralized theme handling for all admin pages
 */

(function() {
    'use strict';
    
    // Theme configuration - Use global key for all portals
    const THEME_KEY = 'app_theme'; // Global key shared across all portals
    const THEME_ATTR = 'data-theme';
    
    /**
     * Get current theme from localStorage
     */
    function getCurrentTheme() {
        return localStorage.getItem(THEME_KEY) || 'light';
    }
    
    /**
     * Set theme and save to localStorage
     */
    function setTheme(theme) {
        document.documentElement.setAttribute(THEME_ATTR, theme);
        localStorage.setItem(THEME_KEY, theme);
        updateThemeIcon(theme);
        
        // Dispatch custom event for charts and other components
        window.dispatchEvent(new CustomEvent('themeChanged', { 
            detail: { theme: theme } 
        }));
    }
    
    /**
     * Toggle between light and dark theme
     */
    function toggleTheme() {
        const currentTheme = getCurrentTheme();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
        return newTheme;
    }
    
    /**
     * Update theme toggle icon
     */
    function updateThemeIcon(theme) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        
        const text = document.getElementById('themeText');
        if (text) {
            text.textContent = theme === 'dark' ? 'Light' : 'Dark';
        }
    }
    
    /**
     * Initialize theme on page load
     */
    function initTheme() {
        const savedTheme = getCurrentTheme();
        setTheme(savedTheme);
        
        // Set up theme toggle button
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const newTheme = toggleTheme();
                
                // Show notification if function exists
                if (typeof showToast === 'function') {
                    showToast(
                        'Theme Changed',
                        `Switched to ${newTheme} mode`,
                        'info'
                    );
                }
            });
        }
    }
    
    /**
     * Get chart colors based on current theme
     */
    function getChartColors() {
        const isDark = getCurrentTheme() === 'dark';
        return {
            text: isDark ? '#eaeaea' : '#2c3e50',
            grid: isDark ? '#2d3748' : '#e0e0e0',
            background: isDark ? 'rgba(126, 34, 206, 0.2)' : 'rgba(126, 34, 206, 0.1)',
            cardBg: isDark ? '#16213e' : '#ffffff',
            bodyBg: isDark ? '#1a1a2e' : '#f5f6fa'
        };
    }
    
    /**
     * Apply theme to Chart.js instances
     */
    function updateChartTheme(chart) {
        if (!chart) return;
        
        const colors = getChartColors();
        
        if (chart.options.plugins && chart.options.plugins.legend) {
            chart.options.plugins.legend.labels.color = colors.text;
        }
        
        if (chart.options.scales) {
            if (chart.options.scales.x) {
                chart.options.scales.x.ticks.color = colors.text;
                chart.options.scales.x.grid.color = colors.grid;
            }
            if (chart.options.scales.y) {
                chart.options.scales.y.ticks.color = colors.text;
                chart.options.scales.y.grid.color = colors.grid;
            }
        }
        
        chart.update();
    }
    
    // Initialize theme when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme);
    } else {
        initTheme();
    }
    
    // Export functions to window for global access
    window.ThemeManager = {
        getCurrentTheme: getCurrentTheme,
        setTheme: setTheme,
        toggleTheme: toggleTheme,
        getChartColors: getChartColors,
        updateChartTheme: updateChartTheme
    };
    
})();
