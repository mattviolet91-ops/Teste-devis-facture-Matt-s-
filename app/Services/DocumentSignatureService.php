<?php

namespace App\Services;

use App\Models\Attachment;
use App\Support\PdfRewriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mpdf\Mpdf;
use Throwable;

/**
 * Documents à faire signer (bon de commande, autorisation de travaux, procès-verbal…) :
 * signés sur le téléphone de l'artisan ou par le client depuis son lien.
 * Le document signé est un PDF : les pages d'origine, une mention en bas de chaque page
 * et une page finale avec la signature, le nom, la date et l'empreinte du document d'origine.
 */
class DocumentSignatureService
{
    public function __construct(
        private readonly ClientLinkService $links,
        private readonly ScanService $scans,
        private readonly Settings $settings,
    ) {}

    /**
     * Prépare la signature : vérifie que le document peut être signé et crée son lien.
     *
     * @throws InvalidArgumentException message à montrer tel quel
     */
    public function request(Attachment $attachment): Attachment
    {
        if ($attachment->isSigned()) {
            return $attachment;
        }
        if (! $attachment->canBeSigned()) {
            throw new InvalidArgumentException('Seul un PDF ou une photo peut être signé. Scannez le document ou enregistrez-le en PDF.');
        }
        // Un PDF protégé ou abîmé ne peut pas recevoir la page de signature : on le dit tout de suite.
        try {
            $this->build($attachment, null, '');
        } catch (Throwable $e) {
            Log::info('Document impossible à préparer pour la signature', ['attachment' => $attachment->id, 'error' => $e->getMessage()]);
            throw new InvalidArgumentException('Ce PDF est protégé ou abîmé et ne peut pas être signé. Imprimez-le puis scannez-le avec « Scanner un document ».');
        }

        $attachment->forceFill([
            'public_token' => $attachment->public_token ?: Str::random(40),
            'signature_requested_at' => $attachment->signature_requested_at ?? now(),
        ])->save();
        ActivityLogger::log('attachment.signature_requested', "Signature demandée : {$attachment->name}", $attachment->client);

        return $attachment;
    }

    /** Annule la demande (le lien ne fonctionne plus). Un document signé le reste. */
    public function cancel(Attachment $attachment): void
    {
        if ($attachment->isSigned()) {
            return;
        }
        $attachment->forceFill(['public_token' => null, 'signature_requested_at' => null, 'viewed_at' => null])->save();
        ActivityLogger::log('attachment.signature_cancelled', "Demande de signature annulée : {$attachment->name}", $attachment->client);
    }

    /** Première ouverture du lien par le client : notification. */
    public function markViewed(Attachment $attachment): void
    {
        if ($attachment->viewed_at) {
            return;
        }
        $attachment->forceFill(['viewed_at' => now()])->saveQuietly();
        $this->links->notifyText(
            'Document consulté',
            "{$attachment->client?->displayName()} vient d'ouvrir le document « {$attachment->name} » à signer.",
            $this->adminUrl($attachment),
        );
    }

    /**
     * Signature : image PNG du tracé, nom, date, heure (et adresse IP en ligne).
     *
     * @throws InvalidArgumentException signature illisible
     */
    public function sign(Attachment $attachment, string $name, string $signatureDataUrl, ?string $ip, ?string $userAgent, bool $onSite): Attachment
    {
        $png = $this->links->decodeSignature($signatureDataUrl);
        $folder = dirname($attachment->path);

        $attachment = DB::transaction(function () use ($attachment, $name, $png, $ip, $userAgent, $onSite, $folder) {
            $signaturePath = $folder.'/signature-'.Str::random(24).'.png';
            Storage::disk('local')->put($signaturePath, $png);

            $attachment->forceFill([
                'public_token' => $attachment->public_token ?: Str::random(40),
                'signature_requested_at' => $attachment->signature_requested_at ?? now(),
                'signed_at' => now(),
                'signed_name' => $name,
                'signed_ip' => $onSite ? null : $ip,
                'signed_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'signed_on_site' => $onSite,
                'signature_path' => $signaturePath,
                'original_hash' => hash('sha256', (string) Storage::disk('local')->get($attachment->path)),
            ]);

            $signedPath = $folder.'/'.Str::random(40).'-signe.pdf';
            Storage::disk('local')->put($signedPath, $this->build($attachment, Storage::disk('local')->path($signaturePath), $name));
            $attachment->forceFill(['signed_path' => $signedPath])->save();

            ActivityLogger::log('attachment.signed', "Document « {$attachment->name} » signé ".($onSite ? 'sur place' : 'en ligne')." par $name", $attachment->client, ['ip' => $ip]);

            return $attachment;
        });

        if (! $onSite) {
            $this->links->notifyText(
                'Document signé',
                "{$attachment->client?->displayName()} a signé en ligne le document « {$attachment->name} » le ".now()->format('d/m/Y à H:i').'.',
                $this->adminUrl($attachment),
            );
        }

        return $attachment;
    }

    /** Supprime les fichiers liés à la signature (le document lui-même est supprimé par l'appelant). */
    public function deleteFiles(Attachment $attachment): void
    {
        foreach (array_filter([$attachment->signature_path, $attachment->signed_path]) as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    public function adminUrl(Attachment $attachment): string
    {
        [$route, $id] = $attachment->adminRoute();

        return ClientLinkService::adminUrl($route, $id).'#documents';
    }

    /**
     * PDF signé (ou, sans signature, simple essai d'ouverture du document).
     * Pages d'origine à leur taille, mention en bas de page, puis page de signature.
     */
    private function build(Attachment $attachment, ?string $signatureFile, string $name): string
    {
        $mpdf = $this->newMpdf();
        $source = Storage::disk('local')->path($attachment->path);
        $stamp = $signatureFile
            ? 'Signé électroniquement par '.$name.' le '.$attachment->signed_at->format('d/m/Y à H:i').' — voir la page de signature en fin de document'
            : null;
        $rewritten = null;

        try {
            if ($attachment->mime === 'application/pdf') {
                try {
                    $pages = $mpdf->setSourceFile($source);
                } catch (Throwable $e) {
                    // PDF récent (objets compressés) : réécrit en PDF classique, sans toucher aux pages.
                    if (str_contains($e->getMessage(), 'encrypted')) {
                        throw $e;
                    }
                    $rewritten = tempnam(sys_get_temp_dir(), 'pdf');
                    file_put_contents($rewritten, PdfRewriter::toClassic((string) file_get_contents($source)));
                    $mpdf = $this->newMpdf();
                    $pages = $mpdf->setSourceFile($rewritten);
                }
            }
            if ($attachment->mime === 'application/pdf') {
                for ($i = 1; $i <= $pages; $i++) {
                    $template = $mpdf->importPage($i);
                    $size = $mpdf->getTemplateSize($template);
                    $mpdf->AddPageByArray([
                        'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                        'sheet-size' => [$size['width'], $size['height']],
                        'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0,
                    ]);
                    $mpdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
                    $this->stamp($mpdf, $stamp, $size['height'], $i, $pages);
                }
            } else {
                $jpeg = $this->scans->jpegFrom($source);
                try {
                    [$w, $h] = getimagesize($jpeg) ?: [0, 0];
                    if (! $w || ! $h) {
                        throw new InvalidArgumentException('Image illisible.');
                    }
                    $mpdf->AddPageByArray(['sheet-size' => 'A4', 'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0]);
                    $scale = min(194 / $w, 275 / $h);
                    $mpdf->Image($jpeg, (210 - $w * $scale) / 2, 8, $w * $scale, $h * $scale, 'jpg', '', false, false);
                    $this->stamp($mpdf, $stamp, 297, 1, 1);
                } finally {
                    @unlink($jpeg);
                }
            }

            if ($signatureFile) {
                $mpdf->AddPageByArray(['sheet-size' => 'A4', 'orientation' => 'P', 'margin-left' => 18, 'margin-right' => 18, 'margin-top' => 18, 'margin-bottom' => 18]);
                $mpdf->WriteHTML($this->signaturePage($attachment, $signatureFile));
            }

            $mpdf->SetTitle($attachment->name);

            return $mpdf->Output('', 'S');
        } finally {
            if ($rewritten) {
                @unlink($rewritten);
            }
        }
    }

    private function newMpdf(): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'tempDir' => $tempDir,
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15]);
        $mpdf->SetTitle('Document signé');

        return $mpdf;
    }

    private function stamp(Mpdf $mpdf, ?string $text, float $pageHeight, int $page, int $pages): void
    {
        if (! $text) {
            return;
        }
        $mpdf->SetFont('dejavusans', '', 6.5);
        $mpdf->SetTextColor(90, 90, 90);
        $mpdf->WriteText(6, $pageHeight - 3, $text.' (page '.$page.'/'.$pages.')');
        $mpdf->SetTextColor(0, 0, 0);
    }

    private function signaturePage(Attachment $attachment, string $signatureFile): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $company = $this->settings->group('company');
        $client = $attachment->client;
        $rows = [
            'Document' => $attachment->name,
            'Rattaché à' => ucfirst($attachment->attachedToLabel()),
            'Client' => $client?->displayName(),
            'Signé par' => $attachment->signed_name,
            'Date et heure' => $attachment->signed_at->format('d/m/Y à H:i:s').' (heure de Paris)',
            'Mode' => $attachment->signed_on_site ? 'Signé sur place, en présence de l\'entreprise' : 'Signé en ligne depuis le lien personnel envoyé au client'.($attachment->signed_ip ? ' — adresse IP '.$attachment->signed_ip : ''),
            'Empreinte du document d\'origine (SHA-256)' => $attachment->original_hash,
        ];
        $html = '<h2 style="font-size:15pt;margin:0 0 4mm">Signature électronique du document</h2>'
            .'<p style="font-size:9.5pt;color:#555;margin:0 0 6mm">'.$e($company['trade_name'] ?? '').'</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:9.5pt">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="width:38%;padding:2mm 3mm 2mm 0;color:#555;vertical-align:top;border-bottom:0.2mm solid #ddd">'.$e($label).'</td>'
                .'<td style="padding:2mm 0;vertical-align:top;border-bottom:0.2mm solid #ddd;'.(str_contains($label, 'SHA') ? 'font-size:7.5pt;' : '').'">'.$e($value).'</td></tr>';
        }

        return $html.'</table>'
            .'<p style="font-size:10pt;margin:8mm 0 3mm"><strong>Le signataire déclare avoir lu le document ci-dessus et l\'accepte.</strong></p>'
            .'<div style="border:0.3mm solid #bbb;padding:3mm;width:80mm"><img src="'.$e($signatureFile).'" style="width:72mm"></div>';
    }
}
