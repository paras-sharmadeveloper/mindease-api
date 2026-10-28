<?php

namespace App\Services;

use App\Models\ContentDocument;
use App\Models\Program;
use App\Support\ContentResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports the Flutter app's assets/content/*.json into the database.
 * Files are stored verbatim (re-encoded compactly), so API responses keep the exact app shape.
 *
 *   app_config.json                 -> content_documents[config]
 *   onboarding_questionnaire.json   -> content_documents[questionnaire.onboarding]
 *   support_resources_<region>.json -> content_documents[support_resources.<region>]
 *   <anything>_program.json         -> programs[<json id>]
 */
class ContentImporter
{
    /** @return list<string> human-readable lines describing what was imported */
    public function importDirectory(string $dir, bool $force = false): array
    {
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        if ($files === []) {
            throw new InvalidArgumentException("No .json files found in {$dir}");
        }

        $log = [];
        DB::transaction(function () use ($files, $force, &$log) {
            foreach ($files as $file) {
                $log[] = $this->importFile($file, $force);
            }
        });

        return array_values(array_filter($log));
    }

    public function importFile(string $path, bool $force = false): ?string
    {
        $name = basename($path, '.json');
        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException("{$name}.json is not valid JSON: ".json_last_error_msg());
        }

        return match (true) {
            $name === 'app_config' => $this->storeDocument(ContentRepository::CONFIG, $this->checkConfig($data)),
            $name === 'onboarding_questionnaire' => $this->storeDocument(ContentRepository::ONBOARDING, $data),
            str_starts_with($name, 'support_resources_') => $this->storeDocument(
                'support_resources.'.strtolower(substr($name, strlen('support_resources_'))), $data),
            str_ends_with($name, '_program') => $this->storeProgram($data, $force),
            default => "skipped {$name}.json (unknown file)",
        };
    }

    private function storeDocument(string $key, array $data): string
    {
        $json = $this->encode($data);

        ContentDocument::updateOrCreate(['key' => $key], [
            'body' => $json,
            'content_version' => $data['contentVersion'] ?? $data['version'] ?? null,
            'etag' => ContentResponse::etagFor($json),
        ]);

        return "document {$key}";
    }

    private function storeProgram(array $data, bool $force): string
    {
        $this->checkProgram($data);
        $id = $data['id'];

        if (! $force) {
            $this->guardRemovedIds($id, $data);
        }

        $json = $this->encode($data);
        $existing = Program::find($id);

        Program::updateOrCreate(['id' => $id], [
            'title' => $data['title'],
            'short' => $data['short'] ?? null,
            'status' => $data['status'] ?? $existing?->status ?? 'active',
            'display_order' => $data['displayOrder'] ?? $existing?->display_order ?? 0,
            'cover_image' => $data['coverImage'] ?? $existing?->cover_image,
            'content_version' => $data['contentVersion'] ?? null,
            'body' => $json,
            'etag' => ContentResponse::etagFor($json),
        ]);

        return "program {$id} (".count($data['phases']).' phases, version '.($data['contentVersion'] ?? '-').')';
    }

    /** Same checks as the app's test/content_assets_test.dart, for the rules the server depends on. */
    public function checkProgram(array $p): void
    {
        $fail = fn (string $msg) => throw new InvalidArgumentException("Program ".($p['id'] ?? '?').": {$msg}");

        foreach (['id', 'title', 'phases'] as $key) {
            if (empty($p[$key])) {
                $fail("missing '{$key}'");
            }
        }

        $ids = [];
        $seen = function (string $id) use (&$ids, $fail) {
            if (isset($ids[$id])) {
                $fail("duplicate id '{$id}' (ids must be unique and stable)");
            }
            $ids[$id] = true;
        };

        foreach ($p['phases'] as $i => $phase) {
            if (empty($phase['id'])) {
                $fail("phase #{$i} has no id");
            }
            $seen($phase['id']);

            foreach ($phase['lessons'] ?? [] as $lesson) {
                $seen($lesson['id'] ?? $fail("a lesson in {$phase['id']} has no id"));
            }

            foreach ($phase['videos'] ?? [] as $video) {
                $seen($video['id'] ?? $fail("a video in {$phase['id']} has no id"));
                if (! is_int($video['duration'] ?? null) || $video['duration'] <= 0) {
                    $fail("video {$video['id']} needs a positive integer 'duration' in seconds");
                }
            }

            if (! empty($phase['activity'])) {
                $seen($phase['activity']['id'] ?? $fail("activity in {$phase['id']} has no id"));
            }

            if (! empty($phase['quiz'])) {
                $quiz = $phase['quiz'];
                $seen($quiz['id'] ?? $fail("quiz in {$phase['id']} has no id"));
                if (empty($quiz['questions'])) {
                    $fail("quiz {$quiz['id']} has no questions");
                }
                foreach ($quiz['questions'] as $q) {
                    $options = $q['options'] ?? [];
                    if (! is_int($q['answer'] ?? null) || $q['answer'] < 0 || $q['answer'] >= count($options)) {
                        $fail("question ".($q['id'] ?? '?')." has an 'answer' index outside its options (0-based)");
                    }
                }
            }
        }
    }

    private function checkConfig(array $c): array
    {
        foreach (['passThresholdPercent', 'pointsPerPhase', 'videoCompletePercent'] as $key) {
            if (isset($c[$key]) && (! is_numeric($c[$key]) || $c[$key] < 0)) {
                throw new InvalidArgumentException("app_config: '{$key}' must be a non-negative number");
            }
        }

        return $c;
    }

    /** Progress is stored against ids; refuse to drop ids that users already have progress on. */
    private function guardRemovedIds(string $programId, array $data): void
    {
        $phaseIds = $itemIds = [];
        foreach ($data['phases'] as $phase) {
            $phaseIds[] = $phase['id'];
            foreach (['lessons', 'videos'] as $list) {
                foreach ($phase[$list] ?? [] as $item) {
                    $itemIds[] = $item['id'];
                }
            }
        }

        $used = DB::table('phase_completions')->where('program_id', $programId)->distinct()->pluck('phase_id')
            ->merge(DB::table('lesson_reads')->where('program_id', $programId)->distinct()->pluck('lesson_id'))
            ->merge(DB::table('video_progress')->where('program_id', $programId)->distinct()->pluck('video_id'))
            ->unique();

        $missing = $used->diff(array_merge($phaseIds, $itemIds))->values();

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                "Program {$programId}: these ids have user progress but are missing from the new file: "
                .$missing->implode(', ').'. Keep ids stable, or re-run with --force if this is intended.'
            );
        }
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
