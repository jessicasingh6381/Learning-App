<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    student: Record<string, unknown>;
    academy: string;
    enrollment: Record<string, unknown> | null;
}>();
</script>

<template>
    <Head title="My Learning" />
    <StudentPortalLayout>
        <h1 class="h2">My Learning</h1>
        <div class="card mt-4">
            <div class="card-body py-5 text-center">
                <h2 class="h4">Your lessons and assignments will appear here once they are assigned.</h2>
                <p class="text-secondary mb-0">There is nothing to complete yet.</p>
            </div>
        </div>
    </StudentPortalLayout>
</template>
