<?php
declare(strict_types=1);

$db = __DIR__ . '/private/portal.sqlite';
$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO SQLite extension' => extension_loaded('pdo_sqlite'),
    'Multibyte text extension' => extension_loaded('mbstring'),
    'Database file is present' => is_file($db),
    'Private folder is writable' => is_writable(dirname($db)),
    'Database file is writable' => is_writable($db),
];

if ($checks['PDO SQLite extension'] && $checks['Database file is present']) {
    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $checks['SQLite file opens'] = (int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() >= 0;
    } catch (Throwable $e) {
        $checks['SQLite file opens'] = false;
    }
}

$ready = !in_array(false, $checks, true);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hosting check · Class Groups</title><link rel="stylesheet" href="style.css"></head>
<body><header class="site-header"><div class="wrap header-inner"><a class="brand" href="index.php"><span class="brand-mark">CG</span><span>Class Groups</span></a></div></header>
<main class="wrap"><div class="narrow"><div class="page-intro"><div class="eyebrow">DEPLOYMENT CHECK</div><h1><?= $ready ? 'Your hosting is ready' : 'Hosting needs attention' ?></h1><p>This page checks whether your PHP hosting can run the file-based SQLite portal.</p></div><div class="card"><ul class="question-list">
<?php foreach ($checks as $label => $passed): ?><li><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong style="color:<?= $passed ? '#245b48' : '#a43b37' ?>"><?= $passed ? 'Pass' : 'Needs attention' ?></strong></li><?php endforeach; ?>
</ul></div><p class="under-card">Also check that <code>/private/portal.sqlite</code> returns 403 Forbidden in your browser. Delete this check page after deployment.</p></div></main></body></html>
