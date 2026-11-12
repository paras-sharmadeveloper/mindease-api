<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\LessonRead;
use App\Models\Program;
use App\Models\VideoProgress;
use App\Services\ContentRepository;
use App\Support\ContentResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content endpoints return the app's JSON files verbatim, with ETag / X-Content-Version.
 * Send If-None-Match to get 304 when nothing changed.
 */
class ContentController extends Controller
{
    public function __construct(private ContentRepository $content) {}

    /**
     * Get app settings.
     *
     * Same shape as `assets/content/app_config.json`: pass mark, points per phase, video completion %, phase requirements.
     */
    #[Group('App settings & help', 'Settings and support numbers the app shows. Cacheable with ETag / If-None-Match.', weight: 3)]
    public function config(Request $request): Response
    {
        return $this->document($request, ContentRepository::CONFIG);
    }

    /**
     * Get the onboarding questionnaire.
     *
     * The questions shown after sign-up. Same shape as `onboarding_questionnaire.json`. Save the user's answers with `PUT /me/assessment`.
     */
    #[Group('Onboarding questionnaire', 'The onboarding questions (content) and the user\'s saved answers.', weight: 5)]
    public function onboardingQuestionnaire(Request $request): Response
    {
        return $this->document($request, ContentRepository::ONBOARDING);
    }

    /**
     * List programs.
     *
     * Programs for the Programs tab: `[{ id, title, short, status, displayOrder, coverImage }]`, sorted by `displayOrder`.
     */
    #[Group('Programs', 'Program content (phases, lessons, videos, activity, quiz). Cacheable with ETag / If-None-Match.', weight: 4)]
    public function programs(): JsonResponse
    {
        $programs = Program::where('status', '!=', 'hidden')
            ->orderBy('display_order')->orderBy('title')
            ->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'short' => $p->short,
                'status' => $p->status,
                'displayOrder' => $p->display_order,
                'coverImage' => $p->cover_image,
            ]);

        return response()->json($programs);
    }

    /**
     * Get a program.
     *
     * The full program: phases, lessons, videos, activity and quiz. Same shape as `mhfa_program.json`, plus
     * per-user status: `is_read` (true/false) on every lesson, right after `intro`, set by
     * `POST /me/programs/{programId}/lessons/{lessonId}/read`; and `is_video_watched` (true/false) on every
     * video, true once the video is complete through `POST /me/programs/{programId}/videos/{videoId}/progress`
     * (90% of its seconds played). The ETag is per user, so it changes when either status changes.
     */
    #[Group('Programs', 'Program content (phases, lessons, videos, activity, quiz). Cacheable with ETag / If-None-Match.', weight: 4)]
    public function program(Request $request, string $programId): Response
    {
        $program = $this->content->program($programId);
        $userId = $request->user()->id;

        $readIds = LessonRead::where('user_id', $userId)
            ->where('program_id', $program->id)
            ->orderBy('lesson_id')
            ->pluck('lesson_id')
            ->all();

        $watchedIds = VideoProgress::where('user_id', $userId)
            ->where('program_id', $program->id)
            ->whereNotNull('completed_at')
            ->orderBy('video_id')
            ->pluck('video_id')
            ->all();

        $json = $this->withUserStatus($program->body, array_flip($readIds), array_flip($watchedIds));
        $etag = '"'.sha1($program->etag.'|'.implode(',', $readIds).'|'.implode(',', $watchedIds)).'"';

        return ContentResponse::make($request, $json, $etag, $program->content_version);
    }

    /**
     * Adds `is_read` to every lesson (right after `intro`) and `is_video_watched` to every video (at the end).
     * Decodes as objects so `{}` stays `{}`.
     *
     * @param  array<string, int>  $readIds  lesson ids the user has read (as keys)
     * @param  array<string, int>  $watchedIds  video ids the user has completed (as keys)
     */
    private function withUserStatus(string $body, array $readIds, array $watchedIds): string
    {
        $data = json_decode($body);
        if (! is_object($data) || ! is_array($data->phases ?? null)) {
            return $body;
        }

        foreach ($data->phases as $phase) {
            if (! is_object($phase)) {
                continue;
            }
            if (is_array($phase->lessons ?? null)) {
                $phase->lessons = $this->flagItems($phase->lessons, 'is_read', $readIds, after: 'intro');
            }
            if (is_array($phase->videos ?? null)) {
                $phase->videos = $this->flagItems($phase->videos, 'is_video_watched', $watchedIds);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Sets `$flag` on each item (true when its id is in `$ids`), placed right after `$after`, or last.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, int>  $ids
     * @return array<int, mixed>
     */
    private function flagItems(array $items, string $flag, array $ids, ?string $after = null): array
    {
        foreach ($items as $i => $item) {
            if (! is_object($item)) {
                continue;
            }
            $value = isset($item->id) && isset($ids[$item->id]);
            $out = new \stdClass;
            $placed = false;
            foreach (get_object_vars($item) as $key => $v) {
                if ($key === $flag) {
                    continue;
                }
                $out->{$key} = $v;
                if ($key === $after) {
                    $out->{$flag} = $value;
                    $placed = true;
                }
            }
            if (! $placed) {
                $out->{$flag} = $value;
            }
            $items[$i] = $out;
        }

        return $items;
    }

    /**
     * Get support resources (Get Help).
     *
     * Support numbers for the Get Help screen. Same shape as `support_resources_au.json`. `?region=AU` (default AU).
     */
    #[Group('App settings & help', 'Settings and support numbers the app shows. Cacheable with ETag / If-None-Match.', weight: 3)]
    public function supportResources(Request $request): Response
    {
        $region = strtolower($request->validate(['region' => ['nullable', 'string', 'alpha', 'size:2']])['region'] ?? 'AU');

        return $this->document($request, 'support_resources.'.$region);
    }

    private function document(Request $request, string $key): Response
    {
        $doc = $this->content->document($key);

        return ContentResponse::make($request, $doc->body, $doc->etag, $doc->content_version);
    }
}
