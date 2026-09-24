<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime les données fictives du jeu de démonstration : clients aux adresses
 * « @example.com » / « .example », le client « Benali » de démonstration et le
 * compte demo@example.com. Les vrais clients ne sont jamais concernés.
 */
class RemoveDemoData extends Command
{
    protected $signature = 'app:remove-demo-data {--confirmer : Obligatoire : confirme la suppression}';

    protected $description = 'Supprime les clients fictifs et le compte de démonstration';

    public function handle(BackupService $backups): int
    {
        $clients = $this->demoClients()->get();
        $demoUser = User::query()->where('email', 'demo@example.com')->first();

        $this->line($clients->count().' client(s) fictif(s) trouvé(s)'.($clients->isNotEmpty() ? ' : '.$clients->map->displayName()->implode(', ') : '').'.');
        $this->line($demoUser ? 'Compte de démonstration demo@example.com trouvé.' : 'Aucun compte de démonstration.');

        $blocked = $clients->filter(fn (Client $c) => $c->quotes()->withTrashed()->exists() || $c->invoices()->withTrashed()->exists());
        if ($blocked->isNotEmpty()) {
            $this->warn('Conservés car ils ont des devis ou factures : '.$blocked->map->displayName()->implode(', '));
            $clients = $clients->diff($blocked);
        }

        if (! $this->option('confirmer')) {
            $this->warn('Rien n\'a été supprimé. Relancez avec --confirmer pour supprimer.');

            return self::SUCCESS;
        }

        if ($clients->isEmpty() && ! $demoUser) {
            $this->info('Rien à supprimer.');

            return self::SUCCESS;
        }

        $this->info('Sauvegarde avant suppression : '.$backups->create('complete'));

        DB::transaction(function () use ($clients, $demoUser) {
            foreach ($clients as $client) {
                $files = array_merge(
                    DB::table('photos')->where('client_id', $client->id)->pluck('path')->all(),
                    DB::table('photos')->where('client_id', $client->id)->pluck('thumb_path')->all(),
                    DB::table('photos')->where('client_id', $client->id)->whereNotNull('annotated_path')->pluck('annotated_path')->all(),
                    DB::table('attachments')->where('client_id', $client->id)->pluck('path')->all(),
                );
                Storage::disk('local')->delete($files);
                DB::table('photos')->where('client_id', $client->id)->delete();
                DB::table('attachments')->where('client_id', $client->id)->delete();
                $client->worksites()->withTrashed()->forceDelete();
                $client->forceDelete();
            }

            // Jamais le dernier compte : l'accès à l'application doit rester possible.
            if ($demoUser && User::query()->count() > 1) {
                DB::table('sessions')->where('user_id', $demoUser->id)->delete();
                $demoUser->delete();
            }
        });

        $this->info($clients->count().' client(s) fictif(s) supprimé(s)'.($demoUser ? ', compte de démonstration supprimé' : '').'.');

        return self::SUCCESS;
    }

    /** @return Builder<Client> */
    private function demoClients(): Builder
    {
        return Client::withTrashed()->where(function (Builder $q) {
            $q->where('email', 'like', '%@example.com')
                ->orWhere('email', 'like', '%.example')
                ->orWhere(fn (Builder $b) => $b->where('last_name', 'Benali')->where('first_name', 'Karim')->where('phone', '07 55 44 33 22'));
        });
    }
}
