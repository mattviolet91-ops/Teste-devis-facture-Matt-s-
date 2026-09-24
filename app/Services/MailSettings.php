<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Connexion à Gmail pour l'envoi des emails. L'adresse et le mot de passe
 * d'application sont saisis dans Réglages → Emails ; à défaut, la
 * configuration du fichier .env s'applique.
 */
class MailSettings
{
    public function __construct(private readonly Settings $settings) {}

    public function username(): string
    {
        return (string) ($this->settings->get('mail.username') ?: $this->settings->get('company.email'));
    }

    public function hasPassword(): bool
    {
        return $this->password() !== null;
    }

    /** Envoi réel possible : Gmail configuré dans l'application ou serveur SMTP dans .env. */
    public function isConfigured(): bool
    {
        return $this->hasPassword() || ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function fromName(): string
    {
        return (string) ($this->settings->get('mail.from_name') ?: $this->settings->get('company.trade_name'));
    }

    /** Adresse en copie cachée de chaque envoi (votre propre boîte). */
    public function bccAddress(): ?string
    {
        return $this->settings->get('mail.bcc_self') ? ($this->settings->get('company.email') ?: null) : null;
    }

    /** Remplace la configuration d'envoi par Gmail si un mot de passe est enregistré. */
    public function apply(): void
    {
        $password = $this->password();
        if ($password === null) {
            return;
        }

        config([
            'mail.default' => 'gmail',
            'mail.mailers.gmail' => [
                'transport' => 'smtp',
                'scheme' => 'smtps',
                'host' => 'smtp.gmail.com',
                'port' => 465,
                'username' => $this->username(),
                'password' => $password,
                'timeout' => 20,
            ],
            'mail.from.address' => $this->username(),
            'mail.from.name' => $this->fromName(),
        ]);
    }

    public static function encrypt(string $password): string
    {
        return Crypt::encryptString(preg_replace('/\s+/', '', $password));
    }

    public function password(): ?string
    {
        $stored = $this->settings->get('mail.password');
        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }
}
