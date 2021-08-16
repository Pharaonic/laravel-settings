<?php

namespace Pharaonic\Laravel\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Translatable\Translatable;
use Pharaonic\Laravel\Users\Traits\HasPermissions;

/**
 * @property integer $id
 * @property string $name
 * @property string|null $value
 * @property string $modelable_type
 * @property integer $modelable_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @author Moamen Eltouny (Raggi) <raggi@raggitech.com>
 */
class Settings extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'value', 'modelable_type', 'modelable_id'];
}
