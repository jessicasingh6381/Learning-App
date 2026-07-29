<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{ student: any; access: any | null }>();
const page = usePage<any>();
const canManageStudents = page.props.auth.permissions.includes('students.manage');
const canManageEnrollments = page.props.auth.permissions.includes('enrollments.manage');
</script>

<template>
    <Head :title="student.preferred_name || student.first_name" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between">
            <div>
                <h1 class="h2">{{ student.preferred_name || student.first_name }} {{ student.last_name }}</h1>
                <span class="badge text-bg-secondary status-badge">{{ student.status }}</span>
            </div>
            <div>
                <Link v-if="canManageStudents" class="btn btn-outline-secondary me-2" :href="route('students.edit', student.id)">Edit</Link>
                <Link v-if="canManageStudents" class="btn btn-outline-primary me-2" :href="route('students.access.show', student.id)">
                    {{ access ? 'Manage student access' : 'Enable student access' }}
                </Link>
                <Link v-if="canManageEnrollments && student.status === 'active'" class="btn btn-primary" :href="route('enrollments.create', { student: student.id })">Add enrollment</Link>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="h5">Student portal access</h2>
                        <template v-if="access">
                            <p class="mb-1"><strong>Username:</strong> {{ access.username }}</p>
                            <p class="mb-1"><strong>Status:</strong> <span class="text-capitalize">{{ access.status }}</span></p>
                            <p class="mb-0"><strong>Password change required:</strong> {{ access.must_change_password ? 'Yes' : 'No' }}</p>
                        </template>
                        <p v-else class="text-secondary mb-0">Portal access has not been enabled. Enrollment does not create a login automatically.</p>
                    </div>
                    <Link v-if="canManageStudents" class="btn btn-sm btn-outline-primary" :href="route('students.access.show', student.id)">
                        {{ access ? 'Manage access' : 'Enable access' }}
                    </Link>
                </div>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-body">
                <h2 class="h5">Enrollment history</h2>
                <div v-if="!student.enrollments.length" class="empty-state">No enrollment history yet.</div>
                <div v-else class="table-responsive">
                    <table class="table">
                        <thead><tr><th>School year</th><th>Grade level</th><th>Status</th><th>Dates</th></tr></thead>
                        <tbody>
                            <tr v-for="enrollment in student.enrollments" :key="enrollment.id">
                                <td>{{ enrollment.school_year.name }}</td>
                                <td>{{ enrollment.grade_level.name }}</td>
                                <td><span class="badge text-bg-info status-badge">{{ enrollment.status }}</span></td>
                                <td>{{ enrollment.enrollment_date }}<span v-if="enrollment.completion_date"> – {{ enrollment.completion_date }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
