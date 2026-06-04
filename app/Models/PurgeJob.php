<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurgeJob extends Model
{
    protected $table = 'purge_queue';

    public const STATES = ['queued', 'running', 'done', 'error'];

    protected $fillable = ['user_id', 'email', 'payload', 'state', 'attempts', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
