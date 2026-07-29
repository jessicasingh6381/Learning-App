<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantOwnershipService
{
    public function updateMembership(TenantMembership $membership, array $data, AuditService $audit): TenantMembership
    {
        return DB::transaction(function () use ($membership, $data, $audit) {
            Tenant::query()->whereKey($membership->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['user_id', 'role', 'status']);
            $locked->update($data);
            $audit->record('membership.updated', $locked, $before, $locked->only(['user_id', 'role', 'status']));

            return $locked;
        });
    }

    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $owned = TenantMembership::query()
                ->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')
                ->orderBy('tenant_id')->get();

            $this->lockTenants($owned->pluck('tenant_id'));
            $this->assertUserDeletionRetainsOwners($user);
            $user->delete();
        });
    }

    public function assertMembershipMutationRetainsOwner(
        TenantMembership $membership,
        ?string $newRole,
        ?string $newStatus,
    ): void {
        $removesActiveOwner = $membership->getOriginal('role') === 'owner'
            && $membership->getOriginal('status') === 'active'
            && ($newRole !== 'owner' || $newStatus !== 'active');

        if ($removesActiveOwner && ! $this->hasAnotherActiveOwner($membership)) {
            throw ValidationException::withMessages([
                'role' => 'The final active owner cannot be demoted, deactivated, or removed.',
            ]);
        }
    }

    public function assertUserDeletionRetainsOwners(User $user): void
    {
        $owned = TenantMembership::query()
            ->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->get();

        foreach ($owned as $membership) {
            if (! $this->hasAnotherActiveOwner($membership)) {
                throw ValidationException::withMessages([
                    'password' => 'Transfer ownership before deleting an account that is the final active tenant owner.',
                ]);
            }
        }
    }

    private function hasAnotherActiveOwner(TenantMembership $membership): bool
    {
        return TenantMembership::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereKeyNot($membership->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->exists();
    }

    private function lockTenants(Collection $tenantIds): void
    {
        Tenant::query()->whereKey($tenantIds->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get();
    }
}
