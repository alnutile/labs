<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoRun extends Model
{
    protected $fillable = ['user_id', 'message', 'status', 'file_path', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
