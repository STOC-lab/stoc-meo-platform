<?php

namespace App\Http\Requests;

use App\Models\Keyword;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKeywordRequest extends FormRequest
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
     * A PATCH may carry only the fields being changed, so every rule is
     * conditional on the field being present.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('location');
        $keyword = $this->route('keyword');

        return [
            'keyword' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('keywords', 'keyword')
                    ->where('location_id', $location instanceof Location ? $location->getKey() : $location)
                    ->ignore($keyword instanceof Keyword ? $keyword->getKey() : $keyword),
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
