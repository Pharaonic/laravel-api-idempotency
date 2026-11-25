<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Pharaonic\Laravel\ApiIdempotency\ApiIdempotencyServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;
use RuntimeException;

final class TranslationTest extends TestCase
{
    /**
     * @return list<IdempotencyException>
     */
    private function exceptions(): array
    {
        return [
            IdempotencyException::keyMissing(),
            IdempotencyException::keyInvalid(),
            IdempotencyException::keyConflict(),
            IdempotencyException::inProgress(5),
            IdempotencyException::outcomeUnknown(),
            IdempotencyException::unauthenticated(),
            IdempotencyException::unsupportedRequest(),
            IdempotencyException::invalidRequest(),
            IdempotencyException::storeUnavailable(new RuntimeException('down')),
        ];
    }

    public function test_the_english_lines_are_the_messages_of_the_exceptions(): void
    {
        foreach ($this->exceptions() as $exception) {
            $this->assertSame($exception->getMessage(), $exception->localizedMessage(), $exception->errorCode());
        }
    }

    public function test_every_exception_has_a_line_in_every_locale(): void
    {
        $this->app->setLocale('ar');

        foreach ($this->exceptions() as $exception) {
            $this->assertNotSame($exception->getMessage(), $exception->localizedMessage(), $exception->errorCode());
        }
    }

    public function test_refused_requests_get_the_message_of_the_current_locale_and_the_same_code(): void
    {
        $this->app->setLocale('ar');

        $this->idempotent('/orders', ['quantity' => 2], null)
            ->assertStatus(400)
            ->assertExactJson(['message' => 'الترويسة Idempotency-Key مطلوبة.', 'code' => 'idempotency_key_missing']);
        $this->assertNothingRan();
    }

    public function test_get_message_stays_english_and_unknown_locales_fall_back_to_english(): void
    {
        $this->app->setLocale('xx');
        $exception = IdempotencyException::keyConflict();

        $this->assertSame('This Idempotency-Key was already used with a different request.', $exception->localizedMessage());

        $this->app->setLocale('ar');

        $this->assertSame('This Idempotency-Key was already used with a different request.', $exception->getMessage());
    }

    public function test_the_application_can_override_a_line(): void
    {
        $this->app['translator']->addLines(['messages.key_missing' => 'Send an Idempotency-Key.'], 'en', 'api-idempotency');

        $this->idempotent('/orders', ['quantity' => 2], null)->assertExactJson(['message' => 'Send an Idempotency-Key.', 'code' => 'idempotency_key_missing']);
    }

    public function test_the_translations_are_published_with_the_four_tags(): void
    {
        foreach (['pharaonic', 'laravel-api-idempotency', 'pharaonic-translations', 'api-idempotency-translations'] as $tag) {
            $this->assertContains($this->app->resourcePath('lang/vendor/api-idempotency'), ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag), $tag);
        }
    }

    public function test_every_locale_has_the_english_keys(): void
    {
        $english = require __DIR__.'/../../resources/lang/en/messages.php';

        foreach (glob(__DIR__.'/../../resources/lang/*/messages.php') ?: [] as $file) {
            $this->assertSame(array_keys($english), array_keys(require $file), $file);
        }
    }

    public function test_render_sends_the_package_response(): void
    {
        $exception = IdempotencyException::inProgress(5);

        $this->assertSame(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress'], $exception->response()->getData(true));
        $this->assertSame('5', $exception->response()->headers->get('Retry-After'));
        $this->assertSame($exception->response()->getData(true), $exception->render()->getData(true));
    }
}
