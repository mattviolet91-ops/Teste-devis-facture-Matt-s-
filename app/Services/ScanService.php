<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Mpdf\Mpdf;

/**
 * « Scanner un document » : les photos prises avec le téléphone (une par page)
 * deviennent un seul PDF, pages redressées et allégées.
 */
class ScanService
{
    /** Largeur ou hauteur maximale d'une page scannée (pixels) : lisible et léger. */
    private const MAX_SIDE = 2000;

    /**
     * @param  list<UploadedFile>  $images
     * @return string contenu du PDF
     */
    public function toPdf(array $images, string $title): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8, 'tempDir' => $tempDir]);
        $pdf->SetTitle($title);

        $files = [];
        try {
            foreach ($images as $index => $image) {
                $files[] = $jpeg = $this->normalize($image);
                if ($index > 0) {
                    $pdf->AddPage();
                }
                // Page A4 (194 × 281 mm utiles) : l'image s'y adapte sans déformation.
                $pdf->WriteHTML('<div style="text-align:center"><img src="'.$jpeg.'" style="max-width:194mm; max-height:279mm;"></div>');
            }

            return $pdf->Output('', 'S');
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /** Photo redressée (orientation du téléphone), réduite et ré-enregistrée en JPEG. */
    private function normalize(UploadedFile $image): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($image->getRealPath()));
        if ($source === false) {
            throw new InvalidArgumentException('Photo illisible.');
        }

        $orientation = function_exists('exif_read_data') ? (int) (@exif_read_data($image->getRealPath())['Orientation'] ?? 1) : 1;
        $source = match ($orientation) {
            3 => imagerotate($source, 180, 0),
            6 => imagerotate($source, -90, 0),
            8 => imagerotate($source, 90, 0),
            default => $source,
        };

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, self::MAX_SIDE / max($width, $height));
        if ($ratio < 1) {
            $source = imagescale($source, (int) round($width * $ratio), (int) round($height * $ratio));
        }

        $base = tempnam(sys_get_temp_dir(), 'scan');
        @unlink($base);
        $path = $base.'.jpg';
        imagejpeg($source, $path, 82);

        return $path;
    }
}
