<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface AccessDetails {
    username: string;
    status: 'active' | 'disabled';
    must_change_password: boolean;
    last_login_at: string | null;
    enabled_at: string | null;
}

const props = defineProps<{
    student: { id: number; name: string; display_name: string; status: string };
    access: AccessDetails | null;
    suggestedUsername: string | null;
}>();

const enableForm = useForm({
    username: props.suggestedUsername ?? '',
    password: '',
    password_confirmation: '',
    must_change_password: true,
});
const usernameForm = useForm({ username: props.access?.username ?? '' });
const resetForm = useForm({ password: '', password_confirmation: '' });
const disableForm = useForm({ confirm: true });
const reenableForm = useForm({});
const showReset = ref(false);
const showDisable = ref(false);

const enable = () =>
    enableForm.post(route('students.access.enable', props.student.id));
const updateUsername = () =>
    usernameForm.patch(route('students.access.username', props.student.id));
const resetPassword = () =>
    resetForm.put(route('students.access.password', props.student.id), {
        onSuccess: () => {
            showReset.value = false;
            resetForm.reset();
        },
    });
const disable = () =>
    disableForm.patch(route('students.access.disable', props.student.id), {
        onSuccess: () => (showDisable.value = false),
    });
const reenable = () =>
    reenableForm.patch(route('students.access.reenable', props.student.id));

const formatDateTime = (value: string | null) =>
    value ? new Date(value).toLocaleString() : 'Never';
</script>

<template>
    <Head :title="`Student access — ${student.display_name}`" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1 class="h2">Student access</h1>
                <p class="text-secondary mb-0">{{ student.name }}</p>
            </div>
            <Link class="btn btn-outline-secondary" :href="route('students.show', student.id)">Back to student</Link>
        </div>

        <div v-if="!access" class="card">
            <form class="card-body" @submit.prevent="enable">
                <h2 class="h5">Enable student portal access</h2>
                <p class="text-secondary">This creates one dedicated login linked to the student profile. It does not change enrollment history.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="access_username">Username</label>
                        <input id="access_username" v-model="enableForm.username" class="form-control" autocomplete="off" required>
                        <div v-if="enableForm.errors.username" class="text-danger small" role="alert">{{ enableForm.errors.username }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-md-4 pt-md-2">
                            <input id="must_change_password" v-model="enableForm.must_change_password" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="must_change_password">Require password change at first login</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="temporary_password">Temporary password</label>
                        <input id="temporary_password" v-model="enableForm.password" class="form-control" type="password" autocomplete="new-password" required>
                        <div v-if="enableForm.errors.password" class="text-danger small" role="alert">{{ enableForm.errors.password }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="temporary_password_confirmation">Confirm temporary password</label>
                        <input id="temporary_password_confirmation" v-model="enableForm.password_confirmation" class="form-control" type="password" autocomplete="new-password" required>
                    </div>
                </div>
                <button class="btn btn-primary mt-4" :disabled="enableForm.processing">
                    <span v-if="enableForm.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
                    Enable student access
                </button>
            </form>
        </div>

        <template v-else>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-3">
                        <div>
                            <h2 class="h5">Portal access</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Status</dt><dd class="col-sm-7 text-capitalize">{{ access.status }}</dd>
                                <dt class="col-sm-5">Username</dt><dd class="col-sm-7">{{ access.username }}</dd>
                                <dt class="col-sm-5">Password change required</dt><dd class="col-sm-7">{{ access.must_change_password ? 'Yes' : 'No' }}</dd>
                                <dt class="col-sm-5">Last login</dt><dd class="col-sm-7">{{ formatDateTime(access.last_login_at) }}</dd>
                                <dt class="col-sm-5">Enabled</dt><dd class="col-sm-7">{{ formatDateTime(access.enabled_at) }}</dd>
                            </dl>
                        </div>
                        <div class="d-flex align-items-start gap-2">
                            <button class="btn btn-outline-primary" type="button" @click="showReset = true">Reset password</button>
                            <button v-if="access.status === 'active'" class="btn btn-outline-danger" type="button" @click="showDisable = true">Disable access</button>
                            <button v-else class="btn btn-success" type="button" :disabled="reenableForm.processing" @click="reenable">Re-enable access</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <form class="card-body" @submit.prevent="updateUsername">
                    <h2 class="h5">Change username</h2>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label" for="updated_username">Username</label>
                            <input id="updated_username" v-model="usernameForm.username" class="form-control" required>
                            <div v-if="usernameForm.errors.username" class="text-danger small" role="alert">{{ usernameForm.errors.username }}</div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-outline-primary" :disabled="usernameForm.processing">Save username</button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        <Modal :show="showReset" max-width="md" @close="showReset = false">
            <form class="p-4" @submit.prevent="resetPassword">
                <h2 class="h5">Reset student password</h2>
                <p class="text-secondary">This signs out other sessions and requires another password change.</p>
                <label class="form-label" for="reset_password">New temporary password</label>
                <input id="reset_password" v-model="resetForm.password" class="form-control" type="password" autocomplete="new-password" required>
                <div v-if="resetForm.errors.password" class="text-danger small" role="alert">{{ resetForm.errors.password }}</div>
                <label class="form-label mt-3" for="reset_password_confirmation">Confirm temporary password</label>
                <input id="reset_password_confirmation" v-model="resetForm.password_confirmation" class="form-control" type="password" autocomplete="new-password" required>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button class="btn btn-outline-secondary" type="button" @click="showReset = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="resetForm.processing">Reset password</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showDisable" max-width="md" @close="showDisable = false">
            <div class="p-4">
                <h2 class="h5">Disable student access?</h2>
                <p>The student will be signed out and unable to use the portal. Their profile, username, enrollment, and history will remain.</p>
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" type="button" @click="showDisable = false">Cancel</button>
                    <button class="btn btn-danger" type="button" :disabled="disableForm.processing" @click="disable">Disable access</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
