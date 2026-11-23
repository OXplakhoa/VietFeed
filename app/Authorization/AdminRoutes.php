<?php

namespace App\Authorization;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LogicException;

final class AdminRoutes
{
    // Names are relative to the admin route group.
    private const ABILITIES = [
        Permissions::STAFF => ['dashboard'],
        Permissions::CONTENT => [
            'articles.index', 'articles.show', 'articles.edit', 'articles.update', 'articles.destroy', 'articles.bulk-destroy',
            'categories.index', 'categories.create', 'categories.store', 'categories.show', 'categories.edit', 'categories.update', 'categories.destroy', 'categories.toggle-active',
            'sources.index', 'sources.create', 'sources.store', 'sources.show', 'sources.edit', 'sources.update', 'sources.destroy', 'sources.toggle-active',
        ],
        Permissions::COMMENTS => ['comments.index', 'comments.destroy'],
        Permissions::MODERATION => ['reports.index', 'reports.show', 'reports.update', 'sanctions.index', 'sanctions.store', 'sanctions.show', 'sanctions.update', 'sanctions.revoke'],
        Permissions::OPERATIONS => ['processing.index', 'processing.retry', 'sources.health', 'sources.test', 'sources.retry'],
        Permissions::STORIES => ['stories.index', 'stories.show', 'stories.accept', 'stories.reject', 'stories.retry'],
        Permissions::USERS => ['users.index', 'users.edit', 'users.update', 'users.destroy'],
        Permissions::SUPER => ['authorization.audit'],
    ];

    public static function ability(?string $routeName): ?string
    {
        if ($routeName === null || ! str_starts_with($routeName, 'admin.')) {
            return null;
        }

        foreach (self::ABILITIES as $ability => $names) {
            if (in_array(substr($routeName, 6), $names, true)) {
                return $ability;
            }
        }

        return null;
    }

    public static function allows(string $routeName): bool
    {
        $ability = self::ability($routeName);

        return $ability !== null && Gate::allows($ability);
    }

    public static function middleware(string $name): string
    {
        $ability = self::ability('admin.'.$name) ?? throw new LogicException("Admin route $name has no ability.");

        return 'can:'.$ability;
    }

    public static function resource(string $name, string $controller, array $actions): void
    {
        $resource = Route::resource($name, $controller)->only($actions);
        foreach ($actions as $action) {
            $resource->middlewareFor($action, self::middleware($name.'.'.$action));
        }
    }
}
