<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role model - roles table.
 * role_user pivot: user_id -> admin_users.id
 */
class Role extends Model
{
    protected $fillable = ['name', 'slug'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'role_user', 'role_id', 'user_id');
    }
}
