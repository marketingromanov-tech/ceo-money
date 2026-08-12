<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Profile extends Component
{
    use InteractsWithInvestorData;

    public string $name = '';
    public string $phone = '';
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->phone = (string) ($user->investor->phone ?? '');
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
        $user = auth()->user();
        $user->update(['name' => $data['name']]);
        $user->investor->update(['phone' => $data['phone'] ?: null]);
        session()->flash('profileSuccess', 'Профиль обновлён.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ], [
            'newPassword.min' => 'Новый пароль должен содержать не менее 8 символов.',
            'newPasswordConfirmation.same' => 'Подтверждение пароля не совпадает.',
        ]);

        $user = auth()->user();
        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Текущий пароль указан неверно.');
            return;
        }
        if (Hash::check($this->newPassword, $user->password)) {
            $this->addError('newPassword', 'Новый пароль должен отличаться от текущего.');
            return;
        }

        $user->update(['password' => Hash::make($this->newPassword)]);
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->resetValidation();
        session()->flash('passwordSuccess', 'Пароль изменён.');
    }

    public function render()
    {
        return view('livewire.investor.profile', [
            'user' => auth()->user(),
            'investor' => $this->investor(),
        ])->title('Профиль — CEO Money');
    }
}
