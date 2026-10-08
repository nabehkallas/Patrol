<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A currency a station works with (see App\Support\Currency for the helpers the rest of the
 * app uses). Exactly one is the primary currency: reports and converted totals are shown in it.
 *
 * @property string $code
 * @property string $name
 * @property string|null $symbol
 * @property int $decimals
 * @property bool $is_active
 * @property bool $is_primary
 */
#[Fillable(['code', 'name', 'symbol', 'decimals', 'is_active', 'is_primary'])]
class StationCurrency extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected $table = 'currencies';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }
}
