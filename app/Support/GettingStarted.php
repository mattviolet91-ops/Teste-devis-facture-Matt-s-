<?php

namespace App\Support;

use App\Models\CatalogItem;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\InsuranceService;
use App\Services\MailSettings;
use App\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * « Bien démarrer » : ce qu'il faut régler pour que l'application soit prête.
 * Chaque point est vérifié par l'application elle-même (rien à cocher à la main).
 */
final class GettingStarted
{
    /** @return list<array{label: string, hint: string, done: bool, url: string}> */
    public static function items(User $user): array
    {
        $settings = app(Settings::class);
        $filled = fn (string ...$keys) => collect($keys)->every(fn ($k) => filled($settings->get($k)));
        $lastBackup = $settings->get('backups.last_download_at');
        $insurance = app(InsuranceService::class);

        return [
            ['label' => 'Coordonnées de l\'entreprise', 'hint' => 'Adresse, téléphone, email et SIRET : ils figurent sur chaque devis et facture.',
                'done' => $filled('company.address', 'company.phone', 'company.email', 'company.siret'), 'url' => route('settings.company')],
            ['label' => 'Coordonnées bancaires', 'hint' => 'L\'IBAN imprimé sur les factures pour être payé par virement.',
                'done' => $filled('bank.iban'), 'url' => route('settings.company')],
            ['label' => 'Assurance décennale à jour', 'hint' => 'Mention obligatoire sur les devis : assureur, contrat et date de fin.',
                'done' => $filled('insurance.insurer', 'insurance.policy_number') && ($insurance->daysLeft() ?? 0) >= 0, 'url' => route('settings.insurance')],
            ['label' => 'Conditions générales de vente', 'hint' => 'Jointes à vos devis.',
                'done' => (bool) $settings->get('pdf.cgv_enabled') && filled($settings->get('pdf.cgv')), 'url' => route('settings.documents')],
            ['label' => 'Bibliothèque de prestations', 'hint' => 'Au moins 5 prestations prêtes pour faire un devis en quelques touches.',
                'done' => CatalogItem::query()->active()->count() >= 5, 'url' => route('catalog.index')],
            ['label' => 'Envoi des emails', 'hint' => 'Votre messagerie reliée pour envoyer devis et factures.',
                'done' => app(MailSettings::class)->isConfigured(), 'url' => route('settings.emails')],
            ['label' => 'Notifications sur ce téléphone', 'hint' => 'Être prévenu d\'une nouvelle demande ou d\'un devis signé.',
                'done' => PushSubscription::query()->where('user_id', $user->id)->exists(), 'url' => route('settings.account')],
            ['label' => 'Sauvegarde téléchargée ce mois-ci', 'hint' => 'Une copie de vos données chez vous, en plus de celle du serveur.',
                'done' => $lastBackup && Carbon::parse($lastBackup)->gt(now()->subDays(31)), 'url' => route('settings.backups')],
        ];
    }

    /** @return array{done: int, total: int} */
    public static function progress(User $user): array
    {
        $items = self::items($user);

        return ['done' => count(array_filter($items, fn ($i) => $i['done'])), 'total' => count($items)];
    }
}
