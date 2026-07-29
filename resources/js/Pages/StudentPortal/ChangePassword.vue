<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '', password_confirmation: '' });
const submit = () => form.put(route('student.password.update'), {
    onFinish: () => form.reset(),
});
</script>

<template>
    <Head title="Change your password" />
    <StudentPortalLayout restricted>
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card">
                    <form class="card-body p-4" @submit.prevent="submit">
                        <h1 class="h3">Choose a new password</h1>
                        <p class="text-secondary">Your temporary password must be changed before you continue to the student portal.</p>
                        <label class="form-label" for="student_new_password">New password</label>
                        <input id="student_new_password" v-model="form.password" class="form-control" type="password" autocomplete="new-password" required autofocus>
                        <div v-if="form.errors.password" class="text-danger small" role="alert">{{ form.errors.password }}</div>
                        <label class="form-label mt-3" for="student_new_password_confirmation">Confirm new password</label>
                        <input id="student_new_password_confirmation" v-model="form.password_confirmation" class="form-control" type="password" autocomplete="new-password" required>
                        <button class="btn btn-primary mt-4" :disabled="form.processing">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
                            Change password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </StudentPortalLayout>
</template>
