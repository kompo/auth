<?php

namespace Kompo\Auth\Common;

use Kompo\Modal as KompoModal;

class Modal extends KompoModal
{
    use HasAuthorizationUtils;
    
    /* TO OVERRIDE METHODS */
    protected $_Title;
    protected $_Icon;
    protected $noHeaderButtons = false;

    public function body()
    {
        //...
    }

    public function headerButtons()
    {
        return $this->noHeaderButtons ? null : _SubmitButton('general.save');
    }

    /* BASE METHODS */
    public function render()
    {
        return _Modal(
            _ModalHeader(
                _TitleModal($this->_Title, $this->_Icon),
                $this->headerButtons(),
            ),
            _ModalBody(
                $this->body()
            ),
        );
    }
}
