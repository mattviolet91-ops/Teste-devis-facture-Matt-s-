<?php

/**
 * Prépare les données du test navigateur (base vide et migrée) et affiche en JSON les
 * adresses à ouvrir. Lancé par tests/browser/run.sh — jamais sur le serveur réel.
 */

use App\Models\Attachment;
use App\Models\Client;
use App\Models\User;
use App\Services\DocumentSignatureService;
use App\Services\InvoiceService;
use App\Services\MyposGateway;
use App\Services\PaymentRequestService;
use App\Services\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "Refusé : ce script ne tourne que sur une base d'essai.\n");
    exit(1);
}

User::query()->updateOrCreate(['email' => 'essai@local.test'], ['name' => 'Essai', 'password' => bcrypt('motdepasse-essai-123'), 'role' => 'admin']);
app(Settings::class)->set(['mypos.enabled' => true, 'mypos.test' => true]);
$client = Client::factory()->create(['last_name' => 'Essai', 'email' => null]);

$request = app(PaymentRequestService::class)->createRequest($client, null, 'standard', 'Dépannage d\'essai', 12000, 1000);
$invoices = app(InvoiceService::class);
$invoice = $invoices->send($invoices->createForAmount($client, null, 'standard', 'Réparation d\'essai', 35000, 1000));

// Document à signer : une image d'une page.
$image = imagecreatetruecolor(600, 800);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
ob_start();
imagejpeg($image);
$path = 'documents/clients/'.$client->id.'/essai.jpg';
Storage::disk('local')->put($path, (string) ob_get_clean());
$attachment = new Attachment(['name' => 'Bon de commande.jpg']);
$attachment->client()->associate($client);
$attachment->forceFill(['path' => $path, 'mime' => 'image/jpeg', 'size' => Storage::disk('local')->size($path)])->save();
app(DocumentSignatureService::class)->request($attachment);

echo json_encode([
    'payment' => '/p/'.$request->token.'/payer?essai=1',
    'invoice' => '/f/'.$invoice->public_token.'/payer?essai=1',
    'document' => '/doc/'.$attachment->fresh()->public_token,
    'open' => '/payer/'.basename(app(MyposGateway::class)->freeLinkUrl()).'?essai=1',
]);
