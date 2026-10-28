<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ContentDocument;
use App\Models\Program;

/**
 * Read side for content. Keys of content_documents:
 *   config                    <- app_config.json
 *   questionnaire.onboarding  <- onboarding_questionnaire.json
 *   support_resources.<region> <- support_resources_<region>.json
 */
class ContentRepository
{
    public const CONFIG = 'config';

    public const ONBOARDING = 'questionnaire.onboarding';

    private ?array $config = null;

    /** @var array<string, Program> */
    private array $programs = [];

    public function document(string $key): ContentDocument
    {
        return ContentDocument::find($key)
            ?? throw new ApiException('content_not_found', 'This content isn\'t available yet.', 404);
    }

    /** app_config merged over safe defaults (used by ProgressionService). */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $stored = ContentDocument::find(self::CONFIG)?->data() ?? [];
        $defaults = config('mindease.defaults');
        $stored['phaseRequirements'] = array_merge($defaults['phaseRequirements'], $stored['phaseRequirements'] ?? []);

        return $this->config = array_merge($defaults, $stored);
    }

    public function program(string $programId): Program
    {
        return $this->programs[$programId] ??= Program::where('id', $programId)->where('status', '!=', 'hidden')->first()
            ?? throw new ApiException('program_not_found', 'We couldn\'t find that program.', 404);
    }
}
