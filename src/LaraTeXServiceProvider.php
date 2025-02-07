<?php declare(strict_types=1);

namespace Websta\LaraTeX;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class LaraTeXServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('laratex.php'),
            ], 'config');
        }

        Blade::directive('latex', function ($exp) {
            $path = LatexEscaper::class;
            return "<?php echo $path::escape($exp) ?>";
        });
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'laratex');

        // Register the main class to use with the facade
        $this->app->singleton('laratex', function () {
            return new LaraTeX;
        });
    }
}
