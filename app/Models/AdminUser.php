<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;

class AdminUser extends Model
{
    protected $fillable = [
        'name', 'email', 'password', 'role',
        'permissions', 'last_login_at', 'last_login_ip', 'is_active',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function verifyPassword(string $password): bool
    {
        return Hash::check($password, $this->password);
    }

    public function can(string $permission): bool
    {
        $defaults = static::roleDefaults($this->role);
        $override = $this->permissions ?? [];
        return $override[$permission] ?? $defaults[$permission] ?? false;
    }

    private static function roleDefaults(string $role): array
    {
        return match ($role) {
            'admin' => array_fill_keys([
                'parser.start', 'parser.stop', 'products.view', 'products.edit', 'products.delete',
                'categories.edit', 'brands.edit', 'excluded.edit', 'filters.edit',
                'settings.edit', 'logs.view', 'users.manage',
            ], true),
            'editor' => array_fill_keys([
                'parser.start', 'parser.stop', 'products.view', 'products.edit',
                'categories.edit', 'brands.edit', 'excluded.edit', 'filters.edit', 'logs.view',
            ], true),
            default => array_fill_keys(['products.view', 'logs.view'], true),
        };
    }
}
