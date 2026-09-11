<?php

use Workflow\Support\CustomFieldDefinitionParser;

beforeEach(function () {
    $this->parser = new CustomFieldDefinitionParser;
});

it('parses a literal PHP array, including nested arrays', function () {
    $result = $this->parser->parse("['tab' => 'Details', 'options' => ['a' => 'A', 'b' => 'B']]");

    expect($result)->toBe(['tab' => 'Details', 'options' => ['a' => 'A', 'b' => 'B']]);
});

it('parse() returns null for an empty string', function () {
    expect($this->parser->parse(''))->toBeNull();
    expect($this->parser->parse('   '))->toBeNull();
});

it('validate() returns null for a valid literal array', function () {
    expect($this->parser->validate("['tab' => 'Details']"))->toBeNull();
});

it('validate() returns null for an empty string (nothing to validate)', function () {
    expect($this->parser->validate(''))->toBeNull();
});

it('rejects anything that is not a pure literal array expression', function (string $payload) {
    expect($this->parser->parse($payload))->toBeNull();
    expect($this->parser->validate($payload))->not->toBeNull();
})->with([
    'function call' => "['pwned' => shell_exec('id')]",
    'variable reference' => "['pwned' => \$_SERVER]",
    'string interpolation' => '["pwned" => "$_SERVER[HTTP_HOST]"]',
    'static method call' => "['pwned' => \\Illuminate\\Support\\Str::random()]",
    'string concatenation' => "['pwned' => 'a' . 'b']",
    'not an array at all' => "'just a string'",
    'invalid syntax' => "['unterminated",
]);
