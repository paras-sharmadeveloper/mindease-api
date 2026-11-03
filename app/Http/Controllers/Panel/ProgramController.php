<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Models\Program;
use App\Services\ContentImporter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin: programs shown in the app. The app reads the same rows through GET /programs and GET /programs/{id},
 * so every save here is live in the app straight away (new ETag, so cached copies refresh).
 *
 * Content is saved with the same checks as `php artisan mindease:import-content`: ids must be unique,
 * quiz answers must point at an option, and ids that users already have progress on can't be removed.
 */
#[Group('Programs', 'Admin only. View programs, show or hide them in the app, and edit their content.', weight: 7)]
class ProgramController extends PanelController
{
    private const STATUSES = ['active', 'coming_soon', 'hidden'];

    /**
     * List programs.
     *
     * Every program, including hidden ones, sorted the way the app shows them.
     */
    public function index(): JsonResponse
    {
        $programs = Program::orderBy('display_order')->orderBy('title')->get();

        return response()->json($programs->map(fn (Program $p) => $this->summary($p)));
    }

    /**
     * Get a program.
     *
     * The list fields plus `content`: the full program JSON the app receives (phases, lessons, videos, activity, quiz).
     */
    public function show(string $programId): JsonResponse
    {
        $program = $this->find($programId);

        return response()->json($this->summary($program) + ['content' => $program->data()]);
    }

    /**
     * Add a program.
     *
     * Body: `{ content }`, a full program JSON (same shape as `mhfa_program.json`). Its `id` must be new.
     * Starts hidden unless `content.status` says otherwise, so it can be checked before the app shows it.
     */
    public function store(Request $request, ContentImporter $importer): JsonResponse
    {
        $data = $this->validatedContent($request);

        if (Program::whereKey($data['id'])->exists()) {
            throw new ApiException('program_exists', 'A program with this id already exists. Choose another id.', 409, 'content.id');
        }

        $this->save($importer, $data, ['status' => $data['status'] ?? 'hidden']);

        return response()->json($this->summary($this->find($data['id'])) + ['content' => $this->find($data['id'])->data()], 201);
    }

    /**
     * Update program details.
     *
     * Any of `title`, `short`, `status` (`active` | `coming_soon` | `hidden`), `displayOrder`, `coverImage`.
     * `hidden` removes the program from the app (and its progress endpoints) without deleting anything.
     */
    public function update(Request $request, string $programId, ContentImporter $importer): JsonResponse
    {
        $program = $this->find($programId);
        $input = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'short' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:'.implode(',', self::STATUSES)],
            'displayOrder' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'coverImage' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $data = $program->data();
        $meta = [];
        foreach (['title', 'short', 'status', 'displayOrder', 'coverImage'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            // Keep the app's JSON in step; fields the file never had stay out of it (the list columns hold them).
            if (in_array($key, ['title', 'short'], true) || array_key_exists($key, $data)) {
                $data[$key] = $input[$key];
            }
        }
        if (array_key_exists('status', $input)) {
            $meta['status'] = $input['status'];
        }
        if (array_key_exists('displayOrder', $input)) {
            $meta['display_order'] = $input['displayOrder'];
        }
        if (array_key_exists('coverImage', $input)) {
            $meta['cover_image'] = $input['coverImage'];
        }

        $this->save($importer, $data, $meta);

        return response()->json($this->summary($this->find($programId)));
    }

    /**
     * Replace program content.
     *
     * Body: `{ content }`, the full program JSON. `content.id` must match the URL. `422 invalid_content` explains
     * what is wrong, for example a duplicate id, a quiz answer outside its options, or a removed lesson that users have read.
     */
    public function replaceContent(Request $request, string $programId, ContentImporter $importer): JsonResponse
    {
        $program = $this->find($programId);
        $data = $this->validatedContent($request);

        if ($data['id'] !== $program->id) {
            throw new ApiException('invalid_content', 'The program id can\'t change. Keep "id": "'.$program->id.'".', 422, 'content.id');
        }

        $this->save($importer, $data, ['status' => $program->status]);
        $program = $this->find($programId);

        return response()->json($this->summary($program) + ['content' => $program->data()]);
    }

    private function find(string $programId): Program
    {
        return Program::find($programId)
            ?? throw new ApiException('program_not_found', 'We couldn\'t find that program.', 404);
    }

    private function validatedContent(Request $request): array
    {
        $request->validate([
            'content' => ['required', 'array'],
            'content.id' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'content.title' => ['required', 'string', 'max:255'],
            'content.phases' => ['required', 'array', 'min:1'],
        ], [
            'content.id.regex' => 'Use lowercase letters, numbers, - or _ for the program id.',
        ]);

        // The whole document is kept as sent (the app's shape), not only the validated keys.
        return $request->input('content');
    }

    private function save(ContentImporter $importer, array $data, array $meta): void
    {
        try {
            DB::transaction(fn () => $importer->saveProgram($data, false, $meta));
        } catch (InvalidArgumentException $e) {
            throw new ApiException('invalid_content', $e->getMessage(), 422, 'content');
        }
    }

    private function summary(Program $p): array
    {
        $phases = $p->data()['phases'] ?? [];

        return [
            'id' => $p->id,
            'title' => $p->title,
            'short' => $p->short,
            'status' => $p->status,
            'displayOrder' => $p->display_order,
            'coverImage' => $p->cover_image,
            'contentVersion' => $p->content_version,
            'counts' => [
                'phases' => count($phases),
                'lessons' => array_sum(array_map(fn ($ph) => count($ph['lessons'] ?? []), $phases)),
                'videos' => array_sum(array_map(fn ($ph) => count($ph['videos'] ?? []), $phases)),
                'quizzes' => count(array_filter($phases, fn ($ph) => ! empty($ph['quiz']))),
            ],
            'updatedAt' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }
}
