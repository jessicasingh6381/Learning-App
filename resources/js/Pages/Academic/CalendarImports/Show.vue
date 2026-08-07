<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import CalendarImportProposalRow from '@/Components/CalendarImportProposalRow.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ calendarImport: any; source: any; schoolYear: any; proposals: any[]; eventTypes: string[]; effects: string[]; canManage: boolean; canRetry: boolean; canDelete: boolean; linkedEventsCount: number }>();
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
const editableState = (proposal: any) => ({
    id: proposal.id,
    included: proposal.included,
    event_date: proposal.event_date ?? '',
    end_date: proposal.end_date ?? '',
    name: proposal.name,
    event_type: proposal.event_type,
    instructional_effect: proposal.instructional_effect,
});

const addForm = useForm({ event_date: '', end_date: '', name: '', event_type: 'other', instructional_effect: 'informational', included: true, parser_note: 'Added manually during import review.' });
const reviewForm = useForm({ proposals: Object.fromEntries(props.proposals.map(proposal => [proposal.id, editableState(proposal)])) as Record<number, ReturnType<typeof editableState>> });
const approvalForm = useForm({ replace_previous: false, update_school_year_dates: false, included_proposal_ids: [] as number[] });
const retryForm = useForm({});
const savedBaseline = ref(JSON.stringify(reviewForm.proposals));
const deleting = ref(false);
const suppressUnsavedWarning = ref(false);
const isDirty = computed(() => JSON.stringify(reviewForm.proposals) !== savedBaseline.value);
const includedProposalIds = computed(() => Object.values(reviewForm.proposals).filter(proposal => proposal.included).map(proposal => proposal.id));
const reviewSummaryErrors = computed(() => [...new Set(Object.values(reviewForm.errors as Record<string, string>))]);
const approvalSummaryErrors = computed(() => Object.entries(approvalForm.errors as Record<string, string>)
    .filter(([key]) => key === 'approval' || key === 'replace_previous' || key === 'included_proposal_ids')
    .map(([, message]) => message));

const rowErrors = (proposalId: number) => {
    const errors: Record<string, string> = {};
    for (const [key, message] of Object.entries(reviewForm.errors as Record<string, string>)) {
        const prefix = `proposals.${proposalId}.`;
        if (key.startsWith(prefix)) errors[key.slice(prefix.length)] = message;
    }
    const approvalError = (approvalForm.errors as Record<string, string>)[`proposals.${proposalId}`];
    if (approvalError) errors.approval = approvalError;
    return errors;
};
const add = () => addForm.post(route('academic.calendar-imports.proposals.store', props.calendarImport.id), { preserveScroll: true, onSuccess: () => addForm.reset() });
const saveReview = () => {
    if (!isDirty.value) return;
    suppressUnsavedWarning.value = true;
    reviewForm.put(route('academic.calendar-imports.proposals.bulk-update', props.calendarImport.id), {
        preserveScroll: true,
        onSuccess: () => { savedBaseline.value = JSON.stringify(reviewForm.proposals); },
        onFinish: () => { suppressUnsavedWarning.value = false; },
    });
};
const approve = () => {
    if (isDirty.value || !window.confirm('Approve the included proposals and publish them to the selected school calendar?')) return;
    suppressUnsavedWarning.value = true;
    approvalForm.included_proposal_ids = [...includedProposalIds.value];
    approvalForm.post(route('academic.calendar-imports.approve', props.calendarImport.id), {
        onFinish: () => { suppressUnsavedWarning.value = false; },
    });
};
const deleteImport = () => {
    if (!window.confirm('Delete this import attempt? Its draft proposals will be permanently removed. The uploaded PDF and live calendar will not be changed.')) return;
    deleting.value = true;
    router.delete(route('academic.sources.calendar-imports.destroy', [props.source.id, props.calendarImport.id]), { onFinish: () => { deleting.value = false; } });
};
const confirmPageExit = (event: BeforeUnloadEvent) => {
    if (!isDirty.value || suppressUnsavedWarning.value) return;
    event.preventDefault();
    event.returnValue = '';
};
let removeNavigationListener: undefined | (() => void);
onMounted(() => {
    window.addEventListener('beforeunload', confirmPageExit);
    removeNavigationListener = router.on('before', event => {
        if (isDirty.value && !suppressUnsavedWarning.value && !window.confirm('You have unsaved review changes. Leave without saving them?')) event.preventDefault();
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', confirmPageExit);
    removeNavigationListener?.();
});
watch(() => props.proposals, proposals => {
    const baseline = JSON.parse(savedBaseline.value);
    for (const proposal of proposals) {
        if (!reviewForm.proposals[proposal.id]) {
            const state = editableState(proposal);
            reviewForm.proposals[proposal.id] = state;
            baseline[proposal.id] = state;
        }
    }
    savedBaseline.value = JSON.stringify(baseline);
});
</script>

<template>
    <Head :title="`Review calendar import ${calendarImport.id}`" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3"><div><h1 class="h2">Review calendar import</h1><p class="text-secondary mb-0">{{ source.title }} · {{ source.file.name }}</p></div><div class="d-flex gap-2"><button v-if="canDelete" class="btn btn-outline-danger" type="button" :disabled="deleting" @click="deleteImport">{{ deleting ? 'Deleting…' : 'Delete import' }}</button><Link class="btn btn-outline-secondary" :href="route('academic.sources.show', source.id)">Back to source</Link></div></div>
        <AcademicNav />
        <div v-if="calendarImport.status === 'failed'" class="alert alert-danger"><strong>Extraction failed.</strong> {{ calendarImport.diagnostic }}</div>
        <div v-else-if="calendarImport.status === 'manual_handling'" class="alert alert-warning"><strong>Reliable date mapping needs manual attention.</strong> {{ calendarImport.diagnostic }}<div v-if="canRetry" class="mt-3"><button class="btn btn-sm btn-outline-warning" type="button" :disabled="retryForm.processing" @click="retryForm.post(route('academic.sources.calendar-imports.store', source.id))">Retry with current parser</button></div></div>
        <div v-else-if="calendarImport.status === 'approved'" class="alert alert-success">This import has been approved and published.</div>
        <div v-else class="alert alert-info">These are proposals only. Review and save changes before approval.</div>
        <div v-if="linkedEventsCount" class="alert alert-secondary">This import has {{ linkedEventsCount }} linked calendar event(s) and cannot be deleted from this screen. Manual events and events from other imports are not affected.</div>

        <section v-if="calendarImport.status === 'approved'" class="card border-success mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-3"><div><h2 class="h5 text-success">Approved</h2><p class="mb-1"><strong>{{ calendarImport.events_created_count }}</strong> calendar events created · {{ calendarImport.included_count }} included · {{ calendarImport.excluded_count }} excluded</p><p class="small text-secondary mb-0"><template v-if="calendarImport.approved_at">Approved {{ new Date(calendarImport.approved_at).toLocaleString() }}</template><template v-if="calendarImport.approved_by"> by {{ calendarImport.approved_by }}</template></p></div><Link class="btn btn-success align-self-start" :href="route('workspace.calendar')">Open live calendar</Link></div></div></section>

        <section class="card mb-4"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><strong>Saved school-year range</strong><span class="d-block">{{ formatDateOnly(schoolYear.start_date) }} – {{ formatDateOnly(schoolYear.end_date) }}</span></div>
            <div class="col-md-4"><strong>Proposed first day</strong><span class="d-block">{{ calendarImport.proposed_first_day ? formatDateOnly(calendarImport.proposed_first_day) : 'Not recognized' }}</span></div>
            <div class="col-md-4"><strong>Proposed last day</strong><span class="d-block">{{ calendarImport.proposed_last_day ? formatDateOnly(calendarImport.proposed_last_day) : 'Not recognized' }}</span></div>
        </div><p class="small text-secondary mb-0 mt-3">Extraction: {{ label(calendarImport.extraction_method) }} · Parser {{ calendarImport.parser_version }}. PDF text extraction does not use OCR.</p></div></section>

        <section v-if="calendarImport.comparison" class="card mb-4"><div class="card-body"><h2 class="h5">Changes from the previous approved import</h2><div class="d-flex flex-wrap gap-4"><span><strong>{{ calendarImport.comparison.added }}</strong> added</span><span><strong>{{ calendarImport.comparison.changed }}</strong> changed</span><span><strong>{{ calendarImport.comparison.removed }}</strong> removed</span><span><strong>{{ calendarImport.comparison.unchanged }}</strong> unchanged</span></div><p class="small text-secondary mb-0 mt-2">Replacement affects only unchanged events created by the earlier import. Manual events and manually edited imported events are preserved.</p></div></section>

        <section class="card mb-4"><div class="card-header bg-body d-flex justify-content-between"><h2 class="h5 mb-0">{{ calendarImport.status === 'approved' ? 'Reviewed proposals' : 'Proposed events' }}</h2><span>{{ proposals.length }} found</span></div><div v-if="!proposals.length" class="card-body text-secondary">No reliably dated proposals were created. Retry extraction or add events manually below.</div><div v-else class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Use</th><th>Date range</th><th>Name</th><th>Type</th><th>Instruction</th><th>Review warnings</th></tr></thead><tbody><CalendarImportProposalRow v-for="proposal in proposals" :key="proposal.id" v-model:review="reviewForm.proposals[proposal.id]" :proposal="proposal" :event-types="eventTypes" :effects="effects" :editable="canManage" :errors="rowErrors(proposal.id)" /></tbody></table></div></section>

        <section v-if="canManage" class="card mb-4"><div class="card-body"><h2 class="h5">Add a missed event</h2><form class="row g-2" @submit.prevent="add"><div class="col-md-2"><input v-model="addForm.event_date" type="date" class="form-control" required aria-label="Event date"></div><div class="col-md-2"><input v-model="addForm.end_date" type="date" class="form-control" aria-label="End date"></div><div class="col-md-3"><input v-model="addForm.name" class="form-control" placeholder="Event name" required></div><div class="col-md-2"><select v-model="addForm.event_type" class="form-select"><option v-for="type in eventTypes" :key="type" :value="type">{{ label(type) }}</option></select></div><div class="col-md-2"><select v-model="addForm.instructional_effect" class="form-select"><option v-for="effect in effects" :key="effect" :value="effect">{{ label(effect) }}</option></select></div><div class="col-md-1"><button class="btn btn-outline-primary" :disabled="addForm.processing">Add</button></div><div v-if="Object.keys(addForm.errors).length" class="invalid-feedback d-block">{{ Object.values(addForm.errors)[0] }}</div></form></div></section>

        <section v-if="canManage" class="card border-primary"><div class="card-body"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h5 mb-0">Review actions</h2><span v-if="isDirty" class="badge text-bg-warning">Unsaved changes</span></div>
            <p class="small text-secondary">Save the full review after changing included events, dates, names, event types, or instructional status.</p>
            <div v-if="calendarImport.previous_approved_count" class="form-check mb-3"><input id="replace-previous" v-model="approvalForm.replace_previous" class="form-check-input" type="checkbox"><label class="form-check-label" for="replace-previous">Replace unchanged events from {{ calendarImport.previous_approved_count }} earlier approved import(s). Manually edited and manual events are preserved.</label></div>
            <div v-if="calendarImport.proposed_first_day && calendarImport.proposed_last_day" class="form-check mb-3"><input id="update-year-dates" v-model="approvalForm.update_school_year_dates" class="form-check-input" type="checkbox"><label class="form-check-label" for="update-year-dates">Update the saved school-year start and end dates to the proposed first and last days. This is optional and never automatic.</label></div>
            <div v-if="reviewSummaryErrors.length" class="alert alert-danger" role="alert"><strong>Review changes were not saved.</strong><ul class="mb-0 mt-1"><li v-for="error in reviewSummaryErrors" :key="error">{{ error }}</li></ul></div>
            <div v-if="approvalSummaryErrors.length" class="alert alert-danger" role="alert"><strong>Calendar import was not approved.</strong><ul class="mb-0 mt-1"><li v-for="error in approvalSummaryErrors" :key="error">{{ error }}</li></ul></div>
            <p v-if="isDirty" class="small text-warning-emphasis mb-2">Save your review changes before approving this import.</p>
            <div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary" type="button" :disabled="!isDirty || reviewForm.processing || approvalForm.processing" @click="saveReview">{{ reviewForm.processing ? 'Saving…' : 'Save review changes' }}</button><button class="btn btn-primary" type="button" :disabled="isDirty || reviewForm.processing || approvalForm.processing" @click="approve">{{ approvalForm.processing ? 'Approving…' : `Approve ${includedProposalIds.length} included event${includedProposalIds.length === 1 ? '' : 's'}` }}</button></div>
        </div></section>
    </AuthenticatedLayout>
</template>
