<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <h1 class="h2 mb-4">Profile</h1>
        <div class="row g-4">
            <div class="col-12">
                <div class="card"><div class="card-body p-4">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                    />
                </div></div>
            </div>
            <div class="col-12"><div class="card"><div class="card-body p-4"><UpdatePasswordForm /></div></div></div>
            <div class="col-12"><div class="card border border-danger-subtle"><div class="card-body p-4"><DeleteUserForm /></div></div></div>
            </div>
    </AuthenticatedLayout>
</template>
