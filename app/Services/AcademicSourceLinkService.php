<?php

namespace App\Services;

use App\Domain\AcademicSources\AcademicSourceOptions;
use App\Models\AcademicSource;
use App\Models\AcademicSourceLink;
use App\Models\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AcademicSourceLinkService
{
    public function resolves(string $type, int $id): bool
    {
        return $this->resolve($type, $id) !== null;
    }

    public function resolve(string $type, int $id): ?Model
    {
        $modelClass = AcademicSourceOptions::LINK_TYPES[$type] ?? null;
        if ($modelClass === null) {
            return null;
        }

        $query = $modelClass::query();
        if ($modelClass === GradeLevel::class) {
            $query->where('is_active', true);
        }

        return $query->find($id);
    }

    public function add(AcademicSource $source, string $type, int $id, AuditService $audit): AcademicSourceLink
    {
        if (! $this->resolves($type, $id)) {
            throw ValidationException::withMessages(['link_id' => 'The selected academic record is not available.']);
        }

        $link = $source->links()->firstOrCreate(['link_type' => $type, 'link_id' => $id]);
        if (! $link->wasRecentlyCreated) {
            throw ValidationException::withMessages(['link_id' => 'That source link already exists.']);
        }
        $audit->record('academic-source.linked', $link, [], $link->toArray());

        return $link;
    }
}
