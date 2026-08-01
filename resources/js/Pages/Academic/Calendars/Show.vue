<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import CalendarEventRow from '@/Components/CalendarEventRow.vue';
import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    calendar: any;
    sourceWebsite?: { url: string; domain: string } | null;
    summaries: any[];
    linkedSources?: any[];
    suggestedSources?: any[];
}>();
const canManage = usePage<any>().props.auth.permissions.includes('calendars.manage') && !props.calendar.is_shared;
const form = useForm({ event_date: '', end_date: '', event_type: 'holiday', name: '', instructional_effect: 'non_instructional', status: 'active', notes: '', source_reference: '' });
const linkForm = useForm({ link_type: 'calendar_profile', link_id: props.calendar.id });
const unlinkForm = useForm({});
const compatibleYear = computed(() => props.summaries.find((item) => item.compatible));
const sourceFilters = computed(() => ({
    category: 'calendar',
    school_year_id: compatibleYear.value?.school_year_id,
    education_provider_id: props.calendar.education_provider?.id,
}));
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const submit = () => form.post(route('academic.calendars.events.store', props.calendar.id), { preserveScroll: true, onSuccess: () => form.reset() });
const linkSource = (sourceId: number) => linkForm.post(route('academic.sources.links.store', sourceId), { preserveScroll: true });
const unlinkSource = (source: any) => unlinkForm.delete(route('academic.sources.links.destroy', { source: source.id, link: source.link_id }), { preserveScroll: true });
</script>

<template>
    <Head :title="calendar.name" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between gap-3 mb-3">
            <div><h1 class="h2">{{ calendar.name }}</h1><p class="text-secondary mb-0">Calendar Profile administration and provenance</p></div>
            <div><OwnershipBadge :shared="calendar.is_shared" /> <Link v-if="canManage" class="btn btn-sm btn-outline-secondary ms-2" :href="route('academic.calendars.edit', calendar.id)">Edit profile</Link></div>
        </div>
        <AcademicNav />

        <section class="card mb-4" aria-labelledby="calendar-information-heading">
            <div class="card-body"><h2 id="calendar-information-heading" class="h5">Calendar information</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8">{{ calendar.name }}</dd>
                    <dt class="col-sm-4">Academic year</dt><dd class="col-sm-8">{{ calendar.academic_year_label || 'Not specified' }}</dd>
                    <dt class="col-sm-4">Dates</dt><dd class="col-sm-8">{{ formatDateOnly(calendar.start_date) }} – {{ formatDateOnly(calendar.end_date) }}</dd>
                    <dt class="col-sm-4">Timezone</dt><dd class="col-sm-8">{{ calendar.timezone }}</dd>
                    <dt class="col-sm-4">Provider</dt><dd class="col-sm-8">{{ calendar.education_provider?.name ?? 'Not specified' }}</dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8 text-capitalize">{{ calendar.status }}</dd>
                </dl>
            </div>
        </section>

        <section class="card mb-4" aria-labelledby="source-information-heading">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-3 align-items-start"><div><h2 id="source-information-heading" class="h5">Source information</h2><p class="text-secondary">Reference metadata and managed source documents are kept separately.</p></div><Link v-if="canManage" :href="route('academic.calendars.edit', calendar.id)">Edit source information</Link></div>
                <h3 class="h6">Direct source URL</h3>
                <p v-if="sourceWebsite"><a :href="sourceWebsite.url" target="_blank" rel="noopener noreferrer">Open source website</a><span class="text-secondary ms-2">{{ sourceWebsite.domain }}</span></p>
                <p v-else class="text-secondary">No direct source URL has been recorded.</p>
                <p v-if="calendar.source_version"><strong>Source version:</strong> {{ calendar.source_version }}</p>

                <hr>
                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                    <div><h3 class="h6 mb-1">Source documents</h3><p class="small text-secondary">Private uploads and managed URL references from the Academic Source Library.</p></div>
                    <div v-if="canManage" class="d-flex gap-2"><Link :href="route('academic.sources.index', sourceFilters)">Link existing source</Link><Link :href="route('academic.sources.create', sourceFilters)">Add new source</Link></div>
                </div>
                <p v-if="!(linkedSources ?? []).length" class="text-secondary">No source documents are linked to this Calendar Profile.</p>
                <div v-else class="vstack gap-3">
                    <article v-for="source in linkedSources" :key="source.id" class="border rounded p-3">
                        <div class="d-flex justify-content-between gap-3"><div><h4 class="h6 mb-1">{{ source.title }}</h4><div class="small text-secondary">{{ label(source.source_kind) }} · {{ label(source.source_category) }} · {{ label(source.authority_level) }} · {{ label(source.review_status) }}</div><div class="small mt-1">{{ source.current_file ? `File: ${source.current_file.original_filename}` : (source.external_url ? 'URL source' : 'Manual reference') }}</div></div><span class="badge text-bg-light align-self-start">Linked</span></div>
                        <div class="d-flex flex-wrap gap-3 mt-2">
                            <a v-if="source.current_file?.is_pdf && source.can_download" :href="route('academic.sources.files.view', { source: source.id, file: source.current_file.id })" target="_blank" rel="noopener">View PDF</a>
                            <a v-if="source.current_file && source.can_download" :href="route('academic.sources.files.download', { source: source.id, file: source.current_file.id })">Download</a>
                            <a v-if="source.external_url" :href="source.external_url.url" target="_blank" rel="noopener noreferrer">Open URL</a>
                            <Link :href="route('academic.sources.show', source.id)">View source details</Link>
                            <button v-if="canManage && source.can_manage" type="button" class="btn btn-link text-danger p-0" :disabled="unlinkForm.processing" @click="unlinkSource(source)">Unlink</button>
                        </div>
                    </article>
                </div>
                <div v-if="(suggestedSources ?? []).length" class="mt-4">
                    <h3 class="h6">Suggested compatible sources</h3>
                    <p class="small text-secondary">These calendar sources match this profile’s provider and covered school year. Linking is optional and does not create events.</p>
                    <article v-for="source in suggestedSources" :key="source.id" class="border rounded p-3 mb-2 d-flex justify-content-between gap-3 align-items-start">
                        <div><strong>{{ source.title }}</strong><div class="small text-secondary">{{ label(source.source_kind) }} · {{ label(source.authority_level) }} · {{ label(source.review_status) }} · {{ source.current_file ? 'File' : (source.external_url ? 'URL' : 'Manual reference') }}</div><Link :href="route('academic.sources.show', source.id)">View source details</Link></div>
                        <button v-if="canManage && source.can_manage" type="button" class="btn btn-sm btn-outline-primary" :disabled="linkForm.processing" @click="linkSource(source.id)">Link source</button>
                    </article>
                    <div v-if="linkForm.errors.link_id" class="alert alert-danger mt-2" role="alert">{{ linkForm.errors.link_id }}</div>
                </div>
            </div>
        </section>

        <section class="mb-4" aria-labelledby="calculated-summary-heading">
            <h2 id="calculated-summary-heading" class="h5">Calculated summary</h2>
            <div class="row g-3"><div v-for="summary in summaries" :key="summary.school_year_id" class="col-lg-6"><div class="card"><div class="card-body"><h3 class="h6">{{ summary.school_year_name }}</h3><div class="row text-center"><div class="col"><strong>{{ summary.base_days }}</strong><small class="d-block">Base</small></div><div class="col"><strong>-{{ summary.removed_days }}</strong><small class="d-block">Removed</small></div><div class="col"><strong>+{{ summary.added_days }}</strong><small class="d-block">Added</small></div><div class="col"><strong>{{ summary.scheduled_days }}</strong><small class="d-block">Scheduled</small></div></div><Link v-if="summary.compatible" class="btn btn-sm btn-outline-primary mt-3" :href="route('academic.overview', { school_year_id: summary.school_year_id })">Use in Academic Setup</Link></div></div></div></div>
        </section>

        <form v-if="canManage" class="card mb-4" @submit.prevent="submit"><div class="card-body"><h2 class="h5">Add calendar event</h2><div class="row g-3">
            <div class="col-md-6"><label for="event-name" class="form-label">Name</label><input id="event-name" v-model="form.name" class="form-control" required :aria-describedby="form.errors.name ? 'event-name-error' : undefined"><div v-if="form.errors.name" id="event-name-error" class="invalid-feedback d-block">{{ form.errors.name }}</div></div>
            <div class="col-md-3"><label for="event-start" class="form-label">Start date</label><input id="event-start" v-model="form.event_date" type="date" class="form-control" required></div><div class="col-md-3"><label for="event-end" class="form-label">End date (optional)</label><input id="event-end" v-model="form.end_date" type="date" class="form-control"><div v-if="form.errors.end_date" class="invalid-feedback d-block">{{ form.errors.end_date }}</div></div>
            <div class="col-md-6"><label for="event-type" class="form-label">Event type</label><select id="event-type" v-model="form.event_type" class="form-select"><option v-for="type in ['holiday','break','teacher_workday','staff_development','weather_closure','tenant_day_off','district_closure','instructional_makeup_day','instructional_override','other']" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option></select></div>
            <div class="col-md-6"><label for="event-effect" class="form-label">Instructional effect</label><select id="event-effect" v-model="form.instructional_effect" class="form-select"><option value="non_instructional">Non-instructional</option><option value="instructional">Instructional override</option><option value="informational">Informational only</option></select></div>
        </div></div><div class="card-footer bg-white text-end"><button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Adding…' : 'Add event' }}</button></div></form>

        <section class="card" aria-labelledby="calendar-events-heading"><div class="card-body"><h2 id="calendar-events-heading" class="h5">Calendar events</h2></div><div v-if="!calendar.events.length" class="empty-state">No events have been saved. Scheduled days currently equal base days.</div><div v-else class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Event</th><th>Date range</th><th>Effect</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody><CalendarEventRow v-for="event in calendar.events" :key="event.id" :calendar-id="calendar.id" :event="event" :editable="canManage" /></tbody></table></div></section>
    </AuthenticatedLayout>
</template>
