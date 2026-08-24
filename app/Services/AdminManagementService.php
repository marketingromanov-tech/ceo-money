<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class AdminManagementService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function create(User $actor, string $name, string $email, string $password): User
    {
        $this->assertAuthorized($actor);

        return DB::transaction(function () use ($actor, $name, $email, $password): User {
            $admin = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'admin',
                'is_active' => true,
            ]);
            $this->audit->log('admin.created', $admin, $actor, null, [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'admin',
                'is_active' => true,
            ]);

            return $admin;
        });
    }

    public function activate(User $actor, User $admin): User
    {
        $this->assertAuthorized($actor);
        $this->assertAdmin($admin);
        if ($admin->is_active) return $admin;

        $admin->update(['is_active' => true]);
        $this->audit->log('admin.activated', $admin, $actor, ['is_active' => false], ['is_active' => true]);

        return $admin;
    }

    public function deactivate(User $actor, User $admin): User
    {
        $this->assertAuthorized($actor);
        if ($actor->is($admin)) throw new DomainException('Нельзя деактивировать самого себя.');

        return DB::transaction(function () use ($actor, $admin): User {
            $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmin($locked);
            if (! $locked->is_active) return $locked;
            if (User::query()->where('role', 'admin')->where('is_active', true)->lockForUpdate()->count() <= 1) {
                throw new DomainException('Нельзя деактивировать последнего активного администратора.');
            }
            $locked->update(['is_active' => false]);
            $this->audit->log('admin.deactivated', $locked, $actor, ['is_active' => true], ['is_active' => false]);

            return $locked;
        });
    }

    public function resetPassword(User $actor, User $admin, string $password): User
    {
        $this->assertAuthorized($actor);
        $this->assertAdmin($admin);
        $admin->update(['password' => $password]);
        $this->audit->log('admin.password_reset', $admin, $actor, null, ['user_id' => $admin->id]);

        return $admin;
    }

    private function assertAuthorized(User $actor): void
    {
        if ($actor->role !== 'admin' || ! $actor->is_active) throw new DomainException('Недостаточно прав для управления администраторами.');
    }

    private function assertAdmin(User $user): void
    {
        if ($user->role !== 'admin') throw new DomainException('Пользователь не является администратором.');
    }
}
