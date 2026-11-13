<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfilePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Profile photo through POST /me (photo / removePhoto): files in public/profile_pics; older ones via GET /avatars/{file}. */
class ProfilePhotoTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(ProfilePhoto::DISK);
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

        $url = $this->post('/v1/me', ['photo' => $file], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');
        $this->assertMatchesRegularExpression('#^http.*/profile_pics/[A-Za-z0-9]{40}\.jpg$#', $url);
        $this->getJson('/v1/me')->assertJsonPath('photoUrl', $url);

        // The web server serves public/profile_pics directly; the file is there under the URL's name.
        $disk = Storage::disk(ProfilePhoto::DISK);
        $disk->assertExists(basename($url));
        $this->assertSame('profile_pics/'.basename($url), $this->user->fresh()->photo_path);
        $bytes = $disk->get(basename($url));
        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertSame([512, 512], [$w, $h]);
        $this->assertStringNotContainsString('GPS-SECRET-LOCATION', $bytes);
    }

    public function test_failed_write_keeps_the_old_photo_and_returns_503(): void
    {
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $oldPath = $this->user->fresh()->photo_path;

        // Storage that can't write (e.g. folder not writable by www-data on the server).
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with(ProfilePhoto::DISK)->andReturn($disk);

        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('b.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertStatus(503)->assertJsonPath('error.code', 'photo_unavailable');
        $this->assertSame($oldPath, $this->user->fresh()->photo_path);
    }

    public function test_new_upload_replaces_and_deletes_the_old_file(): void
    {
        $first = $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');
        $oldPath = $this->user->fresh()->photo_path;
        $second = $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('b.png', 100, 200)], ['Accept' => 'application/json'])->assertOk()->json('photoUrl');

        $this->assertNotSame($first, $second);
        Storage::disk(ProfilePhoto::DISK)->assertMissing(basename($oldPath));
        // Small photos are never scaled up.
        [$w, $h] = getimagesizefromstring(Storage::disk(ProfilePhoto::DISK)->get(basename($second)));
        $this->assertSame([100, 100], [$w, $h]);
    }

    public function test_delete_photo(): void
    {
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->photo_path;

        $this->postJson('/v1/me', ['removePhoto' => true])->assertOk()->assertJsonPath('photoUrl', null);
        Storage::disk(ProfilePhoto::DISK)->assertMissing(basename($path));
    }

    public function test_rejects_files_that_are_not_photos(): void
    {
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_photo');
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.gif')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_photo');
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.field', 'photo');
        $this->post('/v1/me', ['removePhoto' => 'maybe'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.field', 'removePhoto');
        $this->assertNull($this->user->fresh()->photo_path);
    }

    public function test_photo_uploads_are_rate_limited(): void
    {
        foreach (range(1, 20) as $i) {
            $this->post('/v1/me', ['photo' => UploadedFile::fake()->image("p{$i}.jpg", 50, 50)], ['Accept' => 'application/json'])->assertOk();
        }
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('p21.jpg', 50, 50)], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        // Saving other fields still works.
        $this->postJson('/v1/me', ['preferredName' => 'Bit'])->assertOk();
    }

    public function test_avatar_route_only_serves_avatar_names(): void
    {
        $this->get('/avatars/../../.env')->assertNotFound();
        $this->get('/avatars/'.str_repeat('a', 40).'.jpg')->assertNotFound();
    }

    public function test_older_avatars_photos_still_work(): void
    {
        $name = str_repeat('b', 40).'.jpg';
        Storage::disk('local')->put('avatars/'.$name, 'jpeg-bytes');
        $this->user->forceFill(['photo_path' => 'avatars/'.$name])->save();

        $url = $this->getJson('/v1/me')->assertOk()->json('photoUrl');
        $this->assertStringEndsWith('/avatars/'.$name, $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // A new upload moves to profile_pics and removes the old private file.
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing('avatars/'.$name);
        $this->assertStringStartsWith('profile_pics/', $this->user->fresh()->photo_path);
    }

    public function test_deleting_the_account_deletes_the_photo(): void
    {
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->photo_path;

        $this->deleteJson('/v1/me')->assertNoContent(); // account deletion
        Storage::disk(ProfilePhoto::DISK)->assertMissing(basename($path));
    }

    public function test_photo_over_8_mb_says_too_large(): void
    {
        $this->post('/v1/me', ['photo' => UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.field', 'photo')
            ->assertJsonPath('error.message', 'This photo is too large. Choose one under 8 MB.');
        $this->assertNull($this->user->fresh()->photo_path);
    }

    public function test_photo_refused_by_php_upload_limit_says_too_large(): void
    {
        $file = new UploadedFile(__FILE__, 'big.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);
        $this->post('/v1/me', ['firstName' => 'Changed', 'photo' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.field', 'photo')
            ->assertJsonPath('error.message', 'This photo is too large. Choose one under 8 MB.');
        $this->assertNotSame('Changed', $this->user->fresh()->first_name); // nothing saved
    }

    public function test_request_over_php_post_limit_returns_413(): void
    {
        $this->call('POST', '/v1/me', [], [], [], ['CONTENT_LENGTH' => 1024 ** 4, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large')->assertJsonPath('error.field', 'photo');
    }
}
