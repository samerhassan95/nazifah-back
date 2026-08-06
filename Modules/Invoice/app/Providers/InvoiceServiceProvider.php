<?php

namespace Modules\Invoice\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Invoice\Contracts\InvoiceComplianceGatewayInterface;
use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\Services\InvoiceSettingsService;
use Modules\Invoice\Services\Providers\HttpWhatsappGateway;
use Modules\Invoice\Services\Providers\HttpZatcaGateway;
use Modules\Invoice\Services\Providers\MockWhatsappGateway;
use Modules\Invoice\Services\Providers\MockZatcaGateway;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InvoiceServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Invoice';

    protected string $nameLower = 'invoice';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $this->app->bind(InvoiceComplianceGatewayInterface::class, function () {
            $settings = app(InvoiceSettingsService::class);

            return match ($settings->get('invoice_zatca_driver', 'mock')) {
                'http' => new HttpZatcaGateway($settings),
                default => new MockZatcaGateway,
            };
        });

        $this->app->bind(WhatsappInvoiceGatewayInterface::class, function () {
            $settings = app(InvoiceSettingsService::class);

            return match ($settings->get('invoice_whatsapp_driver', 'mock')) {
                'http' => new HttpWhatsappGateway($settings),
                default => new MockWhatsappGateway,
            };
        });
    }

    public function registerTranslations(): void
    {
    }

    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (! is_dir($configPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $configKey = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
            $segments = explode('.', $this->nameLower.'.'.$configKey);

            $normalized = [];
            foreach ($segments as $segment) {
                if (end($normalized) !== $segment) {
                    $normalized[] = $segment;
                }
            }

            $key = $config === 'config.php' ? $this->nameLower : implode('.', $normalized);
            $this->publishes([$file->getPathname() => config_path($config)], 'config');
            $this->mergeConfigFromModule($file->getPathname(), $key);
        }
    }

    protected function mergeConfigFromModule(string $path, string $key): void
    {
        $existing = config($key, []);
        $moduleConfig = require $path;

        config([$key => array_replace_recursive($existing, $moduleConfig)]);
    }

    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);
        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);
        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }

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
