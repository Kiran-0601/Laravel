<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'addline1',
        'addline2',
        'country_id',
        'city',
        'pincode'
    ];
    public function country()
    {
        return $this->belongsTo(Country::class);
    }
    
}
