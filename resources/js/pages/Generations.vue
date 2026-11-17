<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import GenerationHistory from '@/components/generations/GenerationHistory.vue';
import { index } from '@/routes/generations';
import type { GenerationHistory as History } from '@/types/generation';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: index() }],
    },
});

const props = defineProps<{ history: History }>();
const submitting = ref(false);
const changingPage = ref(false);
const shouldPoll = computed(
    () =>
        !submitting.value &&
        !changingPage.value &&
        props.history.data.some(
            ({ status }) => status === 'queued' || status === 'processing',
        ),
);

onMounted(() => {
    watch(
        shouldPoll,
        (enabled, _, onCleanup) => {
            if (!enabled) {
                return;
            }

            let stopped = false;
            let timer: ReturnType<typeof setTimeout>;
            let cancel: (() => void) | undefined;

            function schedule() {
                if (stopped) {
                    return;
                }

                timer = setTimeout(() => {
                    router.reload({
                        only: ['history'],
                        preserveErrors: true,
                        showProgress: false,
                        onCancelToken: (token) => {
                            cancel = () => token.cancel();
                        },
                        onFinish: () => {
                            cancel = undefined;
                            schedule();
                        },
                    });
                }, 5000);
            }

            schedule();
            onCleanup(() => {
                stopped = true;
                clearTimeout(timer);
                cancel?.();
            });
        },
        { immediate: true, flush: 'sync' },
    );
});
</script>

<template>
    <Head title="Generations" />

    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        <h1 class="mb-6 text-2xl font-semibold tracking-tight">
            Image generations
        </h1>

        <div
            class="grid items-start gap-8 xl:grid-cols-[minmax(0,25rem)_minmax(0,1fr)]"
        >
            <GenerationForm @submitting="submitting = $event" />
            <GenerationHistory
                :history="history"
                @navigating="changingPage = $event"
            />
        </div>
    </div>
</template>
