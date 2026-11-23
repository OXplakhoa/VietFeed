<?php

namespace App\Models;

use App\ApiTokens\ApiTokenService;
use App\Authorization\Permissions;
use App\Billing\BillingGateway;
use App\Moderation\ModerationQueries;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
    protected $attributes = ['role' => Permissions::USER, 'permissions' => [], 'api_token_epoch' => 0];

    protected $fillable = [
        'name',
        'email',
        'password',
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

    public function scopeEligibleForPasswordReset(Builder $query): void
    {
        $query->whereNull('deletion')->where(fn (Builder $query) => $query
            ->where('password_reset_requires_verified_email', '!=', true)
            ->orWhereNotNull('email_verified_at'));
    }

    public function resetPasswordIfEligible(string $password): bool
    {
        $epoch = $this->getRawOriginal('api_token_epoch');
        $changes = [
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
            'api_token_epoch' => (int) $epoch + 1,
        ];
        $updated = static::whereKey($this->id)->where('email', $this->email)
            ->where('api_token_epoch', $epoch)->eligibleForPasswordReset()->update($changes);
        if ($updated !== 1) {
            return false;
        }

        $this->forceFill($changes)->syncOriginalAttributes(array_keys($changes));
        app(ApiTokenService::class)->cleanupForUser((string) $this->id);

        return true;
    }

    public function markEmailAsVerified(): bool
    {
        $verifiedAt = $this->freshTimestamp();
        // Bind the verification to the address that was checked by the signed link or OAuth.
        if (static::whereKey($this->id)->where('email', $this->email)->whereNull('email_verified_at')
            ->update(['email_verified_at' => $verifiedAt]) !== 1) {
            return false;
        }

        $this->email_verified_at = $verifiedAt;
        $this->syncOriginalAttribute('email_verified_at');

        return true;
    }

    public function isAdmin(): bool
    {
        return $this->role === Permissions::ADMIN || $this->isSuperAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Permissions::SUPER_ADMIN;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, Permissions::ALL, true) && ($this->isSuperAdmin()
            || ($this->role === Permissions::ADMIN && in_array($permission, $this->permissions ?? [], true)));
    }

    /** Gate 9.7b: a deletion request is pending or being purged (the account is closed to normal use). */
    public function isPendingDeletion(): bool
    {
        return $this->getAttribute('deletion') !== null;
    }

    public function isUser(): bool
    {
        return $this->role === Permissions::USER;
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
        return $this->hasMany(Bookmark::class, 'user_id', '_id');
    }

    public function boosts()
    {
        return $this->hasMany(Boost::class, 'user_id', '_id');
    }

    public function comments()
    {
        // Gate 9.5: Mongo → Mongo, keyed by the user's string id.
        return $this->hasMany(Comment::class, 'user_id', '_id');
    }

    public function articleUnlocks()
    {
        return $this->hasMany(ArticleUnlock::class, 'user_id', '_id');
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
