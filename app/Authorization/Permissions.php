<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class Permissions
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const USER = 'user';

    public const ROLES = [self::USER, self::ADMIN, self::SUPER_ADMIN];

    public const STAFF = 'admin-dashboard';

    public const SUPER = 'super-admin';

    public const CONTENT = 'content.manage';

    public const COMMENTS = 'comments.manage';

    public const MODERATION = 'moderation.manage';

    public const OPERATIONS = 'operations.manage';

    public const STORIES = 'stories.review';

    public const USERS = 'users.manage';

    public const ALL = [self::CONTENT, self::COMMENTS, self::MODERATION, self::OPERATIONS, self::STORIES, self::USERS];

    public const MANAGE_USER = 'manage-user-account';

    public static function register(): void
    {
        Gate::define(self::STAFF, fn (User $user) => $user->isAdmin());
        Gate::define(self::SUPER, fn (User $user) => $user->isSuperAdmin());
        foreach (self::ALL as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        Gate::define('review-story-brief', fn (User $user) => $user->hasPermission(self::STORIES));
        Gate::define(self::MANAGE_USER, fn (User $actor, User $target) => $actor->isSuperAdmin()
            || ($actor->hasPermission(self::USERS) && $target->isUser()));
    }
}
