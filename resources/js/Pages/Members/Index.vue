<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps<{ members: any[] }>();
const page = usePage<any>();
const isOwner = page.props.auth.membershipRole === 'owner';
const saving = ref<number | null>(null);
const drafts = reactive(Object.fromEntries(props.members.map((member) => [
    member.id,
    { role: member.role, status: member.status },
])));

const save = (member: any) => {
    const draft = drafts[member.id];
    const destructive = member.role === 'owner' && (draft.role !== 'owner' || draft.status !== 'active')
        || member.status === 'active' && draft.status === 'inactive';
    if (destructive && !confirm(`Apply this role or status change to ${member.user.name}?`)) return;

    saving.value = member.id;
    router.patch(route('members.update', member.id), draft, {
        preserveScroll: true,
        onFinish: () => saving.value = null,
    });
};
</script>

<template>
    <Head title="Members" />
    <AuthenticatedLayout>
        <h1 class="h2">Tenant members</h1>
        <p class="text-secondary">Existing memberships can be updated here. Adding and inviting members is deferred.</p>
        <div v-if="page.props.errors?.role || page.props.errors?.status" class="alert alert-danger" role="alert">
            {{ page.props.errors.role || page.props.errors.status }}
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Member</th><th>Role</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id">
                            <td>{{ member.user.name }}<small class="d-block text-secondary">{{ member.user.email }}</small></td>
                            <td>
                                <label class="visually-hidden" :for="`role-${member.id}`">Role for {{ member.user.name }}</label>
                                <select :id="`role-${member.id}`" v-model="drafts[member.id].role" class="form-select form-select-sm">
                                    <option v-if="isOwner || member.role === 'owner'">owner</option>
                                    <option>administrator</option><option>teacher</option><option>parent</option><option>tutor</option><option>student</option>
                                </select>
                            </td>
                            <td>
                                <label class="visually-hidden" :for="`status-${member.id}`">Status for {{ member.user.name }}</label>
                                <select :id="`status-${member.id}`" v-model="drafts[member.id].status" class="form-select form-select-sm">
                                    <option>active</option><option>inactive</option>
                                </select>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary" type="button" :disabled="saving === member.id" @click="save(member)">
                                    <span v-if="saving === member.id" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Save
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
