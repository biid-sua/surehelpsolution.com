<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Services\Training\TrainingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training files are private (D42): served only to people who may take or manage the course,
 * checked on every request. Videos and audio support seeking (range requests).
 */
class TrainingFileController extends Controller
{
    /** Shown in the browser; everything else downloads. */
    private const INLINE = ['application/pdf', 'video/', 'audio/', 'image/'];

    public function __construct(private readonly TrainingAccess $access) {}

    /** A lesson file of a published version, for learners. */
    public function lesson(Request $request, TrainingCourse $course, int $version, string $lesson): Response
    {
        abort_unless($this->access->canLearn($request->user(), $course) && $version <= $course->current_version, 404);
        $file = $course->version($version)?->lesson($lesson)['file'] ?? null;
        abort_unless(is_array($file), 404);

        return $this->send($file['disk'], $file['path'], $file['name'], $file['mime']);
    }

    /** A lesson file in the draft, for the people editing the course. */
    public function draft(Request $request, TrainingCourse $course, string $lesson): Response
    {
        abort_unless($this->access->canManage($request->user(), $course), 404);
        $model = TrainingLesson::query()->where('course_id', $course->id)->where('ulid', $lesson)->whereNotNull('file_path')->first();
        abort_unless($model !== null, 404);

        return $this->send((string) $model->file_disk, (string) $model->file_path, (string) $model->file_name, (string) $model->file_mime);
    }

    public function thumbnail(Request $request, TrainingCourse $course): Response
    {
        $user = $request->user();
        abort_unless($course->thumbnail_path && ($this->access->canLearn($user, $course) || $this->access->canManage($user, $course)), 404);

        return $this->send((string) config('training.disk'), $course->thumbnail_path, 'cover', null);
    }

    private function send(string $disk, string $path, string $name, ?string $mime): Response
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $mime ??= $storage->mimeType($path) ?: 'application/octet-stream';
        $inline = collect(self::INLINE)->contains(fn (string $type) => str_starts_with($mime, $type));
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600'];

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            $response = response()->file($storage->path($path), $headers);
            $response->setContentDisposition($inline ? 'inline' : 'attachment', $name, 'file');

            return $response;
        }

        return $storage->response($path, $name, $headers, $inline ? 'inline' : 'attachment');
    }
}
