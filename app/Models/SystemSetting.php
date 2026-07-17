<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'encrypted'];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }
}
