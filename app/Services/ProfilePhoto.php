<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile photos. Every upload is decoded and re-encoded as a 512x512 JPEG, which:
 *  - drops EXIF metadata (phone photos can carry GPS location),
 *  - applies the EXIF rotation first, so portrait photos aren't sideways,
 *  - rejects anything that isn't really an image.
 * Files live in public/profile_pics/ (disk `profile_pics`) with random 40-character names, served straight by
 * the web server at /profile_pics/<name>.jpg. Older photos (photo_path `avatars/...`) stay on the private `local`
 * disk and are still served by GET /avatars/{file}.
 */
class ProfilePhoto
{
    public const SIZE = 512;

    public const DIR = 'profile_pics';

    public const DISK = 'profile_pics';

    /** Photos uploaded before profile_pics: private `local` disk, served by GET /avatars/{file}. */
    public const LEGACY_DIR = 'avatars';

    private const MAX_PIXELS = 40_000_000; // refuse "decompression bomb" images

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    public function replace(User $user, UploadedFile $file): void
    {
        $this->store($user, $this->encode($file));
    }

    /** Checks and converts the upload to the final JPEG bytes, without saving anything. Throws 422/503. */
    public function encode(UploadedFile $file): string
    {
        if (! self::available()) {
            throw new ApiException('photo_unavailable', 'Photo uploads aren\'t available right now. Please try again later.', 503);
        }

        $invalid = new ApiException('invalid_photo', 'Choose a JPG, PNG or WebP photo.', 422, 'photo');
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw $invalid;
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ApiException('invalid_photo', 'This photo is too large. Choose a smaller one.', 422, 'photo');
        }

        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw $invalid;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $src = $this->applyExifRotation($src, $file->getRealPath());
        }

        // Centre square crop, then scale down (never up) to SIZE.
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = min(self::SIZE, $side);
        $dst = imagecreatetruecolor($out, $out);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $out, $out, $side, $side);

        ob_start();
        imagejpeg($dst, null, 85);

        return (string) ob_get_clean();
    }

    /** Saves encoded JPEG bytes as the user's photo and deletes the previous file. */
    public function store(User $user, string $jpeg): void
    {
        $name = Str::random(40).'.jpg';

        // The disk doesn't throw, so check: a failed write must not save a URL that 404s.
        if (! Storage::disk(self::DISK)->put($name, $jpeg)) {
            report(new \RuntimeException('Profile photo not saved: '.public_path(self::DIR).' is missing or not writable by the web server user.'));

            throw new ApiException('photo_unavailable', 'Photo uploads aren\'t available right now. Please try again later.', 503);
        }

        $old = $user->photo_path;
        $user->forceFill(['photo_path' => self::DIR.'/'.$name])->save();
        $this->deleteFile($old);
    }

    public function remove(User $user): void
    {
        $old = $user->photo_path;
        $user->forceFill(['photo_path' => null])->save();
        $this->deleteFile($old);
    }

    public function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIR.'/')) {
            Storage::disk(self::DISK)->delete(basename($path));
        } elseif ($path && str_starts_with($path, self::LEGACY_DIR.'/')) {
            Storage::disk('local')->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, self::LEGACY_DIR.'/')
            ? url('/avatars/'.basename($path))
            : url('/'.self::DIR.'/'.basename($path));
    }

    private function applyExifRotation($img, string $path)
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $rotated = match ((int) $orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        return $rotated ?: $img;
    }
}
