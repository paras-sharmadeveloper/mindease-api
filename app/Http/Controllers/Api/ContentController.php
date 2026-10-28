<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\ContentRepository;
use App\Support\ContentResponse;
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

    /** Same shape as assets/content/app_config.json */
    public function config(Request $request): Response
    {
        return $this->document($request, ContentRepository::CONFIG);
    }

    /** Same shape as onboarding_questionnaire.json */
    public function onboardingQuestionnaire(Request $request): Response
    {
        return $this->document($request, ContentRepository::ONBOARDING);
    }

    /** [{ id, title, short, status, displayOrder, coverImage }] */
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

    /** Same shape as mhfa_program.json (phases, lessons, videos, activity, quiz). */
    public function program(Request $request, string $programId): Response
    {
        $program = $this->content->program($programId);

        return ContentResponse::make($request, $program->body, $program->etag, $program->content_version);
    }

    /** Same shape as support_resources_au.json. ?region=AU (default AU). */
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
