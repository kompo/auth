<?php
/**
 * Re-stream and report CALLEES for a small set of "leaf-suspect" functions:
 *  - PermissionDefinitionCache->permissionByKey
 *  - PermissionDefinitionCache->{closure:.../PermissionDefinitionCache.php:20-20}
 *  - AuthCacheLayer->remember / cacheRememberWithTags
 *  - ReadSecurityService->setupReadSecurity / shouldApplyReadSecurity
 *  - EventAudienceService->applyVisibilityConstraint
 *
 * For each, list every cfn= invoked from inside the body, with call count and
 * inclusive cost charged to that call site. This is the only way to see WHERE
 * those 13–38 seconds are actually being burned downstream.
 */
$cgrindPath = $argv[1] ?? 'C:/wamp64/tmp/trace.sisc_local.1778445751.59068.cgrind';

// substring -> bucket name
$wantNeedles = [
    'permissiondefinitioncache->permissionbykey'  => 'permissionByKey',
    'permissiondefinitioncache.php:20-20'         => 'permissionByKey closure (line 20)',
    'authcachelayer->remember'                    => 'AuthCacheLayer->remember',
    'authcachelayer->cacherememberwithtags'       => 'AuthCacheLayer->cacheRememberWithTags',
    'readsecurityservice->setupreadsecurity'      => 'ReadSecurityService->setupReadSecurity',
    'readsecurityservice->shouldapplyreadsecurity'=> 'ReadSecurityService->shouldApplyReadSecurity',
    'readsecurityservice->applyteambasedrestrictions' => 'ReadSecurityService->applyTeamBasedRestrictions',
    'readsecurityservice.php:51-57'               => 'ReadSecurityService closure (line 51-57)',
    'readsecurityservice.php:123-129'             => 'ReadSecurityService closure (line 123-129)',
    'eventaudienceservice->applyvisibilityconstraint' => 'EventAudienceService->applyVisibilityConstraint',
    'eventaudienceservice.php:75-99'              => 'EventAudienceService closure (line 75-99)',
    'eventaudienceservice.php:85-98'              => 'EventAudienceService closure (line 85-98)',
    'eventaudienceservice.php:90-97'              => 'EventAudienceService closure (line 90-97)',
    'permissionmustbeauthorized'                  => 'permissionMustBeAuthorized',
];

// fn id -> bucket name we care about
$watchedIds = [];
$fnNames    = [];
// bucket -> [calleeFnId => ['count'=>, 'incl'=>]]
$buckets    = [];

$fh = fopen($cgrindPath, 'rb');
if (!$fh) { fwrite(STDERR, "open failed\n"); exit(1); }

$curFnId = null;
$pendingCfn = null;
$pendingCount = 0;
$pendingBucket = null;

$lineNum = 0;
while (($line = fgets($fh)) !== false) {
    $lineNum++;
    if ($lineNum % 5_000_000 === 0) {
        fwrite(STDERR, "  $lineNum lines, buckets: " . implode(',', array_map('count', $buckets)) . "\n");
    }
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    $c0 = $line[0];

    if ($c0 >= '0' && $c0 <= '9') {
        // ln cost mem
        if ($pendingCfn !== null) {
            // inclusive cost charged by current fn to callee
            if ($pendingBucket !== null) {
                $cost = (int) explode(' ', $line, 3)[1];
                $b = &$buckets[$pendingBucket];
                if (!isset($b[$pendingCfn])) {
                    $b[$pendingCfn] = ['count' => 0, 'incl' => 0];
                }
                $b[$pendingCfn]['count'] += $pendingCount;
                $b[$pendingCfn]['incl']  += $cost;
                unset($b);
            }
            $pendingCfn = null;
            $pendingCount = 0;
        }
        continue;
    }

    if (str_starts_with($line, 'fn=')) {
        $rest = substr($line, 3);
        $id = null; $name = null;
        if ($rest !== '' && $rest[0] === '(') {
            $rp = strpos($rest, ')');
            $id = (int) substr($rest, 1, $rp - 1);
            $maybe = ltrim(substr($rest, $rp + 1));
            if ($maybe !== '') {
                $fnNames[$id] = $maybe;
                $name = $maybe;
            } else {
                $name = $fnNames[$id] ?? null;
            }
        }
        $curFnId = $id;
        $pendingBucket = null;
        if ($name !== null) {
            $low = strtolower($name);
            foreach ($wantNeedles as $needle => $bucket) {
                if (str_contains($low, $needle)) {
                    $pendingBucket = $bucket;
                    if (!isset($buckets[$bucket])) $buckets[$bucket] = [];
                    break;
                }
            }
        }
        $pendingCfn = null;
        $pendingCount = 0;
        continue;
    }
    if (str_starts_with($line, 'cfn=')) {
        $rest = substr($line, 4);
        if ($rest !== '' && $rest[0] === '(') {
            $rp = strpos($rest, ')');
            $id = (int) substr($rest, 1, $rp - 1);
            $maybe = ltrim(substr($rest, $rp + 1));
            if ($maybe !== '') $fnNames[$id] = $maybe;
            $pendingCfn = $id;
        }
        continue;
    }
    if (str_starts_with($line, 'calls=')) {
        $sp = strpos($line, ' ', 6);
        $n = $sp === false ? (int) substr($line, 6) : (int) substr($line, 6, $sp - 6);
        $pendingCount = $n;
        continue;
    }
    // fl=, cfl= we still need to register names
    if (str_starts_with($line, 'fl=') || str_starts_with($line, 'cfl=')) {
        $off = $line[1] === 'f' ? 3 : 4;
        $rest = substr($line, $off);
        if ($rest !== '' && $rest[0] === '(') {
            $rp = strpos($rest, ')');
            $id = (int) substr($rest, 1, $rp - 1);
            $maybe = ltrim(substr($rest, $rp + 1));
            // we don't store fl names; not needed for callee report
        }
        continue;
    }
}
fclose($fh);

$fmtTime = fn(int $c) => $c >= 1e8 ? sprintf("%.2fs", $c/1e8) : sprintf("%.1fms", $c/1e5);

$md = "# Callee breakdown for hot security functions\n\n";
$md .= "For each function below, the table lists every downstream callee it invokes,\n";
$md .= "ranked by inclusive cost charged to that call site. This shows WHERE the time\n";
$md .= "actually goes after control leaves the named function.\n\n";

foreach ($buckets as $bucket => $callees) {
    uasort($callees, fn($a, $b) => $b['incl'] <=> $a['incl']);
    $total = array_sum(array_column($callees, 'incl'));
    $md .= "\n## $bucket\n\n";
    $md .= "Sum of all calls from inside this fn: **" . $fmtTime($total) . "** across " . count($callees) . " distinct callees.\n\n";
    $md .= "| Inclusive cost | Calls | Callee |\n|---:|---:|---|\n";
    $shown = 0;
    foreach ($callees as $cid => $info) {
        $md .= sprintf("| %s | %s | `%s` |\n",
            $fmtTime($info['incl']),
            number_format($info['count']),
            $fnNames[$cid] ?? "(fn $cid)");
        if (++$shown >= 15) break;
    }
}

file_put_contents(__DIR__ . '/CALLEES.md', $md);
echo "Wrote CALLEES.md\n";
