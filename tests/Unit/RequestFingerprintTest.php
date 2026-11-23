<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Unit;

use Pharaonic\Fingerprint\Fingerprint;
use Pharaonic\Laravel\ApiIdempotency\Support\RequestFingerprint;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class RequestFingerprintTest extends TestCase
{
    public function test_the_storage_key_is_a_namespaced_sha256_of_its_parts(): void
    {
        $key = RequestFingerprint::key('user#1', 'POST', '/orders', 'abc');

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $key);
        $this->assertSame($key, RequestFingerprint::key('user#1', 'POST', '/orders', 'abc'));
        $this->assertNotSame($key, RequestFingerprint::key('user#2', 'POST', '/orders', 'abc'));
        $this->assertNotSame($key, RequestFingerprint::key('user#1', 'PUT', '/orders', 'abc'));
        $this->assertNotSame($key, RequestFingerprint::key('user#1', 'POST', '/orders/copy', 'abc'));
        $this->assertNotSame($key, RequestFingerprint::key('user#1', 'POST', '/orders', 'abd'));
        // Never the plain fingerprint of the same data: the namespace separates them.
        $this->assertNotSame($key, Fingerprint::make(['scope' => 'user#1', 'method' => 'POST', 'route' => '/orders', 'key' => 'abc']));
        // Joining parts differently can never collide.
        $this->assertNotSame(RequestFingerprint::key('a', 'POST', '/b', 'c'), RequestFingerprint::key('a/', 'POST', 'b', 'c'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function bodies(): array
    {
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
    }

    /**
     * @dataProvider bodies
     */
    public function test_json_bodies_compare_by_value_with_strict_types(string $first, string $second, bool $same): void
    {
        $this->assertSame($same, RequestFingerprint::request($this->jsonRequest($first)) === RequestFingerprint::request($this->jsonRequest($second)));
    }

    public function test_the_method_path_and_query_count_and_headers_do_not(): void
    {
        $base = RequestFingerprint::request($this->jsonRequest('{}'));

        $this->assertNotSame($base, RequestFingerprint::request($this->jsonRequest('{}', '/orders', 'PUT')));
        $this->assertNotSame($base, RequestFingerprint::request($this->jsonRequest('{}', '/orders/2')));
        $this->assertNotSame($base, RequestFingerprint::request($this->jsonRequest('{}', '/orders?x=1')));
        $this->assertSame($base, RequestFingerprint::request($this->jsonRequest('{}', '/orders', 'POST', ['HTTP_X_TRACE' => 'abc', 'HTTP_AUTHORIZATION' => 'Bearer other'])));
    }
}
