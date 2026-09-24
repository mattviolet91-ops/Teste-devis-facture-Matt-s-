<?php

namespace App\Services;

use App\Models\Intervention;

/** Messages au client pour le planning : confirmation et rappel. */
class PlanningMessages
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EmailComposer $composer,
    ) {}

    public function confirmation(Intervention $intervention): string
    {
        return $this->render($intervention->isAppointment() ? 'mail.appointment' : 'mail.intervention', $intervention);
    }

    public function reminderSubject(Intervention $intervention): string
    {
        return $this->render('mail.visit_reminder_subject', $intervention);
    }

    public function reminder(Intervention $intervention): string
    {
        return $this->render('mail.visit_reminder', $intervention);
    }

    private function render(string $key, Intervention $intervention): string
    {
        $when = $intervention->whenLabel();
        $text = strtr((string) $this->settings->get($key), [
            '{date_intervention}' => ($intervention->days() > 1 ? '' : 'le ').$when,
            '{date_rdv}' => ($intervention->days() > 1 ? '' : 'le ').$when,
            '{objet_rdv}' => mb_strtolower(mb_substr($intervention->title, 0, 1)).mb_substr($intervention->title, 1),
            '{adresse_chantier}' => (string) $intervention->address(),
        ]);

        return $this->composer->renderText($text, $intervention->client, $intervention->quote);
    }
}
