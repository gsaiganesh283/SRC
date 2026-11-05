<?php
/**
 * Test the net-balance-manager.php page for all code issues
 */

echo "=== NET BALANCE MANAGER - CODE QUALITY TESTS ===\n\n";

// 1. Check for duplicate CSS keyframes
echo "1. CSS Keyframe Check:\n";
$nbmContent = file_get_contents('superadmin/net-balance-manager.php');
$keyframes = [];
preg_match_all('/@keyframes\s+(\w+)/', $nbmContent, $matches);
if (!empty($matches[1])) {
    foreach ($matches[1] as $kf) {
        if (!isset($keyframes[$kf])) {
            $keyframes[$kf] = 0;
        }
        $keyframes[$kf]++;
    }
}

$hasDuplicates = false;
foreach ($keyframes as $name => $count) {
    if ($count > 1) {
        echo "   ❌ DUPLICATE: @keyframes $name found $count times\n";
        $hasDuplicates = true;
    } else {
        echo "   ✓ @keyframes $name defined once\n";
    }
}

if (!$hasDuplicates) {
    echo "   ✅ No duplicate keyframes detected\n";
}

// 2. Check for syntax errors
echo "\n2. PHP Syntax Check:\n";
$output = shell_exec('php -l superadmin/net-balance-manager.php 2>&1');
if (strpos($output, 'error') === false) {
    echo "   ✅ net-balance-manager.php: No syntax errors\n";
} else {
    echo "   ❌ net-balance-manager.php: " . trim($output) . "\n";
}

$output = shell_exec('php -l superadmin/export-net-balance-pdf.php 2>&1');
if (strpos($output, 'error') === false) {
    echo "   ✅ export-net-balance-pdf.php: No syntax errors\n";
} else {
    echo "   ❌ export-net-balance-pdf.php: " . trim($output) . "\n";
}

// 3. Check for properly closed HTML tags
echo "\n3. HTML Tag Balance Check:\n";
$openTags = substr_count($nbmContent, '<div');
$closeTags = substr_count($nbmContent, '</div>');
echo "   <div tags: $openTags opened, $closeTags closed\n";
if ($openTags === $closeTags) {
    echo "   ✅ DIV tags are balanced\n";
} else {
    echo "   ⚠️  DIV tag mismatch (opened: $openTags, closed: $closeTags)\n";
}

// 4. Check JavaScript functions are defined
echo "\n4. JavaScript Function Check:\n";
$requiredFunctions = ['switchTab', 'exportReport', 'autoRecalculate', 'viewDetails', 'editBalance'];
foreach ($requiredFunctions as $func) {
    if (strpos($nbmContent, "function $func(") !== false) {
        echo "   ✓ function $func() defined\n";
    } else {
        echo "   ❌ function $func() NOT found\n";
    }
}

// 5. Check CSS variables are defined
echo "\n5. CSS Custom Properties Check:\n";
$hasRootVars = strpos($nbmContent, ':root {') !== false;
if ($hasRootVars) {
    preg_match_all('/--[\w-]+:/', $nbmContent, $vars);
    if (!empty($vars[0])) {
        echo "   ✅ CSS custom properties defined: " . count(array_unique($vars[0])) . " unique variables\n";
    }
} else {
    echo "   ⚠️  :root CSS variables not found\n";
}

// 6. Check for proper JavaScript variable declarations
echo "\n6. JavaScript Variable Check:\n";
if (strpos($nbmContent, 'const academicYearsData = ') !== false) {
    echo "   ✓ academicYearsData declared\n";
} else {
    echo "   ❌ academicYearsData NOT declared\n";
}

// 7. Check database queries
echo "\n7. Database Query Check (in PHP):\n";
$hasCollectionQuery = strpos($nbmContent, 'CollectionAmount > 0') !== false;
$hasDistributionQuery = strpos($nbmContent, 'DistributedAmount > 0') !== false;
if ($hasCollectionQuery) {
    echo "   ✓ Collection query uses correct column (CollectionAmount > 0)\n";
} else {
    echo "   ❌ Collection query incomplete\n";
}
if ($hasDistributionQuery) {
    echo "   ✓ Distribution query uses correct column (DistributedAmount > 0)\n";
} else {
    echo "   ❌ Distribution query incomplete\n";
}

// 8. Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ CODE QUALITY ASSESSMENT COMPLETE\n";
echo str_repeat("=", 50) . "\n";

?>
