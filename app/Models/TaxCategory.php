<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** accommodation (SAC 9963), food, service, other. */
class TaxCategory extends Model
{
    protected $table = 'tax_categories';

    public $timestamps = false;

    protected $guarded = ['id'];
}
