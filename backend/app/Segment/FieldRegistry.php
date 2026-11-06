<?php

declare(strict_types=1);

namespace App\Segment;

use Closure;

/**
 * The single source of truth for what a segment may filter on (K7). The parser, compiler, prompt and
 * JSON schema read it; column names reach SQL only through here.
 */
final class FieldRegistry
{
    private const AGE_MAX = 120;

    private const COUNT_MAX = 1_000_000;

    private const MONEY_MAX = 100_000_000;

    /** @var array<string, FieldDefinition> keyed by field name, so lookup is O(1) */
    private array $fields = [];

    /** @param  Closure(): list<string>  $cities  returns the distinct cities of the customers */
    public function __construct(Closure $cities)
    {
        $this->register(
            $this->enum('city', 'Şehir', $cities),
            $this->enum('gender', 'Cinsiyet', fn (): array => ['female', 'male']),
            $this->enum('language', 'Dil', fn (): array => ['tr', 'en']),
            $this->enum('platform', 'Platform', fn (): array => ['ios', 'android', 'web']),
            $this->enum('loyalty_tier', 'Sadakat seviyesi', fn (): array => ['bronze', 'silver', 'gold', 'platinum']),
            $this->enum('favorite_category', 'Favori kategori', fn (): array => ['elektronik', 'moda', 'ev', 'kozmetik', 'spor', 'kitap', 'market']),
            new FieldDefinition('age', FieldType::Number, 'birth_date', 'Yaş', min: 0, max: self::AGE_MAX, derivedFromBirthDate: true),
            $this->number('orders_count', 'Sipariş sayısı', self::COUNT_MAX),
            $this->number('total_spent', 'Toplam harcama', self::MONEY_MAX),
            $this->number('avg_order_value', 'Ortalama sipariş tutarı', self::MONEY_MAX),
            $this->number('cart_items_count', 'Sepetteki ürün sayısı', self::COUNT_MAX),
            $this->number('cart_value', 'Sepet tutarı', self::MONEY_MAX),
            $this->date('registered_at', 'Kayıt tarihi'),
            $this->date('last_login_at', 'Son giriş tarihi'),
            $this->date('first_purchase_at', 'İlk alışveriş tarihi'),
            $this->date('last_purchase_at', 'Son alışveriş tarihi'),
            $this->date('last_cart_add_at', 'Sepete son ekleme tarihi'),
        );
    }

    public function find(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, FieldDefinition> */
    public function all(): array
    {
        return $this->fields;
    }

    private function register(FieldDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->fields[$definition->name] = $definition;
        }
    }

    /** @param  Closure(): list<string>  $values */
    private function enum(string $name, string $label, Closure $values): FieldDefinition
    {
        return new FieldDefinition($name, FieldType::Enum, $name, $label, enumValues: $values);
    }

    private function number(string $name, string $label, int $max): FieldDefinition
    {
        return new FieldDefinition($name, FieldType::Number, $name, $label, min: 0, max: $max);
    }

    private function date(string $name, string $label): FieldDefinition
    {
        return new FieldDefinition($name, FieldType::Date, $name, $label);
    }
}
