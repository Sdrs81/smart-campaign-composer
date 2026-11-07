<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

class PreviewCampaignRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['customer_id' => ['nullable', 'integer', 'min:1']];
    }

    /** @throws ModelNotFoundException for an id that matches no customer (rendered as 404) */
    public function customer(): ?Customer
    {
        $id = $this->validated('customer_id');

        return $id === null ? null : Customer::query()->findOrFail($id);
    }
}
