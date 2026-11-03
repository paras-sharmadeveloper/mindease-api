<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** POST/DELETE /me/photo and GET /avatars/{file}. */
class ProfilePhotoTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->signIn();
    }

    /** A real JPEG with EXIF data, to prove it is stripped. */
    private function jpegWithExif(int $w = 1200, int $h = 800): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // Minimal APP1 Exif segment with a marker string, inserted after SOI.
        $exif = "Exif\0\0".'GPS-SECRET-LOCATION';
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $path = tempnam(sys_get_temp_dir(), 'ph').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'me.jpg', 'image/jpeg', null, true);
    }

    public function test_upload_crops_resizes_and_strips_metadata(): void
    {
        $file = $this->jpegWithExif();
        $this->assertStringContainsString('GPS-SECRET-LOCATION', file_get_contents($file->getRealPath()));

        $url = $this->post('/v1/me/photo', ['photo' => $file], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');
        $this->assertMatchesRegularExpression('#/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);
        $this->getJson('/v1/me')->assertJsonPath('photoUrl', $url);

        $res = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('immutable', $res->headers->get('Cache-Control'));
        $bytes = $res->getContent();
        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertSame([512, 512], [$w, $h]);
        $this->assertStringNotContainsString('GPS-SECRET-LOCATION', $bytes);
    }

    public function test_new_upload_replaces_and_deletes_the_old_file(): void
    {
        $first = $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');
        $oldPath = $this->user->fresh()->photo_path;
        $second = $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->image('b.png', 100, 200)], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($oldPath);
        $this->get(parse_url($first, PHP_URL_PATH))->assertNotFound();
        // Small photos are never scaled up.
        [$w, $h] = getimagesizefromstring($this->get(parse_url($second, PHP_URL_PATH))->getContent());
        $this->assertSame([100, 100], [$w, $h]);
    }

    public function test_delete_photo(): void
    {
        $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->photo_path;

        $this->deleteJson('/v1/me/photo')->assertOk()->assertJsonPath('photoUrl', null);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_rejects_files_that_are_not_photos(): void
    {
        $this->post('/v1/me/photo', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.field', 'photo');
        $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_photo');
        $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.gif')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_photo');
        $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.field', 'photo');
        $this->assertNull($this->user->fresh()->photo_path);
    }

    public function test_avatar_route_only_serves_avatar_names(): void
    {
        $this->get('/avatars/../../.env')->assertNotFound();
        $this->get('/avatars/'.str_repeat('a', 40).'.jpg')->assertNotFound();
    }

    public function test_deleting_the_account_deletes_the_photo(): void
    {
        $this->post('/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->photo_path;

        $this->deleteJson('/v1/me')->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }
}
