<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonResource;
use App\Models\Student;
use App\Services\LessonAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonResourceController extends Controller
{
    public function teacher(LessonPlan $lessonPlan, Lesson $lesson, LessonResource $resource): BinaryFileResponse|StreamedResponse
    {
        Gate::authorize('view', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id && $resource->lesson_id === $lesson->id, 404);
        return $this->serve($resource);
    }

    public function student(Request $request, Lesson $lesson, LessonResource $resource, LessonAvailabilityService $availability): BinaryFileResponse|StreamedResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $lesson->loadMissing('lessonPlan');
        $enrollment = $student->enrollments()->whereKey($lesson->lessonPlan->student_enrollment_id)
            ->where('status', 'active')->firstOrFail();
        abort_unless($resource->lesson_id === $lesson->id && $availability->canAccess($lesson, $enrollment), 404);
        return $this->serve($resource);
    }

    private function serve(LessonResource $resource): BinaryFileResponse|StreamedResponse
    {
        abort_unless($resource->category === 'lesson_resource' && $resource->isAvailable(), 404);
        $disk = Storage::disk($resource->asset_disk);
        abort_unless($disk->exists($resource->asset_path), 404);
        if ($resource->checksum_sha256) {
            $stream = $disk->readStream($resource->asset_path);
            abort_unless(is_resource($stream), 404);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            abort_unless(hash_equals($resource->checksum_sha256, hash_final($hash)), 404);
        }
        $headers = ['Content-Type' => $resource->mime_type ?: 'application/octet-stream'];
        return $resource->delivery_type === 'downloadable'
            ? $disk->download($resource->asset_path, $resource->original_filename ?: basename($resource->asset_path), $headers)
            : $disk->response($resource->asset_path, $resource->original_filename ?: basename($resource->asset_path), $headers);
    }
}
