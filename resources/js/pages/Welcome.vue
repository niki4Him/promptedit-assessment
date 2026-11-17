<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { login, register } from '@/routes';
import { index as generations } from '@/routes/generations';
</script>

<template>
    <Head title="PromptEdit" />

    <main
        class="flex min-h-screen items-center justify-center bg-background px-6 py-12 text-foreground"
    >
        <div class="w-full max-w-xl space-y-8">
            <p
                class="text-sm font-semibold tracking-wide text-muted-foreground"
            >
                PromptEdit
            </p>
            <div class="space-y-4">
                <h1 class="text-4xl font-semibold tracking-tight">
                    Turn your prompts into images.
                </h1>
                <p class="text-lg text-muted-foreground">
                    Generate images with Pixelforge, see an upfront estimate,
                    and follow progress in your generation history.
                </p>
                <p class="text-sm text-muted-foreground">
                    Pixelforge is simulated: images are placeholders and no real
                    money is charged. Displayed charges use provider token
                    pricing plus a 25% markup. Failed generations have zero
                    charge.
                </p>
            </div>
            <nav
                aria-label="Get started"
                class="flex flex-wrap items-center gap-4"
            >
                <Link
                    v-if="$page.props.auth.user"
                    :href="generations()"
                    class="rounded-md bg-primary px-5 py-3 font-medium text-primary-foreground"
                >
                    Open generations
                </Link>
                <template v-else>
                    <Link
                        :href="register()"
                        class="rounded-md bg-primary px-5 py-3 font-medium text-primary-foreground"
                        >Create an account</Link
                    >
                    <Link
                        :href="login()"
                        class="rounded-md border px-5 py-3 font-medium"
                        >Log in</Link
                    >
                </template>
            </nav>
            <a
                href="https://github.com/niki4Him/promptedit-assessment"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-block text-sm underline underline-offset-4"
                >View source on GitHub</a
            >
        </div>
    </main>
</template>
