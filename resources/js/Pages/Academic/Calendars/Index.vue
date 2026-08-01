<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{ calendars: any[] }>();
const canManage = usePage<any>().props.auth.permissions.includes('calendars.manage');
</script>

<template>
    <Head title="Calendar Profiles" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between mb-3"><div><h1 class="h2">Calendar profiles</h1><p class="text-secondary">Structured schedules and instructional adjustments. Uploaded sources remain in the Sources library until a profile is created.</p></div><Link v-if="canManage" class="btn btn-primary align-self-start" :href="route('academic.calendars.create')">Add calendar</Link></div>
        <AcademicNav />
        <div v-if="!calendars.length" class="empty-state card">No Calendar Profiles yet. Uploads are source documents, not structured profiles.</div>
        <div v-else class="row g-3"><div v-for="calendar in calendars" :key="calendar.id" class="col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between gap-2"><h2 class="h5">{{ calendar.name }}</h2><OwnershipBadge :shared="calendar.is_shared" /></div>
            <p class="mb-1">{{ calendar.academic_year_label || 'No school-year label' }}</p>
            <p class="text-secondary mb-2">{{ formatDateOnly(calendar.start_date) }} – {{ formatDateOnly(calendar.end_date) }}</p>
            <dl class="row small mb-2"><dt class="col-4">Provider</dt><dd class="col-8">{{ calendar.education_provider?.name ?? 'Not specified' }}</dd><dt class="col-4">Status</dt><dd class="col-8 text-capitalize">{{ calendar.status }}</dd><dt class="col-4">Events</dt><dd class="col-8">{{ calendar.events_count }}</dd></dl>
            <div v-if="calendar.linked_sources.length" class="alert alert-light border py-2 mb-0"><strong>{{ calendar.linked_sources.length }} linked source{{ calendar.linked_sources.length === 1 ? '' : 's' }}</strong><div><Link v-for="source in calendar.linked_sources" :key="source.id" class="me-2" :href="route('academic.sources.show', source.id)">{{ source.title }}</Link></div></div>
        </div><div class="card-footer bg-white d-flex gap-2"><Link class="btn btn-sm btn-outline-primary" :href="route('academic.calendars.show', calendar.id)">View</Link><Link v-if="canManage && !calendar.is_shared" class="btn btn-sm btn-outline-secondary" :href="route('academic.calendars.edit', calendar.id)">Edit</Link></div></div></div></div>
    </AuthenticatedLayout>
</template>
