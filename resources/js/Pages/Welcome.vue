<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ canLogin?: boolean; canRegister?: boolean }>();
</script>

<template>
    <Head title="Welcome" />
    <main class="min-vh-100 d-flex align-items-center py-5">
        <div class="container" style="max-width: 900px">
            <div class="card overflow-hidden">
                <div class="card-body p-4 p-md-5">
                    <span class="badge text-bg-primary mb-3">Platform foundation</span>
                    <h1 class="display-5 fw-bold">A calm place to organize learning.</h1>
                    <p class="lead text-secondary">Securely manage families, students, school years, and enrollment history in one tenant-aware workspace.</p>
                    <div v-if="canLogin" class="d-flex gap-2 flex-wrap mt-4">
                        <Link v-if="$page.props.auth?.user" class="btn btn-primary btn-lg" :href="route('dashboard')">Open dashboard</Link>
                        <template v-else>
                            <Link class="btn btn-primary btn-lg" :href="route('login')">Log in</Link>
                            <Link v-if="canRegister" class="btn btn-outline-primary btn-lg" :href="route('register')">Create an account</Link>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>
