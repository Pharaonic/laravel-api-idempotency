<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class CreateIdempotencyKeysTable extends Migration
{
    public function up(): void
    {
        Schema::connection($this->store('connection'))->create($this->store('table') ?? 'idempotency_keys', function (Blueprint $table): void {
            $table->char('storage_key', 64)->primary();
            $table->char('request_fingerprint', 64);
            $table->string('state', 16);
            $table->string('owner_token', 64);
            $table->string('method', 16);
            $table->string('route', 255);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('lease_expires_at');
            $table->unsignedBigInteger('expires_at')->index();
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->store('connection'))->dropIfExists($this->store('table') ?? 'idempotency_keys');
    }

    /**
     * The database store's connection or table
     */
    private function store(string $key): ?string
    {
        $value = Config::get('pharaonic.api-idempotency.stores.database.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
