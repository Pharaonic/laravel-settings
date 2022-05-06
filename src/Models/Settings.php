<?php

namespace Pharaonic\Laravel\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id
 * @property string $name
 * @property string|null $value
 * @property string|null $modelable_type
 * @property string|null $modelable_id
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

    /**
     * Getting string/array value
     *
     * @return string|array|null
     */
    protected function getValueAttribute()
    {
        $value = $this->getAttributes()['value'];

        if ($value && substr($value, 0, 1) == '{')
            return json_decode($value);

        return $value;
    }

    /**
     * Setting string/array value
     * 
     * @param string|array|null $value
     * @return void
     */
    protected function setValueAttribute($value)
    {
        if (is_array($value))
            $value = json_encode($value);

        $this->attributes['value'] = $value;
    }
}
