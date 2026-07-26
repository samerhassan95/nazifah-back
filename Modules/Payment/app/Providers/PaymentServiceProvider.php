<?php

namespace Modules\Payment\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PaymentServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Payment';

    protected string $nameLower = 'payment';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RepositoryServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // Register Payment Service as Singleton
        $this->app->singleton(\Modules\Payment\Services\PaymentService::class, function ($app) {
            $service = new \Modules\Payment\Services\PaymentService;

            // Register available gateways from config
            $gateways = config('payment.gateways', []);
            foreach ($gateways as $name => $config) {
                $isEnabled = filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if ($isEnabled) {
                    try {
                        $gateway = $this->createGateway($name, $config);
                        if ($gateway) {
                            $service->registerGateway($name, $gateway);

                            // Register aliases for the gateway (to handle different naming conventions)
                            $aliases = $this->getGatewayAliases($name);
                            foreach ($aliases as $alias) {
                                $service->registerGateway($alias, $gateway);
                            }
                        } else {
                        }
                    } catch (\Exception $e) {
                    }
                } else {
                }
            }

            // Set default gateway if configured
            $defaultGateway = config('payment.default');
            if ($defaultGateway) {
                try {
                    $service->setGateway($defaultGateway);
                } catch (\Exception $e) {
                }
            }

            return $service;
        });

        // Alias for easier access
        $this->app->alias(\Modules\Payment\Services\PaymentService::class, 'payment.service');
    }

    /**
     * Get aliases for a gateway name
     */
    protected function getGatewayAliases(string $name): array
    {
        return match ($name) {
            'amazon_pay' => ['amazon_payment_services', 'amazonpay', 'payfort'],
            'moyasar' => ['moyasar_payments', 'moyasarpay'],
            default => [],
        };
    }

    /**
     * Create gateway instance based on name
     */
    protected function createGateway(string $name, array $config): ?\Modules\Payment\Contracts\PaymentGatewayInterface
    {
        return match ($name) {
            'amazon_pay' => new \Modules\Payment\Gateways\AmazonPayGateway($config),
            'moyasar' => new \Modules\Payment\Gateways\MoyasarGateway($config),
            // Add more gateways here as they are implemented
            // 'stripe' => new \Modules\Payment\app\Gateways\StripeGateway($config),
            // 'paypal' => new \Modules\Payment\app\Gateways\PayPalGateway($config),
            default => null,
        };
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        // $this->commands([]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        // Translations are loaded from resources/lang/ directory by Laravel automatically
        // No need to load them here
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $this->nameLower.'.'.$config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->merge_config_from($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function merge_config_from(string $path, string $key): void
    {
        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
