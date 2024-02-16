<?php

namespace App\Models\Library;

use App\View\File\FileLibraryAttachmentQuery;
use Kompo\Auth\Models\Contracts\Searchable;
use Kompo\Auth\Models\Files\FileVisibilityEnum;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BelongsToTeamTrait;
use Kompo\Auth\Models\Traits\BelongsToUserTrait;
use Kompo\Auth\Models\Traits\HasSearchableNameTrait;
use Kompo\Core\FileHandler;

class File extends Model implements Searchable
{
    use BelongsToTeamTrait;
    use BelongsToUserTrait;

    use HasSearchableNameTrait;
    public const SEARCHABLE_NAME_ATTRIBUTE = 'name';

    protected $casts = [
        'visibility' => FileVisibilityEnum::class,
    ];

    public function save(array $options = [])
    {
        $this->setTeamId();
        $this->setUserId();

        parent::save();
    }

    /* RELATIONS */
    public function fileable()
    {
        return $this->morphTo();
    }

    /* SCOPES */
    public function scopeHasFilename($query, $name = '')
    {
        return $query->where(
            fn($q) => $q->where('name', 'LIKE', wildcardSpace($name))
        );
    }

    public function scopeFileAttachedTo($query, $parentId, $parentType)
    {
        return $query->where('parent_type', $parentType)->where('parent_id', $parentId);
    }

    public function scopeGetLibrary($query, $filters = [])
    {
        $query = $query->with('fileable')->where('team_id', currentTeamId())->orderByDesc('created_at');

        if(array_key_exists('fileable_type', $filters) && $fileableType = $filters['fileable_type']) {
            $query = $query->where('fileable_type', $fileableType);
        }

        if(array_key_exists('filename', $filters)) {
            $query = $query->hasFilename($filters['filename']);
        }

        if (array_key_exists('mime_type', $filters) && $mimeType = $filters['mime_type']) {
            $mimeTypes = $this->iconMimeTypes();

            if($mimeType == 'far fa-file-alt'){ //other

                $excludeValues = collect($mimeTypes)->flatMap(fn($types) => $types)->toArray();

                $query = $query->whereNotIn('mime_type', $excludeValues);


            }else{

                $values = $mimeTypes[$mimeType];

                $query = $query->whereIn('mime_type', $values);

            }
        }

        if(array_key_exists('year', $filters) && $year = $filters['year']) {
            $query = $query->whereRaw('YEAR(created_at) = ?', [$year]);
        }

        if(array_key_exists('month', $filters) && $yearMonth = $filters['month']) {
            $query = $query->whereRaw('LEFT(created_at, 7) = ?', [$yearMonth]);
        }

        return $query;
    }

    /* ATTRIBUTES */

    public function getDisplayFlAttribute()
    {
        return $this->name;
    }

    public function getLinkAttribute()
    {
        return \Storage::url($this->path);
    }


    /* ACTIONS */
    public function delete()
    {

        parent::delete();
    }

    public static function uploadMultipleFiles($files, $fileableType = null, $fileableId = null)
    {
        $fileHandler = new FileHandler();

        $fileHandler->setDisk('public'); // TODO: make this configurable

        collect($files)->map(function ($uploadedFile) use ($fileHandler, $fileableId, $fileableType) {

            $file = new File();

            foreach ($fileHandler->fileToDB($uploadedFile, new File()) as $key => $value) {
                $file->{$key} = $value;
            }

            if ($fileableId && $fileableType) {
                $file->fileable_id = $fileableId;
                $file->fileable_type = $fileableType;
            }

            $file->team_id = currentTeam()->id;

            $file->save();

            return $file->id;
        });
    }

    /* ELEMENTS */
    public function uploadedAt()
    {
        return _Html($this->created_at->translatedFormat('d M Y'))->class('text-gray-500 text-xs whitespace-nowrap');
    }

    public static function emptyPanel()
    {
        return _DashedBox('file.text-click-on-a-file', 'py-20 text-lg px-4');
    }

    public static function typesOptions()
    {

        return config('kompo-files.types');
    }

    public static function fileFilters($titleKompo, $more = null)
    {
        return _Rows(
            _Flex(
                $titleKompo,

                $more,
            ),
            _Rows(

                _Rows(
//                    _TitleMini('attached-to')->class('mr-4'),
                    static::buttonGroup('parent_type_bis', false)
                        ->options(
                            collect(static::typesOptions())->mapWithKeys(
                                fn($label, $value) => static::selectOption($value, $label[0], $label[1])
                            )
                        )->selectedClass('!bg-info text-white', '')
                )->class('mb-4'),


            _Columns(
                _Rows(
                )->class('mb-4'),
                _Rows(
                    _Input()->icon('icon-search')->placeholder('library.search')
                        ->name('name', false)
                        ->class('mb-0')
                        ->type('search')
                        ->filter(),
                )->class('mb-4'),
            ),
            _Panel(
                static::yearlyMonthlyLinkGroup()
            )->id('file-manager-year-month-filter'),
            )->class('card bg-white rounded-2xl shadow-lg pt-6 pb-0 px-6 mb-8'),
        );
    }

    protected static function getFilesCountFor($year = null)
    {
        $labelFunc = $year ? 'LEFT(created_at,7)' : 'YEAR(created_at)';

        $query = static::selectRaw($labelFunc.' as label, COUNT(*) as cn')->where('team_id', currentTeam()->id)
            ->groupByRaw($labelFunc)->orderByRaw($labelFunc.' DESC');

        return ($year ? $query->whereRaw('YEAR(created_at) = ?', [$year]) : $query )->get();
    }

    protected static function buttonGroup($name, $interactsWithModel = true)
    {
        return _ButtonGroup()->name($name, $interactsWithModel)
            ->class('mb-0')
            ->containerClass('row no-gutters')
            ->optionClass('w-20 lg:w-24')
            ->selectedClass('bg-info text-level1', 'text-level1')
            ->filter();
    }

    protected static function selectOption($value, $label, $icon = null, $iconSvg = true)
    {
        $icon = $icon ? ($iconSvg ? _Sax($icon) : _I()->class($icon)) : null;

        $label = _HtmlSax('<br><div class="truncate">'.$label.'</div>')
            ->class('justify-center p-2 text-xs font-bold cursor-pointer');

        return [
            $value => _Rows(
                $icon ? $label->icon($icon->class('text-lg')) : $label
            )
        ];
    }

    /* SEARCHS */
    public function scopeSearch($query, $search)
    {
        return $query->forTeam(currentTeamId())
            ->searchName($search);
    }

    public function searchElement($file, $search)
    {
        return _SearchResult(
            $search,
            $file->display_fl,
            [
                $file->uploadedAt(),
            ],
        )->redirect('file.page', ['id' => $file->id]);
    }
}
