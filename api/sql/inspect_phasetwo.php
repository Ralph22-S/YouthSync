<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$stmt = $pdo->prepare(
    'SELECT id, organization_id, first_name, last_name, birth_date
     FROM youth
     WHERE first_name = :first_name
     ORDER BY id'
);
$stmt->execute(['first_name' => 'PhaseTwo']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "matching_rows=" . count($rows) . PHP_EOL;
if ($rows === []) {
    echo "(none)\n";
    exit(0);
}

echo "id\torganization_id\tfirst_name\tlast_name\tbirth_date" . PHP_EOL;
foreach ($rows as $row) {
    echo $row['id']
        . "\t" . $row['organization_id']
        . "\t" . $row['first_name']
        . "\t" . $row['last_name']
        . "\t" . $row['birth_date']
        . PHP_EOL;
}
