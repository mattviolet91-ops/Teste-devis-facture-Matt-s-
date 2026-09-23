<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\Worksite;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enregistrement des photos de chantier : redressement selon l'orientation
 * de l'appareil, redimensionnement (2 000 px maximum), compression JPEG et
 * miniature. Les fichiers restent dans le dossier privé.
 */
class PhotoService
{
    public const MAX_SIZE = 2000;

    public const THUMB_SIZE = 480;

    public function store(UploadedFile $file, Worksite $worksite, string $category, ?string $caption = null): Photo
    {
        $image = $this->load($file->getRealPath());
        $image = $this->orient($image, $file->getRealPath());
        $full = $this->resize($image, self::MAX_SIZE);
        $thumb = $this->resize($image, self::THUMB_SIZE);

        $base = 'photos/'.$worksite->id.'/'.now()->format('Ymd-His').'-'.Str::random(8);
        $path = $base.'.jpg';
        $thumbPath = $base.'-mini.jpg';
        $this->save($full, $path, 82);
        $this->save($thumb, $thumbPath, 75);

        $photo = new Photo(['category' => $category, 'caption' => $caption]);
        $photo->worksite()->associate($worksite);
        $photo->forceFill([
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => imagesx($full),
            'height' => imagesy($full),
            'size' => Storage::disk('local')->size($path),
            'taken_at' => $this->takenAt($file->getRealPath()),
            'created_by' => auth()->id(),
        ])->save();

        return $photo;
    }

    /** Enregistre la version annotée (image dessinée dans le navigateur). */
    public function annotate(Photo $photo, UploadedFile $file): Photo
    {
        $image = $this->resize($this->load($file->getRealPath()), self::MAX_SIZE);
        $path = Str::beforeLast($photo->path, '.').'-annotee-'.Str::random(4).'.jpg';
        $thumbPath = Str::beforeLast($photo->path, '.').'-mini-annotee-'.Str::random(4).'.jpg';
        $this->save($image, $path, 82);
        $this->save($this->resize($image, self::THUMB_SIZE), $thumbPath, 75);

        $old = array_filter([$photo->annotated_path, $photo->annotated_path ? $photo->thumb_path : null]);
        Storage::disk('local')->delete($old);

        $photo->forceFill(['annotated_path' => $path, 'thumb_path' => $thumbPath])->save();

        return $photo;
    }

    /** Retire l'annotation : retour à la photo d'origine. */
    public function removeAnnotation(Photo $photo): Photo
    {
        if (! $photo->annotated_path) {
            return $photo;
        }

        $thumbPath = Str::beforeLast($photo->path, '.').'-mini-'.Str::random(4).'.jpg';
        $this->save($this->resize($this->load(Storage::disk('local')->path($photo->path)), self::THUMB_SIZE), $thumbPath, 75);
        Storage::disk('local')->delete([$photo->annotated_path, $photo->thumb_path]);
        $photo->forceFill(['annotated_path' => null, 'thumb_path' => $thumbPath])->save();

        return $photo;
    }

    public function delete(Photo $photo): void
    {
        Storage::disk('local')->delete(array_filter([$photo->path, $photo->thumb_path, $photo->annotated_path]));
        $photo->delete();
    }

    private function load(string $file): GdImage
    {
        $data = @file_get_contents($file);
        $image = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Image illisible (formats acceptés : JPEG, PNG, WebP).');
        }

        return $image;
    }

    /** Applique l'orientation EXIF (photos prises téléphone tourné). */
    private function orient(GdImage $image, string $file): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function resize(GdImage $image, int $max): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    private function save(GdImage $image, string $path, int $quality): void
    {
        ob_start();
        imageinterlace($image, true);
        imagejpeg($image, null, $quality);
        Storage::disk('local')->put($path, (string) ob_get_clean());
    }

    private function takenAt(string $file): ?string
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $date = @exif_read_data($file)['DateTimeOriginal'] ?? null;
        if (! $date || ! preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $date)) {
            return null;
        }

        return str_replace(':', '-', substr($date, 0, 10)).substr($date, 10);
    }
}
