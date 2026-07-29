<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue'; import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'; import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';
defineProps<{ calendars: any[] }>(); const canManage = usePage<any>().props.auth.permissions.includes('calendars.manage');
</script>
<template><Head title="Calendar Profiles" /><AuthenticatedLayout>
<div class="d-flex justify-content-between mb-3"><div><h1 class="h2">Calendar profiles</h1><p class="text-secondary">Reusable dated schedules and instructional adjustments.</p></div><Link v-if="canManage" class="btn btn-primary align-self-start" :href="route('academic.calendars.create')">Add calendar</Link></div><AcademicNav />
<div v-if="!calendars.length" class="empty-state card">No calendar profiles yet.</div><div v-else class="row g-3"><div v-for="calendar in calendars" :key="calendar.id" class="col-lg-6"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h2 class="h5">{{ calendar.name }}</h2><OwnershipBadge :shared="calendar.is_shared" /></div><p class="text-secondary">{{ formatDateOnly(calendar.start_date) }} – {{ formatDateOnly(calendar.end_date) }}</p><p class="mb-0">{{ calendar.events_count }} events · <span class="text-capitalize">{{ calendar.status }}</span></p></div><div class="card-footer bg-white d-flex gap-2"><Link class="btn btn-sm btn-outline-primary" :href="route('academic.calendars.show', calendar.id)">View</Link><Link v-if="canManage && !calendar.is_shared" class="btn btn-sm btn-outline-secondary" :href="route('academic.calendars.edit', calendar.id)">Edit</Link></div></div></div></div>
</AuthenticatedLayout></template>
