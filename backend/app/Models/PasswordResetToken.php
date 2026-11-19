<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One pending "forgot password" link. The plain token is never stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property-read User|null $user
 */
class PasswordResetToken extends Model
{
    use BelongsToTenant, HasUuids;

    /** Minutes a reset link stays valid. */
    public const LIFETIME_MINUTES = 60;

    protected $fillable = ['user_id', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
