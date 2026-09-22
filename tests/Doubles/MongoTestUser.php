<?php

namespace Tests\Doubles;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use MongoDB\Laravel\Eloquent\Model;

// Test-only double (Gate 3 T1): stock Eloquent models build SQL query builders
// even on the mongodb connection, so the double extends the package base model
// (its documented User recipe) with only the fields the Auth probe needs.
// No prod model change; T2 moves the prod User model.
class MongoTestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $connection = 'mongodb';

    protected $table = 'users';

    protected $primaryKey = '_id';

    protected $fillable = ['name', 'email', 'password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
