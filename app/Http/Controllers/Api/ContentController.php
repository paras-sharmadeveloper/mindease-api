<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Program;
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
     * The full program: phases, lessons, videos, activity and quiz. Same shape as `mhfa_program.json`.
     */
    #[Group('Programs', 'Program content (phases, lessons, videos, activity, quiz). Cacheable with ETag / If-None-Match.', weight: 4)]
    public function program(Request $request, string $programId): Response
    {
        $program = $this->content->program($programId);

        return ContentResponse::make($request, $program->body, $program->etag, $program->content_version);
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
