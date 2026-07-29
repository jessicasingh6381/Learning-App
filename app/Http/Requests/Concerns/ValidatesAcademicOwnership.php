<?php

namespace App\Http\Requests\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait ValidatesAcademicOwnership
{
    protected function visibleExists(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return Rule::exists($table, 'id')->where(
            fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope->whereNull('tenant_id')->orWhere('tenant_id', $tenantId),
            ),
        );
    }

    protected function tenantUnique(string $table, string $column, mixed $ignore = null): Unique
    {
        return Rule::unique($table, $column)
            ->where('ownership_key', 'tenant:'.app(TenantContext::class)->tenantId())
            ->ignore($ignore);
    }
}
