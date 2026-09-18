<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$schema = file_get_contents(__DIR__ . '/schema_phase5.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_phase5.sql\n");
    exit(1);
}

foreach (['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE', 'DELETE FROM', 'DROP TABLE'] as $forbidden) {
    if (stripos($schema, $forbidden) !== false) {
        fwrite(STDERR, "Refusing to run SQL that contains {$forbidden}\n");
        exit(1);
    }
}

$schema = preg_replace('/^\s*--.*$/m', '', $schema) ?? $schema;
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$types = [
    [1, 'SK Educational Scholarship', 'scholarship', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Certificate of Enrolment', 'Current semester or school year.', true, 'image/*,application/pdf'],
        ['Latest Report Card or Grades', 'Most recent grading period.', true, 'image/*,application/pdf'],
        ['Proof of Family Income', 'Payslip, certificate of indigency, or BIR form.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ]],
    [2, 'College Grant', 'scholarship', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Certificate of Enrolment', 'Current semester, with units enrolled.', true, 'image/*,application/pdf'],
        ['Latest Grades', 'Most recent semester.', true, 'image/*,application/pdf'],
        ['Certificate of Indigency', 'Issued by the barangay or DSWD.', false, 'image/*,application/pdf'],
    ]],
    [3, 'Financial Assistance', 'financial', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
        ['Proof of Family Income', 'Payslip, certificate of indigency, or BIR form.', true, 'image/*,application/pdf'],
    ]],
    [4, 'Educational Assistance', 'other', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Certificate of Enrolment', 'Current semester or school year.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ]],
    [5, 'Emergency Assistance', 'other', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
        ['Incident or Police Report', 'Document describing the emergency.', true, 'image/*,application/pdf'],
    ]],
    [6, 'Livelihood Assistance', 'other', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
        ['Livelihood Plan', 'Short description of the livelihood you will start.', true, 'image/*,application/pdf'],
    ]],
    [7, 'Medical Assistance', 'other', [
        ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
        ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
        ['Medical Certificate', 'Signed by the attending physician.', true, 'image/*,application/pdf'],
        ['Prescription or Hospital Bill', 'Whichever applies to your request.', true, 'image/*,application/pdf'],
    ]],
    [8, 'Other', 'other', []],
];

$insert = $pdo->prepare(
    'INSERT IGNORE INTO assistance_types (id, organization_id, name, category, is_system, requirements_json)
     VALUES (:id, NULL, :name, :category, 1, :requirements_json)'
);
foreach ($types as [$id, $name, $category, $reqs]) {
    $insert->execute([
        'id' => $id,
        'name' => $name,
        'category' => $category,
        'requirements_json' => json_encode($reqs, JSON_UNESCAPED_UNICODE),
    ]);
}

echo "assistance tables ensured.\n";
echo "system_types=" . $pdo->query('SELECT COUNT(*) FROM assistance_types WHERE is_system = 1')->fetchColumn() . PHP_EOL;
echo "tables: " . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo "assistance_programs=" . $pdo->query('SELECT COUNT(*) FROM assistance_programs')->fetchColumn() . PHP_EOL;
echo "beneficiaries=" . $pdo->query('SELECT COUNT(*) FROM beneficiaries')->fetchColumn() . PHP_EOL;
echo "youth=" . $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn() . PHP_EOL;
echo "users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
