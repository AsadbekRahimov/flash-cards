<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** DELETE /api/twa/me/organizations/{member}: only the shape of the id is checked here; whose it is, the service decides. */
final class LeaveOrganizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['member' => $this->route('member')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'member' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function memberId(): int
    {
        return $this->integer('member');
    }
}
