<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'password' => 'hashed'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
