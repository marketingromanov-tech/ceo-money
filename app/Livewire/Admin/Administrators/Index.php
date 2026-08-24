<?php

namespace App\Livewire\Admin\Administrators;

use App\Models\User;
use App\Services\AdminManagementService;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?int $resettingId = null;
    public string $resetPassword = '';
    public string $resetPassword_confirmation = '';

    public function create(AdminManagementService $service): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $service->create(auth()->user(), $data['name'], mb_strtolower($data['email']), $data['password']);
        $this->reset(['name', 'email', 'password', 'password_confirmation']);
        $this->dispatch('admin-created');
    }

    public function toggle(int $id, AdminManagementService $service): void
    {
        $admin = User::query()->where('role', 'admin')->findOrFail($id);
        try {
            $admin->is_active ? $service->deactivate(auth()->user(), $admin) : $service->activate(auth()->user(), $admin);
        } catch (DomainException $exception) {
            $this->addError('management', $exception->getMessage());
        }
    }

    public function openPasswordReset(int $id): void
    {
        $this->resetValidation();
        $this->resettingId = User::query()->where('role', 'admin')->findOrFail($id)->id;
        $this->reset(['resetPassword', 'resetPassword_confirmation']);
    }

    public function resetAdminPassword(AdminManagementService $service): void
    {
        $this->validate(['resettingId' => ['required', 'integer'], 'resetPassword' => ['required', 'string', 'min:12', 'same:resetPassword_confirmation']]);
        $admin = User::query()->where('role', 'admin')->findOrFail($this->resettingId);
        $service->resetPassword(auth()->user(), $admin, $this->resetPassword);
        $this->reset(['resettingId', 'resetPassword', 'resetPassword_confirmation']);
        $this->dispatch('admin-password-reset');
    }

    public function render()
    {
        return view('livewire.admin.administrators.index', [
            'administrators' => User::query()->where('role', 'admin')->orderByDesc('is_active')->orderBy('name')->paginate(20),
        ])->title('Администраторы — CEO Money');
    }
}
