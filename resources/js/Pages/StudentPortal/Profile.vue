<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    student: { first_name: string; last_name: string; preferred_name: string | null; display_name: string };
    academy: string;
    username: string;
    enrollment: { school_year: string; grade_level: string; status: string } | null;
}>();
</script>

<template>
    <Head title="My Profile" />
    <StudentPortalLayout>
        <h1 class="h2">My Profile</h1>
        <div class="card mt-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Preferred name</dt><dd class="col-sm-8">{{ student.preferred_name || 'Not set' }}</dd>
                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8">{{ student.first_name }} {{ student.last_name }}</dd>
                    <dt class="col-sm-4">Username</dt><dd class="col-sm-8">{{ username }}</dd>
                    <dt class="col-sm-4">Academy</dt><dd class="col-sm-8">{{ academy }}</dd>
                    <dt class="col-sm-4">School year</dt><dd class="col-sm-8">{{ enrollment?.school_year || 'Not currently enrolled' }}</dd>
                    <dt class="col-sm-4">Grade level</dt><dd class="col-sm-8">{{ enrollment?.grade_level || 'Not currently enrolled' }}</dd>
                    <dt class="col-sm-4">Enrollment status</dt><dd class="col-sm-8 text-capitalize">{{ enrollment?.status || 'Not enrolled' }}</dd>
                </dl>
            </div>
        </div>
    </StudentPortalLayout>
</template>
