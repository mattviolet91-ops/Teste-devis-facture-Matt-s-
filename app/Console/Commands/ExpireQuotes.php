<?php

namespace App\Console\Commands;

use App\Services\QuoteService;
use Illuminate\Console\Command;

class ExpireQuotes extends Command
{
    protected $signature = 'app:expire-quotes';

    protected $description = 'Passe en « expiré » les devis envoyés dont la date de validité est dépassée';

    public function handle(QuoteService $quotes): int
    {
        $this->info($quotes->expireOverdue().' devis expiré(s).');

        return self::SUCCESS;
    }
}
