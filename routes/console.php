<?php

use Illuminate\Support\Facades\Schedule;

// Lancé chaque minute par la tâche cron d'o2switch (voir docs d'installation).
Schedule::command('app:purge-trash')->dailyAt('03:00');
Schedule::command('app:expire-quotes')->dailyAt('02:30');
Schedule::command('app:insurance-reminder')->dailyAt('08:00');
Schedule::command('app:payment-reminders')->dailyAt('09:00');
Schedule::command('app:backup')->dailyAt('01:30');
Schedule::command('app:reminder-notifications')->dailyAt('08:30');
Schedule::command('app:maintenance-notifications')->dailyAt('08:40');
Schedule::command('app:planning-notifications')->dailyAt('19:00');
Schedule::command('app:planning-notifications --bientot')->everyFiveMinutes();
Schedule::command('app:review-requests')->dailyAt('10:00');
Schedule::command('app:planning-notifications --rappels')->dailyAt('09:00');
