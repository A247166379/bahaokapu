<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/** Entity-scoped, human reviewed translations; never stores price or stock. */
class ContentTranslation extends Model
{
    protected $table = 'content_translation';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'status' => 'integer', 'source_revision' => 'integer'];
}
