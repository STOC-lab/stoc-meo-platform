<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKeywordRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the keyword
     * policy, which the controller consults.
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

        return [
            'keyword' => [
                'required',
                'string',
                'max:255',
                Rule::unique('keywords', 'keyword')
                    ->where('location_id', $location instanceof Location ? $location->getKey() : $location),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keyword.unique' => 'このキーワードは既にこの店舗で計測されています。',
        ];
    }
}
