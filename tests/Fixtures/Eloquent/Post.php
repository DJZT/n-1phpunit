<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Post extends Model
{
    public $timestamps = false;

    protected $table = 'posts';

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
