<?php

namespace App\Livewire\Admin\Settings;

use App\Models\BackupRecord;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.settings.index', ['backups' => BackupRecord::latest()->limit(20)->get()])->title('Настройки — CEO Money');
    }
}
