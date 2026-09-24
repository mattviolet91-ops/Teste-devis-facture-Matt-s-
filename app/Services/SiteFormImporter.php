<?php

namespace App\Services;

use App\Models\QuoteRequest;
use App\Models\SiteEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Demandes du formulaire du site WordPress : les emails de notification du
 * formulaire sont lus dans la boîte Gmail (sans les modifier ni les marquer
 * comme lus) et deviennent des demandes de devis dans l'application.
 */
class SiteFormImporter
{
    /** Libellé du formulaire (sans accents, en minuscules) → champ. Le premier qui correspond l'emporte. */
    private const LABELS = [
        'first_name' => ['prenom', 'first name', 'firstname'],
        'phone' => ['telephone', 'tel', 'phone', 'portable', 'mobile', 'numero de telephone', 'numero'],
        'email' => ['e-mail', 'email', 'courriel', 'adresse e-mail', 'adresse email', 'mail', 'votre email', 'votre e-mail'],
        'postal_code' => ['code postal', 'cp', 'zip', 'code'],
        'city' => ['ville', 'commune', 'city', 'localite'],
        'address' => ['adresse', 'address', 'adresse du chantier', 'adresse des travaux', 'rue'],
        'works' => ['type de travaux', 'travaux', 'prestation', 'prestations', 'service', 'services', 'type de prestation', 'objet', 'sujet'],
        'message' => ['message', 'votre message', 'commentaire', 'commentaires', 'demande', 'votre demande', 'description', 'projet', 'precisions', 'details'],
        'last_name' => ['nom', 'name', 'votre nom', 'nom complet', 'nom et prenom', 'nom prenom', 'nom / prenom'],
    ];

    /** Lignes techniques ajoutées par WordPress / Jetpack : ignorées. */
    private const IGNORED = ['time', 'heure', 'date', 'ip address', 'adresse ip', 'ip', 'contact form url', 'url du formulaire',
        'source url', 'sent by', 'envoye par', 'user agent', 'consentement', 'consent', 'rgpd', 'page'];

    /** Mots des travaux → cases de la demande. */
    private const WORK_WORDS = [
        'demoussage' => ['demouss', 'mousse', 'nettoyage de toit', 'nettoyage toiture', 'nettoyage'],
        'traitement' => ['hydrofuge', 'traitement'],
        'fuite' => ['fuite', 'infiltration', 'reparation'],
        'couverture' => ['couverture', 'refection', 'toiture neuve', 'tuile', 'ardoise'],
        'zinguerie' => ['gouttiere', 'zinguerie', 'zinc', 'cheneau'],
        'isolation' => ['isolation', 'isoler'],
        'velux' => ['velux', 'fenetre de toit'],
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly MailSettings $mail,
        private readonly QuoteRequestService $requests,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('site_form.enabled') && $this->mail->isConfigured();
    }

    /**
     * Lit les emails récents de la boîte Gmail.
     *
     * @return list<array{id: string, from: string, reply_to: string, subject: string, date: ?Carbon, text: string}>
     */
    public function fetch(Carbon $since, int $limit = 50): array
    {
        $manager = new ClientManager(['options' => ['fetch_order' => 'desc']]);
        $client = $manager->make([
            'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true,
            'username' => $this->mail->username(), 'password' => $this->mail->password(), 'protocol' => 'imap', 'timeout' => 20,
        ]);
        $client->connect();

        $messages = [];
        try {
            $query = $client->getFolder('INBOX')->query()->since($since->copy()->startOfDay())->leaveUnread()->setFetchBody(true)->limit($limit);
            foreach ($query->get() as $message) {
                $html = $message->hasHTMLBody() ? (string) $message->getHTMLBody() : '';
                $messages[] = [
                    'id' => (string) $message->getMessageId() ?: sha1((string) $message->getSubject().(string) $message->getDate()),
                    'from' => strtolower((string) ($message->getFrom()->first()?->mail ?? '')),
                    'reply_to' => strtolower((string) ($message->getReplyTo()->first()?->mail ?? '')),
                    'subject' => (string) $message->getSubject(),
                    'date' => ($date = $message->getDate()->first()) ? Carbon::instance($date) : null,
                    'text' => $message->hasTextBody() ? (string) $message->getTextBody() : self::htmlToText($html),
                ];
            }
        } finally {
            $client->disconnect();
        }

        return $messages;
    }

    /** Mentions ajoutées par WordPress / Jetpack en bas des emails de formulaire. */
    private const MARKERS = ['contact form url', 'ip address', 'adresse ip', 'url du formulaire', 'sent by an unverified visitor',
        'sent by a verified', 'envoye par un visiteur', 'formulaire de contact', 'contact form', 'jetpack', 'wpforms', 'contact form 7'];

    /**
     * L'email vient-il du formulaire du site ? Sans réglage : détection automatique
     * (mentions WordPress / Jetpack, ou expéditeur WordPress). Sinon : expéditeur et/ou objet.
     */
    public function matches(array $message): bool
    {
        $from = trim(mb_strtolower((string) $this->settings->get('site_form.from', '')));
        $subject = trim(mb_strtolower((string) $this->settings->get('site_form.subject', '')));
        if ($from === '' && $subject === '') {
            if (preg_match('/^(re|tr|fwd?)\s*:/i', trim($message['subject']))) {
                return false; // Réponses et transferts : jamais une nouvelle demande.
            }
            $sender = mb_strtolower($message['from']);
            $text = Str::of($message['text'])->ascii()->lower()->toString();

            return str_contains($sender, 'wordpress') || str_contains($sender, 'jetpack')
                || collect(self::MARKERS)->contains(fn ($marker) => str_contains($text, $marker));
        }

        return ($from === '' || str_contains(mb_strtolower($message['from']), $from))
            && ($subject === '' || str_contains(mb_strtolower($message['subject']), $subject));
    }

    /**
     * Importe les nouveaux emails du formulaire. Renvoie les demandes créées.
     *
     * @param  list<array{id: string, from: string, reply_to: string, subject: string, date: ?Carbon, text: string}>  $messages
     * @return list<QuoteRequest>
     */
    public function import(array $messages): array
    {
        $created = [];
        foreach ($messages as $message) {
            if (! $this->matches($message) || SiteEmail::query()->where('message_id', mb_substr($message['id'], 0, 255))->exists()) {
                continue;
            }

            $data = $this->parse($message['text']);
            if (empty($data['email']) && $message['reply_to'] && ! str_contains($message['reply_to'], $this->mail->username())) {
                // Jetpack met l'adresse du visiteur en « Répondre à ».
                $data['email'] = $message['reply_to'];
            }

            $request = null;
            if (! empty($data['last_name']) || ! empty($data['phone']) || ! empty($data['email'])) {
                $request = $this->requests->receive($data, [], null, 'Formulaire du site internet');
                $created[] = $request;
            }

            SiteEmail::query()->create([
                'message_id' => mb_substr($message['id'], 0, 255),
                'quote_request_id' => $request?->id,
                'subject' => mb_substr($message['subject'], 0, 255),
                'received_at' => $message['date'],
            ]);
        }

        return $created;
    }

    /** Lecture complète : Gmail → demandes. Enregistre la date et l'éventuelle erreur. */
    public function run(): int
    {
        $enabledAt = Carbon::parse($this->settings->get('site_form.enabled_at') ?? now());
        $since = $enabledAt->max(now()->subDays(3));

        try {
            $count = count($this->import($this->fetch($since)));
            $this->settings->set(['site_form.last_check_at' => now()->toDateTimeString(), 'site_form.last_error' => '']);

            return $count;
        } catch (Throwable $e) {
            $this->settings->set(['site_form.last_check_at' => now()->toDateTimeString(), 'site_form.last_error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }

    /**
     * Transforme le texte de l'email en champs de demande.
     *
     * @return array<string, mixed>
     */
    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (preg_match('/<(br|p|div|table|td|tr)\b/i', $text)) {
            $text = self::htmlToText($text);
        }
        $lines = array_values(array_filter(array_map(fn ($l) => trim($l, " \t\u{00A0}*"), explode("\n", $text)), fn ($l) => $l !== ''));

        $fields = [];
        $extra = [];
        $current = null;
        $pendingLabel = null;
        foreach ($lines as $line) {
            // « Libellé : valeur »
            if (preg_match('/^([^:]{1,40}?)\s*:\s*(.*)$/u', $line, $m) && ($key = $this->labelKey($m[1])) !== null) {
                $pendingLabel = null;
                $current = $key;
                if ($key !== 'ignore' && $m[2] !== '') {
                    $fields[$key] = isset($fields[$key]) ? $fields[$key]."\n".$m[2] : $m[2];
                }

                continue;
            }
            // Libellé seul sur sa ligne, valeur à la ligne suivante (emails HTML).
            if (mb_strlen($line) <= 40 && ($key = $this->labelKey($line)) !== null) {
                $current = $key;
                $pendingLabel = $key;

                continue;
            }
            if ($current === 'ignore') {
                continue;
            }
            if ($current !== null) {
                $fields[$current] = isset($fields[$current]) ? $fields[$current]."\n".$line : $line;
                $pendingLabel = null;

                continue;
            }
            $extra[] = $line;
        }

        $data = [];
        foreach (['first_name', 'last_name', 'phone', 'email', 'address', 'postal_code', 'city'] as $key) {
            if (isset($fields[$key])) {
                $data[$key] = trim(strtok($fields[$key], "\n"));
            }
        }

        // Nom complet sans prénom séparé : « Julie Garnier » → prénom + nom.
        if (! empty($data['last_name']) && empty($data['first_name']) && str_contains($data['last_name'], ' ')) {
            [$first, $last] = explode(' ', $data['last_name'], 2);
            $data['first_name'] = $first;
            $data['last_name'] = $last;
        }
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $data['email'] = preg_match('/[\w.+-]+@[\w-]+(\.[\w-]+)+/', $data['email'], $m) ? $m[0] : null;
        }
        if (! empty($data['phone']) && strlen(preg_replace('/\D/', '', $data['phone'])) < 9) {
            unset($data['phone']);
        }

        // Adresse complète sur une ligne : « 12 rue X, 91140 Ville ».
        if (! empty($data['address']) && empty($data['postal_code']) && preg_match('/^(.*?)[,\s]+(\d{5})\s+(.+)$/u', $data['address'], $m)) {
            $data['address'] = trim($m[1], ' ,');
            $data['postal_code'] = $m[2];
            $data['city'] ??= trim($m[3]);
        }
        if (! empty($data['postal_code'])) {
            $data['postal_code'] = preg_match('/\d{5}/', $data['postal_code'], $m) ? $m[0] : null;
        }

        $works = trim(($fields['works'] ?? '').' '.($fields['message'] ?? ''));
        $data['works'] = $this->detectWorks($works);
        $data['message'] = trim(implode("\n", array_filter([
            isset($fields['works']) ? 'Travaux : '.$fields['works'] : null,
            $fields['message'] ?? null,
            $extra ? implode("\n", $extra) : null,
        ]))) ?: null;

        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function labelKey(string $label): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', Str::of($label)->ascii()->lower()->replace(['*', '(obligatoire)', '(requis)', '?'], '')->toString()));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, self::IGNORED, true)) {
            return 'ignore';
        }
        foreach (self::LABELS as $key => $labels) {
            if (in_array($normalized, $labels, true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function detectWorks(string $text): array
    {
        $text = Str::of($text)->ascii()->lower()->toString();
        $found = [];
        foreach (self::WORK_WORDS as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return $found;
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h\d|table)>#i', "\n", $html);
        $html = preg_replace('#</t[dh]>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
