<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les PDF figés et les fichiers envoyés ne touchent jamais le vrai dossier privé.
        Storage::fake('local');
        // Aucun appel réel à Internet pendant les tests (météo, paiement…).
        Http::preventStrayRequests();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
