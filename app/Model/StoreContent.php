<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/** Shared content identity; language editions are stored separately. */
class StoreContent extends Model
{
    protected $table = 'store_content';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'sort' => 'integer', 'status' => 'integer', 'source_revision' => 'integer', 'revision' => 'integer'];
}
