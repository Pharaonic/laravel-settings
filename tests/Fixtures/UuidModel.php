<?php

namespace Pharaonic\Laravel\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Concerns\HasSettings;

final class UuidModel extends Model
{
    use HasSettings;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
