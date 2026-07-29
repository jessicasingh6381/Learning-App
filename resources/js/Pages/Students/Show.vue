<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{ student: any }>();
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
                <Link v-if="canManageEnrollments && student.status === 'active'" class="btn btn-primary" :href="route('enrollments.create', { student: student.id })">Add enrollment</Link>
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
