<?php

declare(strict_types=1);

use Pharaonic\Fingerprint\Fingerprint;
use Pharaonic\Laravel\ApiIdempotency\Support\RequestFingerprint;

test('the storage key is a namespaced sha256 of its parts', function () {
    $key = RequestFingerprint::key('user#1', 'POST', '/orders', 'abc');

    expect($key)->toMatch('/\A[0-9a-f]{64}\z/');
    expect(RequestFingerprint::key('user#1', 'POST', '/orders', 'abc'))->toBe($key);
    expect(RequestFingerprint::key('user#2', 'POST', '/orders', 'abc'))->not->toBe($key);
    expect(RequestFingerprint::key('user#1', 'PUT', '/orders', 'abc'))->not->toBe($key);
    expect(RequestFingerprint::key('user#1', 'POST', '/orders/copy', 'abc'))->not->toBe($key);
    expect(RequestFingerprint::key('user#1', 'POST', '/orders', 'abd'))->not->toBe($key);
    // Never the plain fingerprint of the same data: the namespace separates them.
    expect(Fingerprint::make(['scope' => 'user#1', 'method' => 'POST', 'route' => '/orders', 'key' => 'abc']))->not->toBe($key);
    // Joining parts differently can never collide.
    expect(RequestFingerprint::key('a/', 'POST', 'b', 'c'))->not->toBe(RequestFingerprint::key('a', 'POST', '/b', 'c'));
});

dataset('bodies', function () {
    return [
        'key order' => ['{"a":1,"b":{"c":2,"d":3}}', '{"b":{"d":3,"c":2},"a":1}', true],
        'whitespace' => ['{"a":[1,2]}', "{ \"a\" : [ 1 , 2 ] }\n", true],
        'unicode escapes' => ['{"name":"é"}', '{"name":"\\u00e9"}', true],
        'list order' => ['{"a":[1,2]}', '{"a":[2,1]}', false],
        'int and string' => ['{"a":1}', '{"a":"1"}', false],
        'int and float' => ['{"a":1}', '{"a":1.0}', false],
        'true and 1' => ['{"a":true}', '{"a":1}', false],
        'null and missing' => ['{"a":null}', '{}', false],
        'object and list' => ['{}', '[]', false],
        'numeric keys and list' => ['{"0":"x"}', '["x"]', false],
        'big integers' => ['{"a":12345678901234567890}', '{"a":12345678901234567891}', false],
        'float spellings' => ['{"a":1e2}', '{"a":100.0}', true],
    ];
});

test('json bodies compare by value with strict types', function (string $first, string $second, bool $same) {
    expect(RequestFingerprint::request($this->jsonRequest($first)) === RequestFingerprint::request($this->jsonRequest($second)))->toBe($same);
})->with('bodies');

test('the method path and query count and headers do not', function () {
    $base = RequestFingerprint::request($this->jsonRequest('{}'));

    expect(RequestFingerprint::request($this->jsonRequest('{}', '/orders', 'PUT')))->not->toBe($base);
    expect(RequestFingerprint::request($this->jsonRequest('{}', '/orders/2')))->not->toBe($base);
    expect(RequestFingerprint::request($this->jsonRequest('{}', '/orders?x=1')))->not->toBe($base);
    expect(RequestFingerprint::request($this->jsonRequest('{}', '/orders', 'POST', ['HTTP_X_TRACE' => 'abc', 'HTTP_AUTHORIZATION' => 'Bearer other'])))->toBe($base);
});
