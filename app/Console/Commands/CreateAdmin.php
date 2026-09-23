<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name= : Nom affiché} {--email= : Adresse email de connexion}';

    protected $description = 'Crée (ou réinitialise) le compte administrateur';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nom', 'Matt Violet');
        $email = $this->option('email') ?: $this->ask('Email de connexion', 'mv.entreprise91@gmail.com');
        $password = $this->secret('Mot de passe (12 caractères minimum, lettres et chiffres)');
        $confirmation = $this->secret('Confirmez le mot de passe');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email'],
                'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'role' => 'admin']
        );

        $this->info($user->wasRecentlyCreated ? "Compte administrateur créé pour {$email}." : "Mot de passe mis à jour pour {$email}.");

        return self::SUCCESS;
    }
}
