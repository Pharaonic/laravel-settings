<?php

namespace Pharaonic\Laravel\Settings\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as Builder;

/**
 * The tables of the fixture models.
 */
final class Schema
{
    public static function create(): void
    {
        Builder::create('companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Builder::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });

        Builder::create('soft_companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->softDeletes();
        });

        Builder::create('uuid_models', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->timestamps();
        });
    }
}
