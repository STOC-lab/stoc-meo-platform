<?php

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the
     * organization policy, which the controller consults. The rules that
     * protect the owner seat depend on the member being acted on and are
     * enforced in MemberController.
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
        return [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
