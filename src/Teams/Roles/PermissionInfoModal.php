<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Illuminate\Support\Collection;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionInfoMediaTypeEnum;
use Kompo\Auth\Models\Teams\PermissionInfoSlide;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Read-only info modal for a single permission key.
 *
 * Layout (the "media-left" variant): a media carousel on the left (uploaded
 * images/gifs or scribehow guides, one per slide, each with a caption) and a
 * vertically scrollable panel on the right with Read / Write / Dependencies
 * sections plus a contextual "your rights" banner. When the permission has no
 * slides, the right panel takes the full width.
 */
class PermissionInfoModal extends Modal
{
    public $model = Permission::class;

    public $class = 'overflow-y-auto mini-scroll max-w-4xl';

    protected $hasSubmitButton = false;

    protected $refreshId;

    public function created()
    {
        $this->refreshId = $this->prop('refresh_id');
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

            !isAppSuperAdmin() ? null : $this->editLink(),
        );
    }

    /* ── RIGHT PANEL ── */

    protected function infoPanel()
    {
        return _Rows(
            $this->sectionCard(__('auth-permission-read'), $this->model->permission_description_read, 'text-info', 'bg-info'),
            $this->sectionCard(__('auth-permission-write'), $this->model->permission_description_write, 'text-warning', 'bg-warning'),
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
            _Html(__('auth-permission-dependencies'))->class('text-xs font-bold uppercase tracking-wider mb-2 text-gray-600'),
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
            _Html(__('auth-permission-your-rights'))->class('text-xs font-bold uppercase tracking-wider mb-1 text-gray-600'),
            _Html($label)->class('text-sm font-semibold ' . $text),
        )->class('p-4 rounded ' . $bg . ' bg-opacity-10');
    }

    /* ── LEFT CAROUSEL (the active slide is held in a client-side field; slides swap via jsShowWhen) ── */

    protected function mediaCarousel(Collection $slides)
    {
        $slides = $slides->values();
        $count = $slides->count();
        $field = 'active_slide';

        return _Rows(
            _JsComponentWhen($field,
                $slides->mapWithKeys(fn (PermissionInfoSlide $slide, $i) => [$i => $this->slidePanel($slide)])->toArray(),
            ),

            $count <= 1 ? null : $this->slideDots($field, $count),
        )->class('flex flex-col');
    }

    protected function slidePanel(PermissionInfoSlide $slide)
    {
        return _Rows(
            $this->slideMedia($slide),
            !$this->slideCaption($slide) ? null : _Html($this->slideCaption($slide))
                ->class('text-sm text-gray-700 mt-3 ck ck-content'),
        );
    }

    /**
     * Dot navigation as a ButtonGroup bound to the active-slide field; picking a
     * dot swaps the visible slide through jsShowWhen — no imperative JS.
     */
    protected function slideDots(string $field, int $count)
    {
        return _ButtonGroup()->name($field)
            ->default('0')
            ->options(collect(range(0, $count - 1))->mapWithKeys(fn ($i) => [(string) $i => ''])->toArray())
            ->class('flex items-center justify-center gap-2 mt-3')
            ->optionClass('perm-dot cursor-pointer w-3 h-3 rounded-full !p-0 !min-w-0 !border-0 !shadow-none bg-gray-300')
            ->selectedClass('!bg-info', '!bg-gray-300');
    }

    protected function slideMedia(PermissionInfoSlide $slide)
    {
        if ($slide->media_type === PermissionInfoMediaTypeEnum::SCRIBE && $slide->scribe_id) {
            return _Html($this->scribeIframeHtml((string) $slide->scribeEmbedUrl()))->class('w-full');
        }

        $url = $slide->mediaUrl();

        if (!$url) {
            return _Div(
                _Html(__('auth-permission-no-media'))->class('text-gray-400 text-sm'),
            )->class('w-full h-48 rounded-lg bg-gray-100 flex items-center justify-center');
        }

        return _Div(
            _Img($url)->class('w-full rounded-lg object-contain')->style('max-height: 420px; cursor: zoom-in;'),
        )->style('cursor: zoom-in;')->onClick(fn ($e) => $e->run($this->lightboxJs($url)));
    }

    /** Click-to-zoom: a full-screen overlay with the image; click or Escape closes it. */
    protected function lightboxJs(string $url): string
    {
        return '() => {
            const o = document.createElement("div");
            o.style.cssText = "position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;cursor:zoom-out;padding:2rem;";
            const img = document.createElement("img");
            img.src = ' . json_encode($url) . ';
            img.style.cssText = "max-width:92vw;max-height:92vh;border-radius:.5rem;box-shadow:0 10px 40px rgba(0,0,0,.5);";
            o.appendChild(img);
            const close = () => { o.remove(); document.removeEventListener("keydown", onKey); };
            const onKey = (ev) => { if (ev.key === "Escape") close(); };
            o.addEventListener("click", close);
            document.addEventListener("keydown", onKey);
            document.body.appendChild(o);
        }';
    }

    protected function slideCaption(PermissionInfoSlide $slide): ?string
    {
        return $slide->caption ?: null;
    }

    /**
     * Minimal scribehow embed (iframe + spinner that fades on load). Replicated
     * locally on purpose so kompo/auth does not depend on the cms package.
     */
    protected function scribeIframeHtml(string $embedUrl): string
    {
        $domId = uniqid('perm-scribe-');
        $spinner = _Spinner()->__toHtml();
        $src = htmlspecialchars($embedUrl, ENT_QUOTES);

        return '<div>'
            . '<div id="loading-' . $domId . '" style="display:flex;justify-content:center;padding:40px 0;">' . $spinner . '</div>'
            . '<iframe id="iframe-' . $domId . '" src="' . $src . '" width="100%" frameborder="0" height="420" style="border-radius:0.5rem;"></iframe>'
            . '</div>'
            . '<script>(function(){var s=document.getElementById("loading-' . $domId . '"),f=document.getElementById("iframe-' . $domId . '");if(!f||!s)return;f.addEventListener("load",function(){s.style.display="none";});})();</script>';
    }

    /* ── ADMIN ── */

    protected function editLink()
    {
        return _FlexEnd(
            _Link('crm.edit')->icon('edit')->class('mt-4 text-sm text-gray-500')
                ->selfGet('getEditPermissionInfoForm')->inModal(),
        );
    }

    public function getEditPermissionInfoForm()
    {
        return new EditPermissionInfo($this->model->id, [
            'refresh_id' => $this->refreshId,
        ]);
    }
}
