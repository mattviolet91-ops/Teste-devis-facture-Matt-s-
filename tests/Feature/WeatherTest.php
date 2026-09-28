<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Worksite;
use App\Services\PushService;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    use RefreshDatabase;

    private Intervention $work;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00'); // mercredi
        $client = Client::factory()->create(['last_name' => 'Leroy']);
        $worksite = Worksite::factory()->for($client)->create(['postal_code' => '91300', 'city' => 'Massy']);
        $this->work = Intervention::query()->create(['client_id' => $client->id, 'worksite_id' => $worksite->id, 'title' => 'Faîtage',
            'starts_on' => '2026-10-08', 'ends_on' => '2026-10-09', 'start_time' => '08:00']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Prévisions horaires : jeudi 8 pluvieux et venteux, vendredi 9 sec avec gel le matin. */
    private function fakeApis(): void
    {
        $series = [];
        for ($hour = 0; $hour < 72; $hour++) {
            $time = Carbon::parse('2026-10-07 22:00:00', 'UTC')->addHours($hour); // minuit à Paris le 8
            $local = $time->copy()->setTimezone('Europe/Paris');
            $thursday = $local->isSameDay(Carbon::parse('2026-10-08'));
            $series[] = [
                'time' => $time->format('Y-m-d\TH:i:s\Z'),
                'data' => [
                    'instant' => ['details' => [
                        'air_temperature' => $thursday ? 12.0 : ($local->hour < 8 ? -1.5 : 9.0),
                        'wind_speed' => $thursday && $local->hour === 14 ? 13.0 : 3.0, // 47 km/h
                    ]],
                    'next_1_hours' => ['details' => ['precipitation_amount' => $thursday && $local->hour >= 9 && $local->hour < 12 ? 1.5 : 0.0]],
                ],
            ];
        }

        Http::fake([
            'data.geopf.fr/*' => Http::response(['features' => [['geometry' => ['coordinates' => [2.2696, 48.7281]]]]]),
            'api.met.no/*' => Http::response(['properties' => ['timeseries' => $series]]),
        ]);
    }

    public function test_weather_alerts_appear_on_the_planning(): void
    {
        $this->fakeApis();

        // Tant que la tâche n'est pas passée, rien n'est affiché et aucune requête n'est faite pendant l'affichage.
        $this->get(route('planning.index'))->assertOk()->assertDontSee('Pluie');
        Http::assertNothingSent();

        $this->artisan('app:weather')->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.met.no') && str_contains($request->header('User-Agent')[0], 'MattsCouverture'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'data.geopf.fr') && str_contains(urldecode($request->url()), '91300 Massy'));

        $days = app(WeatherService::class)->forIntervention($this->work);
        $this->assertSame(['Pluie 4,5 mm', 'Vent fort 47 km/h'], $days['2026-10-08']['alerts']);
        $this->assertSame(['Gel -1,5 °C'], $days['2026-10-09']['alerts']);

        $this->get(route('planning.index'))->assertOk()->assertSee('⚠ Pluie 4,5 mm · Vent fort 47 km/h')->assertSee('⚠ Gel -1,5 °C')->assertSee('MET Norway');
        $this->get(route('planning.show', $this->work))->assertOk()->assertSee('Météo')->assertSee('Vent fort 47 km/h');
    }

    public function test_evening_notification_warns_about_bad_weather(): void
    {
        $this->fakeApis();
        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => str_contains($body, 'Leroy (Massy) ⚠ Pluie 4,5 mm, Vent fort 47 km/h'));

        $this->artisan('app:planning-notifications')->assertSuccessful();
    }

    public function test_weather_service_down_never_breaks_the_planning(): void
    {
        Http::fake(['*' => Http::response('Erreur', 500)]);

        $this->artisan('app:weather')->assertSuccessful();
        $this->get(route('planning.index'))->assertOk()->assertDontSee('Météo :');
        $this->get(route('planning.show', $this->work))->assertOk();
    }
}
