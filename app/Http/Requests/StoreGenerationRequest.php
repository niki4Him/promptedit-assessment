<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGenerationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'string', 'in:low,medium,high'],
            'reference_images' => ['sometimes', 'array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https'],
        ];
    }
}
