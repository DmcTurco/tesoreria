<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // ── Flags de rol ──────────────────────────────────────────────────────────
    const ROLE_TESORERO  = 0;
    const ROLE_PROFESORA = 1;
    const ROLE_PADRE     = 2;

    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
        'padre_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'role'     => 'integer',
        'padre_id' => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }

    // ── Helpers de rol ────────────────────────────────────────────────────────

    public function isTesorero(): bool
    {
        return $this->role === self::ROLE_TESORERO;
    }
    public function isProfesora(): bool
    {
        return $this->role === self::ROLE_PROFESORA;
    }
    public function isPadre(): bool
    {
        return $this->role === self::ROLE_PADRE;
    }
}
