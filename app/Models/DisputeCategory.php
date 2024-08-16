<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisputeCategory extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
    ];

    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }
}