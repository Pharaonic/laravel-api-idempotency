<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $total
 */
final class Order extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['id' => 'integer', 'total' => 'integer'];
}
