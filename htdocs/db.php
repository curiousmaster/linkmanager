<?php
// db.php: Returns a PDO instance using config.yml file
function get_config() {
    static $config = null;
    if ($config === null) {
        $config_file = __DIR__ . '/../etc/config.yml';
        if (!function_exists('yaml_parse_file')) {
            die("Error: PHP yaml extension not installed.");
        }
        if (!file_exists($config_file)) {
            die("Error: config.yml not found.");
        }
        $config = yaml_parse_file($config_file);
    }
    return $config;
}

function get_pdo() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $config = get_config()['database'];
    $driver = $config['driver'] ?? 'sqlite';
    try {
        if ($driver === 'sqlite') {
            $file = $config['file'] ?? '';
            if (!$file) throw new Exception("No database file in config");
            $pdo = new PDO("sqlite:$file");
        } elseif ($driver === 'mysql') {
            $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['username'], $config['password']);
        } elseif ($driver === 'pgsql') {
            $dsn = "pgsql:host={$config['host']};dbname={$config['name']}";
            $pdo = new PDO($dsn, $config['username'], $config['password']);
        } else {
            throw new Exception("Unknown DB driver: $driver");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }
    return $pdo;
}

