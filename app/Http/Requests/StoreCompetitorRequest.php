<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompetitorRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the
     * competitor policy, which the controller consults.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('location');
        $locationId = $location instanceof Location ? $location->getKey() : $location;

        return [
            'name' => ['required', 'string', 'max:255'],
            'gbp_place_id' => [
                'nullable',
                'string',
                'max:255',
                // Two store fronts may watch the same rival, so the place id
                // is only unique within one of them.
                Rule::unique('competitors', 'gbp_place_id')->where('location_id', $locationId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '競合店舗名を入力してください。',
            'gbp_place_id.unique' => 'この競合店舗は既にこの店舗に登録されています。',
        ];
    }
}
