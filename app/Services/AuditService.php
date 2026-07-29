<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    private const SENSITIVE = ['password', 'remember_token', 'token', 'secret', 'api_key'];

    public function record(string $action, Model $model, array $before = [], array $after = []): void
    {
        $clean = fn (array $values) => collect($values)->except(self::SENSITIVE)->all();
        AuditLog::create([
            'user_id' => auth()->id(), 'action' => $action,
            'auditable_type' => $model::class, 'auditable_id' => (string) $model->getKey(),
            'old_values' => $clean($before) ?: null, 'new_values' => $clean($after) ?: null,
        ]);
    }
}
