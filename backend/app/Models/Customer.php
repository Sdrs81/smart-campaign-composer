<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $first_name
 * @property string|null $email
 * @property string|null $phone
 * @property CarbonInterface $birth_date
 * @property bool $sms_opt_in
 * @property bool $email_opt_in
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    // Behavior columns are summaries owned by the seeder, so created_at/updated_at add nothing.
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'registered_at' => 'datetime',
            'last_login_at' => 'datetime',
            'first_purchase_at' => 'datetime',
            'last_purchase_at' => 'datetime',
            'last_cart_add_at' => 'datetime',
            'orders_count' => 'integer',
            'cart_items_count' => 'integer',
            'total_spent' => 'decimal:2',
            'avg_order_value' => 'decimal:2',
            'cart_value' => 'decimal:2',
            'sms_opt_in' => 'boolean',
            'email_opt_in' => 'boolean',
        ];
    }

    /** Consent and a usable address for the channel are both required. */
    public function canReceive(Channel $channel): bool
    {
        return match ($channel) {
            Channel::Sms => $this->sms_opt_in && filled($this->phone),
            Channel::Email => $this->email_opt_in && filled($this->email),
        };
    }

    public function age(): int
    {
        return (int) $this->birth_date->diffInYears(now(), true);
    }
}
