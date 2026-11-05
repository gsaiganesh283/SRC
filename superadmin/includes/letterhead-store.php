<?php
/**
 * Editable letterheads, shared by every report on the letterhead.
 *
 * Letterheads are stored in report_letterheads (created on first use). When none is saved,
 * a default built from the system settings is used, so reports look the same as before.
 *
 * Requires: config.php ($pdo, UPLOADS_PATH, UPLOADS_URL), includes/logo-helper.php
 */

const RB_LETTERHEAD_DIR = 'letterheads'; // under assets/uploads

if (!function_exists('resolveLetterheadLogo')) {
    /**
     * The system's uploaded logo (stored as a URL path) as a local image file
     */
    function resolveLetterheadLogo() {
        foreach (['getWebsiteLogo', 'getSystemLogo'] as $fn) {
            $logo = function_exists($fn) ? $fn() : '';
            if (empty($logo)) {
                continue;
            }
            $path = UPLOADS_PATH . '/logos/' . basename(parse_url($logo, PHP_URL_PATH) ?? '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true) && is_file($path) && @getimagesize($path)) {
                return $path;
            }
        }
        return null;
    }
}

function rbEnsureLetterheadTable(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_letterheads (
            LetterheadID INT AUTO_INCREMENT PRIMARY KEY,
            Name VARCHAR(100) NOT NULL,
            Config TEXT NOT NULL,
            IsDefault TINYINT(1) NOT NULL DEFAULT 0,
            UpdatedBy INT DEFAULT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** The letterhead used until one is saved: the organisation details from system settings */
function rbLetterheadDefaults() {
    return [
        'name' => function_exists('getSiteName') ? getSiteName('SR Colony Chitti') : 'SR Colony Chitti',
        'tagline' => 'Transparent  |  Organized  |  Efficient  |  Secure',
        'address' => function_exists('getSystemSetting') ? getSystemSetting('site_address', 'SR Colony, Yerraguntlepalli, Pileru, Andhra Pradesh') : '',
        'extra' => '',
        'phone' => function_exists('getSystemSetting') ? (string)getSystemSetting('site_phone', '') : '',
        'email' => function_exists('getSystemSetting') ? (string)getSystemSetting('site_email', '') : '',
        'website' => '',
        'logo' => 'system',          // system | none | custom:<file>
        'logo_position' => 'left',   // left | right | top
        'logo_height' => 22,         // mm
        'align' => 'auto',           // auto | left | center | right
        'name_size' => 19,
        'name_upper' => true,
        'primary' => '#312E81',
        'accent' => '#C49A2C',
        'bands' => true,
        'double_rule' => true,
        'title_style' => 'band',     // band | plain
    ];
}

/** Clean a letterhead config: known keys only, safe values */
function rbNormalizeLetterhead($raw) {
    $d = rbLetterheadDefaults();
    $raw = is_array($raw) ? $raw : [];
    $str = function ($k, $max) use ($raw, $d) {
        return array_key_exists($k, $raw) && is_scalar($raw[$k]) ? mb_substr(trim((string)$raw[$k]), 0, $max) : $d[$k];
    };
    $color = function ($k) use ($raw, $d) {
        $v = is_string($raw[$k] ?? null) ? trim($raw[$k]) : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $d[$k];
    };
    $enum = function ($k, array $allowed) use ($raw, $d) {
        return in_array($raw[$k] ?? null, $allowed, true) ? $raw[$k] : $d[$k];
    };
    $logo = is_string($raw['logo'] ?? null) ? $raw['logo'] : $d['logo'];
    if (!in_array($logo, ['system', 'none'], true) && !preg_match('/^custom:lh_[a-f0-9]{16}\.(png|jpe?g|gif)$/', $logo)) {
        $logo = $d['logo'];
    }
    return [
        'name' => $str('name', 120),
        'tagline' => $str('tagline', 160),
        'address' => $str('address', 200),
        'extra' => $str('extra', 160),
        'phone' => $str('phone', 60),
        'email' => $str('email', 100),
        'website' => $str('website', 100),
        'logo' => $logo,
        'logo_position' => $enum('logo_position', ['left', 'right', 'top']),
        'logo_height' => max(10, min(35, (float)($raw['logo_height'] ?? $d['logo_height']))),
        'align' => $enum('align', ['auto', 'left', 'center', 'right']),
        'name_size' => max(12, min(28, (float)($raw['name_size'] ?? $d['name_size']))),
        'name_upper' => array_key_exists('name_upper', $raw) ? !empty($raw['name_upper']) : $d['name_upper'],
        'primary' => $color('primary'),
        'accent' => $color('accent'),
        'bands' => array_key_exists('bands', $raw) ? !empty($raw['bands']) : $d['bands'],
        'double_rule' => array_key_exists('double_rule', $raw) ? !empty($raw['double_rule']) : $d['double_rule'],
        'title_style' => $enum('title_style', ['band', 'plain']),
    ];
}

/** The contact line printed under the address */
function rbLetterheadContact(array $lh) {
    $parts = [];
    if ($lh['phone'] !== '') $parts[] = 'Phone: ' . $lh['phone'];
    if ($lh['email'] !== '') $parts[] = 'Email: ' . $lh['email'];
    if ($lh['website'] !== '') $parts[] = $lh['website'];
    return implode('   |   ', $parts);
}

/** All saved letterheads: [['id', 'name', 'is_default', 'config'], ...] (never empty) */
function rbListLetterheads(PDO $pdo) {
    try {
        rbEnsureLetterheadTable($pdo);
        $rows = $pdo->query('SELECT LetterheadID, Name, Config, IsDefault FROM report_letterheads ORDER BY IsDefault DESC, Name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Letterhead list failed: ' . $e->getMessage());
        $rows = [];
    }
    $list = array_map(function ($r) {
        return ['id' => (int)$r['LetterheadID'], 'name' => $r['Name'], 'is_default' => (bool)$r['IsDefault'], 'config' => rbNormalizeLetterhead(json_decode($r['Config'], true))];
    }, $rows);
    if (!$list) {
        $list[] = ['id' => 0, 'name' => 'Standard letterhead', 'is_default' => true, 'config' => rbLetterheadDefaults()];
    }
    return $list;
}

/** One letterhead by id; 0 (or an unknown id) gives the default */
function rbGetLetterhead(PDO $pdo, $id = 0) {
    $list = rbListLetterheads($pdo);
    foreach ($list as $lh) {
        if ($id > 0 && $lh['id'] === (int)$id) return $lh['config'];
    }
    foreach ($list as $lh) {
        if ($lh['is_default']) return $lh['config'];
    }
    return $list[0]['config'];
}

/** Local file of the letterhead's logo (or null) */
function rbLetterheadLogoPath(array $lh) {
    if ($lh['logo'] === 'none') return null;
    if (strpos($lh['logo'], 'custom:') === 0) {
        $path = UPLOADS_PATH . '/' . RB_LETTERHEAD_DIR . '/' . basename(substr($lh['logo'], 7));
        return is_file($path) ? $path : null;
    }
    return function_exists('resolveLetterheadLogo') ? resolveLetterheadLogo() : null;
}

/** Web address of the letterhead's logo, for previews (or '') */
function rbLetterheadLogoUrl(array $lh) {
    $path = rbLetterheadLogoPath($lh);
    if (!$path) return '';
    $dir = basename(dirname($path));
    return UPLOADS_URL . '/' . rawurlencode($dir) . '/' . rawurlencode(basename($path));
}

/** "#312E81" -> [49, 46, 129] */
function rbHexRgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** A colour mixed with white (for soft backgrounds) */
function rbTint(array $rgb, $amount) {
    return array_map(function ($c) use ($amount) { return (int)round($c + (255 - $c) * $amount); }, $rgb);
}
