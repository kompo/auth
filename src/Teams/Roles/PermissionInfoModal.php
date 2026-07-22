<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionInfoMediaTypeEnum;
use Kompo\Auth\Models\Teams\PermissionInfoSlide;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Read-only info modal for a single permission: a media carousel on the left
 * (image/gif or scribehow guide per slide) and a panel on the right with the
 * Read / Write / Dependencies sections plus a contextual "your rights" banner.
 */
class PermissionInfoModal extends Modal
{
    public $model = Permission::class;

    public $class = 'overflow-y-auto mini-scroll max-w-4xl';

    protected $hasSubmitButton = false;

    protected $refreshId;
    protected $isReadOnly = false;

    public function created()
    {
        $this->refreshId = $this->prop('refresh_id');
        $this->isReadOnly = $this->prop('is_read_only');
    }

    public function title()
    {
        return _Rows(
            _Html($this->model->permission_name ?: $this->model->permission_key)
                ->class('!text-black text-xl sm:text-2xl font-semibold'),
            _Html($this->model->permission_key)->class('text-xs text-gray-400 mt-1'),
        );
    }

    public function body()
    {
        $slides = $this->model->slides;

        return _Rows(
            $slides->isEmpty()
                ? $this->infoPanel()
                : _Flex(
                    $this->mediaCarousel($slides)->class('w-full md:w-1/2'),
                    $this->infoPanel()->class('w-full md:w-1/2'),
                )->class('gap-6 !items-stretch flex-col md:flex-row'),

            !isAppSuperAdmin() || $this->isReadOnly ? null : _FlexEnd(
                _Link('crm.edit')->icon('edit')->class('mt-4 text-sm text-gray-500')
                    ->selfGet('getEditPermissionInfoForm')->inModal(),
            ),
        );
    }

    protected function infoPanel()
    {
        return _Rows(
            $this->sectionCard('auth-permission-read', $this->model->permission_description_read, 'text-info', 'bg-info'),
            $this->sectionCard('auth-permission-write', $this->model->permission_description_write, 'text-warning', 'bg-warning'),
            $this->dependenciesCard(),
            $this->yourRightsCard(),
        )->class('gap-3 overflow-y-auto mini-scroll pr-1')->style('max-height: 60vh;');
    }

    protected function sectionCard(string $label, ?string $text, string $labelClass, string $bgClass)
    {
        return _Rows(
            _Html($label)->class('text-xs font-bold uppercase tracking-wider mb-1 ' . $labelClass),
            _Html($text ?: '—')->class('text-sm text-gray-700 ck ck-content'),
        )->class('p-4 rounded ' . $bgClass . ' bg-opacity-10');
    }

    protected function dependenciesCard()
    {
        $deps = $this->model->dependencies;

        if ($deps->isEmpty()) {
            return null;
        }

        return _Rows(
            _Html('auth-permission-dependencies')->class('text-xs font-bold uppercase tracking-wider mb-2 text-gray-600'),
            _Flex(
                $deps->map(fn ($dep) => _Html($dep->permission_name ?: $dep->permission_key)
                    ->class('text-xs px-2 py-1 rounded bg-gray-500 bg-opacity-10 text-gray-700 border border-gray-300')),
            )->class('gap-2 flex-wrap'),
        )->class('p-4 rounded bg-gray-500 bg-opacity-5');
    }

    protected function yourRightsCard()
    {
        $user = auth()->user();
        $key = $this->model->permission_key;

        $canWrite = (bool) $user?->hasPermission($key, PermissionTypeEnum::WRITE);
        $canRead = $canWrite || (bool) $user?->hasPermission($key, PermissionTypeEnum::READ);

        [$label, $bg, $text] = match (true) {
            $canWrite => [__('auth-permission-your-rights-write'), 'bg-green-500', 'text-green-600'],
            $canRead => [__('auth-permission-your-rights-read'), 'bg-blue-500', 'text-blue-600'],
            default => [__('auth-permission-your-rights-none'), 'bg-red-500', 'text-red-600'],
        };

        return _Rows(
            _Html('auth-permission-your-rights')->class('text-xs font-bold uppercase tracking-wider mb-1 text-gray-600'),
            _Html($label)->class('text-sm font-semibold ' . $text),
        )->class('p-4 rounded ' . $bg . ' bg-opacity-10');
    }

    protected function mediaCarousel(Collection $slides)
    {
        $slides = $slides->values();

        if ($slides->count() === 1) {
            return $this->slidePanel($slides->first());
        }

        $field = 'active_slide';
        $count = $slides->count();
        $dotsId = 'permission-carousel-dots-' . $this->model->id;

        return _Rows(
            _JsComponentWhen($field,
                $slides->mapWithKeys(fn (PermissionInfoSlide $slide, $i) => ['s' . ($i + 1) => $this->slidePanel($slide)])->toArray(),
                $this->slidePanel($slides->first()),
            ),

            $this->slideControls($field, $count, $dotsId),

            $this->carouselNavScript(),
        )->class('flex flex-col');
    }

    protected function slideControls(string $field, int $count, string $dotsId)
    {
        return _Flex(
            $this->navArrow('arrow-left-2', $dotsId, -1),
            $this->slideDots($field, $count, $dotsId),
            $this->navArrow('arrow-right-2', $dotsId, 1),
        )->class('mt-3 items-center justify-center gap-2');
    }

    protected function navArrow(string $icon, string $dotsId, int $direction)
    {
        return _Link()->icon(_Sax($icon, 20))
            ->class('sisc-carousel-nav cursor-pointer select-none text-gray-400')
            ->run("() => window.siscCarouselMove('{$dotsId}', {$direction})");
    }

    protected function slidePanel(PermissionInfoSlide $slide)
    {
        return _Rows(
            $this->slideMedia($slide),
            !$slide->caption ? null : _Html($slide->caption)->class('text-sm text-gray-700 mt-3 ck ck-content'),
        );
    }

    protected function slideDots(string $field, int $count, string $dotsId)
    {
        return _ButtonGroup()->name($field, false)->default('s1')
            ->id($dotsId)
            ->noInputWrapper()
            ->options(collect(range(1, $count))->mapWithKeys(fn ($i) => [
                's' . $i => _Html('●')->class('sisc-carousel-dot block leading-none text-xs'),
            ])->toArray())
            ->class('sisc-carousel-dots !mb-1')
            ->containerClass('inline-flex items-center justify-center gap-2')
            ->optionClass('cursor-pointer text-center')
            ->selectedClass('text-info', 'text-gray-300');
    }

    protected function carouselNavScript()
    {
        // Prev/next arrows just re-click the matching dot, so the slide swap and
        // the selected state reuse the exact ButtonGroup path. Registered once,
        // globally, via a hidden carrier's onLoad (no inline handler).
        return _Hidden()->onLoad(fn ($e) => $e->run('() => {
            window.siscCarouselMove = window.siscCarouselMove || function (containerId, dir) {
                var cont = document.getElementById(containerId);
                if (!cont) return;
                var opts = cont.querySelectorAll(".vlOption");
                if (!opts.length) return;
                var current = 0;
                for (var i = 0; i < opts.length; i++) {
                    if (opts[i].classList.contains("vlSelected")) { current = i; break; }
                }
                var next = (current + dir + opts.length) % opts.length;
                opts[next].click();
            };
        }'));
    }

    protected function slideMedia(PermissionInfoSlide $slide)
    {
        if ($slide->media_type === PermissionInfoMediaTypeEnum::SCRIBE && $slide->scribe_id) {
            return _Iframe()->src($slide->scribeEmbedUrl())
                ->class('w-full rounded-lg')->style('height: 420px;');
        }

        $url = $slide->mediaUrl();

        if (!$url) {
            return _Div(
                _Html('auth-permission-no-media')->class('text-gray-400 text-sm'),
            )->class('w-full h-48 rounded-lg bg-gray-100 flex items-center justify-center');
        }

        return _Rows(
            _Img($url)->class('w-full rounded-lg object-contain')->style('max-height: 420px;'),
        )->href($url)->inNewTab()->class('block cursor-zoom-in');
    }

    public function getEditPermissionInfoForm()
    {
        return new EditPermissionInfo($this->model->id, [
            'refresh_id' => $this->refreshId,
        ]);
    }
}
