<?php

namespace App\Livewire\Admin\Deposits;

use App\Models\DepositRequest;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $status = '';
    public string $actionStep = 'details';
    public string $receivedAmount = '';
    public string $txid = '';
    public string $decisionReason = '';
    public ?int $selectedDepositId = null;
    public bool $showActionModal = false;

    public function mount(): void
    {
        $id = request()->integer('deposit');
        if ($id && DepositRequest::whereKey($id)->exists()) {
            $this->openAction($id);
        }
    }

    public function updatedStatus(): void { $this->resetPage(); }

    public function openAction(int $id): void
    {
        $request = DepositRequest::findOrFail($id);
        $this->selectedDepositId = $request->id;
        $this->showActionModal = true;
        $this->actionStep = 'details';
        $this->receivedAmount = (string) ($request->received_amount ?? $request->requested_amount);
        $this->txid = (string) $request->txid;
        $this->decisionReason = '';
        $this->resetErrorBag();
        app(DepositVerificationService::class)->initializeForRequest($request);
    }

    public function closeAction(): void
    {
        $this->reset(['selectedDepositId', 'showActionModal', 'receivedAmount', 'txid', 'decisionReason']);
        $this->actionStep = 'details';
        $this->resetErrorBag();
    }

    public function requestSubmitConfirmation(DepositRequestService $service): void
    {
        try {
            $service->preview($this->selected(), $this->receivedAmount);
            $this->actionStep = 'submit-confirm';
            $this->resetErrorBag();
        } catch (DomainException $exception) {
            $this->addError('action', $exception->getMessage());
        }
    }

    public function submitDeposit(DepositRequestService $service): void
    {
        $this->perform(fn () => $service->submit($this->selected(), $this->receivedAmount, $this->txid, auth()->user()), 'Заявка принята на проверку.');
    }

    public function requestConfirmation(DepositRequestService $service): void
    {
        $request = $this->selected();
        if ($request->status !== 'submitted') {
            $this->addError('action', 'Подтверждение доступно только для заявки на проверке.');
            return;
        }
        if (! $service->confirmationPreflight($request)['can_confirm']) {
            $this->addError('action', $this->missingTermsMessage());
            return;
        }
        $verification = app(DepositVerificationService::class);
        $verification->refreshSystemChecks($request);
        if (! $verification->allRequiredPassed($request)) {
            $this->addError('action', 'Завершите все обязательные пункты проверки поступления.');
            return;
        }
        $this->actionStep = 'confirm';
    }

    public function passVerification(string $key, DepositVerificationService $service): void
    {
        $this->verificationAction(fn () => $service->markPassed($this->selected(), $key, auth()->user()));
    }

    public function failVerification(string $key, DepositVerificationService $service): void
    {
        $this->verificationAction(fn () => $service->markFailed($this->selected(), $key, auth()->user()));
    }

    public function resetVerification(string $key, DepositVerificationService $service): void
    {
        $this->verificationAction(fn () => $service->resetManualCheck($this->selected(), $key, auth()->user()));
    }

    public function confirmDeposit(DepositRequestService $service): void
    {
        try {
            $request = $this->selected();
            if (! $service->confirmationPreflight($request)['can_confirm']) {
                throw new DomainException($this->missingTermsMessage());
            }
            $service->confirm($request, auth()->user());
            session()->flash('success', 'Пополнение подтверждено.');
            $this->closeAction();
        } catch (DomainException $exception) {
            $message = str_contains($exception->getMessage(), 'Explicit lot terms')
                ? $this->missingTermsMessage()
                : $exception->getMessage();
            $this->addError('action', $message);
        }
    }

    public function beginDecision(string $decision): void
    {
        if (! in_array($decision, ['reject', 'cancel'], true)) {
            return;
        }
        $this->decisionReason = '';
        $this->actionStep = $decision;
        $this->resetErrorBag();
    }

    public function rejectDeposit(DepositRequestService $service): void
    {
        $this->perform(fn () => $service->reject($this->selected(), $this->decisionReason, auth()->user()), 'Заявка отклонена.');
    }

    public function cancelDeposit(DepositRequestService $service): void
    {
        $this->perform(fn () => $service->cancel($this->selected(), $this->decisionReason, auth()->user()), 'Заявка отменена.');
    }

    private function perform(callable $operation, string $message): void
    {
        try {
            $operation();
            session()->flash('success', $message);
            $this->closeAction();
        } catch (DomainException $exception) {
            $this->addError('action', $exception->getMessage());
        }
    }

    private function verificationAction(callable $operation): void
    {
        try { $operation(); $this->resetErrorBag(); }
        catch (DomainException $exception) { $this->addError('action', $exception->getMessage()); }
    }

    private function selected(): DepositRequest
    {
        return DepositRequest::with('investor.user')->findOrFail($this->selectedDepositId);
    }

    private function missingTermsMessage(): string
    {
        return 'Для инвестора не настроены действующие условия инвестирования. Перед подтверждением пополнения необходимо указать ставку и срок доступности капитала.';
    }

    public function render(DepositRequestService $service, DepositVerificationService $verification)
    {
        $selected = $this->selectedDepositId
            ? DepositRequest::with('investor.user')->find($this->selectedDepositId)
            : null;
        $preview = null;
        if (in_array($selected?->status, ['pending', 'payment_submitted'], true)) {
            try {
                $preview = $service->preview($selected, $this->receivedAmount);
            } catch (DomainException) {
                // Invalid draft input is presented by the action validation.
            }
        }
        $confirmationPreflight = $selected?->status === 'submitted'
            ? $service->confirmationPreflight($selected)
            : null;
        $verificationSummary = null;
        if ($selected) {
            $verification->initializeForRequest($selected);
            $verificationSummary = $verification->summary($selected);
        }

        return view('livewire.admin.deposits.index', [
            'deposits' => DepositRequest::with('investor.user')
                ->withCount(['verificationChecks', 'verificationChecks as verification_passed_count' => fn ($query) => $query->where('status', 'passed')])
                ->when($this->status, fn ($query) => $query->where('status', $this->status))
                ->latest('requested_at')->paginate(20),
            'selectedDeposit' => $selected,
            'preview' => $preview,
            'confirmationPreflight' => $confirmationPreflight,
            'verificationSummary' => $verificationSummary,
        ])->title('Пополнения — CEO Money');
    }
}
