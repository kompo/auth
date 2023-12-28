<?php

function _HorizontalLinks($label = '')
{
	return _LinkGroup()
		->selectedClass('text-level1 font-medium', 'text-gray-500')
		->optionClass('p-4 cursor-pointer');
}

function _VerticalLinks($label = '')
{
	return _LinkGroup()->vertical()
		->selectedClass('bg-info text-white', 'bg-level5 border')
		->optionClass('rounded-2xl p-4 mb-2 cursor-pointer');
}

function _SelectOptionsWithIcon($labels, $icons)
{
	return collect($labels)->mapWithKeys(fn($label, $key) => [
        $key => _Flex4(
            _Sax($icons[$key], 36),
            _Html($label),
        )->class('mb-2'),
    ]);
}

function _HorizontalButtons($label = '')
{
	return _ButtonGroup($label)->optionClass('p-4 text-center cursor-pointer');
}
