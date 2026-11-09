<?php

namespace Pharaonic\Laravel\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Concerns\HasSettings;

final class Branch extends Model
{
    use HasSettings;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
