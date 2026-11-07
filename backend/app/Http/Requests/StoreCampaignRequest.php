<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Brief limits come from the single LLM config so the extractor and the API agree.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'brief' => [
                'required',
                'string',
                'min:'.config('llm.brief.min_length'),
                'max:'.config('llm.brief.max_length'),
            ],
        ];
    }
}
