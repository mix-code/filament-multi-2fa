<?php

namespace MixCode\FilamentMulti2fa\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrustDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_name',
        'device_signature',
        'expires_at',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(config('filament-multi-2fa.user_model'), 'user_id')->withoutGlobalScopes();
    }
}
