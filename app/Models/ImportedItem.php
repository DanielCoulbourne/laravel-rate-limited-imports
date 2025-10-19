<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedItem extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
    ];
}
