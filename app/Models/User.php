<?php

namespace App\Models;

use App\ApiTokens\ApiTokenService;
use App\Billing\BillingGateway;
use App\Moderation\ModerationQueries;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('password')) {
                static::normalizeApiTokenEpoch((string) $user->id);
                $user->api_token_epoch = (int) $user->getRawOriginal('api_token_epoch') + 1;
            }
        });
        static::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                $user->api_token_epoch = (int) static::where('_id', $user->id)->value('api_token_epoch');
                app(ApiTokenService::class)->cleanupForUser((string) $user->id);
            }
        });
        static::deleted(fn (User $user) => app(ApiTokenService::class)->cleanupForUser((string) $user->id));
    }

    public static function normalizeApiTokenEpoch(string $userId): void
    {
        // The predicate matches null/missing only, so a concurrent increment is
        // never reset. Password $set and epoch $inc still share one atomic write.
        static::where('_id', $userId)->whereNull('api_token_epoch')->update(['api_token_epoch' => 0]);
    }

    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();
        if ($this->isDirty('password')) {
            // Mongo applies $inc and the password $set in one document write.
            // Increment the stored value even when this model snapshot is stale.
            unset($dirty['api_token_epoch']);
            $dirty['$inc'] = ['api_token_epoch' => 1];
        }

        return $dirty;
    }

    // Gate 3: canonical store is Mongo (T2). SQL users table kept until all refs migrate.
    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    // Replaces the SQL column default ('user') — Mongo has no schema defaults.
    protected $attributes = ['role' => 'user', 'api_token_epoch' => 0];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'google_id',
        'email_verified_at',
        'favorite_category_ids',
        'stripe_customer_id',
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
            'api_token_epoch' => 'integer',
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
        return app(BillingGateway::class)->isPro($this);
    }

    public function stripeCustomerUrl(): ?string
    {
        return app(BillingGateway::class)->customerUrl($this);
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

    /**
     * Gate 9b: every sanction helper reads one module query (MongoDB), memoised per HTTP request,
     * so middleware, controller and views see the same snapshot.
     *
     * @return Collection<int, Sanction>
     */
    private function activeSanctions(): Collection
    {
        return app(ModerationQueries::class)->activeSanctionsFor((string) $this->id);
    }

    private function hasActiveSanction(array $types): bool
    {
        return $this->activeSanctions()->contains(fn (Sanction $s) => in_array($s->type, $types, true));
    }

    public function activeSanction(): ?Sanction
    {
        return $this->activeSanctions()->first();
    }

    public function blockingCommentSanction(): ?Sanction
    {
        return $this->activeSanctions()->first(fn (Sanction $s) => in_array($s->type, ['mute', 'temporary_ban', 'permanent_ban'], true));
    }

    public function isMuted(): bool
    {
        return $this->hasActiveSanction(['mute', 'temporary_ban', 'permanent_ban']);
    }

    public function isBanned(): bool
    {
        return $this->hasActiveSanction(['temporary_ban', 'permanent_ban']);
    }

    public function isPermanentlyBanned(): bool
    {
        return $this->hasActiveSanction(['permanent_ban']);
    }

    public function isTempBanned(): bool
    {
        return $this->hasActiveSanction(['temporary_ban']);
    }

    public function canComment(): bool
    {
        return ! $this->isMuted();
    }

    /** Reporting is for verified, non-admin, non-banned users (mute-only users may report). */
    public function canReport(): bool
    {
        return ! $this->isAdmin() && $this->hasVerifiedEmail() && ! $this->isBanned();
    }

    public function canBookmark(): bool
    {
        return ! $this->isBanned();
    }

    public function canBoost(): bool
    {
        return $this->canBookmark();
    }
}
