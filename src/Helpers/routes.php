<?php

function fileRoute($type, $id)
{
	return route('files.display', ['type' => $type, 'id' => $id]);
}