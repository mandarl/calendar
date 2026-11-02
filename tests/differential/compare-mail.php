<?php
declare(strict_types=1);

/**
 * Compares the captured SparkPost payloads of the Cake 3 and the Cake 5 run.
 * usage: php compare-mail.php mail-cake3.json mail-cake5.json
 *
 * Normalisation: runs of whitespace collapse to one space (the brief's rule), and the Cake 5 payload's
 * `options` (click_tracking=false, an owner decision) is removed before the comparison and checked separately.
 * Everything else is compared field by field. Exit status 1 when any case differs.
 */
$old = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$new = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

$ws = fn (?string $s) => $s === null ? null : trim((string)preg_replace('/\s+/u', ' ', $s));
$norm = function (array $msg) use ($ws): array {
    unset($msg['options'], $msg['replyTo']);
    foreach (['subject', 'html', 'text'] as $k) {
        $msg['content'][$k] = $ws($msg['content'][$k] ?? null);
    }

    return $msg;
};

$explain = function (array $a, array $b): string {
    $out = [];
    $flat = function (array $x, string $p = '') use (&$flat): array {
        $r = [];
        foreach ($x as $k => $v) {
            is_array($v) ? $r += $flat($v, $p . $k . '.') : $r[$p . $k] = $v;
        }
        return $r;
    };
    $fa = $flat($a);
    $fb = $flat($b);
    foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $k) {
        $va = $fa[$k] ?? '<absent>';
        $vb = $fb[$k] ?? '<absent>';
        if ($va !== $vb) {
            $va = (string)$va;
            $vb = (string)$vb;
            $i = 0;
            while ($i < min(strlen($va), strlen($vb)) && $va[$i] === $vb[$i]) {
                $i++;
            }
            $out[] = "
     [$k] first difference at byte $i: cake3=" . json_encode(substr($va, max(0, $i - 25), 70), JSON_UNESCAPED_UNICODE) . " cake5=" . json_encode(substr($vb, max(0, $i - 25), 70), JSON_UNESCAPED_UNICODE);
        }
    }
    return implode('', $out);
};

$diffs = 0;
$exact = 0;
$optionsOk = 0;
$sentCount = 0;
$knownCount = [];
foreach ($old as $name => $o) {
    $n = $new[$name] ?? null;
    if ($n === null) {
        echo "MISSING in cake5: $name\n";
        $diffs++;
        continue;
    }
    $problems = [];
    if (count($o['sent']) !== count($n['sent'])) {
        $problems[] = 'sent count ' . count($o['sent']) . ' vs ' . count($n['sent']);
    }
    $lvl = fn (array $l) => array_map(fn (string $x) => explode(':', $x, 2)[0], $l); // levels only: the message text (print_r of the Throwable vs its string cast) differs on purpose
    if ($lvl($o['logged']) !== $lvl($n['logged'])) {
        $problems[] = 'logged ' . json_encode($o['logged']) . ' vs ' . json_encode($n['logged']);
    }
    if (($o['exception'] === null) !== ($n['exception'] === null)) {
        $problems[] = 'exception ' . json_encode($o['exception']) . ' vs ' . json_encode($n['exception']);
    }
    foreach ($o['sent'] as $i => $oldMsg) {
        $newMsg = $n['sent'][$i] ?? null;
        if ($newMsg === null) {
            continue;
        }
        $sentCount++;
        if (($newMsg['options'] ?? null) === ['click_tracking' => false]) {
            $optionsOk++;
        } else {
            $problems[] = 'options is ' . json_encode($newMsg['options'] ?? null);
        }
        // byte-exact first; then the two documented, deliberate framework differences; anything else is a failure
        $a = $oldMsg;
        $b = $newMsg;
        unset($b['options']);
        if ($a === $b) {
            $exact++;
            continue;
        }
        $known = [];
        // (1) Cake 3 ended the html part with a newline; Cake 5 Message::setBody() rtrims trailing newlines
        foreach (['html', 'text'] as $f) {
            if ($a['content'][$f] === $b['content'][$f] . "\n") {
                $a['content'][$f] = $b['content'][$f];
                $known['trailing-newline'] = true;
            }
        }
        // (2) recipient display names: the old plugin mb_encode_mimeheader()-ed them inside the JSON payload (libkit finding M-2)
        foreach ($a['recipients'] as $ri => $r) {
            $decoded = mb_decode_mimeheader($r['address']['name']);
            if ($decoded !== $r['address']['name'] && $decoded === ($b['recipients'][$ri]['address']['name'] ?? null)) {
                $a['recipients'][$ri]['address']['name'] = $decoded;
                $known['mime-encoded-name'] = true;
            }
        }
        if ($a === $b) {
            foreach (array_keys($known) as $k) {
                $knownCount[$k] = ($knownCount[$k] ?? 0) + 1;
            }
        } elseif ($norm($a) !== $norm($b)) {
            $problems[] = 'payload differs:' . $explain($norm($a), $norm($b));
        } else {
            $problems[] = 'differs in whitespace beyond the trailing newline';
        }
    }
    if ($problems) {
        $diffs++;
        echo "DIFF $name\n  - " . implode("\n  - ", $problems) . "\n";
    }
}
foreach (array_diff(array_keys($new), array_keys($old)) as $name) {
    echo "EXTRA in cake5: $name\n";
    $diffs++;
}
printf(
    "cases=%d mails=%d byte-identical(excl. options)=%d identical-after-known-differences=%s options.click_tracking=false in %d; failing cases=%d\n",
    count($old),
    $sentCount,
    $exact,
    json_encode($knownCount),
    $optionsOk,
    $diffs
);
exit($diffs ? 1 : 0);
