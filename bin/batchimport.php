#!/usr/bin/env php
<?php
// import_links.php
// Usage: php import_links.php --config=path/to/config.yml --csv=path/to/links.csv

$options = getopt('c:i:', ['config:', 'csv:']);
if (
    empty($options['c']) && empty($options['config'])
    || empty($options['i']) && empty($options['csv'])
) {
    fwrite(STDERR, "Usage: php import_links.php --config=<config.yml> --csv=<links.csv>\n");
    exit(1);
}

$configFile = $options['c'] ?? $options['config'];
$csvFile    = $options['i'] ?? $options['csv'];

if (!is_readable($configFile)) {
    fwrite(STDERR, "Error: cannot read config file '{$configFile}'.\n");
    exit(2);
}
if (!is_readable($csvFile)) {
    fwrite(STDERR, "Error: cannot read CSV file '{$csvFile}'.\n");
    exit(3);
}

// parse YAML config
if (!function_exists('yaml_parse_file')) {
    fwrite(STDERR, "Error: PHP yaml extension is not installed.\n");
    exit(4);
}
$config = yaml_parse_file($configFile);
if (empty($config['database'])) {
    fwrite(STDERR, "Error: 'database' section missing in config.\n");
    exit(5);
}
$db = $config['database'];

// build DSN
$driver = $db['driver'] ?? 'sqlite';
try {
    if ($driver === 'sqlite') {
        if (empty($db['file'])) {
            throw new Exception("No 'file' configured for sqlite");
        }
        $pdo = new PDO("sqlite:{$db['file']}");
    } elseif ($driver === 'mysql') {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            $db['host'], $db['name']
        );
        $pdo = new PDO($dsn, $db['username'], $db['password']);
    } elseif ($driver === 'pgsql') {
        $dsn = sprintf("pgsql:host=%s;dbname=%s", $db['host'], $db['name']);
        $pdo = new PDO($dsn, $db['username'], $db['password']);
    } else {
        throw new Exception("Unsupported DB driver '{$driver}'");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(6);
}

// begin import
$pdo->beginTransaction();
try {
    $file = new SplFileObject($csvFile);
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
    $header = $file->fgetcsv();
    $map = array_flip($header);

    foreach (['page','section','name','description','url','logo','background','color'] as $col) {
        if (!isset($map[$col])) {
            throw new RuntimeException("Missing required column '{$col}' in CSV");
        }
    }

    while (!$file->eof()) {
        $row = $file->fgetcsv();
        if (!$row || empty(array_filter((array)$row, 'strlen'))) {
            continue;
        }
        if (count($row) < count($header)) {
            throw new RuntimeException("CSV row has too few columns");
        }
        // extract
        $page        = trim($row[$map['page']]);
        $section     = trim($row[$map['section']]);
        $name        = trim($row[$map['name']]);
        $description = trim($row[$map['description']]);
        $url         = trim($row[$map['url']]);
        $logo        = trim($row[$map['logo']]);
        $bg          = trim($row[$map['background']]);
        $color       = trim($row[$map['color']]);

        if ($page === '' || $section === '' || $name === '' || $url === '') {
            throw new RuntimeException("page/section/name/url must be non-empty");
        }

        // 1) page
        $stmt = $pdo->prepare("SELECT id FROM page WHERE title = ?");
        $stmt->execute([$page]);
        $pageId = $stmt->fetchColumn();
        if (!$pageId) {
            $pdo->prepare(
                "INSERT INTO page (title, sort_order)
                 VALUES (?, COALESCE((SELECT MAX(sort_order)+1 FROM page),0))"
            )->execute([$page]);
            $pageId = $pdo->lastInsertId();
            echo "Created page '{$page}' (ID {$pageId})\n";
        }

        // 2) section
        $stmt = $pdo->prepare("SELECT id FROM section WHERE page_id = ? AND name = ?");
        $stmt->execute([$pageId, $section]);
        $secId = $stmt->fetchColumn();
        if (!$secId) {
            $pdo->prepare(
                "INSERT INTO section (page_id, name, description, sort_order)
                 VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM section WHERE page_id=?),0))"
            )->execute([$pageId, $section, $description, $pageId]);
            $secId = $pdo->lastInsertId();
            echo "  Created section '{$section}' (ID {$secId})\n";
        }

        // 3) link
        $pdo->prepare(
            "INSERT INTO link
             (section_id,name,description,url,logo,background,color,sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM link WHERE section_id=?),0))"
        )->execute([
            $secId, $name, $description, $url, $logo, $bg, $color, $secId
        ]);
        $linkId = $pdo->lastInsertId();
        echo "    Inserted link '{$name}' (ID {$linkId})\n";
    }

    $pdo->commit();
    echo "Import complete!\n";
    exit(0);

} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(7);
}

