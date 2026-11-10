<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Running the audience again may carry an edited brief. It is optional (a plain re-run keeps the saved
 * brief) and has the same limits as when the campaign is created.
 */
class DefineAudienceRequest extends StoreCampaignRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'brief' => ['sometimes', ...array_values(array_diff(parent::rules()['brief'], ['required']))],
        ];
    }
}
