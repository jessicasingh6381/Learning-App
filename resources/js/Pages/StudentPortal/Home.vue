<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    student: { first_name: string; preferred_name: string | null };
    academy: string;
    enrollment: { school_year: string; grade_level: string; status: string } | null;
}>();
</script>

<template>
    <Head title="Student portal" />
    <StudentPortalLayout>
        <div class="p-4 p-md-5 mb-4 rounded-3 bg-dark text-white">
            <p class="text-info text-uppercase small fw-semibold mb-2">Student portal</p>
            <h1 class="display-6">Welcome back, {{ student.preferred_name || student.first_name }}</h1>
            <p class="lead mb-0">{{ academy }}</p>
        </div>
        <div v-if="enrollment" class="row g-3 mb-4">
            <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-secondary">School year</div><div class="h4 mb-0">{{ enrollment.school_year }}</div></div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-secondary">Grade level</div><div class="h4 mb-0">{{ enrollment.grade_level }}</div></div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-secondary">Enrollment</div><div class="h4 text-capitalize mb-0">{{ enrollment.status }}</div></div></div></div>
        </div>
        <div v-else class="alert alert-info">Your academy is still setting up your current enrollment.</div>
        <div class="card">
            <div class="card-body">
                <h2 class="h4">Your learning missions will appear here soon.</h2>
                <p class="text-secondary">When lessons and assignments are ready, you will find them in My Learning.</p>
                <Link class="btn btn-primary" :href="route('student.learning')">Go to My Learning</Link>
            </div>
        </div>
    </StudentPortalLayout>
</template>
