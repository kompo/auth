<?php 

function _ProgressBar($pct, $bgColor = 'bg-level5', $campaignColor = null, $extraStyle = '')
{
    $progressPct = _Html()->class('rounded')->style('height: 8px; width:'.($pct*100).'%;' . $extraStyle);

    if ($campaignColor) {
        $progressPct = $progressPct->campaignBg($campaignColor);
    } else {
        $progressPct = $progressPct->class($bgColor);
    }

    return _Rows($progressPct)->class('bg-greenmain bg-opacity-10 rounded')->style('height: 8px');
}