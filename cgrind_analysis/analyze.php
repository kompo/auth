<?php
/**
 * Streaming Xdebug cachegrind analyzer.
 *
 * Format reminder:
 *   fl=(N) path        — current file (compressed name)
 *   fn=(N) name        — function block start; following "ln cost mem" rows
 *                         are SELF cost lines for that function (until next
 *                         fn= / fl= or until a cfn= block).
 *   cfl=(N), cfn=(N), calls=N pos1 pos2
 *   <line> <cost> <mem>   — inclusive cost contributed by this called fn.
 *
 * IMPORTANT: After a `cfn=` + `calls=` pair, the NEXT "ln cost mem" line
 * belongs to the call (inclusive cost of callee, attributed to caller).
 * Otherwise "ln cost mem" lines are SELF cost of the current fn.
 *
 * Compressed names: when "fn=(N) Name" first appears it defines id N;
 * later "fn=(N)" alone references it. Same for fl=, cfl=, cfn=.
 */

$cgrindPath = $argv[1] ?? 'C:/wamp64/tmp/trace.sisc_local.1778445751.59068.cgrind';
$outDir     = __DIR__;

// substrings (case-insensitive) that mark "security layer" functions we care about
$filters = [
    'kompo\\auth',
    'hassecurity',
    'readsecurityservice',
    'writesecurityservice',
    'deletesecurityservice',
    'fieldprotectionservice',
    'securitybypassservice',
    'securitymetadataregistry',
    'batchpermissionservice',
    'teamsecurityservice',
    'permissionresolver',
    'securedmodelcollection',
    'globalsecuritybypass',
    'permissionmustbeauthorized',
    'userhaspermission',
    'eventaudienceservice',
];

$fh = fopen($cgrindPath, 'rb');
if (!$fh) {
    fwrite(STDERR, "Cannot open $cgrindPath\n");
    exit(1);
}

// ---- Compressed-name tables ----
$flNames = [];   // id => path
$fnNames = [];   // id => function name

// ---- Per-fn aggregates ----
//   $fnSelf[$id]     => cumulative self cost (Time_10ns)
//   $fnSelfMem[$id]  => self memory
//   $fnIncl[$id]     => cumulative inclusive cost (self + sum of called)
//   $fnCalls[$id]    => how many times this fn was *called* (sum of "calls=" entries that target it)
//   $fnCallers[$id]  => [callerFnId => ['count' => int, 'incl' => int]]
$fnSelf    = [];
$fnSelfMem = [];
$fnIncl    = [];
$fnCalls   = [];
$fnCallers = [];

$totalCost = 0;

// State machine across the stream.
$curFlId = null;
$curFnId = null;
$pendingCallCfnId = null;   // set when we see cfn=; consumed by the next "ln cost mem"
$pendingCallCount = 0;

// Helpers ---------------------------------------------------------------
$parseRef = static function (string $s, ?string &$nameOut): ?int {
    // Forms:
    //   "(123) some name"   -> returns 123, sets $nameOut = "some name"
    //   "(123)"             -> returns 123, $nameOut stays null
    //   "raw name"          -> returns null (uncompressed; rare in xdebug)
    if ($s === '') return null;
    if ($s[0] !== '(') {
        $nameOut = $s;
        return null;
    }
    $rp = strpos($s, ')');
    if ($rp === false) return null;
    $id = (int) substr($s, 1, $rp - 1);
    $rest = ltrim(substr($s, $rp + 1));
    if ($rest !== '') {
        $nameOut = $rest;
    }
    return $id;
};

$lineNum = 0;
$progressEvery = 2_000_000;

while (($line = fgets($fh)) !== false) {
    $lineNum++;
    if ($lineNum % $progressEvery === 0) {
        fwrite(STDERR, sprintf("  ... %d lines, fns=%d, total cost=%d\n",
            $lineNum, count($fnSelf), $totalCost));
    }

    // Trim trailing newline only; keep content as-is.
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;

    $c0 = $line[0];

    // Numeric line: "ln cost mem" — fastest path first.
    if ($c0 >= '0' && $c0 <= '9') {
        // Three space-separated ints (sometimes negative line numbers via '-')
        $parts = explode(' ', $line);
        if (count($parts) < 2) continue;
        $cost = (int) $parts[1];
        $mem  = isset($parts[2]) ? (int) $parts[2] : 0;

        if ($pendingCallCfnId !== null) {
            // This is the CALLED fn's inclusive cost as charged to the call site.
            $callee = $pendingCallCfnId;
            // Inclusive cost of callee accumulates here.
            $fnIncl[$callee] = ($fnIncl[$callee] ?? 0) + $cost;
            $fnCalls[$callee] = ($fnCalls[$callee] ?? 0) + $pendingCallCount;

            if ($curFnId !== null) {
                if (!isset($fnCallers[$callee][$curFnId])) {
                    $fnCallers[$callee][$curFnId] = ['count' => 0, 'incl' => 0];
                }
                $fnCallers[$callee][$curFnId]['count'] += $pendingCallCount;
                $fnCallers[$callee][$curFnId]['incl']  += $cost;
            }

            $pendingCallCfnId = null;
            $pendingCallCount = 0;
        } else {
            // Self cost of current fn.
            if ($curFnId !== null) {
                $fnSelf[$curFnId]    = ($fnSelf[$curFnId]    ?? 0) + $cost;
                $fnSelfMem[$curFnId] = ($fnSelfMem[$curFnId] ?? 0) + $mem;
                // Inclusive cost includes self.
                $fnIncl[$curFnId]    = ($fnIncl[$curFnId]    ?? 0) + $cost;
                $totalCost += $cost;
            }
        }
        continue;
    }

    // Header / directive line.
    if (str_starts_with($line, 'fn=')) {
        $name = null;
        $id = $parseRef(substr($line, 3), $name);
        if ($id !== null) {
            if ($name !== null) {
                $fnNames[$id] = $name;
            }
            $curFnId = $id;
        }
        $pendingCallCfnId = null;
        $pendingCallCount = 0;
        continue;
    }
    if (str_starts_with($line, 'fl=')) {
        $name = null;
        $id = $parseRef(substr($line, 3), $name);
        if ($id !== null) {
            if ($name !== null) {
                $flNames[$id] = $name;
            }
            $curFlId = $id;
        }
        // fl= alone does NOT reset curFnId; xdebug emits fl= before each fn=.
        continue;
    }
    if (str_starts_with($line, 'cfn=')) {
        $name = null;
        $id = $parseRef(substr($line, 4), $name);
        if ($id !== null) {
            if ($name !== null) {
                $fnNames[$id] = $name;
            }
            $pendingCallCfnId = $id;
        }
        continue;
    }
    if (str_starts_with($line, 'calls=')) {
        // "calls=N pos1 pos2"
        $sp = strpos($line, ' ', 6);
        $n  = $sp === false ? (int) substr($line, 6) : (int) substr($line, 6, $sp - 6);
        $pendingCallCount = $n;
        continue;
    }
    if (str_starts_with($line, 'cfl=')) {
        $name = null;
        $parseRef(substr($line, 4), $name);  // we don't need cfl beyond name registration
        // (fl= names are stored in $flNames; cfl ids may overlap fl ids — they do, same table)
        continue;
    }
    if (str_starts_with($line, 'summary:') || str_starts_with($line, 'totals:')) {
        continue;
    }
    // headers we skip: version, creator, cmd, part, positions, events, etc.
}
fclose($fh);

fwrite(STDERR, sprintf("DONE scan: %d lines, %d functions tracked, total cost=%d\n",
    $lineNum, count($fnSelf), $totalCost));

// ----------------------------------------------------------------------
// Build filtered set
// ----------------------------------------------------------------------
$matches = function (string $name) use ($filters): bool {
    $low = strtolower($name);
    foreach ($filters as $needle) {
        if (str_contains($low, $needle)) return true;
    }
    return false;
};

$secFnIds = [];
foreach ($fnNames as $id => $name) {
    if ($matches($name)) {
        $secFnIds[$id] = $name;
    }
}

fwrite(STDERR, "Matched security fns: " . count($secFnIds) . "\n");

// Build rows for top-by-self and top-by-incl.
$rows = [];
foreach ($secFnIds as $id => $name) {
    $rows[] = [
        'id'    => $id,
        'name'  => $name,
        'self'  => $fnSelf[$id] ?? 0,
        'incl'  => $fnIncl[$id] ?? 0,
        'calls' => $fnCalls[$id] ?? 0,
        'mem'   => $fnSelfMem[$id] ?? 0,
    ];
}

usort($rows, fn($a, $b) => $b['self'] <=> $a['self']);
$bySelf = $rows;

usort($rows, fn($a, $b) => $b['incl'] <=> $a['incl']);
$byIncl = $rows;

// Hot per-row loops: anything with > 100k calls inside the security set.
usort($rows, fn($a, $b) => $b['calls'] <=> $a['calls']);
$byCalls = array_values(array_filter($rows, fn($r) => $r['calls'] > 100_000));

// ----------------------------------------------------------------------
// Emit JSON for downstream consumption + write REPORT.md
// ----------------------------------------------------------------------
$totalCostNs = $totalCost * 10; // Time_(10ns) -> ns

$fmtPct = function (int $self) use ($totalCost): string {
    if ($totalCost === 0) return '0%';
    return sprintf('%.2f%%', 100.0 * $self / $totalCost);
};

$fmtTime = function (int $cost): string {
    // cost is in 10ns units
    $ms = $cost / 1e5;
    if ($ms >= 1000) return sprintf('%.2fs', $ms / 1000);
    return sprintf('%.1fms', $ms);
};

// ---- Top 10 by self -> top 5 callers each ----
$top10Self = array_slice($bySelf, 0, 10);
$callersDump = [];
foreach ($top10Self as $row) {
    $callers = $fnCallers[$row['id']] ?? [];
    uasort($callers, fn($a, $b) => $b['incl'] <=> $a['incl']);
    $callersDump[$row['id']] = array_slice($callers, 0, 5, true);
}

// ---------- Markdown report ----------
$md  = "# Cachegrind analysis — security layer\n\n";
$md .= "Source: `C:/wamp64/tmp/trace.sisc_local.1778445751.59068.cgrind` (540 MB)\n\n";
$md .= sprintf("**Total trace cost:** %d (Time_10ns units) = %s wall time equivalent\n\n",
    $totalCost, $fmtTime($totalCost));
$md .= sprintf("**Total functions in trace:** %d  \n", count($fnSelf));
$md .= sprintf("**Functions matching security filters:** %d\n\n", count($secFnIds));

$md .= "## Top 30 functions by SELF cost (security layer)\n\n";
$md .= "| # | % total | Self cost | Calls | Function |\n";
$md .= "|---:|---:|---:|---:|---|\n";
foreach (array_slice($bySelf, 0, 30) as $i => $r) {
    $md .= sprintf("| %d | %s | %s | %s | `%s` |\n",
        $i + 1,
        $fmtPct($r['self']),
        $fmtTime($r['self']),
        number_format($r['calls']),
        $r['name']);
}

$md .= "\n## Top 30 functions by INCLUSIVE cost (security layer)\n\n";
$md .= "| # | % total | Inclusive | Calls | Function |\n";
$md .= "|---:|---:|---:|---:|---|\n";
foreach (array_slice($byIncl, 0, 30) as $i => $r) {
    $md .= sprintf("| %d | %s | %s | %s | `%s` |\n",
        $i + 1,
        $fmtPct($r['incl']),
        $fmtTime($r['incl']),
        number_format($r['calls']),
        $r['name']);
}

$md .= "\n## Top callers for top-10-by-self\n\n";
foreach ($top10Self as $r) {
    $md .= sprintf("\n### `%s`\n", $r['name']);
    $md .= sprintf("- Self: %s (%s of total) — Inclusive: %s — Calls: %s\n\n",
        $fmtTime($r['self']), $fmtPct($r['self']),
        $fmtTime($r['incl']), number_format($r['calls']));
    $callers = $callersDump[$r['id']];
    if (!$callers) {
        $md .= "_No caller info recorded (likely a top-level / event-loop entry)._\n";
        continue;
    }
    $md .= "| Caller | Calls | Inclusive cost charged here |\n|---|---:|---:|\n";
    foreach ($callers as $cid => $info) {
        $cname = $fnNames[$cid] ?? "(fn $cid)";
        $md .= sprintf("| `%s` | %s | %s |\n",
            $cname, number_format($info['count']), $fmtTime($info['incl']));
    }
}

$md .= "\n## Hot per-row loops (call count > 100k inside security set)\n\n";
if (!$byCalls) {
    $md .= "_None_\n";
} else {
    $md .= "| Calls | Self | Inclusive | Function |\n|---:|---:|---:|---|\n";
    foreach (array_slice($byCalls, 0, 30) as $r) {
        $md .= sprintf("| %s | %s | %s | `%s` |\n",
            number_format($r['calls']),
            $fmtTime($r['self']),
            $fmtTime($r['incl']),
            $r['name']);
    }
}

file_put_contents($outDir . '/REPORT.md', $md);

// Dump raw JSON too in case we want to slice differently later.
file_put_contents($outDir . '/summary.json', json_encode([
    'total_cost'      => $totalCost,
    'total_fns'       => count($fnSelf),
    'security_fns'    => count($secFnIds),
    'top_self_50'     => array_slice($bySelf, 0, 50),
    'top_incl_50'     => array_slice($byIncl, 0, 50),
    'top_calls_50'    => array_slice($byCalls, 0, 50),
    'top10_callers'   => array_map(function ($row) use ($callersDump, $fnNames) {
        $callers = [];
        foreach ($callersDump[$row['id']] ?? [] as $cid => $info) {
            $callers[] = [
                'caller' => $fnNames[$cid] ?? "(fn $cid)",
                'count'  => $info['count'],
                'incl'   => $info['incl'],
            ];
        }
        return ['name' => $row['name'], 'self' => $row['self'], 'callers' => $callers];
    }, $top10Self),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Wrote:\n";
echo "  $outDir/REPORT.md\n";
echo "  $outDir/summary.json\n";
