<?php

namespace App\Models\Concerns;

/**
 * Transforme les modifications en attente d'un modèle en lignes lisibles
 * pour le journal : [« champ », « avant », « après »].
 */
trait DescribesChanges
{
    /** @return array<string, string> libellé de chaque champ suivi */
    abstract protected function fieldLabels(): array;

    /** Valeur affichée (libellés des listes de choix, etc.). */
    protected function displayValue(string $field, mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /** @return list<array{champ: string, avant: string, apres: string}> */
    public function describeChanges(): array
    {
        $labels = $this->fieldLabels();
        $changes = [];

        foreach ($this->getDirty() as $field => $new) {
            if (! isset($labels[$field])) {
                continue;
            }
            $before = $this->displayValue($field, $this->getOriginal($field));
            $after = $this->displayValue($field, $this->getAttribute($field));
            if ($before !== $after) {
                $changes[] = ['champ' => $labels[$field], 'avant' => $before, 'apres' => $after];
            }
        }

        return $changes;
    }
}
