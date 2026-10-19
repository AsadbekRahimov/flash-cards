<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/twa/me/course. Access is the `TwaAuth` middleware; availability of the course is a service rule. */
final class SelectCourseRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:64'],
        ];
    }
}
