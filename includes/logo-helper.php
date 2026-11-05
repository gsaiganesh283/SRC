<?php
/**
 * Logo Helper Functions
 * Provides functions to get logo URLs and render logo HTML across all portals
 */

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/config.php';
}

// Include global settings if not already loaded
if (!function_exists('getSystemSetting')) {
    require_once __DIR__ . '/global-settings.php';
}

/**
 * Get the website logo URL (for login pages, public pages)
 * @param string $default Default logo path if none set
 * @return string Logo URL
 */
function getWebsiteLogo($default = '') {
    $logo = getSystemSetting('website_logo', '');
    if (!empty($logo)) {
        return $logo;
    }
    return $default;
}

/**
 * Get the system logo URL (for navbar, sidebar, dashboard)
 * @param string $default Default logo path if none set
 * @return string Logo URL
 */
function getSystemLogo($default = '') {
    $logo = getSystemSetting('system_logo', '');
    if (!empty($logo)) {
        return $logo;
    }
    return $default;
}

/**
 * Get the favicon URL
 * @param string $default Default favicon path if none set
 * @return string Favicon URL
 */
function getFavicon($default = '') {
    $favicon = getSystemSetting('favicon', '');
    if (!empty($favicon)) {
        return $favicon;
    }
    return $default;
}

/**
 * Get the site name
 * @param string $default Default site name if none set
 * @return string Site name
 */
function getSiteName($default = 'SR Colony Chitti') {
    return getSystemSetting('site_name', $default);
}

/**
 * Render website logo HTML (for login pages)
 * @param string $class CSS classes for the image
 * @param string $style Inline styles for the image
 * @param int $height Height of the logo in pixels
 * @return string HTML for the logo
 */
function renderWebsiteLogo($class = '', $style = '', $height = 80) {
    $logo = getWebsiteLogo();
    $siteName = getSiteName();
    
    if (!empty($logo)) {
        return '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars($siteName) . '" class="' . $class . '" style="height: ' . $height . 'px; width: auto; object-fit: contain; ' . $style . '">';
    }
    
    // Return default icon if no logo
    return '<i class="fas fa-users" style="font-size: ' . ($height * 0.8) . 'px;"></i>';
}

/**
 * Render system logo HTML (for navbar/sidebar)
 * @param string $class CSS classes for the image
 * @param string $style Inline styles for the image
 * @param int $height Height of the logo in pixels
 * @return string HTML for the logo
 */
function renderSystemLogo($class = '', $style = '', $height = 40) {
    $logo = getSystemLogo();
    $siteName = getSiteName();
    
    if (!empty($logo)) {
        return '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars($siteName) . '" class="system-logo ' . $class . '" style="height: ' . $height . 'px; width: auto; object-fit: contain; ' . $style . '">';
    }
    
    // Return empty - let the caller use their default icon
    return '';
}

/**
 * Render favicon link tag
 * @return string HTML link tag for favicon
 */
function renderFavicon() {
    $favicon = getFavicon();
    
    if (!empty($favicon)) {
        $ext = strtolower(pathinfo($favicon, PATHINFO_EXTENSION));
        $type = 'image/x-icon';
        if ($ext === 'png') $type = 'image/png';
        elseif ($ext === 'svg') $type = 'image/svg+xml';
        elseif ($ext === 'gif') $type = 'image/gif';
        
        return '<link rel="icon" type="' . $type . '" href="' . htmlspecialchars($favicon) . '">';
    }
    
    return '';
}

/**
 * Check if a logo is set
 * @param string $type 'website', 'system', or 'favicon'
 * @return bool
 */
function hasLogo($type = 'website') {
    switch ($type) {
        case 'website':
            return !empty(getWebsiteLogo());
        case 'system':
            return !empty(getSystemLogo());
        case 'favicon':
            return !empty(getFavicon());
        default:
            return false;
    }
}
