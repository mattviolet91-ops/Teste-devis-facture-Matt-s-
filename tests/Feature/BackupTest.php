<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\BackupService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    public function test_nightly_backup_contains_the_database_and_monthly_one_the_files(): void
    {
        $this->actingAs($this->admin());
        Client::factory()->create(['last_name' => "O'Brien"]);
        Storage::disk('local')->put('photos/1/toit.jpg', 'image');

        Carbon::setTestNow('2026-10-02 01:30');
        $this->artisan('app:backup')->assertSuccessful();
        Carbon::setTestNow('2026-11-01 01:30');
        $this->artisan('app:backup')->assertSuccessful();

        $backups = app(BackupService::class)->list();
        $this->assertCount(2, $backups);
        $monthly = $backups->firstWhere('type', 'mensuelle');
        $daily = $backups->firstWhere('type', 'quotidienne');

        $this->assertContains('base-de-donnees.sql', $this->zipEntries($daily['path']));
        $this->assertNotContains('fichiers/photos/1/toit.jpg', $this->zipEntries($daily['path']));
        $this->assertContains('fichiers/photos/1/toit.jpg', $this->zipEntries($monthly['path']));
    }

    public function test_database_dump_can_be_restored(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create(['last_name' => "O'Brien", 'notes' => "Ligne 1\nLigne 2 « guillemets »"]);
        $sql = app(BackupService::class)->dumpDatabase();

        DB::table('clients')->delete();
        $this->assertSame(0, Client::query()->count());

        DB::unprepared($sql);
        $restored = Client::query()->findOrFail($client->id);
        $this->assertSame("O'Brien", $restored->last_name);
        $this->assertSame("Ligne 1\nLigne 2 « guillemets »", $restored->notes);
    }

    public function test_old_backups_are_pruned(): void
    {
        $service = app(BackupService::class);
        for ($day = 1; $day <= 33; $day++) {
            Carbon::setTestNow(Carbon::create(2026, 10, 1)->addDays($day));
            $service->create('quotidienne');
        }

        $service->prune();
        $this->assertCount(BackupService::KEEP_DAILY, $service->list());
    }

    public function test_backup_can_be_downloaded_and_reminder_is_shown_weekly(): void
    {
        $this->actingAs($this->admin());
        Carbon::setTestNow('2026-10-01 10:00');
        $this->get(route('settings.backups'))->assertOk()->assertSee('jamais');

        $response = $this->post(route('settings.backups.create'));
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertNotNull(app(Settings::class)->get('backups.last_download_at'));

        $name = app(BackupService::class)->list()->first()['name'];
        $this->get(route('settings.backups.download', $name))->assertOk();
        $this->get(route('settings.backups.download', '../../.env'))->assertNotFound();

        $this->get(route('dashboard'))->assertDontSee('Pensez à télécharger');
        Carbon::setTestNow('2026-10-09 10:00');
        $this->get(route('dashboard'))->assertSee('Pensez à télécharger');
    }

    public function test_backups_require_login(): void
    {
        $this->get(route('settings.backups'))->assertRedirect(route('login'));
    }
}
