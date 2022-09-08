<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Lesson extends Model
{
    protected $guarded = [];
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany('App\Group', 'lesson_groups');
    }

}
