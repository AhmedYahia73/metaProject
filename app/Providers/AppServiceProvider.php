<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\ServiceProvider;

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
        $secureCallback = function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
                    ->setDescription('Enter your Sanctum Bearer token (e.g. 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx)')
            );
        };

        Scramble::afterOpenApiGenerated($secureCallback);

        // 1. Admin API documentation link
        Scramble::registerApi('admin', [
            'api_path' => 'api/admin',
            'info' => [
                'version' => '1.0.0',
                'description' => 'Admin API documentation for managing packages, orders, taxes, discounts, settings, and users.',
            ],
            'ui' => [
                'title' => 'Admin API Docs',
            ],
        ])
            ->routes(fn ($route) => str_starts_with($route->uri(), 'api/admin'))
            ->expose(
                ui: 'docs/api/admin',
                document: 'docs/api/admin.json',
            )
            ->afterOpenApiGenerated($secureCallback);

        // 2. User API documentation link
        Scramble::registerApi('user', [
            'api_path' => 'api/user',
            'info' => [
                'version' => '1.0.0',
                'description' => 'User & Public API documentation (Packages, Contact Us).',
            ],
            'ui' => [
                'title' => 'User API Docs',
            ],
        ])
            ->routes(fn ($route) => str_starts_with($route->uri(), 'api/user'))
            ->expose(
                ui: 'docs/api/user',
                document: 'docs/api/user.json',
            );

        // 3. Auth API documentation link
        Scramble::registerApi('auth', [
            'api_path' => 'api/auth',
            'info' => [
                'version' => '1.0.0',
                'description' => 'Authentication API documentation (Admin & User login, Logout).',
            ],
            'ui' => [
                'title' => 'Auth API Docs',
            ],
        ])
            ->routes(fn ($route) => str_starts_with($route->uri(), 'api/auth'))
            ->expose(
                ui: 'docs/api/auth',
                document: 'docs/api/auth.json',
            );

        // Group endpoints into folders: Admin, User, Auth
        Scramble::resolveTagsUsing(function (RouteInfo $routeInfo) {
            $uri = $routeInfo->route->uri();

            if (str_starts_with($uri, 'api/admin') || str_contains($routeInfo->className(), '\\admin\\')) {
                return ['Admin'];
            }

            if (str_starts_with($uri, 'api/user') || str_contains($routeInfo->className(), '\\user\\')) {
                return ['User'];
            }

            if (str_starts_with($uri, 'api/auth') || str_contains($routeInfo->className(), '\\auth\\')) {
                return ['Auth'];
            }

            $defaultName = (string) str(class_basename($routeInfo->className()))->replace('Controller', '');

            return [$defaultName ?: 'General'];
        });
    }
}
