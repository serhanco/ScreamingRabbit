<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared hosting gives no terminal, so a Git deployment cannot run `artisan migrate`.
 * Instead the first admin request after a deployment applies any new migrations.
 * A marker file in storage remembers which set of migrations was applied, so normal requests only read one small file.
 */
class RunPendingMigrations
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->runningUnitTests() && is_file(storage_path('app/installed'))) {
            $this->migrateIfNeeded();
        }

        return $next($request);
    }

    private function migrateIfNeeded(): void
    {
        $signature = md5(implode('|', array_map('basename', glob(database_path('migrations/*.php')) ?: [])));
        $marker = storage_path('app/migrated');

        if (is_file($marker) && trim((string) file_get_contents($marker)) === $signature) {
            return;
        }

        $lock = fopen(storage_path('app/migrate.lock'), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return;
        }

        try {
            // Another request may have finished the job while this one waited for the lock.
            if (! is_file($marker) || trim((string) file_get_contents($marker)) !== $signature) {
                Artisan::call('migrate', ['--force' => true]);
                file_put_contents($marker, $signature);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
