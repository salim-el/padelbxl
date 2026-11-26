<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'sport', 'kind', 'price_cents', 'vat_rate', 'stock', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'vat_rate' => 'decimal:2'];
    }
}
