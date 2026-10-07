<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientImportController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MyposNotificationController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PdfViewerController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\QuickQuoteController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteRequestController;
use App\Http\Controllers\QuoteRequestFormController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings;
use App\Http\Controllers\SiteStatsCollectController;
use App\Http\Controllers\SiteStatsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\WixArchiveController;
use App\Http\Controllers\WorksiteController;
use Illuminate\Support\Facades\Route;

Route::resourceVerbs(['create' => 'nouveau', 'edit' => 'modifier']);

Route::get('/marque/{kind}', [BrandingAssetController::class, 'show'])
    ->whereIn('kind', array_keys(BrandingAssetController::KINDS))
    ->name('branding.image');

// Page de secours du mode hors connexion (mise en cache par le service worker).
Route::get('/hors-ligne', [OfflineController::class, 'page'])->name('offline');

// Espace client (lien secret, sans compte).
Route::middleware('throttle:60,1')->where(['token' => '[A-Za-z0-9]{32,64}'])->group(function () {
    Route::get('/d/{token}', [ClientPortalController::class, 'quote'])->name('portal.quote');
    Route::get('/d/{token}/pdf', [ClientPortalController::class, 'quotePdf'])->name('portal.quote.pdf');
    Route::post('/d/{token}/accepter', [ClientPortalController::class, 'sign'])->middleware('throttle:10,1')->name('portal.quote.sign');
    Route::post('/d/{token}/refuser', [ClientPortalController::class, 'refuse'])->middleware('throttle:10,1')->name('portal.quote.refuse');
    Route::post('/d/{token}/modification', [ClientPortalController::class, 'requestChange'])->middleware('throttle:10,1')->name('portal.quote.change');
    Route::get('/f/{token}', [ClientPortalController::class, 'invoice'])->name('portal.invoice');
    Route::get('/r/{token}', [ClientPortalController::class, 'report'])->name('portal.report');
    Route::get('/f/{token}/pdf', [ClientPortalController::class, 'invoicePdf'])->name('portal.invoice.pdf');
    Route::get('/f/{token}/payer', [ClientPortalController::class, 'pay'])->middleware('throttle:10,1')->name('portal.invoice.pay');
    // Retours du navigateur depuis la page myPOS (POST sans jeton CSRF, voir bootstrap/app.php).
    Route::match(['get', 'post'], '/f/{token}/paiement-ok', [ClientPortalController::class, 'paid'])->name('portal.invoice.paid');
    Route::match(['get', 'post'], '/f/{token}/paiement-annule', [ClientPortalController::class, 'payCancelled'])->name('portal.invoice.pay-cancel');
});

// Statistiques du site internet : visites et clics envoyés par le script « s.js » (sans cookie).
Route::post('/stats/collect', SiteStatsCollectController::class)->middleware('throttle:120,1')->name('portal.site.collect');

// Demande de devis depuis le site internet (page publique, adresse client).
Route::get('/demande-de-devis', [QuoteRequestFormController::class, 'create'])->name('portal.request');
Route::post('/demande-de-devis', [QuoteRequestFormController::class, 'store'])->middleware('throttle:5,1')->name('portal.request.store');
Route::get('/demande-de-devis/merci', [QuoteRequestFormController::class, 'thanks'])->name('portal.request.thanks');

// Notification de paiement envoyée par myPOS (serveur à serveur, signée).
Route::post('/mypos/notification', MyposNotificationController::class)->middleware('throttle:60,1')->name('portal.mypos.notify');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/mot-de-passe-oublie', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reinitialiser/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reinitialiser', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/recherche', SearchController::class)->name('search');
    Route::get('/guide', GuideController::class)->name('guide');
    // Bouton « Documents » : réouvre la dernière liste consultée (devis ou factures).
    Route::get('/documents', [QuoteController::class, 'documents'])->name('documents');
    Route::get('/apercu-pdf', PdfViewerController::class)->name('pdf.view');
    Route::get('/hors-ligne/jeton', [OfflineController::class, 'token'])->name('offline.token');
    Route::get('/hors-ligne/pages', [OfflineController::class, 'pages'])->name('offline.pages');
    Route::get('/statistiques', StatisticsController::class)->name('statistics');
    Route::prefix('statistiques/site')->name('site-stats.')->group(function () {
        Route::get('/', [SiteStatsController::class, 'index'])->name('index');
        Route::put('/wordpress', [SiteStatsController::class, 'saveApp'])->name('wpcom.app');
        Route::get('/wordpress/connexion', [SiteStatsController::class, 'connect'])->name('wpcom.connect');
        Route::get('/wordpress/retour', [SiteStatsController::class, 'callback'])->name('wpcom.callback');
        Route::post('/wordpress/actualiser', [SiteStatsController::class, 'sync'])->middleware('throttle:10,1')->name('wpcom.sync');
        Route::delete('/wordpress', [SiteStatsController::class, 'disconnect'])->name('wpcom.disconnect');
    });

    Route::get('/clients/importer', [ClientImportController::class, 'create'])->name('clients.import');
    Route::post('/clients/importer/apercu', [ClientImportController::class, 'preview'])->middleware('throttle:20,1')->name('clients.import.preview');
    Route::post('/clients/importer', [ClientImportController::class, 'store'])->name('clients.import.store');

    Route::resource('clients', ClientController::class)
        ->parameters(['clients' => 'client'])
        ->names('clients')
        ->whereNumber('client');

    Route::get('/clients/{client}/chantiers/nouveau', [WorksiteController::class, 'create'])->name('worksites.create');
    Route::post('/clients/{client}/chantiers', [WorksiteController::class, 'store'])->name('worksites.store');
    Route::get('/chantiers/{worksite}/modifier', [WorksiteController::class, 'edit'])->name('worksites.edit');
    Route::put('/chantiers/{worksite}', [WorksiteController::class, 'update'])->name('worksites.update');
    Route::delete('/chantiers/{worksite}', [WorksiteController::class, 'destroy'])->name('worksites.destroy');

    Route::get('/devis/express', [QuickQuoteController::class, 'create'])->name('quotes.express');
    Route::post('/devis/express/apercu', [QuickQuoteController::class, 'preview'])->name('quotes.express.preview');
    Route::post('/devis/express', [QuickQuoteController::class, 'store'])->name('quotes.express.store');

    Route::resource('devis', QuoteController::class)
        ->parameters(['devis' => 'quote'])
        ->names('quotes')
        ->whereNumber('quote');
    Route::prefix('devis/{quote}')->whereNumber('quote')->name('quotes.')->group(function () {
        Route::post('/envoye', [QuoteController::class, 'send'])->name('send');
        Route::post('/accepte', [QuoteController::class, 'accept'])->name('accept');
        Route::post('/refuse', [QuoteController::class, 'refuse'])->name('refuse');
        Route::post('/nouvelle-version', [QuoteController::class, 'revise'])->name('revise');
        Route::post('/dupliquer', [QuoteController::class, 'duplicate'])->name('duplicate');
        Route::post('/facturer', [InvoiceController::class, 'fromQuote'])->name('invoice');
        Route::get('/pdf', [PdfController::class, 'quote'])->name('pdf');
        Route::get('/signature', [QuoteController::class, 'signature'])->name('signature');
        Route::get('/signer-sur-place', [QuoteController::class, 'onSite'])->name('on-site');
        Route::post('/signer-sur-place', [QuoteController::class, 'signOnSite'])->middleware('throttle:10,1')->name('on-site.sign');
    });

    Route::resource('factures', InvoiceController::class)
        ->parameters(['factures' => 'invoice'])
        ->names('invoices')
        ->whereNumber('invoice');
    Route::prefix('factures/{invoice}')->whereNumber('invoice')->name('invoices.')->group(function () {
        Route::post('/envoyee', [InvoiceController::class, 'send'])->name('send');
        Route::post('/corriger', [InvoiceController::class, 'correct'])->name('correct');
        Route::post('/annuler', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::put('/lien-paiement', [InvoiceController::class, 'paymentLink'])->name('payment-link');
        Route::get('/pdf', [PdfController::class, 'invoice'])->name('pdf');
        Route::post('/pdf/mettre-a-jour', [PdfController::class, 'refreshInvoice'])->middleware('throttle:20,1')->name('pdf.refresh');
    });

    Route::resource('prestations', CatalogController::class)
        ->except('show')
        ->parameters(['prestations' => 'item'])
        ->names('catalog')
        ->whereNumber('item');
    Route::post('/prestations/categories', [CatalogController::class, 'storeCategory'])->name('catalog.categories.store');
    Route::put('/prestations/categories/{category}', [CatalogController::class, 'updateCategory'])->name('catalog.categories.update');

    Route::get('/archives-wix', [WixArchiveController::class, 'index'])->name('archives.index');
    Route::post('/archives-wix', [WixArchiveController::class, 'store'])->middleware('throttle:10,1')->name('archives.store');
    Route::get('/archives-wix/{archive}', [WixArchiveController::class, 'show'])->whereNumber('archive')->name('archives.show');

    Route::get('/relances', [ReminderController::class, 'index'])->name('reminders.index');
    Route::get('/factures/{invoice}/relancer', [ReminderController::class, 'show'])->whereNumber('invoice')->name('reminders.show');
    Route::post('/factures/{invoice}/relance', [ReminderController::class, 'track'])->whereNumber('invoice')->middleware('throttle:30,1')->name('reminders.track');

    Route::resource('planning', PlanningController::class)
        ->parameters(['planning' => 'intervention'])
        ->names('planning')
        ->whereNumber('intervention');
    Route::get('/planning/{intervention}/agenda.ics', [PlanningController::class, 'ics'])->whereNumber('intervention')->name('planning.ics');
    Route::put('/planning/{intervention}/provenance', [PlanningController::class, 'source'])->whereNumber('intervention')->name('planning.source');
    Route::post('/planning/{intervention}/fait', [PlanningController::class, 'done'])->whereNumber('intervention')->name('planning.done');

    Route::resource('rapports', ReportController::class)->except('index')
        ->parameters(['rapports' => 'report'])->names('reports')->whereNumber('report');
    Route::prefix('rapports/{report}')->whereNumber('report')->name('reports.')->group(function () {
        Route::get('/pdf', [ReportController::class, 'pdf'])->name('pdf');
        Route::post('/email', [ReportController::class, 'email'])->middleware('throttle:10,1')->name('email');
        Route::post('/partage', [ReportController::class, 'shared'])->middleware('throttle:30,1')->name('shared');
        Route::post('/photos', [PhotoController::class, 'attachToReport'])->name('photos');
        Route::post('/photos/ajout', [PhotoController::class, 'uploadToReport'])->middleware('throttle:60,1')->name('photos.upload');
    });

    // Frais d'une facture (visibles par le gérant seul).
    Route::get('/frais', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/frais/nouveau', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('/frais', [ExpenseController::class, 'storeAny'])->name('expenses.store.any');
    Route::get('/frais/generaux', [ExpenseController::class, 'general'])->name('expenses.general');
    Route::post('/frais/generaux', [ExpenseController::class, 'storeGeneral'])->name('expenses.general.store');
    Route::get('/frais/chantier/{project}', [ExpenseController::class, 'project'])->whereNumber('project')->name('expenses.project');
    Route::post('/frais/chantier/{project}', [ExpenseController::class, 'storeForProject'])->whereNumber('project')->name('expenses.project.store');
    Route::put('/frais/chantier/{project}', [ExpenseController::class, 'renameProject'])->whereNumber('project')->name('expenses.project.rename');
    Route::get('/frais/devis/{quote}', [ExpenseController::class, 'quote'])->whereNumber('quote')->name('expenses.quote');
    Route::post('/frais/devis/{quote}', [ExpenseController::class, 'storeForQuote'])->whereNumber('quote')->name('expenses.quote.store');
    Route::post('/devis/{quote}/chantier', [ExpenseController::class, 'attachQuote'])->whereNumber('quote')->name('expenses.attach.quote');
    Route::post('/factures/{invoice}/chantier', [ExpenseController::class, 'attachInvoice'])->whereNumber('invoice')->name('expenses.attach.invoice');
    Route::get('/frais/facture/{invoice}', [ExpenseController::class, 'invoice'])->whereNumber('invoice')->name('expenses.invoice');
    Route::post('/factures/{invoice}/frais', [ExpenseController::class, 'store'])->whereNumber('invoice')->name('expenses.store');
    Route::delete('/frais/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
    Route::get('/frais/{expense}/ticket', [ExpenseController::class, 'receipt'])->whereNumber('expense')->name('expenses.receipt');

    Route::get('/demandes', [QuoteRequestController::class, 'index'])->name('requests.index');
    Route::get('/demandes/{quoteRequest}', [QuoteRequestController::class, 'show'])->whereNumber('quoteRequest')->name('requests.show');
    Route::post('/demandes/{quoteRequest}/traitee', [QuoteRequestController::class, 'toggle'])->whereNumber('quoteRequest')->name('requests.toggle');

    Route::get('/avis', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/avis/{review}', [ReviewController::class, 'show'])->whereNumber('review')->name('reviews.show');
    Route::post('/avis/{review}/email', [ReviewController::class, 'email'])->whereNumber('review')->middleware('throttle:10,1')->name('reviews.email');
    Route::post('/avis/{review}/envoye', [ReviewController::class, 'track'])->whereNumber('review')->middleware('throttle:30,1')->name('reviews.track');
    Route::post('/avis/{review}/ignorer', [ReviewController::class, 'skip'])->whereNumber('review')->name('reviews.skip');
    Route::post('/factures/{invoice}/avis', [ReviewController::class, 'store'])->whereNumber('invoice')->name('reviews.store');

    Route::get('/entretiens', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/entretiens/analyser', [MaintenanceController::class, 'scan'])->middleware('throttle:5,1')->name('maintenance.scan');
    Route::get('/entretiens/{reminder}', [MaintenanceController::class, 'show'])->whereNumber('reminder')->name('maintenance.show');
    Route::post('/entretiens/{reminder}/relance', [MaintenanceController::class, 'track'])->whereNumber('reminder')->middleware('throttle:30,1')->name('maintenance.track');
    Route::put('/entretiens/{reminder}', [MaintenanceController::class, 'update'])->whereNumber('reminder')->name('maintenance.update');
    Route::post('/clients/{client}/entretiens', [MaintenanceController::class, 'store'])->whereNumber('client')->name('maintenance.store');

    Route::get('/paiements', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/factures/{invoice}/paiements', [PaymentController::class, 'store'])->whereNumber('invoice')->name('payments.store');
    Route::delete('/paiements/{payment}', [PaymentController::class, 'destroy'])->whereNumber('payment')->name('payments.destroy');

    Route::get('/photos', [PhotoController::class, 'index'])->name('photos.index');
    Route::get('/chantiers/{worksite}/photos', [PhotoController::class, 'worksite'])->whereNumber('worksite')->name('photos.worksite');
    Route::post('/chantiers/{worksite}/photos', [PhotoController::class, 'store'])->whereNumber('worksite')->middleware('throttle:60,1')->name('photos.store');
    Route::prefix('photos/{photo}')->whereNumber('photo')->name('photos.')->group(function () {
        Route::put('/', [PhotoController::class, 'update'])->name('update');
        Route::delete('/', [PhotoController::class, 'destroy'])->name('destroy');
        Route::post('/annotation', [PhotoController::class, 'annotate'])->name('annotate');
        Route::get('/{variant}', [PhotoController::class, 'file'])->whereIn('variant', ['mini', 'photo', 'original'])->name('file');
    });
    Route::post('/devis/{quote}/photos', [PhotoController::class, 'attachToQuote'])->whereNumber('quote')->name('quotes.photos');
    Route::post('/factures/{invoice}/photos', [PhotoController::class, 'attachToInvoice'])->whereNumber('invoice')->name('invoices.photos');
    Route::post('/devis/{quote}/photos/ajout', [PhotoController::class, 'uploadToQuote'])->whereNumber('quote')->middleware('throttle:60,1')->name('quotes.photos.upload');
    Route::post('/factures/{invoice}/photos/ajout', [PhotoController::class, 'uploadToInvoice'])->whereNumber('invoice')->middleware('throttle:60,1')->name('invoices.photos.upload');

    Route::post('/clients/{client}/documents', [AttachmentController::class, 'store'])->whereNumber('client')->name('attachments.store');
    Route::get('/documents/{attachment}', [AttachmentController::class, 'show'])->whereNumber('attachment')->name('attachments.show');
    Route::delete('/documents/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('attachments.destroy');

    Route::post('/notifications/abonnement', [PushController::class, 'subscribe'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('/notifications/desabonnement', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/notifications/test', [PushController::class, 'test'])->middleware('throttle:5,1')->name('push.test');

    Route::get('/emails', [EmailController::class, 'index'])->name('emails.index');
    Route::get('/emails/nouveau', [EmailController::class, 'create'])->name('emails.create');
    Route::post('/emails', [EmailController::class, 'store'])->middleware('throttle:30,1')->name('emails.store');
    Route::get('/emails/{email}', [EmailController::class, 'show'])->whereNumber('email')->name('emails.show');

    Route::get('/corbeille', [TrashController::class, 'index'])->name('trash.index');
    Route::post('/corbeille/factures/{id}', [TrashController::class, 'restoreInvoice'])->whereNumber('id')->name('trash.invoices.restore');
    Route::post('/corbeille/devis/{id}', [TrashController::class, 'restoreQuote'])->whereNumber('id')->name('trash.quotes.restore');
    Route::post('/corbeille/clients/{id}', [TrashController::class, 'restoreClient'])->whereNumber('id')->name('trash.clients.restore');
    Route::post('/corbeille/chantiers/{id}', [TrashController::class, 'restoreWorksite'])->whereNumber('id')->name('trash.worksites.restore');

    Route::prefix('reglages')->name('settings.')->group(function () {
        Route::redirect('/', '/reglages/entreprise')->name('index');

        Route::get('/entreprise', [Settings\CompanyController::class, 'edit'])->name('company');
        Route::put('/entreprise', [Settings\CompanyController::class, 'update']);

        Route::get('/apparence', [Settings\BrandingController::class, 'edit'])->name('branding');
        Route::post('/apparence', [Settings\BrandingController::class, 'update']);
        Route::post('/apparence/reinitialiser', [Settings\BrandingController::class, 'reset'])->name('branding.reset');

        Route::get('/tva', [Settings\VatController::class, 'edit'])->name('vat');
        Route::put('/tva/regime', [Settings\VatController::class, 'updateRegime'])->name('vat.regime');
        Route::post('/tva/taux', [Settings\VatController::class, 'storeRate'])->name('vat.rates.store');
        Route::put('/tva/taux/{rate}', [Settings\VatController::class, 'updateRate'])->name('vat.rates.update');
        Route::post('/tva/unites', [Settings\VatController::class, 'storeUnit'])->name('units.store');
        Route::put('/tva/unites/{unit}', [Settings\VatController::class, 'updateUnit'])->name('units.update');

        Route::get('/documents', [Settings\DocumentsController::class, 'edit'])->name('documents');
        Route::put('/documents', [Settings\DocumentsController::class, 'update']);

        Route::get('/assurance', [Settings\InsuranceController::class, 'edit'])->name('insurance');
        Route::put('/assurance', [Settings\InsuranceController::class, 'update']);
        Route::get('/assurance/attestations/{certificate}', [Settings\InsuranceController::class, 'download'])->name('insurance.certificate');

        Route::get('/emails', [Settings\EmailSettingsController::class, 'edit'])->name('emails');
        Route::put('/emails', [Settings\EmailSettingsController::class, 'update']);
        Route::put('/emails/messages', [Settings\EmailSettingsController::class, 'updateShareTexts'])->name('emails.share');
        Route::put('/emails/avis', [Settings\EmailSettingsController::class, 'updateReviews'])->name('emails.reviews');
        Route::put('/emails/relance-devis', [Settings\EmailSettingsController::class, 'updateQuoteFollowUps'])->name('emails.quote-follow-ups');
        Route::put('/emails/relances', [Settings\EmailSettingsController::class, 'updateReminders'])->name('emails.reminders');
        Route::post('/emails/test', [Settings\EmailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('emails.test');
        Route::post('/emails/modeles', [Settings\EmailSettingsController::class, 'storeTemplate'])->name('emails.templates.store');
        Route::put('/emails/modeles/{template}', [Settings\EmailSettingsController::class, 'updateTemplate'])->name('emails.templates.update');
        Route::delete('/emails/modeles/{template}', [Settings\EmailSettingsController::class, 'destroyTemplate'])->name('emails.templates.destroy');

        Route::get('/affichage', [Settings\DisplayController::class, 'edit'])->name('display');
        Route::put('/affichage', [Settings\DisplayController::class, 'update']);
        Route::post('/affichage/par-defaut', [Settings\DisplayController::class, 'reset'])->name('display.reset');

        Route::get('/formulaire-site', [Settings\SiteFormController::class, 'edit'])->name('site-form');
        Route::put('/formulaire-site', [Settings\SiteFormController::class, 'update']);
        Route::post('/formulaire-site/apercu', [Settings\SiteFormController::class, 'preview'])->middleware('throttle:10,1')->name('site-form.preview');

        Route::get('/paiement', [Settings\PaymentController::class, 'edit'])->name('payments');
        Route::put('/paiement', [Settings\PaymentController::class, 'update']);

        Route::get('/numerotation', [Settings\NumberingController::class, 'edit'])->name('numbering');
        Route::put('/numerotation', [Settings\NumberingController::class, 'update']);

        Route::get('/textes', [Settings\TextTemplateController::class, 'edit'])->name('texts');
        Route::post('/textes', [Settings\TextTemplateController::class, 'store'])->name('texts.store');
        Route::put('/textes/{template}', [Settings\TextTemplateController::class, 'update'])->name('texts.update');
        Route::delete('/textes/{template}', [Settings\TextTemplateController::class, 'destroy'])->name('texts.destroy');

        Route::get('/compte', [Settings\AccountController::class, 'edit'])->name('account');
        Route::put('/compte/profil', [Settings\AccountController::class, 'updateProfile'])->name('account.profile');
        Route::put('/compte/mot-de-passe', [Settings\AccountController::class, 'updatePassword'])->name('account.password');
        Route::post('/compte/deconnecter-appareils', [Settings\AccountController::class, 'logoutOthers'])->middleware('throttle:5,1')->name('account.logout-others');
        Route::get('/journal', [Settings\AccountController::class, 'journal'])->name('journal');

        Route::get('/acces-claude', [Settings\ApiTokenController::class, 'index'])->name('api');
        Route::post('/acces-claude', [Settings\ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('api.store');
        Route::delete('/acces-claude/{token}', [Settings\ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api.destroy');

        Route::get('/comptes', [Settings\UserController::class, 'index'])->name('users');
        Route::post('/comptes', [Settings\UserController::class, 'store'])->middleware('throttle:10,1')->name('users.store');
        Route::post('/comptes/{user}/invitation', [Settings\UserController::class, 'resend'])->whereNumber('user')->middleware('throttle:5,1')->name('users.resend');
        Route::post('/comptes/{user}/activation', [Settings\UserController::class, 'toggle'])->whereNumber('user')->name('users.toggle');
        Route::delete('/comptes/{user}', [Settings\UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');

        Route::get('/sauvegardes', [Settings\BackupController::class, 'index'])->name('backups');
        Route::post('/sauvegardes', [Settings\BackupController::class, 'create'])->middleware('throttle:5,1')->name('backups.create');
        Route::get('/sauvegardes/{name}', [Settings\BackupController::class, 'download'])->name('backups.download');
    });

});
