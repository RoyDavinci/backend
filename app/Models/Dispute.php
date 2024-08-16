<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'subcategory_id',
        'description',
        'status',
        'created_at',
        'updated_at',
        'resolved_at',
        'start_time',
        'end_time',
        'title'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(DisputeCategory::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(DisputeSubcategory::class);
    }

    public function files()
    {
        return $this->hasMany(DisputeFile::class);
    }
}
