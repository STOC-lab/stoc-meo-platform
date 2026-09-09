<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the location
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

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('brands', 'id')
                    ->where('organization_id', app(Tenancy::class)->id()),
            ],
            'gbp_location_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('locations', 'gbp_location_id')
                    ->ignore($location instanceof Location ? $location->getKey() : $location),
            ],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brand_id.exists' => '指定されたブランドが見つかりません。',
            'gbp_location_id.unique' => 'このGoogleビジネスプロフィールは既に別の店舗に紐付いています。',
        ];
    }
}
