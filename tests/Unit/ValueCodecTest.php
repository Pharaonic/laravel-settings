<?php

use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Support\ValueCodec;

dataset('value_codec_values', function () {
    return [
        [null, 'null'],
        [true, 'true'],
        [0, '0'],
        [-12, '-12'],
        [1.0, '1.0'],
        [0.1, '0.1'],
        ['', '""'],
        ['مرحبا', '"مرحبا"'],
        ['{"a":1}', '"{\"a\":1}"'],
        [[], '[]'],
        [[1, 2], '[1,2]'],
        [['a' => ['b' => null]], '{"a":{"b":null}}'],
        [[1 => 'x'], '{"1":"x"}'],
    ];
});

test('every supported value round trips exactly', function (mixed $value, string $json) {
    $this->assertSame($json, ValueCodec::encode($value, 'k', 's'));
    $this->assertSame($value, ValueCodec::decode($json, 'k', 's'));
})->with('value_codec_values');

test('stored text that is not json is refused without echoing it', function () {
    try {
        ValueCodec::decode('PSENC1:secret', 'api.token', 'tenant');
        $this->fail('No exception was thrown.');
    } catch (CorruptSettingValueException $exception) {
        $this->assertStringContainsString('[api.token]', $exception->getMessage());
        $this->assertStringNotContainsString('secret', $exception->getMessage());
    }
});
