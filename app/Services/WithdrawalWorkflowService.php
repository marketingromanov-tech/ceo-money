<?php

namespace App\Services;

use App\Models\User;
use App\Models\WithdrawalRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

class WithdrawalWorkflowService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function moveToReview(WithdrawalRequest $request, ?User $user = null): WithdrawalRequest
    {
        return $this->transition($request, $user, 'review', ['new'], 'withdrawal.review', []);
    }

    public function approve(WithdrawalRequest $request, ?User $user = null): WithdrawalRequest
    {
        return $this->transition($request, $user, 'approved', ['review'], 'withdrawal.approved', [
            'approved_at' => now(), 'approved_by' => $user?->id,
        ]);
    }

    public function cancel(WithdrawalRequest $request, ?User $user = null, ?string $reason = null): WithdrawalRequest
    {
        return $this->transition($request, $user, 'cancelled', ['new', 'review', 'approved'], 'withdrawal.cancelled', [
            'cancelled_at' => now(), 'cancelled_by' => $user?->id, 'cancellation_reason' => $reason,
        ]);
    }

    public function reject(
        WithdrawalRequest $request,
        ?User $user = null,
        ?string $reason = null,
    ): WithdrawalRequest {
        return $this->transition($request, $user, 'rejected', ['new', 'review'], 'withdrawal.rejected', [
            'rejected_at' => now(), 'rejected_by' => $user?->id, 'rejected_reason' => $reason,
        ]);
    }

    private function transition(
        WithdrawalRequest $request,
        ?User $user,
        string $target,
        array $allowedFrom,
        string $action,
        array $attributes,
    ): WithdrawalRequest {
        return DB::transaction(function () use ($request, $user, $target, $allowedFrom, $action, $attributes) {
            $request = WithdrawalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status === $target) {
                return $request;
            }

            if (! in_array($request->status, $allowedFrom, true)) {
                throw new DomainException("Cannot transition withdrawal from {$request->status} to {$target}.");
            }

            $old = ['status' => $request->status];
            $request->update(array_merge($attributes, ['status' => $target]));
            $this->audit->log($action, $request, $user, $old, ['status' => $target]);

            return $request;
        });
    }
}
