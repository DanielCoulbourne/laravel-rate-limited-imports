<?php

namespace App\Models\SourceApi;

use Illuminate\Database\Eloquent\Model;

/**
 * ApiItem Model
 *
 * Represents the SOURCE data that the API serves.
 * These are the seeded items that exist in the api_items table.
 *
 * This is separate from ImportedItem which represents the IMPORTED data.
 */
class ApiItem extends Model
{
    protected $table = 'api_items';

    protected $fillable = [
        'name',
        'description',
        'price',
    ];
}
