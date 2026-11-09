<?php

use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;

test('the cases keep the stored names', function () {
    $this->assertSame(['string', 'integer', 'float', 'boolean', 'array'], array_column(SettingType::cases(), 'value'));
    $this->assertSame(['strict', 'safe'], array_column(CastingPolicy::cases(), 'value'));
});

test('of names the type of a value', function () {
    $this->assertSame(SettingType::BOOLEAN, SettingType::of(false));
    $this->assertSame(SettingType::INTEGER, SettingType::of(0));
    $this->assertSame(SettingType::FLOAT, SettingType::of(1.5));
    $this->assertSame(SettingType::STRING, SettingType::of(''));
    $this->assertSame(SettingType::ARRAY, SettingType::of([]));
    $this->assertNull(SettingType::of(null));
    $this->assertNull(SettingType::of(new stdClass));
});

test('parse takes a case or its value', function () {
    $this->assertSame(SettingType::FLOAT, SettingType::parse(SettingType::FLOAT));
    $this->assertSame(SettingType::FLOAT, SettingType::parse('float'));
    $this->assertThrowsException(fn () => SettingType::parse('number'), InvalidArgumentException::class);
    $this->assertThrowsException(fn () => SettingType::parse('FLOAT'), InvalidArgumentException::class);
});

test('rule is the validation rule of the type', function () {
    $this->assertSame(
        ['string', 'integer', 'numeric', 'boolean', 'array'],
        array_map(fn (SettingType $type) => $type->rule(), SettingType::cases())
    );
});
