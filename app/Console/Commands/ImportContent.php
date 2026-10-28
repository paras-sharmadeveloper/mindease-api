<?php

namespace App\Console\Commands;

use App\Services\ContentImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportContent extends Command
{
    protected $signature = 'mindease:import-content
        {path? : Folder with the app\'s content JSON (default: database/content)}
        {--force : Allow removing ids that already have user progress}';

    protected $description = 'Import MindEase content JSON (programs, config, questionnaire, support resources)';

    public function handle(ContentImporter $importer): int
    {
        $path = $this->argument('path') ?: database_path('content');

        try {
            foreach ($importer->importDirectory($path, (bool) $this->option('force')) as $line) {
                $this->line("  <info>✓</info> {$line}");
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Content imported. Clients will pick up new ETags on next request.');

        return self::SUCCESS;
    }
}
