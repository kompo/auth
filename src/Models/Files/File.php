<?php

namespace Kompo\Auth\Models\Files;

use Carbon\Carbon;
use Kompo\Auth\Files\FileLibraryAttachmentQuery;
use Kompo\Auth\Models\Contracts\Searchable;
use Kompo\Auth\Models\Files\FileVisibilityEnum;
use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Tags\MorphToManyTagsTrait;
use Kompo\Auth\Models\Teams\BelongsToTeamTrait;
use Kompo\Auth\Models\Traits\BelongsToUserTrait;
use Kompo\Auth\Models\Traits\HasSearchableNameTrait;
use Kompo\Core\FileHandler;
use Intervention\Image\Facades\Image;

class File extends Model implements Searchable
{
    use BelongsToTeamTrait;
    use BelongsToUserTrait;
    use MorphToManyTagsTrait;

    use FileActionsKomponents;

    use HasSearchableNameTrait;

    public $fileType = 'file';

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

        if (array_key_exists('tags_and', $filters) && $tags = $filters['tags_and']) {
            foreach ($tags as $tagId) {
                $query = $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
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

    public function scopeForSubtype($query, $subtype)
    {
        $query->where('subtype', $subtype);
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

    public static function getFileTypeRawQuery()
    {
        $rawCaseQuery = 'CASE ';
		
		foreach (FileTypeEnum::cases() as $case) {
			$mimeTypes = collect($case->mimeTypes())->map(function ($mime) {
				return "'$mime'";
			})->implode(',');

			if ($mimeTypes) {
				$rawCaseQuery .= "WHEN mime_type IN ({$mimeTypes}) THEN {$case->value} ";
			}
		}

		$rawCaseQuery .= 'ELSE ' .  FileTypeEnum::UNKNOWN->value . ' END';

        return $rawCaseQuery;
    }

    public static function uploadMultipleFiles($files, $fileableType = null, $fileableId = null, $tags = [])
    {
        $fileHandler = new FileHandler();

        $fileHandler->setDisk('public'); // TODO: make this configurable

        collect($files)->map(function ($uploadedFile) use ($fileHandler, $fileableId, $fileableType, $tags) {

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

            if($tags && count($tags)) $file->tags()->sync($tags);

            return $file->id;
        });
    }

    public function resizeImage($width, $height)
    {
        $file = \Storage::disk($this->disk ?? 'public')->get($this->path);
        $format = pathinfo($this->path, PATHINFO_EXTENSION);

        $image = Image::make($file)->resize($width, $height, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->orientate()->encode($format);

        \Storage::disk($this->disk ?? 'public')->put($this->path, $image);
        \Storage::disk($this->disk ?? 'public')->setVisibility($this->path, 'public');
    }

    /* ELEMENTS */
    public function uploadedAt()
    {
        return _Html($this->created_at->translatedFormat('d M Y'))->class('text-gray-500 text-xs whitespace-nowrap');
    }

    public static function emptyPanel()
    {
        return _DashedBox('files-text-click-on-a-file', 'py-20 text-lg px-4');
    }

    public static function typesOptions()
    {
        return config('kompo-files.types');
    }

    public static function formattedTypesOptions()
    {
        return collect(static::typesOptions())->mapWithKeys(
            fn($label, $value) => [$value => ucfirst($label[0])]
        );
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
                    _Input()->icon('icon-search')->placeholder('general-search')
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

    protected static function yearlyMonthlyLinkGroup()
    {
        if ($year = request('year')) {
            return _Flex4(
                _Link(__('general-year').' '.$year)->class('text-greenmain font-bold')->icon('arrow-left')
                    ->getElements('getYearsMonthsFilter')->inPanel('file-manager-year-month-filter'),
                _LinkGroup()->name('month', false)->class('mb-0')
                    ->options(
                        static::getFilesCountFor($year)->mapWithKeys(fn($stat) => [
                            $stat->label => static::yearMonthOption(Carbon::createFromFormat('Y-m-d', $stat->label.'-01')->translatedFormat('M'), $stat->cn)
                        ])
                    )->selectedClass('text-level3 border-b-2 border-level3', 'text-level3 border-b-2 border-transparent')
                    ->filter()
            )->class('mb-4');
        }

        return _Flex4(
            _Html('general-filter-by-year')->class('text-greenmain font-medium'),
            _LinkGroup()->name('year', false)->class('mb-0')
                ->options(
                    static::getFilesCountFor()->mapWithKeys(fn($stat) => [
                        $stat->label => static::yearMonthOption($stat->label, $stat->cn)
                    ])
                )->selectedClass('text-greenmain border-b-2 border-greenmain', 'text-greenmain border-b-2 border-transparent')
                ->filter()
                ->onSuccess(fn($e) => $e->getElements('getYearsMonthsFilter')->inPanel('file-manager-year-month-filter'))
        )->class('mb-4');
    }

    protected static function getFilesCountFor($year = null)
    {
        $labelFunc = $year ? 'LEFT(created_at,7)' : 'YEAR(created_at)';

        $query = static::selectRaw($labelFunc.' as label, COUNT(*) as cn')->where('team_id', currentTeam()?->id)
            ->groupByRaw($labelFunc)->orderByRaw($labelFunc.' DESC');

        return ($year ? $query->whereRaw('YEAR(created_at) = ?', [$year]) : $query )->get();
    }

    protected static function yearMonthOption($label, $count)
    {
        return _Html($label.' <span class="text-xs text-gray-600">('.$count.')</span>')->class('font-bold cursor-pointer mr-4');
    }

    protected static function buttonGroup($name, $interactsWithModel = true)
    {
        return _ButtonGroup()->name($name, $interactsWithModel)
            ->class('mb-0')
            ->containerClass('row no-gutters')
            ->optionClass('w-20 lg:w-24')
            ->selectedClass('bg-info text-greenmain', 'text-greenmain')
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

    public static function fileUploadLinkAndBox($name, $toggleOnLoad = true, $fileIds = [])
    {
        $panelId = 'file-upload-'.uniqid();

        return [

            _Flex(
                _Link()->icon(_Sax('paperclip-2'))->class('text-greenmain text-2xl')
                    ->balloon('files-attach-files', 'up')
                    ->toggleId($panelId, $toggleOnLoad),
                _Html()->class('text-xs text-gray-600 font-semibold')->id('file-size-div')
            ),

            _Rows(
                _FlexBetween(
                    _MultiFile()->placeholder('files-browse-files')->name($name)
                        ->extraAttributes([
                            'team_id' => currentTeam()->id,
                        ])->class('mb-0 w-full md:w-5/12')
                        ->id('email-attachments-input')->run('calculateTotalFileSize'),
                    _Html('or')
                        ->class('text-sm text-gray-600 my-2 md:my-0'),
                    FileLibraryAttachmentQuery::libraryFilesPanel($fileIds)
                        ->class('w-full md:w-5/12'),
                )->class('flex-wrap'),
                _Html('files-your-files-exceed-max-size')
                    ->class('hidden text-danger text-xs')->id('file-size-message')
            )->class('mx-2 dashboard-card p-2 space-x-2')
            ->id($panelId)

        ];
    }

    public function linkEl()
    {
        return _Link($this->name)->class('mt-1 -mr-2')->col('col-md-3')
            ->icon('arrow-down');
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
