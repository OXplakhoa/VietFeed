<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'google_id',
        'email_verified_at',
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
}
