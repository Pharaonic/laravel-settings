<?php

namespace Pharaonic\Laravel\Settings\Classes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pharaonic\Laravel\Settings\Models\Settings as ModelsSettings;

/**
 * @property Model|null $model
 * @property Collection $data
 *
 * @author Moamen Eltouny (Raggi) <raggi@raggitech.com>
 */
final class Settings
{
    /**
     * Model Object.
     *
     * @var Model|null
     */
    private $model;

    /**
     * Settings data list.
     *
     * @var Collection|null
     */
    private $data;

    /**
     * Create a new instance.
     *
     * @param Model|null $model
     * @param Collection|null $data
     */
    public function __construct(Model $model = null, Collection $data = null)
    {
        if ($model) {
            $this->model = $model;
            $this->data = $data;
        } else {
            $this->data = ModelsSettings::whereNull('settingable_type')->get()->keyBy('name');
        }
    }

    /**
     * Getting record value
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if (!isset($this->data[$name])) return;

        return $this->data[$name]->value;
    }

    /**
     * Setting key/value information
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set(string $name, $value = null)
    {
        $this->set($name, $value);
    }

    /**
     * Getting record value
     *
     * @param mixed ...$names
     * @return Collection
     */
    public function get(...$names)
    {
        if (empty($names)) return [];
        if (is_array($names[0])) $names = $names[0];

        $data = $this->data->map(function ($value, $name) use ($names) {
            if (in_array($name, $names)) return $value;
            return null;
        })->filter(function ($value) {
            return !is_null($value);
        });

        return count($names) == 1 ? $data->first() : $data;
    }

    /**
     * Setting key/value information
     *
     * @param mixed ...$values
     * @return void
     */
    public function set(...$values)
    {
        if (empty($values)) return;

        if (is_array($values[0])) {
            // Multi
            foreach ($values[0] as $name => $value)
                $this->set($name, $value);
        } elseif (is_string($values[0])) {
            // Single
            if ($this->data->has($values[0])) {
                $this->data[$values[0]]->value = $values[1] ?? null;
            } else {
                $obj = new ModelsSettings;
                $obj->name  = $values[0];
                $obj->value = $values[1] ?? null;

                if ($this->model) {
                    $obj->settingable_type    = get_class($this->model);
                    $obj->settingable_id      = $this->model->getKey();
                }

                $this->data->put($values[0], $obj);
            }
        }
    }

    /**
     * Save all the dirty objects.
     *
     * @return void
     */
    public function save()
    {
        foreach ($this->data as $obj)
            if ($obj->isDirty())
                $obj->save();
    }
}
