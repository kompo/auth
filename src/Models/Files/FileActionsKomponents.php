<?php

namespace Kompo\Auth\Models\Files;

use Illuminate\Support\Facades\Storage;
use App\Models\Messaging\Attachment;

trait FileActionsKomponents
{
    /* ATTRIBUTES */
    public function getDisplayAttribute()
    {
        return $this->title ?: $this->name;
    }

    /* CALCULATED FIELDS */
    public function storageDisk()
    {
        return Storage::disk($this->disk);
    }

    public function storagePath()
    {
        return $this->path;
    }

    public function existsOnStorage()
    {
        return $this->storageDisk()->exists($this->storagePath());
    }

    public function readableSize()
    {
        if(!$this->size && $this->existsOnStorage()){
            $this->size = $this->storageDisk()->size($this->storagePath());
            $this->save();
        }

        return getReadableSize($this->size);
    }

    /* ACTIONS */

    /* ELEMENTS */
    public function downloadAction($el)
    {
        return $el->href('files.download', ['id' => $this->id, 'type' => $this->getMorphClass()]);
    }

    public function mainAction($el)
    {
        if ($this->is_image) {
            return $el->get('image.preview', [
                'id' => $this->id, 'type' => $this->getMorphClass()
            ])->inModal();
        }

        if ($this->is_pdf) {
            return $el->href(fileRoute($this->getMorphClass(), $this->id))->inNewTab();
        }

        return $this->downloadAction($el);
    }

    public function fileThumbnail()
    {
        $actions = func_get_args();

        $actions = count($actions) ? $actions : ['main', 'preview', 'download'];

        return $this->getThumbnail($actions)->class('p-2 border border-gray-100');
    }

    public function miniThumbnail($width = '4rem')
    {
        $actions = ['main', 'preview', 'download'];

        return $this->getThumbnail($actions, $width, true);
    }

    protected function getThumbnail($actions, $width = '8rem', $mini = false)
    {
        $komponent =  _ThumbWrapper([
            thumbStyle(
                $this->thumb->class('mb-2 group2-hover:hidden')
            ),
            thumbStyle(
                $this->withActions(...$actions)->class('text-xl')
            )->class('mb-2 hidden group2-hover:flex'),

            $mini ? null : _Html($this->display)->class('text-xs font-semibold truncate'),

            $mini ? null : _Html($this->readableSize())->class('text-xs text-gray-700 font-bold'),

        ], $width)->balloon($this->display, 'right');

        $withMainAction = in_array('main', $actions);

        return in_array('main', $actions) ? $this->mainAction( $komponent ) : $komponent;
    }

    public function withActions()
    {
        $actionLinks = collect(func_get_args())->map(function($action){
                return $this->fileActions()[$action]();
            })->filter()->map(function($actionElement){
                return $actionElement->class('text-gray-700 hover:text-level3 border-gray-600 border rounded-lg p-1');
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
    public function getThumbAttribute()
    {
        return _Sax($this->file_type_enum->icon(), 24)->class('text-gray-700 flex justify-center items-center');
    }

    protected function getFileTypeEnumAttribute()
    {
        return FileTypeEnum::fromMimeType($this->mime_type);
    }
}
