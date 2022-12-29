<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    protected $guarded = [];
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany('App\Group', 'course_groups');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany('App\User', 'course_teachers');
    }

}
