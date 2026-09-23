<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * PDF des devis, factures et avoirs (mPDF, aux couleurs de l'entreprise).
 *
 * Un brouillon est généré à la demande avec la mention « BROUILLON ». Un
 * document envoyé est figé : son PDF est enregistré à l'envoi (avec son
 * empreinte SHA-256) et c'est toujours ce fichier qui est renvoyé ensuite.
 */
class PdfService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly DocumentCalculator $calculator,
    ) {}

    /** Contenu PDF du document : le fichier figé s'il existe, sinon une génération. */
    public function content(Quote|Invoice $document): string
    {
        if ($document->isDraft()) {
            return $this->render($document);
        }

        $snapshot = $document->snapshot;
        if (! $snapshot || ! Storage::disk('local')->exists($snapshot->path)) {
            $snapshot = $this->freeze($document);
        }

        return Storage::disk('local')->get($snapshot->path);
    }

    /** Génère et enregistre le PDF d'un document envoyé. */
    public function freeze(Quote|Invoice $document): Snapshot
    {
        $pdf = $this->render($document);
        $folder = $document instanceof Quote ? 'devis' : 'factures';
        $path = "documents/$folder/".$document->number.'-'.Str::random(8).'.pdf';

        Storage::disk('local')->put($path, $pdf);

        $snapshot = $document->snapshot()->create([
            'path' => $path,
            'sha256' => hash('sha256', $pdf),
            'size' => strlen($pdf),
        ]);
        $document->setRelation('snapshot', $snapshot);

        return $snapshot;
    }

    /** Nom du fichier proposé : « Devis DEV-2026-0001 - Dupont.pdf ». */
    public function filename(Quote|Invoice $document): string
    {
        $title = ($document instanceof Quote ? 'Devis' : $document->kindLabel()).' '.($document->number ?? 'brouillon');
        $client = $document->client?->displayName();

        return Str::of($title.($client ? ' - '.$client : ''))->ascii()->replaceMatches('/[^A-Za-z0-9 ._\'-]/', '')->squish().'.pdf';
    }

    public function render(Quote|Invoice $document): string
    {
        $html = view('pdf.document', $this->viewData($document))->render();

        $mpdf = $this->mpdf();
        $mpdf->SetTitle(Str::beforeLast($this->filename($document), '.pdf'));
        $mpdf->SetAuthor((string) $this->settings->get('company.trade_name'));
        if ($document->isDraft()) {
            $mpdf->SetWatermarkText('BROUILLON', 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** @return array<string, mixed> */
    private function viewData(Quote|Invoice $document): array
    {
        $document->loadMissing(['client', 'worksite', 'lines']);
        $isQuote = $document instanceof Quote;
        if (! $isQuote) {
            $document->loadMissing(['quote', 'cancels', 'corrects']);
        }

        return [
            'document' => $document,
            'isQuote' => $isQuote,
            'totals' => $this->calculator->calculate(
                $document->lines->map->toCalculation()->values()->all(),
                $document->discount_type,
                (int) $document->discount_value,
                $document->isFranchise(),
            ),
            'company' => $this->settings->group('company'),
            'bank' => $this->settings->group('bank'),
            'insurance' => $this->settings->group('insurance'),
            'pdf' => $this->settings->group('pdf'),
            'vat' => $this->settings->group('vat'),
            'colors' => $this->settings->group('branding'),
            'logo' => $this->logoPath(),
            'annexes' => $this->annexes($document, $isQuote),
        ];
    }

    /** @return array{presentation: bool, cgv: bool} */
    private function annexes(Quote|Invoice $document, bool $isQuote): array
    {
        $pdf = $this->settings->group('pdf');

        return [
            'presentation' => $isQuote && ! empty($pdf['presentation_enabled']) && trim((string) $pdf['presentation_text']) !== '',
            'cgv' => $isQuote && ! empty($pdf['cgv_enabled']) && trim((string) $pdf['cgv']) !== '',
        ];
    }

    private function logoPath(): ?string
    {
        $uploaded = $this->settings->get('branding.logo_path');
        if ($uploaded && Storage::disk('local')->exists($uploaded)) {
            return Storage::disk('local')->path($uploaded);
        }

        $default = public_path(config('entreprise.default_images.logo'));

        return is_file($default) ? $default : null;
    }

    private function mpdf(): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 22,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
            'fontDir' => array_merge($fontDirs, [resource_path('fonts')]),
            'fontdata' => $fontData + [
                'figtree' => [
                    'R' => 'Figtree_400Regular.ttf',
                    'I' => 'Figtree_400Regular_Italic.ttf',
                    'B' => 'Figtree_600SemiBold.ttf',
                ],
                'montserrat' => [
                    'R' => 'Montserrat_600SemiBold.ttf',
                    'B' => 'Montserrat_700Bold.ttf',
                ],
            ],
            'default_font' => 'figtree',
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
        ]);
    }
}
