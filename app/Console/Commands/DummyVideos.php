<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use App\Models\Program;
use App\Services\ContentImporter;
use App\Services\ContentRepository;
use Illuminate\Console\Command;

/**
 * Fills empty video mediaUrl / thumbnailUrl with public sample videos so the app's player has something
 * to show before the client delivers the real videos. Only touches videos whose URL is null (real or
 * panel-edited URLs are never overwritten), so it is safe on a server where admins edit content.
 * --clear removes the sample URLs again (only ones this command set).
 */
class DummyVideos extends Command
{
    /** Public sample films, each 10+ minutes so 90% of any content `duration` can be played. */
    public const VIDEOS = [
        'https://archive.org/download/BigBuckBunny_328/BigBuckBunny_512kb.mp4',
        'https://archive.org/download/ElephantsDream/ed_1024_512kb.mp4',
        'https://archive.org/download/Sintel/sintel-2048-surround_512kb.mp4',
    ];

    public const THUMBNAIL_HOST = 'https://placehold.co/';

    protected $signature = 'mindease:dummy-videos {--clear : Remove the sample URLs this command set}';

    protected $description = 'Fill empty video URLs with sample videos and thumbnails (or remove them with --clear)';

    public function handle(ContentImporter $importer): int
    {
        $clear = (bool) $this->option('clear');
        $changed = 0;

        foreach (Program::all() as $program) {
            $data = $program->data();
            $count = 0;

            foreach ($data['phases'] ?? [] as $p => $phase) {
                foreach ($phase['videos'] ?? [] as $v => $video) {
                    $video = $clear ? self::without($video) : self::with($video, $phase, $v);
                    if ($video !== $data['phases'][$p]['videos'][$v]) {
                        $data['phases'][$p]['videos'][$v] = $video;
                        $count++;
                    }
                }
            }

            if ($count > 0) {
                $importer->saveProgram($data, meta: [
                    'status' => $program->status,
                    'display_order' => $program->display_order,
                    'cover_image' => $program->cover_image,
                ]);
                $changed += $count;
                $this->line("  <info>✓</info> {$program->id}: {$count} videos ".($clear ? 'cleared' : 'filled'));
            }
        }

        // The app shows its preview player while this is true; turn it off while sample videos are in.
        if ($config = ContentDocument::find(ContentRepository::CONFIG)) {
            $cfg = $config->data();
            if (($cfg['previewVideosWithoutMedia'] ?? null) !== $clear) {
                $cfg['previewVideosWithoutMedia'] = $clear;
                $importer->saveDocument(ContentRepository::CONFIG, $cfg);
                $this->line('  <info>✓</info> config: previewVideosWithoutMedia = '.($clear ? 'true' : 'false'));
            }
        }

        $this->info($changed ? "Done ({$changed} videos)." : 'Nothing to change.');

        return self::SUCCESS;
    }

    public static function with(array $video, array $phase, int $index): array
    {
        $number = $phase['number'] ?? null;
        $video['mediaUrl'] ??= self::VIDEOS[($index + (int) $number) % count(self::VIDEOS)];
        $video['thumbnailUrl'] ??= self::thumbnail($phase['color'] ?? '#9BB068', $number, $index + 1);

        return $video;
    }

    public static function without(array $video): array
    {
        if (in_array($video['mediaUrl'] ?? null, self::VIDEOS, true)) {
            $video['mediaUrl'] = null;
        }
        if (str_starts_with((string) ($video['thumbnailUrl'] ?? ''), self::THUMBNAIL_HOST)) {
            $video['thumbnailUrl'] = null;
        }

        return $video;
    }

    public static function thumbnail(string $color, ?int $phase, int $video): string
    {
        $bg = ltrim($color, '#') ?: '9BB068';
        $text = $phase ? "Phase {$phase} - Video {$video}" : "Video {$video}";

        return self::THUMBNAIL_HOST."640x360/{$bg}/FFFFFF/png?text=".rawurlencode($text);
    }
}
