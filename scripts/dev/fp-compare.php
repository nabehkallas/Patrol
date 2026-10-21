<?php
// Compares two fingerprints (prod/fingerprint.php, before and after) and names every part that
// changed, per station. Exits 1 when anything differs, so it can gate a deploy check.
//
//   php scripts/dev/fp-compare.php before.json after.json
//
// When records were added between the two runs (staff using the app), the counts show it: then
// rerun the comparison on a backup copy instead (see scripts/dev/README.md).
if ($argc < 3) {
    fwrite(STDERR, "usage: php fp-compare.php before.json after.json\n");
    exit(2);
}

// The JSON starts at the first "[" (a tinker run may print a line or two before it).
$read = function (string $file): array {
    $text = (string) file_get_contents($file);
    $data = json_decode(substr($text, (int) strpos($text, '[')), true);
    if (! is_array($data)) {
        fwrite(STDERR, "Not a fingerprint: $file\n");
        exit(2);
    }

    return array_column($data, null, 'station');
};

[$before, $after] = [$read($argv[1]), $read($argv[2])];
$different = false;

foreach ($before as $name => $station) {
    $other = $after[$name] ?? null;
    if ($other === null) {
        echo "$name: missing in the second fingerprint\n";
        $different = true;

        continue;
    }

    $changed = [];
    foreach ($station['hash'] as $part => $hash) {
        if (($other['hash'][$part] ?? null) !== $hash) {
            $changed[] = $part;
        }
    }
    $added = array_diff(array_keys($other['hash']), array_keys($station['hash']));
    $counts = $station['counts'] == $other['counts'] ? 'counts unchanged' : 'counts '.json_encode($station['counts']).' -> '.json_encode($other['counts']);

    echo $name, ': ', $changed === [] ? 'all '.count($station['hash']).' parts identical' : 'DIFFERENT: '.implode(', ', $changed),
        $added !== [] ? ' (new parts: '.implode(', ', $added).')' : '', ' | ', $counts, "\n";
    $different = $different || $changed !== [];
}

exit($different ? 1 : 0);
