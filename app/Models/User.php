<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const ROLE_PATIENT = 'paciente';

    public const ROLE_THERAPIST = 'apiterapeuta';

    public const ROLE_ADMINISTRATOR = 'administrador';

    public static function roleOptions(): array
    {
        return self::registrationRoleOptions() + [self::ROLE_ADMINISTRATOR => 'Administrador'];
    }

    public static function registrationRoleOptions(): array
    {
        return [self::ROLE_PATIENT => 'Paciente', self::ROLE_THERAPIST => 'Apiterapeuta'];
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(self::ROLE_ADMINISTRATOR);
    }

    public function hasRole(string $role): bool
    {
        return array_key_exists($role, self::roleOptions()) && $this->role === $role;
    }

    public function hasKnownRole(): bool
    {
        return is_string($this->role) && array_key_exists($this->role, self::roleOptions());
    }

    public function isPatient(): bool
    {
        return $this->hasRole(self::ROLE_PATIENT);
    }

    public function isTherapist(): bool
    {
        return $this->hasRole(self::ROLE_THERAPIST);
    }

    public function roleLabel(): string
    {
        return self::roleOptions()[$this->role] ?? 'Rol no reconocido';
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true, 'role' => self::ROLE_PATIENT];

    public function therapistProfile(): HasOne
    {
        return $this->hasOne(TherapistProfile::class);
    }

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->isTherapist()) {
                $user->therapistProfile()->create();
            } elseif ($user->isPatient()) {
                $user->patientProfile()->create();
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
