<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatNanodollars } from '@/lib/formatNanodollars';
import { recover } from '@/routes/generations';
import type { GenerationHistory } from '@/types/generation';

defineProps<{ history: GenerationHistory }>();
const emit = defineEmits<{ navigating: [value: boolean] }>();
const unavailableImages = reactive(new Set<string>());
const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const recoveringId = ref<string | null>(null);
const recoveryError = ref<{ id: string; message: string } | null>(null);

function checkStatus(id: string) {
    if (recoveringId.value !== null) {
        return;
    }

    recoveringId.value = id;
    recoveryError.value = null;
    emit('navigating', true);

    router.post(
        recover.url(id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                if (typeof errors.recovery === 'string') {
                    recoveryError.value = { id, message: errors.recovery };
                }
            },
            onFinish: () => {
                recoveringId.value = null;
                emit('navigating', false);
            },
        },
    );
}
</script>

<template>
    <section aria-labelledby="history-title" class="min-w-0 space-y-4">
        <h2 id="history-title" class="text-lg font-semibold tracking-tight">
            Generation history
        </h2>

        <div
            v-if="history.data.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{
                history.previous
                    ? 'There are no generations on this page.'
                    : 'Your generated images will appear here after you submit a prompt.'
            }}
        </div>

        <ol v-else class="space-y-3">
            <li v-for="generation in history.data" :key="generation.id">
                <Card class="py-4">
                    <CardContent class="space-y-3 px-4 sm:px-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
                        >
                            <Badge
                                :variant="
                                    generation.status === 'failed'
                                        ? 'destructive'
                                        : generation.status === 'succeeded'
                                          ? 'default'
                                          : 'secondary'
                                "
                                class="capitalize"
                            >
                                {{ generation.status }}
                            </Badge>
                            <time
                                v-if="generation.created_at"
                                :datetime="generation.created_at"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    dateTime.format(
                                        new Date(generation.created_at),
                                    )
                                }}
                            </time>
                        </div>

                        <p
                            class="text-sm font-medium break-words whitespace-pre-wrap"
                        >
                            {{ generation.prompt }}
                        </p>
                        <p class="text-sm text-muted-foreground capitalize">
                            {{ generation.size.replace('x', ' × ') }} ·
                            {{ generation.quality }} quality
                        </p>

                        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Estimated charge (USD)
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{
                                        formatNanodollars(
                                            generation.estimated_user_charge_nanodollars,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Actual charge (USD)
                                </dt>
                                <dd
                                    v-if="
                                        generation.actual_user_charge_nanodollars !==
                                        null
                                    "
                                    class="font-medium tabular-nums"
                                >
                                    {{
                                        formatNanodollars(
                                            generation.actual_user_charge_nanodollars,
                                        )
                                    }}
                                </dd>
                                <dd v-else class="text-muted-foreground">
                                    Pending
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="
                                generation.status === 'queued' ||
                                generation.status === 'processing'
                            "
                            class="space-y-2"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="recoveringId !== null"
                                @click="checkStatus(generation.id)"
                            >
                                {{
                                    recoveringId === generation.id
                                        ? 'Checking…'
                                        : generation.needs_submission_retry
                                          ? 'Retry submission'
                                          : 'Check status'
                                }}
                            </Button>
                            <p
                                v-if="recoveryError?.id === generation.id"
                                role="alert"
                                class="text-sm text-red-600 dark:text-red-500"
                            >
                                {{ recoveryError.message }}
                            </p>
                        </div>

                        <img
                            v-if="
                                generation.image_url &&
                                !unavailableImages.has(generation.image_url)
                            "
                            :src="generation.image_url"
                            :alt="
                                'Generated image for: ' +
                                generation.prompt.slice(0, 100)
                            "
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            class="max-h-72 w-auto max-w-full rounded-md border object-contain"
                            @error="unavailableImages.add(generation.image_url)"
                        />
                        <p
                            v-else-if="generation.image_expired"
                            class="text-sm text-muted-foreground"
                        >
                            Image link expired.
                        </p>
                        <p
                            v-else-if="generation.image_url"
                            class="text-sm text-muted-foreground"
                        >
                            Image unavailable.
                        </p>

                        <p
                            v-if="generation.status === 'failed'"
                            class="text-sm break-words text-red-600 dark:text-red-500"
                        >
                            {{
                                generation.error_message ||
                                'Pixelforge could not generate this image.'
                            }}
                        </p>
                    </CardContent>
                </Card>
            </li>
        </ol>

        <nav
            v-if="history.previous || history.next"
            aria-label="Generation history pages"
            class="flex gap-2"
        >
            <Button
                v-if="history.previous"
                as-child
                variant="outline"
                size="sm"
            >
                <Link
                    :href="history.previous"
                    :only="['history']"
                    preserve-scroll
                    @start="emit('navigating', true)"
                    @finish="emit('navigating', false)"
                >
                    Newer
                </Link>
            </Button>
            <Button v-if="history.next" as-child variant="outline" size="sm">
                <Link
                    :href="history.next"
                    :only="['history']"
                    preserve-scroll
                    @start="emit('navigating', true)"
                    @finish="emit('navigating', false)"
                >
                    Older
                </Link>
            </Button>
        </nav>
    </section>
</template>
