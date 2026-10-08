<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Clé d'accès à l'API : seule son empreinte SHA-256 est enregistrée, la clé n'est affichée qu'une fois. */
class ApiToken extends Model
{
    /** Portées : ce que la clé permet. */
    public const SCOPES = [
        'claude' => 'Claude (devis brouillons)',
        'argent' => 'App Argent (lecture des paiements et frais)',
    ];

    protected $fillable = ['name', 'scope'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Crée une clé et renvoie sa valeur en clair (à copier tout de suite). */
    public static function issue(User $user, string $name, string $scope = 'claude'): string
    {
        $plain = 'mc_'.Str::random(48);
        $token = new self(['name' => $name, 'scope' => $scope]);
        $token->user()->associate($user);
        $token->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $plain))->first();
    }
}
