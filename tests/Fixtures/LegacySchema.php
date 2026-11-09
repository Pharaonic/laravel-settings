<?php

namespace Pharaonic\Laravel\Settings\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The settings table of pharaonic/laravel-settings 1.x, as its migration
 * created it, in place of the 2 table.
 */
final class LegacySchema
{
    public static function create(): void
    {
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('value')->nullable();
            $table->string('settingable_type')->nullable();
            $table->string('settingable_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function insert(array $row): void
    {
        DB::table('settings')->insert($row + [
            'value' => null,
            'settingable_type' => null,
            'settingable_id' => null,
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]);
    }
}
