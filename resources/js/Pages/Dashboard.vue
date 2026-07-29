<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
defineProps<{ activeTenant: any; activeSchoolYear: any; counts: any; setup: any; activity: any[]; canViewActivity: boolean }>();
const page = usePage<any>();
</script>
<template>
<Head title="Dashboard" /><AuthenticatedLayout>
    <div class="d-flex justify-content-between align-items-start mb-4"><div><h1 class="h2 mb-1">Foundation dashboard</h1><p class="text-secondary mb-0">{{ activeTenant.name }}</p></div><span class="badge text-bg-primary fs-6">{{ activeSchoolYear?.name || 'No active school year' }}</span></div>
    <div class="row g-3 mb-4"><div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="text-secondary">Active students</div><div class="display-6">{{ counts.activeStudents }}</div></div></div></div><div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="text-secondary">Current enrollments</div><div class="display-6">{{ counts.currentEnrollments }}</div></div></div></div><div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-secondary mb-2">Setup status</div><div>{{ [setup.hasSchoolYear, setup.hasStudent, setup.hasEnrollment].filter(Boolean).length }} of 3 essentials complete</div></div></div></div></div>
    <div class="row g-4"><div class="col-lg-7"><div class="card"><div class="card-body"><h2 class="h5">Get set up</h2><div class="list-group list-group-flush"><Link class="list-group-item list-group-item-action" :href="route('school-years.create')">1. {{ setup.hasSchoolYear ? '✓' : '○' }} Create a school year</Link><Link class="list-group-item list-group-item-action" :href="route('students.create')">2. {{ setup.hasStudent ? '✓' : '○' }} Add a student</Link><Link class="list-group-item list-group-item-action" :href="route('enrollments.create')">3. {{ setup.hasEnrollment ? '✓' : '○' }} Add an enrollment</Link></div></div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body"><h2 class="h5">Recent administrative activity</h2><div v-if="!canViewActivity" class="empty-state py-4">Activity is available to tenant owners and administrators.</div><div v-else-if="!activity.length" class="empty-state py-4">Activity will appear here as setup changes are made.</div><ul v-else class="list-group list-group-flush"><li v-for="item in activity" :key="item.id" class="list-group-item px-0"><span class="text-capitalize">{{ item.action.replaceAll('.', ' ') }}</span><small class="d-block text-secondary">{{ new Date(item.created_at).toLocaleString() }}</small></li></ul></div></div></div></div>
</AuthenticatedLayout>
</template>
