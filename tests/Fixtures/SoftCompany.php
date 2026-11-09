<?php

namespace Pharaonic\Laravel\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\Settings\Concerns\HasSettings;

final class SoftCompany extends Model
{
    use HasSettings;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
