<?php

namespace Kompo\Auth\Common;

use Kompo\Modal as KompoModal;

class Modal extends KompoModal
{    
    /* TO OVERRIDE METHODS */
    protected $_Title;
    protected $noHeaderButtons = false;

    public function body()
    {
        //...
    }

    public function headerButtons()
    {
        return $this->noHeaderButtons ? null : _SubmitButton('Save');
    }

    /* BASE METHODS */
    public function render()
    {
        return _Modal(
            _ModalHeader(
                _TitleModal($this->_Title),
                $this->headerButtons(),
            ),
            _ModalBody(
                $this->body()
            ),
        );
    }
}
