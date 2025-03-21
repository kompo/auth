<?php 

/* FILE UTILITIES METHODS */
if (!function_exists('appendBeforeExtension')) {
	function appendBeforeExtension($path, $appendText)
	{
	    return substr($path, 0, strrpos($path, '.')).$appendText.'.'.substr($path, strrpos($path, '.') + 1);
	}
}

/* STORAGE METHODS */
if (!function_exists('publicUrlFromPath')) {
	function publicUrlFromPath($path, $defaultUrl = null)
	{
		if (\Storage::disk('public')->exists($path)) {
			return \Storage::disk('public')->url($path);
		}

		return $defaultUrl;
	}
}

if (!function_exists('publicUrlFromFileModel')) {
	function publicUrlFromFileModel($file, $defaultUrl = null)
	{
		if ($file->path ?? false) {
			if ($file->disk) {
				return \Storage::disk($file->disk)->url($file->path);
			}

			return publicUrlFromPath($file->path, $defaultUrl);
		}

		return $defaultUrl;
	}
}


/* ELS & STYLES */
function thumbStyle($komponent)
{
    return $komponent
        ->class('p-2')
        ->class('bg-gray-100 rounded')
        ->style('width: 100%; height: 3.7rem');
}

function _ThumbWrapper($arrayEls, $width = '8rem')
{
	return _Rows($arrayEls)
		->class('group2 cursor-pointer dashboard-card mr-2')
	    ->style('flex:0 0 '.$width.';max-width:'.$width);
}

/* SIZE */
function getReadableSize($sizeBytes)
{
    if ($sizeBytes >= 1073741824){
        return number_format($sizeBytes / 1073741824, 1) . ' GB';
    }elseif ($sizeBytes >= 1048576){
        return number_format($sizeBytes / 1048576, 1) . ' MB';
    }elseif ($sizeBytes >= 1024){
        return number_format($sizeBytes / 1024, 1) . ' KB';
    }else{
    	return $sizeBytes.' bytes';
    }
}

/* ICONS */
function iconMimeTypes()
{
    return [
        'far fa-file-image' => imageMimeTypes(),
        'far fa-file-pdf' => pdfMimeTypes(),
        'far fa-file-archive' => archiveMimeTypes(),
        'far fa-file-word' => docMimeTypes(),
        'far fa-file-excel' => sheetMimeTypes(),
        'far fa-file-audio' => audioMimeTypes(),
        'far fa-file-video' => videoMimeTypes(),
    ];
}

function getIconFromMimeType($mimeType)
{
    foreach (iconMimeTypes() as $iconClass => $mimeTypes) {
        if(in_array($mimeType, $mimeTypes))
            return $iconClass;
    }

    return 'far fa-file-alt';
}

/* MIME TYPES */
function imageMimeTypes()
{
    return ['image/jpeg', 'image/gif', 'image/png', 'image/bmp', 'image/svg+xml', 'image/webp'];
}

function pdfMimeTypes()
{
    return ['application/pdf'];
}

function archiveMimeTypes()
{
    return ['application/x-rar-compressed', 'application/zip', 'application/x-gzip', 'application/gzip', 'application/vnd.rar', 'application/x-7z-compressed'];
}

function docMimeTypes()
{
    return ['text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
}

function sheetMimeTypes()
{
    return ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
}

function audioMimeTypes()
{
    return ['audio/basic', 'audio/aiff', 'audio/mpeg', 'audio/midi', 'audio/wave', 'audio/ogg'];
}

function videoMimeTypes()
{
    return ['video/avi', 'video/x-msvideo', 'video/mpeg', 'video/ogg'];
}