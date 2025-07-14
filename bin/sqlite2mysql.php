#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: must be run from CLI\n");
    exit(1);
}

// --- CLI args ---
$options = getopt('s:c:');
if (empty($options['s']) || empty($options['c'])) {
    fwrite(STDERR, "Usage: php migrate.php -s <sqlite.db> -c <config.yml>\n");
    exit(1);
}
$sqliteFile = $options['s'];
$configFile = $options['c'];

if (!is_file($sqliteFile) || !is_file($configFile)) {
    fwrite(STDERR, "Error: missing files\n");
    exit(1);
}
if (!function_exists('yaml_parse_file')) {
    fwrite(STDERR, "Error: PHP YAML extension required\n");
    exit(1);
}

// --- Load config ---
$config = yaml_parse_file($configFile);
$dbCfg  = $config['database'] ?? null;
if (!is_array($dbCfg) || strtolower($dbCfg['driver'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Error: config.yml must specify MySQL driver\n");
    exit(1);
}
foreach (['host','name','username','password'] as $k) {
    if (empty($dbCfg[$k])) {
        fwrite(STDERR, "Error: database.$k missing\n");
        exit(1);
    }
}
$dsn   = "mysql:host={$dbCfg['host']};dbname={$dbCfg['name']};charset=utf8mb4";
$user  = $dbCfg['username'];
$pass  = $dbCfg['password'];

try {
    $mysql = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // disable FK checks
    $mysql->exec('SET FOREIGN_KEY_CHECKS=0');
} catch (Exception $e) {
    fwrite(STDERR, "MySQL connect error: {$e->getMessage()}\n");
    exit(1);
}

try {
    $sqlite = new PDO("sqlite:$sqliteFile", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    fwrite(STDERR, "SQLite connect error: {$e->getMessage()}\n");
    exit(1);
}

$tables = ['page','section','link','user','role','user_role'];

foreach ($tables as $table) {
    // 1) Fetch MySQL column list
    $colsInMy = array_column(
        $mysql->query("DESCRIBE `$table`")->fetchAll(),
        'Field'
    );

    // 2) Fetch all rows from SQLite
    $rows = $sqlite->query("SELECT * FROM `$table`")->fetchAll();
    if (!$rows) {
        echo "[$table] nothing to migrate\n";
        continue;
    }

    // 3) Build dynamic INSERT
    // intersect row keys with MySQL columns
    $sample = $rows[0];
    $insertCols = array_values(array_intersect(array_keys($sample), $colsInMy));
    if (empty($insertCols)) {
        echo "[$table] no matching columns, skipping\n";
        continue;
    }
    $colList = '`'. implode('`,`', $insertCols) . '`';
    $placeH  = rtrim(str_repeat('?,', count($insertCols)), ',');
    $sql     = "INSERT INTO `$table` ($colList) VALUES ($placeH)";
    $stmt    = $mysql->prepare($sql);

    $count = 0;
    foreach ($rows as $row) {
        $vals = [];
        foreach ($insertCols as $c) {
            $vals[] = $row[$c];
        }
        try {
            $stmt->execute($vals);
            $count++;
        } catch (Exception $e) {
            // skip duplicates or other errors
        }
    }
    echo "[$table] inserted $count rows of ".count($rows)."\n";
}

// re-enable FK
$mysql->exec('SET FOREIGN_KEY_CHECKS=1');
echo "✅ Migration complete\n";

