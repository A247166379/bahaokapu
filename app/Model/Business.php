<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $shop_name
 * @property string $title
 * @property string $notice
 * @property string $service_qq
 * @property string $service_url
 * @property string $subdomain
 * @property string $topdomain
 * @property string $create_time
 * @property int $master_display
 */
class Business extends Model
{
    /**
     * @var string
     */
    protected $table = "business";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'master_display' => 'integer'];

    /**
     * @return Business|mixed
     */
    public static function get(): ?Business
    {
        // This installation runs one shop. Historical business rows remain for order records.
        return null;
    }

    /**
     * @return bool
     */
    public static function state(): bool
    {
        return false;
    }


    public function user(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }
}
