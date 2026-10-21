<?php
// READ-ONLY on the live databases: copies every SQLite database on /data into a new backup
// folder (VACUUM INTO: a consistent snapshot including the WAL), then integrity-checks each copy
// and counts its readings and transactions. Run by prod-backup.sh (plain php, not tinker).
$label = getenv('BACKUP_LABEL') ?: 'manual';
$dir = '/data/backups-'.$label.'-'.gmdate('Ymd-His');
mkdir($dir, 0700, true);
$out = ['dir' => $dir];

foreach (glob('/data/*.sqlite') as $file) {
    $copy = $dir.'/'.basename($file);
    $source = new PDO('sqlite:'.$file);
    $source->exec('VACUUM INTO '.$source->quote($copy));
    $check = new PDO('sqlite:'.$copy);
    $isStation = (bool) $check->query("select count(*) from sqlite_master where type='table' and name='transactions'")->fetchColumn();
    $out['files'][basename($file)] = [
        'size' => filesize($copy),
        'integrity' => $check->query('PRAGMA integrity_check')->fetchColumn(),
        'readings' => $isStation ? (int) $check->query('select count(*) from pump_counter_readings')->fetchColumn() : null,
        'transactions' => $isStation ? (int) $check->query('select count(*) from transactions')->fetchColumn() : null,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT), "\n";
