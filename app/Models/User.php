<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\Role;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property string|null $phone
 * @property int|null $branch_id
 * @property string|null $vehicle_plate
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default values for attributes.
     *
     * Role, branch, vehicle and active flag are never mass assignable:
     * only an admin sets them, explicitly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'customer',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The branch a staff member works at.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The orders a customer has placed.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /**
     * The orders assigned to a driver.
     *
     * @return HasMany<Order, $this>
     */
    public function assignedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    /**
     * Determine if the user has any of the given roles.
     */
    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Determine if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Determine if the user can work the branch counter (staff and admins).
     */
    public function isStaff(): bool
    {
        return $this->hasRole(Role::Staff, Role::Admin);
    }

    /**
     * Determine if the user is a driver.
     */
    public function isDriver(): bool
    {
        return $this->role === Role::Driver;
    }

    /**
     * Determine if the user is a customer.
     */
    public function isCustomer(): bool
    {
        return $this->role === Role::Customer;
    }

    /**
     * Determine if the user may be sent email about their work: an active
     * account with a verified address, as for customers' drop-off reminders.
     *
     * Accounts an admin creates are verified from the start
     * (Admin\UserController::store), so for staff and drivers this only holds
     * email back after they change their address, until they confirm the new one.
     */
    public function canBeEmailed(): bool
    {
        return $this->is_active && $this->hasVerifiedEmail();
    }

    /**
     * Scope a query to users who may be sent email about their work (see canBeEmailed()).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function emailable(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('email_verified_at');
    }

    /**
     * Scope a query to active users.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to users with the given role.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withRole(Builder $query, Role $role): void
    {
        $query->where('role', $role);
    }

    /**
     * Add a "jobs_count" of active deliveries scheduled on the given day (for drivers).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withJobsCountOn(Builder $query, CarbonInterface $date): void
    {
        // The same rule as Order::activeJobs()->scheduledOn($date), as an index-friendly range.
        $query->withCount(['assignedOrders as jobs_count' => fn (Builder $orders) => $orders
            ->whereIn('status', OrderStatus::activeJobs())
            ->where('scheduled_for', '>=', $date->toDateString())
            ->where('scheduled_for', '<', $date->toImmutable()->addDay()->toDateString()),
        ]);
    }
}
