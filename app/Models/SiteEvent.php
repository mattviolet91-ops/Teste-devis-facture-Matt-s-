<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Visite ou clic mesuré sur le site internet de l'entreprise (sans cookie, sans donnée personnelle). */
class SiteEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'pv' => 'Pages vues',
        'tel' => 'Clics « Appeler »',
        'mail' => 'Clics « Email »',
        'whatsapp' => 'Clics WhatsApp',
        'cta' => 'Clics sur un bouton devis / contact',
        'form' => 'Formulaires envoyés',
        'out' => 'Liens vers d\'autres sites',
    ];

    /** Types qui comptent comme une prise de contact. */
    public const CONTACT_TYPES = ['tel', 'mail', 'whatsapp', 'form'];

    protected $fillable = ['type', 'path', 'label', 'referrer_host', 'device', 'visitor'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
