<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Standard soft-delete behaviour for base entity models.
 */
trait HasSoftDeletes
{
    use SoftDeletes;
}
