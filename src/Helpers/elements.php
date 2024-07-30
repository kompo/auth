<?php

function _Video($src)
{
    return _Html('<video controls src="' . $src . '"></video>');
}

function _Vid($src)
{
    return _Video($src);

} 

function _Audio($src)
{
    return _Html('<audio controls src="' . $src . '"></audio>');
}

function _Aud($src)
{
    return _Audio($src);
}