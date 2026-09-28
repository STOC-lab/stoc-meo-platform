<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectGbpLocationRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the
     * connection policy, which the controller consults.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both names are held in Google's resource form, which is what every
     * client and sync job expects to be given.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer'],
            'gbp_account_name' => ['required', 'string', 'max:255', 'regex:/^accounts\/[0-9]+$/'],
            'gbp_location_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^locations\/[0-9]+$/',
                Rule::unique('locations', 'gbp_location_id')->ignore($this->integer('location_id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gbp_location_id.unique' => 'このGoogleビジネスプロフィールは既に別の店舗に紐付いています。',
        ];
    }
}
