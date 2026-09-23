<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Money;

/**
 * Remplit les modèles d'emails : chaque {variable} est remplacée par la
 * valeur du client ou du document concerné.
 */
class EmailComposer
{
    public const VARIABLES = [
        'salutation' => 'Bonjour Madame Dupont, / Bonjour,',
        'client' => 'Nom du client',
        'numero' => 'N° du devis ou de la facture',
        'document' => '« le devis », « la facture d\'acompte »…',
        'document_titre' => '« Devis », « Facture d\'acompte »…',
        'objet' => 'Objet des travaux',
        'montant' => 'Montant total',
        'reste_a_payer' => 'Reste à payer',
        'date_validite' => 'Validité du devis',
        'echeance' => '« payable à réception » / « à régler avant le … »',
        'date_echeance' => 'Date d\'échéance',
        'adresse_chantier' => 'Adresse des travaux',
        'entreprise' => 'Nom commercial',
        'telephone' => 'Votre téléphone',
        'email_entreprise' => 'Votre email',
        'site' => 'Votre site internet',
        'lien' => 'Lien pour consulter (et accepter) en ligne',
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return array{subject: string, body: string} */
    public function render(EmailTemplate $template, Client $client, Quote|Invoice|null $document = null): array
    {
        $values = $this->values($client, $document);

        return [
            'subject' => $this->replace($template->subject, $values),
            'body' => $this->replace($template->body, $values),
        ];
    }

    /** Texte libre (message SMS / WhatsApp) avec ses variables remplies. */
    public function renderText(string $text, Client $client, Quote|Invoice|null $document = null): string
    {
        return $this->replace($text, $this->values($client, $document));
    }

    /** @return array<string, string> */
    public function values(Client $client, Quote|Invoice|null $document = null): array
    {
        $company = $this->settings->group('company');

        $values = [
            'salutation' => $this->salutation($client),
            'client' => $client->displayName(),
            'entreprise' => (string) $company['trade_name'],
            'telephone' => (string) $company['phone'],
            'email_entreprise' => (string) $company['email'],
            'site' => (string) ($company['website'] ?? ''),
            'adresse_chantier' => (string) ($document?->worksite?->fullAddress() ?? $client->fullAddress() ?? ''),
            'lien' => '', 'numero' => '', 'document' => '', 'document_titre' => '', 'objet' => 'vos travaux',
            'montant' => '', 'reste_a_payer' => '', 'date_validite' => '', 'echeance' => '', 'date_echeance' => '',
        ];

        if ($document) {
            // Brouillon : {numero} reste tel quel et sera remplacé à l'envoi par le numéro définitif.
            $values['numero'] = $document->number ?? '{numero}';
            $values['lien'] = $document->number ? $document->publicUrl() : '{lien}';
            $values['objet'] = $document->title ? mb_strtolower(mb_substr($document->title, 0, 1)).mb_substr($document->title, 1) : 'vos travaux';
            $values['montant'] = $this->money($document->total_ttc);
        }

        if ($document instanceof Quote) {
            $values['document'] = 'le devis';
            $values['document_titre'] = 'Devis';
            $values['date_validite'] = $document->valid_until?->format('d/m/Y') ?? today()->addDays($document->validity_days)->format('d/m/Y');
        }

        if ($document instanceof Invoice) {
            $labels = [
                'standard' => 'la facture', 'deposit' => 'la facture d\'acompte', 'progress' => 'la facture de situation',
                'final' => 'la facture de solde', 'credit' => 'l\'avoir',
            ];
            $values['document'] = $labels[$document->kind] ?? 'la facture';
            $values['document_titre'] = $document->kindLabel();
            $values['reste_a_payer'] = $this->money($document->balance());
            $due = $document->due_date ?? today()->addDays($document->due_days);
            $values['date_echeance'] = $document->due_days === 0 ? 'à réception' : $due->format('d/m/Y');
            $values['echeance'] = $document->due_days === 0 ? 'payable à réception' : 'à régler avant le '.$due->format('d/m/Y');
        }

        return $values;
    }

    private function salutation(Client $client): string
    {
        if ($client->isIndividual() && $client->last_name) {
            $title = match ($client->civility) {
                'Mme' => 'Madame',
                'M.' => 'Monsieur',
                'M. et Mme' => 'Madame, Monsieur',
                default => null,
            };
            if ($title) {
                return "Bonjour $title {$client->last_name},";
            }
        }

        return 'Bonjour,';
    }

    private function money(int $cents): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', Money::format($cents));
    }

    /** @param  array<string, string>  $values */
    private function replace(string $text, array $values): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $values[$m[1]] ?? $m[0], $text);
    }
}
