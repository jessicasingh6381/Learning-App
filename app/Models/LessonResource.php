<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonResource extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['student_supply', 'lesson_resource', 'special_material'];
    public const DELIVERY_TYPES = ['physical', 'embedded', 'viewable', 'printable', 'downloadable', 'interactive'];
    public const AVAILABILITY_STATUSES = ['not_applicable', 'needs_asset', 'ready', 'unavailable', 'archived'];

    protected $fillable = ['lesson_id', 'category', 'resource_type', 'title', 'description', 'delivery_type', 'availability_status', 'fulfillment_strategy', 'fulfillment_provider', 'source_url', 'source_attribution', 'license_name', 'license_url', 'sort_order', 'asset_disk', 'asset_path', 'original_filename', 'mime_type', 'checksum_sha256', 'file_size', 'generated_by', 'generated_at', 'fulfillment_attempted_at', 'fulfillment_error', 'metadata', 'validation_metadata'];
    protected function casts(): array { return ['sort_order' => 'integer', 'file_size' => 'integer', 'generated_at' => 'datetime', 'fulfillment_attempted_at' => 'datetime', 'metadata' => 'array', 'validation_metadata' => 'array']; }
    protected static function booted(): void
    {
        $message = fn () => throw \Illuminate\Validation\ValidationException::withMessages([
            'lesson' => 'Approved lesson resource definitions are immutable. Create a new lesson-plan revision instead.',
        ]);
        static::creating(fn (self $resource) => Lesson::query()->whereKey($resource->lesson_id)->where('status', 'approved')->exists() ? $message() : null);
        static::updating(function (self $resource) use ($message): void {
            $definitionFields = ['category', 'resource_type', 'title', 'description', 'delivery_type', 'source_url', 'source_attribution', 'license_name', 'license_url', 'sort_order', 'metadata'];
            if ($resource->isDirty($definitionFields) && $resource->lesson()->where('status', 'approved')->exists()) {
                $message();
            }
        });
        static::deleting(fn (self $resource) => $resource->lesson()->where('status', 'approved')->exists() ? $message() : null);
    }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function isAvailable(): bool { return $this->availability_status === 'ready' && $this->asset_disk && $this->asset_path; }
}
