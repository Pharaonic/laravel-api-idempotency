<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Pharaonic\Laravel\ApiIdempotency\Stores\Stores;

final class PruneCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'idempotency:prune
        {--unresolved : Also delete expired records whose outcome is unknown or that never finished}
        {--apply : Delete the records; without it, only count them}';

    /**
     * @var string
     */
    protected $description = 'Delete expired idempotency records';

    public function handle(Stores $stores): int
    {
        if ($stores->driver() === 'redis') {
            $this->info('The redis store expires its records by itself; nothing to prune.');

            return 0;
        }

        $store = $stores->get();
        $apply = (bool) $this->option('apply');
        $unresolved = (bool) $this->option('unresolved');
        $count = $store->prune(Carbon::now()->getTimestamp(), $unresolved, $apply);

        if ($count === null) {
            $this->info('The store expires its records by itself; nothing to prune.');

            return 0;
        }

        $what = $unresolved ? 'expired records' : 'expired completed records';
        $this->info($apply ? "Deleted {$count} {$what}." : "{$count} {$what} would be deleted. Run with --apply to delete them.");

        $remaining = $store->unresolved();

        if ($remaining !== null && $remaining > 0) {
            $this->warn("{$remaining} unresolved records (still processing, or with an unknown outcome) are kept. Reconcile them, then remove them with --unresolved once they expire.");
        }

        return 0;
    }
}
