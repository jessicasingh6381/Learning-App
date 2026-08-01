<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import CalendarLifecycleActions from '@/Components/CalendarLifecycleActions.vue';
import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{ calendars: any[]; filters: { show: string } }>();
const canManage = usePage<any>().props.auth.permissions.includes('calendars.manage');
const filterOptions = [
    { value: 'active', label: 'Active and draft' },
    { value: 'archived', label: 'Archived' },
    { value: 'all', label: 'All' },
];
</script>

<template>
    <Head title="Calendar Profiles" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between mb-3"><div><h1 class="h2">Calendar profiles</h1><p class="text-secondary">Structured schedules and instructional adjustments. Archived profiles remain available for historical review.</p></div><Link v-if="canManage" class="btn btn-primary align-self-start" :href="route('academic.calendars.create')">Add calendar</Link></div>
        <AcademicNav />
        <nav class="mb-3" aria-label="Calendar Profile status filter"><span class="me-2">Show:</span><div class="btn-group" role="group"><Link v-for="option in filterOptions" :key="option.value" class="btn btn-sm" :class="filters.show === option.value ? 'btn-primary' : 'btn-outline-primary'" :aria-current="filters.show === option.value ? 'page' : undefined" :href="route('academic.calendars.index', { show: option.value })">{{ option.label }}</Link></div></nav>
        <div v-if="!calendars.length" class="empty-state card">No Calendar Profiles match this filter.</div>
        <div v-else class="row g-3"><div v-for="calendar in calendars" :key="calendar.id" class="col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between gap-2"><h2 class="h5">{{ calendar.name }}</h2><div><span v-if="calendar.status === 'archived'" class="badge text-bg-secondary me-2">Archived</span><span v-if="calendar.lifecycle.is_in_use" class="badge text-bg-primary me-2">In use</span><OwnershipBadge :shared="calendar.is_shared" /></div></div>
            <p class="mb-1">{{ calendar.academic_year_label || 'No school-year label' }}</p>
            <p class="text-secondary mb-2">{{ formatDateOnly(calendar.start_date) }} – {{ formatDateOnly(calendar.end_date) }}</p>
            <dl class="row small mb-2"><dt class="col-4">Provider</dt><dd class="col-8">{{ calendar.education_provider?.name ?? 'Not specified' }}</dd><dt class="col-4">Status</dt><dd class="col-8 text-capitalize">{{ calendar.status }}</dd><dt class="col-4">Events</dt><dd class="col-8">{{ calendar.lifecycle.event_count }}</dd><dt class="col-4">Sources</dt><dd class="col-8">{{ calendar.lifecycle.linked_source_count }}</dd></dl>
            <p class="small text-secondary mb-0">{{ calendar.has_source_website ? 'Source website' : 'No source website' }} · {{ calendar.lifecycle.linked_source_count ? `${calendar.lifecycle.linked_source_count} linked document${calendar.lifecycle.linked_source_count === 1 ? '' : 's'}` : 'No linked sources' }}</p>
            <p v-if="calendar.lifecycle.usage.length" class="small mt-2 mb-0">Used by {{ calendar.lifecycle.usage.map((item: any) => `${item.school_year} Academic Setup (${item.status})`).join(', ') }}</p>
            <CalendarLifecycleActions class="mt-3" :calendar="calendar" :lifecycle="calendar.lifecycle" compact />
        </div><div class="card-footer bg-white d-flex gap-2"><Link class="btn btn-sm btn-outline-primary" :href="route('academic.calendars.show', calendar.id)">View</Link><Link v-if="calendar.lifecycle.can_edit" class="btn btn-sm btn-outline-secondary" :href="route('academic.calendars.edit', calendar.id)">Edit</Link></div></div></div></div>
    </AuthenticatedLayout>
</template>
