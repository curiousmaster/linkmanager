#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: must be run from CLI\n");
    exit(1);
}

// --- Parse -c option ---
$options = getopt('c:');
if (!isset($options['c'])) {
    fwrite(STDERR, "Usage: php setup.php -c <config.yml>\n");
    exit(1);
}
$configPath = $options['c'];
if (!is_file($configPath)) {
    fwrite(STDERR, "Error: Config file not found at $configPath\n");
    exit(1);
}
if (!function_exists('yaml_parse_file')) {
    fwrite(STDERR, "Error: PHP YAML extension required\n");
    exit(1);
}
$config = yaml_parse_file($configPath);
$dbCfg   = $config['database'] ?? null;
if (!is_array($dbCfg) || empty($dbCfg['driver'])) {
    fwrite(STDERR, "Error: config.yml missing database.driver\n");
    exit(1);
}

$driver = strtolower($dbCfg['driver']);
$pdoOpts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

switch ($driver) {
    case 'sqlite':
        if (empty($dbCfg['file'])) {
            fwrite(STDERR, "Error: database.file required for sqlite\n");
            exit(1);
        }
        $dsn = 'sqlite:' . $dbCfg['file'];
        $dir = dirname($dbCfg['file']);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            fwrite(STDERR, "Error: cannot create directory $dir\n");
            exit(1);
        }
        break;

    case 'mysql':
        foreach (['host','name','username','password'] as $k) {
            if (empty($dbCfg[$k])) {
                fwrite(STDERR, "Error: database.$k required for mysql\n");
                exit(1);
            }
        }
        $dsn  = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $dbCfg['host'], $dbCfg['name']
        );
        $user = $dbCfg['username'];
        $pass = $dbCfg['password'];
        break;

    default:
        fwrite(STDERR, "Error: unsupported driver '{$dbCfg['driver']}'\n");
        exit(1);
}

try {
    if ($driver === 'mysql') {
        $pdo = new PDO($dsn, $user, $pass, $pdoOpts);
        $pdo->exec("SET SESSION sql_mode = 'TRADITIONAL'");
    } else {
        $pdo = new PDO($dsn, null, null, $pdoOpts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
} catch (Exception $e) {
    fwrite(STDERR, "DB Connection Error: {$e->getMessage()}\n");
    exit(1);
}

// Prompt for admin password
echo "Enter password for default 'admin' user: ";
system('stty -echo');
$adminPass = trim(fgets(STDIN));
system('stty echo');
echo "\n";
if ($adminPass === '') {
    fwrite(STDERR, "Error: password cannot be empty\n");
    exit(1);
}
$adminHash = password_hash($adminPass, PASSWORD_DEFAULT);

// Create schema
$ddl = <<<'SQL'
CREATE TABLE IF NOT EXISTS page (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL UNIQUE,
  sort_order INT NOT NULL
);
CREATE TABLE IF NOT EXISTS section (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  page_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  sort_order INT NOT NULL,
  FOREIGN KEY(page_id) REFERENCES page(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS link (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  section_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  url TEXT,
  logo TEXT,
  background VARCHAR(7),
  color VARCHAR(7),
  sort_order INT NOT NULL,
  FOREIGN KEY(section_id) REFERENCES section(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS user (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL
);
CREATE TABLE IF NOT EXISTS role (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS user_role (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  PRIMARY KEY(user_id, role_id),
  FOREIGN KEY(user_id) REFERENCES user(id) ON DELETE CASCADE,
  FOREIGN KEY(role_id) REFERENCES role(id) ON DELETE CASCADE
);
-- in MySQL or SQLite migration

CREATE TABLE `group` (
  id   INTEGER PRIMARY KEY AUTO_INCREMENT,   -- SQLite: INTEGER PRIMARY KEY
  name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE user_group (
  user_id  INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  PRIMARY KEY(user_id, group_id),
  FOREIGN KEY(user_id) REFERENCES user(id)    ON DELETE CASCADE,
  FOREIGN KEY(group_id) REFERENCES `group`(id) ON DELETE CASCADE
);

CREATE TABLE page_group (
  page_id  INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  PRIMARY KEY(page_id, group_id),
  FOREIGN KEY(page_id)  REFERENCES page(id)   ON DELETE CASCADE,
  FOREIGN KEY(group_id) REFERENCES `group`(id) ON DELETE CASCADE
);

SQL;

$pdo->exec($ddl);

// Seed roles
if ($driver === 'mysql') {
    $pdo->exec("INSERT IGNORE INTO role (name) VALUES ('admin'), ('user')");
} else {
    $pdo->exec("INSERT OR IGNORE INTO role (name) VALUES ('admin'), ('user')");
}

// Seed admin user
if ($driver === 'mysql') {
    $stmt = $pdo->prepare(
        "INSERT INTO user (username,password) VALUES ('admin',?)
         ON DUPLICATE KEY UPDATE password=VALUES(password)"
    );
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO user (username,password) VALUES ('admin',?)
         ON CONFLICT(username) DO UPDATE SET password=excluded.password"
    );
}
$stmt->execute([$adminHash]);

// Assign admin role
$userId = (int)$pdo->query("SELECT id FROM user WHERE username='admin'")->fetchColumn();
$roleId = (int)$pdo->query("SELECT id FROM role WHERE name='admin'")->fetchColumn();
if ($driver === 'mysql') {
    $pdo->exec("INSERT IGNORE INTO user_role (user_id,role_id) VALUES ($userId,$roleId)");
} else {
    $pdo->exec("INSERT OR IGNORE INTO user_role (user_id,role_id) VALUES ($userId,$roleId)");
}

// Seed Main page
if ($driver === 'mysql') {
    $pdo->exec("INSERT IGNORE INTO page (title,sort_order) VALUES ('Main',0)");
} else {
    $pdo->exec(
        "INSERT INTO page (title,sort_order)
         VALUES ('Main',0)
         ON CONFLICT(title) DO NOTHING"
    );
}

echo "✅ Setup complete!\n";
echo "   Driver: $driver\n";
echo "   Admin user: admin\n";
echo "   Main page created (if missing).\n";
exit(0);

