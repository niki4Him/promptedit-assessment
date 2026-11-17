<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <rect
            x="4"
            y="5"
            width="32"
            height="32"
            rx="6"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
        />
        <circle cx="14" cy="15" r="3" fill="currentColor" />
        <path
            d="M6 31 16 22 22 27 29 18 35 25"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
