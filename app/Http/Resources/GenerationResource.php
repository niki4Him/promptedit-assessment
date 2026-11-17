<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => (string) $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'status' => $this->status->value,
            'needs_submission_retry' => $this->provider_generation_id === null,
            'estimated_user_charge_nanodollars' => (string) $this->estimated_user_charge_nanodollars,
            'actual_user_charge_nanodollars' => $this->actual_user_charge_nanodollars === null
                ? null
                : (string) $this->actual_user_charge_nanodollars,
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'error_message' => $this->status === GenerationStatus::Failed ? $this->provider_error_message : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
