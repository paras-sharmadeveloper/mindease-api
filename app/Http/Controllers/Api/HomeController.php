<?php

namespace App\Http\Controllers\Api;

use App\Domain\ProgressionService;
use App\Domain\RecommendationEngine;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\ContentRepository;
use App\Services\Progress\LoadedProgress;
use App\Services\Progress\ProgressRepository;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Home', 'Everything the home screen shows, in one request.', weight: 3)]
class HomeController extends Controller
{
    /**
     * Get my home screen.
     *
     * - `continue`: the "Continue where you left off" card: the next thing to do in the current phase (a half-watched
     *   video first, then the user's preferred kind from the questionnaire, then lessons → videos → activity → quiz).
     *   `kind`: `learn` (lesson), `watch` (video, with `resumePosition` in seconds), `activity` or `quiz`.
     *   `null` when the program is finished (`programCompletedAt` is set).
     * - `focus`: the "Your focus" phases from the questionnaire, each with `unlocked`, `completed` and `percent`.
     *   Empty until the questionnaire is answered.
     * - `phases`: every phase with the same progress fields, for the program card.
     * - `points`, `showSupport` (show the support card), `questionnaireCompleted`.
     */
    public function show(Request $request, ContentRepository $content, ProgressRepository $repository, RecommendationEngine $engine): JsonResponse
    {
        $user = $request->user();
        $program = $content->program(AssessmentController::PROGRAM_ID);
        $data = $program->data();
        $rules = new ProgressionService($data, $content->config());
        $loaded = $repository->load($user->id, $program->id);

        $assessment = Assessment::find($user->id);
        $answers = (array) json_decode(json_encode($assessment?->answers ?? []), true);
        $recommendation = AssessmentController::recommendation($answers, $engine, $content);

        $phases = collect($rules->phaseIds())->map(fn (string $id) => $this->phaseSummary($rules, $loaded, $id))->keyBy('phaseId');

        return response()->json([
            'program' => ['id' => $program->id, 'title' => $data['title'] ?? null, 'contentVersion' => $program->content_version],
            'continue' => $this->next($rules, $loaded, $recommendation['preferredKind'] ?? null),
            'focus' => collect($recommendation['focusPhases'] ?? [])
                ->map(fn (array $f) => $f + array_diff_key($phases[$f['phaseId']] ?? [], $f))->values(),
            'phases' => $phases->values(),
            'points' => (int) $loaded->phaseCompletions->sum('points'),
            'programCompletedAt' => Iso::utc($loaded->programCompletion?->completed_at),
            'showSupport' => $recommendation['showSupport'] ?? false,
            'questionnaireCompleted' => $assessment?->completed_at !== null,
        ]);
    }

    private function phaseSummary(ProgressionService $rules, LoadedProgress $loaded, string $phaseId): array
    {
        $phase = $rules->phase($phaseId);
        $c = $rules->counts($phaseId, $loaded->state);
        $total = $c['lessons']['total'] + $c['videos']['total'] + ($rules->activity($phaseId) ? 1 : 0) + ($rules->quiz($phaseId) ? 1 : 0);
        $done = $c['lessons']['done'] + $c['videos']['done'] + ($c['activity'] ? 1 : 0) + ($c['quiz'] ? 1 : 0);

        return [
            'phaseId' => $phaseId,
            'number' => $phase['number'] ?? null,
            'title' => $phase['title'] ?? null,
            'color' => $phase['color'] ?? null,
            'unlocked' => $rules->isUnlocked($phaseId, $loaded->state),
            'completed' => $loaded->state->isPhaseCompleted($phaseId),
            'done' => $done,
            'total' => $total,
            'percent' => $total ? (int) round($done / $total * 100) : 0,
        ];
    }

    private function next(ProgressionService $rules, LoadedProgress $loaded, ?string $preferredKind): ?array
    {
        $state = $loaded->state;
        $phaseId = collect($rules->phaseIds())->first(fn ($id) => $rules->isUnlocked($id, $state) && ! $state->isPhaseCompleted($id));
        if ($phaseId === null) {
            return null;
        }

        $phase = $rules->phase($phaseId);
        $item = fn (string $kind, array $i, array $extra = []) => [
            'phaseId' => $phaseId,
            'phaseNumber' => $phase['number'] ?? null,
            'phaseTitle' => $phase['title'] ?? null,
            'kind' => $kind,
            'itemId' => $i['id'],
            'title' => $i['title'] ?? null,
        ] + $extra + ['percent' => $this->phaseSummary($rules, $loaded, $phaseId)['percent']];

        $video = fn (array $v) => $item('watch', $v, [
            'resumePosition' => (float) ($state->videos[$v['id']]['position'] ?? 0),
            'minutes' => (int) ceil(($v['duration'] ?? 0) / 60),
        ]);

        // A video the user stopped part-way is the most literal "where you left off".
        foreach ($phase['videos'] ?? [] as $v) {
            if (! $state->isVideoComplete($v['id']) && ($state->videos[$v['id']]['position'] ?? 0) > 0) {
                return $video($v);
            }
        }

        $candidates = [
            'learn' => fn () => ($l = collect($phase['lessons'] ?? [])->first(fn ($l) => ! $state->isLessonRead($l['id'])))
                ? $item('learn', $l, ['minutes' => $l['minutes'] ?? null]) : null,
            'watch' => fn () => ($v = collect($phase['videos'] ?? [])->first(fn ($v) => ! $state->isVideoComplete($v['id'])))
                ? $video($v) : null,
            'activity' => fn () => ($a = $rules->activity($phaseId)) && ! $state->isActivityComplete($phaseId) ? $item('activity', $a) : null,
        ];

        $order = array_unique(array_filter([$preferredKind, 'learn', 'watch', 'activity'], fn ($k) => isset($candidates[$k])));
        foreach ($order as $kind) {
            if ($next = $candidates[$kind]()) {
                return $next;
            }
        }

        $quiz = $rules->quiz($phaseId);

        return $quiz ? $item('quiz', $quiz + ['title' => ($phase['title'] ?? 'Phase').' quiz']) : null;
    }
}
