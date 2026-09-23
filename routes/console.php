<?php

use Illuminate\Support\Facades\Schedule;

// Lancé chaque minute par la tâche cron d'o2switch (voir docs d'installation).
Schedule::command('app:purge-trash')->dailyAt('03:00');
