<?php
echo "<h2>PHP Configuration Check</h2>";
echo "<p><strong>Loaded php.ini:</strong> " . php_ini_loaded_file() . "</p>";
echo "<p><strong>PDO Drivers:</strong> " . implode(", ", PDO::getAvailableDrivers()) . "</p>";
echo "<p><strong>Extensions loaded:</strong></p><ul>";
foreach (get_loaded_extensions() as $ext) {
    if (stripos($ext, 'mysql') !== false || stripos($ext, 'pdo') !== false) {
        echo "<li>$ext</li>";
    }
}
echo "</ul>";
phpinfo(INFO_MODULES);
