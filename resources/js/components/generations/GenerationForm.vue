<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatNanodollars } from '@/lib/formatNanodollars';
import { estimate, store } from '@/routes/generations';

const emit = defineEmits<{ submitting: [value: boolean] }>();

type GenerationFields = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

const defaults: GenerationFields = {
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
};
const generationForm = useForm(defaults);
const preview = useHttp<
    GenerationFields,
    { estimated_user_charge_nanodollars: string }
>(defaults);
const estimatedCharge = ref<string | null>(null);
const estimating = ref(false);
const estimateUnavailable = ref(false);
const estimateRetry = ref(0);
const generationError = ref<string>();
const promptLength = computed(() => [...generationForm.prompt].length);
const promptTooLong = computed(() => promptLength.value > 4000);
const canSubmit = computed(
    () =>
        generationForm.prompt.trim() !== '' &&
        !promptTooLong.value &&
        !generationForm.processing,
);
const references = computed(() =>
    generationForm.reference_images
        .map((url, index) => ({ url: url.trim(), index }))
        .filter(({ url }) => url !== ''),
);

function payload(): GenerationFields {
    return {
        prompt: generationForm.prompt,
        size: generationForm.size,
        quality: generationForm.quality,
        reference_images: references.value.map(({ url }) => url),
    };
}

function referenceError(index: number) {
    const submittedIndex = references.value.findIndex(
        (reference) => reference.index === index,
    );

    return submittedIndex < 0
        ? undefined
        : generationForm.errors[`reference_images.${submittedIndex}`];
}

watch(
    () => generationForm.processing,
    (value) => emit('submitting', value),
    { flush: 'sync' },
);

watch(
    () => [
        generationForm.prompt,
        generationForm.size,
        generationForm.quality,
        ...generationForm.reference_images,
        estimateRetry.value,
    ],
    (_, __, onCleanup) => {
        estimatedCharge.value = null;
        estimateUnavailable.value = false;
        estimating.value =
            generationForm.prompt.trim() !== '' && !promptTooLong.value;

        if (!estimating.value) {
            return;
        }

        let stale = false;
        const timer = setTimeout(async () => {
            try {
                preview.transform(payload);
                const result = await preview.post(estimate.url());

                if (!stale) {
                    estimatedCharge.value =
                        result?.estimated_user_charge_nanodollars ?? null;
                    estimateUnavailable.value = estimatedCharge.value === null;
                }
            } catch {
                if (!stale) {
                    estimateUnavailable.value = true;
                }
            } finally {
                if (!stale) {
                    estimating.value = false;
                }
            }
        }, 350);

        onCleanup(() => {
            stale = true;
            clearTimeout(timer);
            preview.cancel();
        });
    },
);

function removeReference(index: number) {
    generationForm.reference_images.splice(index, 1);
    generationForm.clearErrors(
        'reference_images',
        'reference_images.0',
        'reference_images.1',
        'reference_images.2',
        'reference_images.3',
    );
}

function submit() {
    if (!canSubmit.value) {
        return;
    }

    generationError.value = undefined;
    generationForm.transform(payload).post(store.url(), {
        preserveScroll: true,
        onError: (errors) => {
            if (
                'generation' in errors &&
                typeof errors.generation === 'string'
            ) {
                generationError.value = errors.generation;
            }
        },
        onSuccess: () => {
            generationForm.reset('prompt', 'reference_images');
        },
    });
}
</script>

<template>
    <Card class="min-w-0">
        <CardHeader>
            <CardTitle as="h2">New generation</CardTitle>
        </CardHeader>

        <CardContent>
            <form class="space-y-5" novalidate @submit.prevent="submit">
                <fieldset
                    :disabled="generationForm.processing"
                    class="min-w-0 space-y-5"
                >
                    <div class="space-y-2">
                        <Label for="generation-prompt">Prompt</Label>
                        <textarea
                            id="generation-prompt"
                            v-model="generationForm.prompt"
                            rows="5"
                            required
                            placeholder="Describe the image you want to generate"
                            aria-describedby="generation-prompt-count generation-prompt-error"
                            :aria-invalid="
                                promptTooLong ||
                                Boolean(generationForm.errors.prompt)
                            "
                            class="min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                        />
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                        >
                            <InputError
                                id="generation-prompt-error"
                                :message="
                                    promptTooLong
                                        ? 'Use 4,000 characters or fewer.'
                                        : generationForm.errors.prompt
                                "
                            />
                            <p
                                id="generation-prompt-count"
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ promptLength.toLocaleString() }} / 4,000
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="generation-size">Size</Label>
                            <Select v-model="generationForm.size">
                                <SelectTrigger
                                    id="generation-size"
                                    aria-describedby="generation-size-error"
                                    :aria-invalid="
                                        Boolean(generationForm.errors.size)
                                    "
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1024x1024"
                                        >1024 × 1024</SelectItem
                                    >
                                    <SelectItem value="1536x1024"
                                        >1536 × 1024</SelectItem
                                    >
                                    <SelectItem value="1024x1536"
                                        >1024 × 1536</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError
                                id="generation-size-error"
                                :message="generationForm.errors.size"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="generation-quality">Quality</Label>
                            <Select v-model="generationForm.quality">
                                <SelectTrigger
                                    id="generation-quality"
                                    aria-describedby="generation-quality-error"
                                    :aria-invalid="
                                        Boolean(generationForm.errors.quality)
                                    "
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium"
                                        >Medium</SelectItem
                                    >
                                    <SelectItem value="high">High</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                id="generation-quality-error"
                                :message="generationForm.errors.quality"
                            />
                        </div>
                    </div>

                    <fieldset class="min-w-0 space-y-2">
                        <legend class="mb-2 text-sm font-medium">
                            Reference images
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            >
                        </legend>
                        <div
                            v-for="(
                                url, index
                            ) in generationForm.reference_images"
                            :key="index"
                            class="space-y-1"
                        >
                            <div class="flex gap-2">
                                <Input
                                    :model-value="url"
                                    @update:model-value="
                                        generationForm.reference_images[index] =
                                            String($event)
                                    "
                                    type="url"
                                    placeholder="Paste an HTTP(S) URL"
                                    :aria-label="
                                        'Reference image URL ' + (index + 1)
                                    "
                                    :aria-describedby="
                                        'reference-error-' + index
                                    "
                                    :aria-invalid="
                                        Boolean(referenceError(index))
                                    "
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="
                                        'Remove reference image ' + (index + 1)
                                    "
                                    @click="removeReference(index)"
                                >
                                    Remove
                                </Button>
                            </div>
                            <InputError
                                :id="'reference-error-' + index"
                                :message="referenceError(index)"
                            />
                        </div>
                        <InputError
                            :message="generationForm.errors.reference_images"
                        />
                        <Button
                            v-if="generationForm.reference_images.length < 4"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="generationForm.reference_images.push('')"
                        >
                            Add reference URL
                        </Button>
                    </fieldset>
                </fieldset>

                <div
                    class="space-y-1 rounded-lg border bg-muted/40 p-3"
                    aria-live="polite"
                >
                    <p class="text-sm font-medium">Estimated charge (USD)</p>
                    <p
                        v-if="estimating"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Spinner aria-hidden="true" />
                        Calculating…
                    </p>
                    <p
                        v-else-if="estimatedCharge !== null"
                        class="text-lg font-semibold tabular-nums"
                    >
                        {{ formatNanodollars(estimatedCharge) }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            estimateUnavailable
                                ? 'Estimate unavailable. Check the form or retry.'
                                : 'Enter a prompt to see an estimate.'
                        }}
                    </p>
                    <Button
                        v-if="estimateUnavailable"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="generationForm.processing"
                        @click="estimateRetry += 1"
                    >
                        Retry estimate
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        The final charge depends on actual token usage and
                        provider rates.
                    </p>
                </div>

                <InputError role="alert" :message="generationError" />
                <Button
                    type="submit"
                    class="w-full sm:w-auto"
                    :disabled="!canSubmit"
                >
                    <Spinner
                        v-if="generationForm.processing"
                        aria-hidden="true"
                    />
                    {{
                        generationForm.processing
                            ? 'Submitting…'
                            : 'Generate image'
                    }}
                </Button>
                <p
                    v-if="generationForm.recentlySuccessful"
                    role="status"
                    class="text-sm text-muted-foreground"
                >
                    Generation submitted. Check its status in history.
                </p>
            </form>
        </CardContent>
    </Card>
</template>
