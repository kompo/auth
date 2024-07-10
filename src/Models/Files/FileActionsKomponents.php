<?php

namespace Kompo\Auth\Models\Files;

use App\Models\Messaging\Attachment;
use Illuminate\Support\Facades\Storage;

trait FileActionsKomponents
{
    /* ATTRIBUTES */
    public function getDisplayAttribute()
    {
        return $this->title ?: $this->name;
    }

    /* CALCULATED FIELDS */
    public function readableSize()
    {
        if(!$this->size && Storage::exists($this->storagePath())){
            $this->size = Storage::size($this->storagePath());
            $this->save();
        }

        if ($this->size >= 1073741824){
            return number_format($this->size / 1073741824, 1) . ' GB';
        }elseif ($this->size >= 1048576){
            return number_format($this->size / 1048576, 1) . ' MB';
        }elseif ($this->size >= 1024){
            return number_format($this->size / 1024, 1) . ' KB';
        }else{
        	return $this->size.' bytes';
        }
	}

    public function storagePath()
    {
        return $this->path;
    }

    public function thumbStyle($komponent)
    {
        return $komponent
            ->class('p-2')
            ->class('bg-gray-100 rounded')
            ->style('width: 100%; height: 3.7rem');
    }

    /* ELEMENTS */
    public function mainAction($komponent)
    {
        if ($this->is_image) {
            return $komponent->get('image.preview', [
                'id' => $this->id, 'type' => $this->getMorphClass()
            ])->inModal();
        }

        if ($this->is_pdf) {
            return $komponent->href(fileRoute($this->getMorphClass(), $this->id))->inNewTab();
        }

        return $komponent->href('files.download', ['id' => $this->id, 'type' => $this->getMorphClass()]);
    }

    public function editInfoInModalAction($komponent)
    {
        return $komponent->editInModal('file-info-download.form', [
            'id' => $this->id,
        ]);
    }


    public function fileThumbnail()
    {
        $actions = func_get_args();

        $actions = count($actions) ? $actions : ['main', 'preview', 'download'];

        return $this->getThumbnail($actions, '8rem', false)
            ->class('p-2 border border-gray-100');
    }

    public function miniThumbnail($width = '4rem')
    {
        $actions = ['main', 'preview', 'download'];

        return $this->getThumbnail($actions, $width, true);
    }

    protected function getThumbnail($actions, $width = '8rem', $mini = false)
    {
        $komponent =  _Rows(
            $this->thumbStyle(
                $this->thumb->class('mb-2 group2-hover:hidden')
            ),
            $this->thumbStyle(
                $this->withActions(...$actions)->class('text-xl')
            )->class('mb-2 hidden group2-hover:flex'),

            $mini ? null : _Html($this->display)->class('text-xs font-semibold truncate'),

            $mini ? null : _Html($this->readableSize())->class('text-xs text-gray-600 font-bold'),

        )->class('group2 cursor-pointer dashboard-card mr-2')
        ->balloon($this->display, 'right')
        ->style('flex:0 0 '.$width.';max-width:'.$width);

        $withMainAction = in_array('main', $actions);

        return in_array('main', $actions) ? $this->mainAction( $komponent ) : $komponent;
    }

    public function withActions()
    {
        $actionLinks = collect(func_get_args())->map(function($action){
                return $this->fileActions()[$action]();
            })->filter()->map(function($actionElement){
                return $actionElement->class('text-gray-600 hover:text-level3 border-gray-600 border rounded-lg p-1');
            });

        return _FlexAround($actionLinks)->class('space-x-2 mr-0');
    }

    public function fileActions()
    {
        $previewLink = _Link()->icon('eye')->balloon('Preview', 'down-left');

        return [
            'main'    => fn() => null, //handled eslewhere

            'preview' => fn() => !$this->is_previewable ? null :
                                    ($this->is_pdf ? 
                                        $previewLink->href(fileRoute($this->getMorphClass(), $this->id))->inNewTab() :
                                        
                                        $previewLink->get($this->is_pdf ? 'pdf.preview' : 'image.preview', [
                                            'id' => $this->id, 'type' => $this->getMorphClass()
                                        ])->inModal()
                                    ),

            'link-to' => fn() => $this instanceOf Attachment ?

                                    _Link()->icon('document-add')->balloon('save-as', 'down-left')
                                        ->get('attachment-save.form', ['attm_id' => $this->id])
                                        ->inPopup() :

                                    _Html()->icon('document-add')->balloon('link-to', 'down-left'),

            'download' => fn() => _Link()->icon('download')->balloon('Download', 'down-right')
                                        ->href('files.download', ['id' => $this->id, 'type' => $this->getMorphClass()]),

            'select' => fn() => null, //handled in checkedItemIds
        ];
    }

    public function thumbRow()
    {
        return _Flex(
            $this->thumb->class('mr-2 w-14 h-10 shrink-0'),
            _Rows(
                _Html($this->display)->class('text-sm break-all font-medium'),
                _Flex(
                    _Html($this->created_at->diffForHumans())->class('text-xs mr-2'),
                    _Html('&bull;'),
                    _Html($this->readableSize())->class('text-xs ml-2'),
                )->class('text-gray-600')
            )->class('mr-2 flex-initial')
        )->class('cursor-pointer');
    }

    /* ATTRIBUTES */
    public function getIsImageAttribute()
    {
        //based on Laravel's image validator method
        return in_array($this->mime_type, static::imageMimeTypes());
    }

    public function getIsPdfAttribute()
    {
        return in_array($this->mime_type, static::pdfMimeTypes());
    }

    public function getIsPreviewableAttribute()
    {
        return $this->is_image || $this->is_pdf;
    }

    public function getThumbAttribute()
    {
        return _Sax($this->icon, 24, 'currentColor')->class('text-gray-700 flex justify-center items-center');
    }

    protected function getIconAttribute()
    {
        foreach (static::iconMimeTypes() as $iconClass => $mimeTypes) {
            if(in_array($this->mime_type, $mimeTypes))
                return $iconClass;
        }

        return 'coolecto-archive';
    }

    public static function iconMimeTypes()
    {
        return [
            'coolecto-image' => static::imageMimeTypes(),
            'coolecto-pdf' => static::pdfMimeTypes(),
            'coolecto-archive' => static::archiveMimeTypes(),
            'coolecto-word' => static::docMimeTypes(),
            'coolecto-excel' => static::sheetMimeTypes(),
            'coolecto-audio' => static::audioMimeTypes(),
            'coolecto-video' => static::videoMimeTypes(),
        ];
    }

    public static function imageMimeTypes()
    {
        return ['image/jpeg', 'image/gif', 'image/png', 'image/bmp', 'image/svg+xml', 'image/webp'];
    }

    public static function pdfMimeTypes()
    {
        return ['application/pdf'];
    }

    public static function archiveMimeTypes()
    {
        return ['application/x-rar-compressed', 'application/zip', 'application/x-gzip', 'application/gzip', 'application/vnd.rar', 'application/x-7z-compressed'];
    }

    public static function docMimeTypes()
    {
        return ['text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    public static function sheetMimeTypes()
    {
        return ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    public static function audioMimeTypes()
    {
        return ['audio/basic', 'audio/aiff', 'audio/mpeg', 'audio/midi', 'audio/wave', 'audio/ogg'];
    }

    public static function videoMimeTypes()
    {
        return ['video/avi', 'video/x-msvideo', 'video/mpeg', 'video/ogg'];
    }

}
