<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

defineProps<{ students: any[] }>();
const page = usePage<any>();
const canManage = page.props.auth.permissions.includes('students.manage');

const archive = (student: any) => {
    const name = student.preferred_name || student.first_name;
    if (confirm(`Archive ${name}? Enrollment history will remain available.`)) {
        router.patch(route('students.archive', student.id));
    }
};
</script>

<template>
    <Head title="Students" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2">Students</h1>
                <p class="text-secondary mb-0">Academic profiles are separate from login accounts.</p>
            </div>
            <Link v-if="canManage" class="btn btn-primary" :href="route('students.create')">Add student</Link>
        </div>
        <div class="card">
            <div v-if="!students.length" class="empty-state">
                <h2 class="h5">No students yet</h2>
                <p>Add a student without needing an email address or login.</p>
            </div>
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Name</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="student in students" :key="student.id">
                            <td><Link :href="route('students.show', student.id)">{{ student.preferred_name || student.first_name }} {{ student.last_name }}</Link></td>
                            <td><StatusBadge :status="student.status" /></td>
                            <td class="text-end">
                                <Link v-if="canManage" class="btn btn-sm btn-outline-secondary me-2" :href="route('students.edit', student.id)">Edit</Link>
                                <button v-if="canManage && student.status !== 'archived'" class="btn btn-sm btn-outline-danger" type="button" @click="archive(student)">Archive</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
