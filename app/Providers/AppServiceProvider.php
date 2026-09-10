<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('rupiah', function ($expression) {
            return "<?php echo 'Rp. ' . number_format($expression,0,',','.'); ?>";
        });

        $this->preferDatabaseSessions();
    }

    private function preferDatabaseSessions(): void
    {
        if (config('session.driver') === 'database') {
            return;
        }

        try {
            if (Schema::hasTable('sessions')) {
                config(['session.driver' => 'database']);
            }
        } catch (Throwable $e) {
            // Tetap pakai driver file jika database belum siap.
        }
    }
}
