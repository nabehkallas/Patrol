<?php
// Adds (and removes) translation keys in all 11 lang/*.json files at once.
//
//   php scripts/dev/add-keys.php keys.php            # refuses to change any key that exists
//   php scripts/dev/add-keys.php keys.php --replace  # only to deliberately re-translate one
//
// keys.php returns ['add' => [key => [en, ar, fr, es, de, it, tr, ru, ur, hi, ku]],
// 'remove' => [key, ...]]. Nothing is written unless every check passes: each key has all 11
// values, an existing key keeps its translations (without --replace), and a removed key is no
// longer used in app/ or resources/js/. English values equal to the key are left out of en.json
// (an English key falls back to itself).
$langs = ['en', 'ar', 'fr', 'es', 'de', 'it', 'tr', 'ru', 'ur', 'hi', 'ku'];
$root = dirname(__DIR__, 2);

if ($argc < 2) {
    fwrite(STDERR, "usage: php scripts/dev/add-keys.php keys.php [--replace]\n");
    exit(2);
}
$spec = require $argv[1];
$replace = in_array('--replace', $argv, true);
$add = $spec['add'] ?? [];
$remove = $spec['remove'] ?? [];
$problems = [];

$files = [];
foreach ($langs as $lang) {
    $files[$lang] = json_decode((string) file_get_contents("$root/lang/$lang.json"), true, 512, JSON_THROW_ON_ERROR);
}

foreach ($add as $key => $values) {
    if (count($values) !== count($langs)) {
        $problems[] = "\"$key\": ".count($values).' values, needs '.count($langs);

        continue;
    }
    foreach ($langs as $i => $lang) {
        $current = $files[$lang][$key] ?? null;
        if ($current !== null && $current !== $values[$i] && ! $replace) {
            $problems[] = "\"$key\" already exists in $lang.json as \"$current\" (pass --replace to change it, or pick another key)";
        }
    }
}

foreach ($remove as $key) {
    // git directly, without a shell (on Windows shell_exec goes through cmd.exe).
    $process = proc_open(['git', '-C', $root, 'grep', '-l', '-F', "'".$key."'", '--', 'app', 'resources/js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $used = trim((string) stream_get_contents($pipes[1]));
    proc_close($process);
    if ($used !== '') {
        $problems[] = "\"$key\" is still used in: ".str_replace("\n", ', ', $used);
    }
}

if ($problems !== []) {
    fwrite(STDERR, "Nothing written:\n  - ".implode("\n  - ", array_unique($problems))."\n");
    exit(1);
}

foreach ($langs as $i => $lang) {
    foreach ($add as $key => $values) {
        if ($lang === 'en' && $values[$i] === $key && ! array_key_exists($key, $files['en'])) {
            continue;
        }
        $files[$lang][$key] = $values[$i];
    }
    foreach ($remove as $key) {
        unset($files[$lang][$key]);
    }
    file_put_contents("$root/lang/$lang.json", json_encode($files[$lang], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

echo 'ok: ', count($add), ' added or kept, ', count($remove), " removed, in 11 languages\n";
