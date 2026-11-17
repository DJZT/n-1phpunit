<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class Setting extends Model
{
    public $timestamps = false;

    protected $table = 'settings';
}
