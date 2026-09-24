<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DeployedTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_update_is_notified_and_logged(): void
    {
        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => $title === 'Application mise à jour' && $body === 'Rendez-vous dans le planning');
        $push->shouldReceive('send')->once()->withArgs(fn ($title) => str_contains($title, 'échec'));

        $this->artisan('app:deployed', ['message' => 'Rendez-vous dans le planning'])->assertSuccessful();
        $this->artisan('app:deployed', ['--echec' => true])->assertSuccessful();

        $this->assertSame(['app.deployed', 'app.deploy_failed'], ActivityLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_deploy_script_is_valid_bash(): void
    {
        exec('bash -n '.escapeshellarg(base_path('scripts/deploy.sh')).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
