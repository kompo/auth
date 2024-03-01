<?php

function makeMailButton($label, $url, $color = null, $extraStyle = "")
{
	//@BENOIT: DO NOT CHANGE THE COLOR PRIMARY! -> HAHA FUNNY!
	return makeCenteredElement('<a href="'.$url.'" class="button button-'.( $color ?? 'primary' ).'" target="_blank" rel="noopener" style="text-transform:uppercase">'.__($label).'</a>', $extraStyle);
}

function makeMailSimpleImage($path, $extraStyle = "", $alt = "default")
{
    return "<img style='" . $extraStyle . "' src='" . $path . "' alt='" . $alt . "'>";
}

function makeMailImage($file, $extraStyle = "")
{
	$src = \Storage::url(thumb($file->path));
	$alt = $file->name;

	return makeCenteredElement('<img src="'.$src.'" alt="'.$alt.'" />', $extraStyle);
}

function makeCenteredElement($element, $extraStyle = "")
{
	return '<table class="action table-without-borders" style="'.$extraStyle.'" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">'.$element.'</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>';
}

function makeParagraph($text, $extraStyle = "")
{
	return '<p style="'.$extraStyle.'">'.__($text).'</p>';
}

function makeMailButtonWrappedInDiv($label, $url, $color = null, $outlined = false)
{
    return '<div style="text-align:center"><a href="'.$url.'" class="button '. ($outlined ? 'outlined' : '') .' button-'.( $color ?? 'primary' ).'" target="_blank" rel="noopener" style="text-transform:uppercase">'.__($label).'</a></div>';
}

function makeQrElement($element, $text = '')
{
    return '
        <div style="background-color: #fff; border-radius: 1rem; border-color: #f1f2f3; padding: 16px; text-align: center; width: max-content;">
            <div>' . $element . '</div>
            <p style="font-size: 1rem; margin-top: 1rem; font-weight: 500; color: #000; text-align: center;">' . $text . '</p>
        </div>
    ';
}

function getCenteredGrid($elements, $bg = '#fff', $extraStyles = "", $extraStylesInElements = "padding: 25px 15px;")
{
    $tds = '';
    foreach ($elements as $element) {
        $tds .= '<td align="center" style="' . $extraStylesInElements . ' background-color:' . $bg . ';">'.$element.'</td>';
    }

    return '<table class="action table-without-borders" align="center" width="100%" style="' . $extraStyles . '" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                    <tr>
                        '.$tds.'
                    </tr>
                </table>
            </td>
        </tr>
    </table>';
}

if (!function_exists('getMentionHtml')) {
    function getMentionHtml($type)
    {
        return '<span class="mention" data-mention="'.$type.'">';
    }
}

function getFullMentionHtml($type, $label)
{
	return '<p>'.getMentionHtml($type).__($label).'</span></p>';
}

if(!function_exists('replaceMention')) {
    function replaceMention($subject, $type, $replaceWith)
    {
        $start = strpos($subject, getMentionHtml($type));

        while ($start > -1) {

            $end = strpos($subject, '</span>', $start + strlen(getMentionHtml($type)));

            $subject = substr_replace($subject, $replaceWith, $start, $end - $start);

            $start = strpos($subject, getMentionHtml($type));

        }

        return $subject;
    }
}

if (!function_exists('replaceAllMentions')) {
    function replaceAllMentions($text, $mentions = [])
    {
        collect($mentions)->each(function($mention, $type) use (&$text){
            $text = replaceMention($text, $type, $mention);
        });

        return $text;
    }
}

function adfLinkHtml()
{
	return '<a href="https://'.coolectoDotCom().'" target="_blank">'.coolectoDotCom().'</a>';
}

/* MAIL HTML ELEMENTS */
function mailTitle($label)
{
	return '<div style="font-weight:700;font-size:1.3rem;color:rgb(5, 21, 61)">'.__($label).'</div>';
}

function mailTitleDark($label)
{
	return '<div style="font-weight:700;font-size:1.3rem;color:rgb(5, 21, 61)">'.__($label).'</div>';
}

function mailSubtitle($label)
{
	return '<div style="font-size:1.3rem;">'.__($label).'</div>';
}

function mailMinititle($label, $additionalStyle = 'text-align:center')
{
	return '<div style="font-size:0.9rem;font-weight:700;color:black;margin-bottom:0.5rem;'.$additionalStyle.'">'.__($label).'</div>';
}

function mailMiniLabel($label)
{
	return '<div style="font-weight:600;font-size:0.7rem;opacity:60%;text-transform:uppercase;margin-bottom:0.2rem;">'.__($label).'</div>';
}

function mailCurrency($label)
{
	return '<div style="font-weight:400;text-align:right">$'.number_format($label, 2).' CAD</div>';
}

function mailCurrencyBold($label)
{
	return '<div style="text-align:right;font-weight:600;">$'.number_format($label, 2).'</div>';
}

function mailCurrencyBigBold($label)
{
	return '<div style="font-size:1.5rem;font-weight:800">$'.number_format($label, 2).'</div>';
}

function mailCurrencyLeft($label)
{
	return '<div style="font-size:0.9rem;font-weight:400;">$'.number_format($label, 2).'</div>';
}

function mailValue($label)
{
	return '<div style="font-size:0.9rem;font-weight:400">'.__($label).'</div>';
}

function mailValueBold($label)
{
	return '<div style="font-size:0.9rem;font-weight:700">'.__($label).'</div>';
}

function mailCard($innerHtml, $backgroundColor = "rgb(247 249 252)", $styles = "")
{
	return '<div style="background: '. $backgroundColor .';padding:.9rem;border-radius: 1rem;'. $styles .'">'.$innerHtml.'</div>';
}

function mailTable($innerHtml, $style = 'width: 100%')
{
	return '<table class="table" style="'.$style.'">'.$innerHtml.'</table>';
}

function mailTableCard($innerHtml, $style = 'width: 100%', $borderColor = '#EEF2F6')
{
	return '<table class="table" style="'.$style.'; background-color: '.$borderColor.'; border-radius:1rem; padding: 8px 8px 8px 8px;">'.$innerHtml.'</table>';
}

function mailTr($innerHtml, $style = '')
{
    return '<tr style="'.$style.'">'.$innerHtml.'</tr>';
}

function mailTd($innerHtml, $colspan = null, $style = '', $defautStyle = ';vertical-align:top;padding:0.5rem 0.5rem')
{
	return '<td style="font-size:0.9rem;color:rgb(5, 21, 61);'.$style.$defautStyle.'"'.($colspan ? (' colspan="'.$colspan.'"') : '').'>'.$innerHtml.'</td>';
}

function mailTdBorderT($innerHtml, $colspan = null)
{
	return mailTd($innerHtml, $colspan, 'border-top:1px solid gainsboro; border-color:#E6E6F0;font-weight:600;');
}

function mailPledgeTd($icon, $text)
{
	$iconHtml = $icon ? _SaxSvg($icon, 40) : '<div style="height:40px"></div>';

	return mailTd($iconHtml.'<div style="height:10px"></div>'.mailTitle($text), null, 'text-align:center');
}

function mailIcon($icon, $alt = 'icon', $styles = '')
{
	return '<img style="width:24px;'. $styles .'" src="'.asset('images/'.$icon.'.svg') .'" alt="'.__($alt).'">';
}

function mailSvg($icon, $size = 24, $styles = '')
{
	return '<span style="'. $styles .'">' . _SaxSvg($icon, $size) . '</span>';
}

/* FUNKY MAIL ELEMENTS */
function mailCampaignProgressBar($campaign)
{
	$color = '#007EFF';
    $borderColor = '#EEF2F6';
    $totalSales = number_format($campaign->goal_sales);
    $goal = number_format($campaign->goal);
    $pct = intval($campaign->pct_goal);

    return '<div style="text-align:center; background-color: '.$borderColor.'; border-radius:1rem; padding: 16px 16px 16px 16px;">'.
        '<div style="font-weight:700;font-size:2.5rem;color:'.$color.';margin-bottom:0.5rem">$'.$totalSales.'</div>'.
    	_ProgressBarHtml($pct, $color).
        '<div style="display:flex;justify-content:space-between;font-size:0.85rem;font-weight:bold;margin-top:0.25rem">'.
        	'<div>$'.$totalSales.' / $'.$goal.'</div>'.
        	'<div>'.$pct.'%</div>'.
        '</div>'.
    '</div>';
}
