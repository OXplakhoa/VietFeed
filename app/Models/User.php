<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    // Gate 3: canonical store is Mongo (T2). SQL users table kept until all refs migrate.
    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    // Replaces the SQL column default ('user') — Mongo has no schema defaults.
    protected $attributes = ['role' => 'user'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'google_id',
        'email_verified_at',
        'favorite_category_ids',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'favorite_category_ids' => 'array',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isPro(): bool
    {
        return $this->subscribed('pro');
    }

    public function stripeCustomerUrl(): ?string
    {
        if (! $this->stripe_id) {
            return null;
        }

        $prefix = str_starts_with((string) config('cashier.secret'), 'sk_test_')
            ? 'https://dashboard.stripe.com/test/customers/'
            : 'https://dashboard.stripe.com/customers/';

        return $prefix.$this->stripe_id;
    }

    // Gate 3: related SQL models must stay on the default SQL connection.
    // Stock newRelatedInstance() inherits the parent (mongo) connection, which
    // silently repoints every hasMany/belongsToMany query at Mongo (PDO-less
    // SQL grammar → fatal). SQL→mongo belongsTo is unaffected (User pins its own).
    protected function newRelatedInstance($class)
    {
        return new $class;
    }

    public function bookmarks()
    {
        return $this->hasMany(Bookmark::class);
    }

    public function boosts()
    {
        return $this->hasMany(Boost::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function favoriteCategories()
    {
        return $this->belongsToMany(Category::class, 'category_user');
    }

    public function articleUnlocks()
    {
        return $this->hasMany(ArticleUnlock::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function sanctions()
    {
        return $this->hasMany(Sanction::class, 'user_id');
    }

    public function activeSanction(): ?Sanction
    {
        return $this->sanctions()
            ->active()
            ->orderByDesc('created_at')
            ->first();
    }

    public function isMuted(): bool
    {
        $sanction = $this->activeSanction();

        return $sanction && in_array($sanction->type, ['mute', 'temporary_ban', 'permanent_ban']);
    }

    public function isBanned(): bool
    {
        $sanction = $this->activeSanction();

        return $sanction && in_array($sanction->type, ['temporary_ban', 'permanent_ban']);
    }

    public function isPermanentlyBanned(): bool
    {
        $sanction = $this->activeSanction();

        return $sanction && $sanction->type === 'permanent_ban';
    }

    public function isTempBanned(): bool
    {
        $sanction = $this->activeSanction();

        return $sanction && $sanction->type === 'temporary_ban';
    }

    public function canComment(): bool
    {
        $sanction = $this->activeSanction();

        if (! $sanction) {
            return true;
        }

        return $sanction->type === 'warning';
    }

    public function canBookmark(): bool
    {
        $sanction = $this->activeSanction();

        if (! $sanction) {
            return true;
        }

        return in_array($sanction->type, ['warning', 'mute']);
    }

    public function canBoost(): bool
    {
        return $this->canBookmark();
    }
}
