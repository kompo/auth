<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Models\Teams\PermissionInfoMediaTypeEnum;
use Kompo\Auth\Models\Teams\PermissionInfoSlide;

/**
 * Create / edit a single carousel slide. The media field toggles between an
 * image upload and a scribehow id depending on the selected media type.
 */
class PermissionInfoSlideForm extends Modal
{
    public $model = PermissionInfoSlide::class;

    public $_Title = 'auth-permission-slide';

    protected $hasSubmitButton = false;

    protected $permissionId;
    protected $refreshId;

    public function created()
    {
        $this->permissionId = $this->prop('permission_id');
        $this->refreshId = $this->prop('refresh_id');
    }

    public function beforeSave()
    {
        $this->model->permission_id = $this->permissionId;

        if (!$this->model->id) {
            $this->model->position = PermissionInfoSlide::where('permission_id', $this->permissionId)->count();
        }
    }

    public function body()
    {
        $type = (int) (request('media_type')
            ?: $this->model->media_type?->value
            ?: PermissionInfoMediaTypeEnum::IMAGE->value);

        return _Rows(
            _Select('auth-permission-media-type')->name('media_type')
                ->options(PermissionInfoMediaTypeEnum::optionsWithLabels())
                ->default($type)
                ->onChange(fn ($e) => $e->selfGet('getMediaFields')->inPanel('slide-media-fields')),

            _Panel($this->mediaFields($type))->id('slide-media-fields'),

            _TranslatableEditor('auth-permission-slide-caption')->name('caption'),

            _FlexEnd(
                _SubmitButton('generic.save')->closeModal()->refresh($this->refreshId),
            ),
        );
    }

    public function getMediaFields()
    {
        return $this->mediaFields((int) request('media_type'));
    }

    protected function mediaFields(int $type)
    {
        if ($type === PermissionInfoMediaTypeEnum::SCRIBE->value) {
            return _Input('auth-permission-scribe-id')->name('scribe_id');
        }

        return _Image('auth-permission-image')->name('image');
    }

    public function rules()
    {
        return [
            'media_type' => 'required|integer',
            'scribe_id' => 'nullable|string|max:255',
        ];
    }
}
