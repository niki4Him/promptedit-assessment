<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ApplyPixelforgeWebhook
{
    private const EVENT_TYPES = [
        'ping',
        'generation.processing',
        'generation.succeeded',
        'generation.failed',
    ];

    public function __construct(private readonly ApplyPixelforgeSnapshot $apply) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $envelope = Validator::make($event, [
            'id' => ['required', 'string', 'max:255', 'regex:/\Aevt_[a-zA-Z0-9_-]+\z/'],
            'object' => ['sometimes', 'required', 'in:event'],
            'type' => ['required', 'string', 'in:'.implode(',', self::EVENT_TYPES)],
        ])->validate();

        DB::transaction(function () use ($event, $envelope): void {
            // Claim the event before reading the generation. On SQLite this takes the write lock first.
            $claimed = DB::table('pixelforge_webhook_events')->insertOrIgnore([
                'id' => $envelope['id'],
                'processed_at' => now(),
            ]);

            if ($claimed === 0 || $envelope['type'] === 'ping') {
                return;
            }

            $status = GenerationStatus::from(str($envelope['type'])->after('generation.')->toString());
            $data = Validator::make($event, [
                'data.object' => ['required', 'array'],
                'data.object.metadata.generation_id' => ['required', 'string', 'integer', 'min:1', 'regex:/\A[1-9][0-9]*\z/'],
            ])->validate();

            $this->apply->handle((int) $data['data']['object']['metadata']['generation_id'], data_get($event, 'data.object'), $status);
        }, attempts: 3);
    }
}
