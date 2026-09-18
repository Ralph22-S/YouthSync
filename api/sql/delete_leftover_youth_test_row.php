<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$before = $pdo->prepare(
    'SELECT id, organization_id, first_name, last_name, birth_date
     FROM youth
     WHERE id = :id
       AND organization_id = :org
       AND first_name = :fn
       AND last_name = :ln
       AND birth_date = :bd'
);
$before->execute([
    'id' => 5,
    'org' => 1,
    'fn' => 'PhaseTwo',
    'ln' => 'Updated',
    'bd' => '2004-06-15',
]);
$match = $before->fetch(PDO::FETCH_ASSOC);
echo $match ? "before: found id={$match['id']}\n" : "before: no matching row\n";

$delete = $pdo->prepare(
    'DELETE FROM youth
     WHERE id = :id
       AND organization_id = :org
       AND first_name = :fn
       AND last_name = :ln
       AND birth_date = :bd'
);
$delete->execute([
    'id' => 5,
    'org' => 1,
    'fn' => 'PhaseTwo',
    'ln' => 'Updated',
    'bd' => '2004-06-15',
]);
echo 'deleted_rows=' . $delete->rowCount() . PHP_EOL;

$after = $pdo->prepare('SELECT id FROM youth WHERE id = :id');
$after->execute(['id' => 5]);
echo $after->fetch() ? "after: id=5 still present\n" : "after: id=5 gone\n";

echo "remaining_youth=" . $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn() . PHP_EOL;
echo "users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
