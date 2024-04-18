<?php----*******************------------

namespace Kompo\Auth\Models\Contracts;

interface Searchable
{
    public function scopeSearch($query, $search);

    public function searchElement($result, $search);
}
