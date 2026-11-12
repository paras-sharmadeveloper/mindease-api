<?php

namespace Tests\Feature;

use App\Console\Commands\DummyVideos;
use App\Models\ContentDocument;
use App\Models\Program;
use App\Services\ContentRepository;
use App\Services\ContentImporter;

class DummyVideosTest extends ApiTestCase
{
    public function test_fills_only_empty_video_urls_and_clear_removes_them(): void
    {
        // A URL set by an admin must survive both runs.
        $data = Program::find('mhfa')->data();
        $data['phases'][0]['videos'][0]['mediaUrl'] = 'https://cdn.example.com/real.mp4';
        app(ContentImporter::class)->saveProgram($data);

        $this->artisan('mindease:dummy-videos')->assertSuccessful();

        $this->signIn();
        $program = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.videos.0.mediaUrl', 'https://cdn.example.com/real.mp4');
        $this->assertContains($program->json('phases.0.videos.1.mediaUrl'), DummyVideos::VIDEOS);
        $this->assertStringStartsWith(DummyVideos::THUMBNAIL_HOST, $program->json('phases.0.videos.1.thumbnailUrl'));
        $this->getJson('/v1/config')->assertJsonPath('previewVideosWithoutMedia', false);

        $this->artisan('mindease:dummy-videos --clear')->assertSuccessful();

        // Checked in the DB: within one test the route keeps its controller (and its content cache).
        $videos = Program::find('mhfa')->data()['phases'][0]['videos'];
        $this->assertSame('https://cdn.example.com/real.mp4', $videos[0]['mediaUrl']);
        $this->assertNull($videos[1]['mediaUrl']);
        $this->assertNull($videos[1]['thumbnailUrl']);
        $this->assertTrue(ContentDocument::find(ContentRepository::CONFIG)->data()['previewVideosWithoutMedia']);
    }
}
