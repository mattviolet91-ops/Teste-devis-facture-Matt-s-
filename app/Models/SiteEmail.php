<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Email du formulaire du site déjà traité. */
class SiteEmail extends Model
{
    protected $fillable = ['message_id', 'quote_request_id', 'subject', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
