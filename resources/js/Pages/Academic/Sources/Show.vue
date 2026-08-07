<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
    source: any;
    links: any[];
    linkChoices: Record<string, any[]>;
    courseChoices: Record<string, any[]>;
    courseDefaults: Record<string, any>;
    calendarSetup: any;
    curriculumSetup?: any;
    permissions: Record<string, boolean>;
    reviewTransitions: string[];
}>(), { curriculumSetup: () => ({ is_curriculum: false, current_file_is_pdf: false, imports: [] }) });
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const reviewForm = useForm({ review_status: '' });
const linkType = ref(Object.keys(props.linkChoices)[0] ?? '');
const linkForm = useForm({ link_type: linkType.value, link_id: null as number | null });
const fileForm = useForm({ source_file: null as File | null });
const courseForm = useForm<any>({ ...props.courseDefaults, code: '', status: 'draft' });
const calendarDraftForm = useForm({});
const calendarImportForm = useForm({});
const curriculumImportForm = useForm({});
const calendarImportError = computed(() => (calendarImportForm.errors as Record<string, string>).source);
const curriculumImportError = computed(() => Object.values(curriculumImportForm.errors as Record<string, string>)[0]);
const deletingImportId = ref<number | null>(null);
const currentChoices = computed(() => props.linkChoices[linkType.value] ?? []);
const currentFile = computed(() => props.source.files.find((file: any) => file.is_current) ?? null);
const canDraftCalendar = computed(() => props.source.review_status === 'reviewed' && props.source.source_category === 'calendar' && props.permissions.manage);
const canDraftCurriculum = computed(() => props.source.review_status === 'reviewed' && ['curriculum', 'pacing', 'scope_and_sequence'].includes(props.source.source_category) && props.permissions.manage);
const canDraftCourse = computed(() => props.source.review_status === 'reviewed' && ['course_guide', 'curriculum'].includes(props.source.source_category) && props.permissions.manage);
const changeReview = () => {
    if (reviewForm.review_status === 'rejected' && !window.confirm('Reject this source? It will remain available as historical evidence.')) return;
    reviewForm.patch(route('academic.sources.review', props.source.id), { preserveScroll: true, onSuccess: () => reviewForm.reset() });
};
const setReviewStatus = (status: string) => {
    reviewForm.review_status = status;
    changeReview();
};
const addLink = () => {
    linkForm.link_type = linkType.value;
    linkForm.post(route('academic.sources.links.store', props.source.id), { preserveScroll: true, onSuccess: () => linkForm.reset('link_id') });
};
const replaceFile = (event: Event) => { fileForm.source_file = (event.target as HTMLInputElement).files?.[0] ?? null; };
const upload = () => fileForm.post(route('academic.sources.files.store', props.source.id), { forceFormData: true, preserveScroll: true, onSuccess: () => fileForm.reset() });
const archive = () => { if (window.confirm('Archive this source? Its history and files will be preserved.')) router.patch(route('academic.sources.archive', props.source.id)); };
const importDate = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const deleteImport = (item: any) => {
    if (!window.confirm('Delete this import attempt? Its draft proposals will be permanently removed. The uploaded PDF and live calendar will not be changed.')) return;
    deletingImportId.value = item.id;
    router.delete(route('academic.sources.calendar-imports.destroy', [props.source.id, item.id]), {
        preserveScroll: true,
        onFinish: () => { deletingImportId.value = null; },
    });
};
</script>

<template>
    <Head :title="source.title" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h1 class="h2 mb-1">{{ source.title }}</h1>
                <p v-if="curriculumSetup.is_curriculum" class="text-secondary mb-1">{{ source.grade_level?.name ?? 'Grade not set' }} · {{ source.school_year?.name ?? source.academic_year_label ?? 'School year not set' }} · {{ curriculumSetup.subject?.name ?? 'Subject not set' }}</p>
                <p v-if="curriculumSetup.is_curriculum" class="text-secondary mb-0">{{ source.education_provider?.name ?? 'Provider not set' }}</p>
                <p v-else class="text-secondary mb-0">{{ label(source.source_category) }} · {{ label(source.source_kind) }} · {{ label(source.authority_level) }}</p>
            </div>
            <div class="d-flex gap-2"><Link class="btn btn-outline-secondary" :href="curriculumSetup.is_curriculum ? curriculumSetup.back_url : route('academic.sources.index')">Back</Link><Link v-if="permissions.manage && !source.archived_at" class="btn btn-outline-primary" :href="route('academic.sources.edit', source.id)">Edit source details</Link></div>
        </div>
        <AcademicNav v-if="!curriculumSetup.is_curriculum" />
        <div v-if="source.archived_at" class="alert alert-secondary">Archived {{ new Date(source.archived_at).toLocaleString() }}. This source is retained as historical evidence.</div>

        <div class="row g-4"><div class="col-lg-8">
            <section v-if="curriculumSetup.is_curriculum" class="card mb-4" aria-labelledby="source-summary-heading"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div><h2 id="source-summary-heading" class="h5 mb-1">{{ currentFile?.original_filename ?? source.title }}</h2><span class="badge" :class="source.review_status === 'reviewed' ? 'text-bg-success' : 'text-bg-secondary'">{{ label(source.review_status) }}</span></div>
                    <div class="d-flex gap-2"><a v-if="currentFile && curriculumSetup.current_file_is_pdf && permissions.download" class="btn btn-outline-primary" target="_blank" rel="noopener" :href="route('academic.sources.files.view', [source.id, currentFile.id])">View PDF</a><a v-if="currentFile && permissions.download" class="btn btn-outline-secondary" :href="route('academic.sources.files.download', [source.id, currentFile.id])">Download</a></div>
                </div>
                <dl class="row small mt-3 mb-0"><dt class="col-sm-3">Grade</dt><dd class="col-sm-9">{{ source.grade_level?.name ?? 'Not specified' }}</dd><dt class="col-sm-3">Subject</dt><dd class="col-sm-9">{{ curriculumSetup.subject?.name ?? 'Not specified' }}</dd><dt class="col-sm-3">School year</dt><dd class="col-sm-9">{{ source.school_year?.name ?? source.academic_year_label ?? 'Not specified' }}</dd><dt class="col-sm-3">Provider</dt><dd class="col-sm-9 mb-0">{{ source.education_provider?.name ?? 'Not specified' }}</dd></dl>
            </div></section>
            <section v-if="curriculumSetup.is_curriculum" class="card border-primary mb-4" aria-labelledby="curriculum-import-heading"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2"><h2 id="curriculum-import-heading" class="h5">{{ curriculumSetup.primary_action_label ?? 'Curriculum outline' }}</h2><span v-if="curriculumSetup.workflow_state === 'standards_ready'" class="badge text-bg-success align-self-start">{{ source.grade_level?.name }} standards detected</span><span v-else-if="curriculumSetup.capability?.state === 'supported' && curriculumSetup.workflow_state === 'ready'" class="badge text-bg-success align-self-start">Outline extraction supported</span></div>
                <template v-if="curriculumSetup.workflow_state === 'standards_ready'">
                    <h3 class="h6">{{ source.grade_level?.name }} standards detected</h3>
                    <p class="mb-1">This document contains standards for multiple grades. Only the {{ source.grade_level?.name }} {{ curriculumSetup.subject?.name }} section will be imported.</p>
                    <p class="small text-secondary mb-0">Review strands, parent standards, and student expectations before approving reusable standards. This document does not contain pacing or curriculum units.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'standards_imported'">
                    <h3 class="h6">Standards imported</h3><p class="mb-1">The selected grade standards are available as reusable references.</p><p class="small text-secondary mb-0">Pacing guide still needed.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'standards_review'">
                    <p class="mb-1">The selected grade standards are ready for hierarchical review.</p><p class="small text-secondary mb-0">No pacing, units, dates, or assessments will be created.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'format_setup_needed'">
                    <h3 class="h6">Curriculum outline setup needed</h3>
                    <p class="mb-1">This document uses a format the system has not learned yet. Your PDF is saved and unchanged.</p>
                    <p class="small text-secondary mb-0">An authorized curriculum manager can inspect detected headings and save a reusable, declarative format mapping.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'format_setup_in_progress'">
                    <p class="mb-1">Document-format setup has been started. Continue confirming reporting periods, unit rows, assessments, and recognition fingerprints.</p>
                    <p class="small text-secondary mb-0">No curriculum import exists until the active format is reassessed and extraction is explicitly started.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'ambiguous'">
                    <p class="mb-0">This document matches more than one supported format and needs review before extraction.</p>
                </template>
                <template v-else-if="curriculumSetup.workflow_state === 'capability_failed'">
                    <p class="mb-0">{{ curriculumSetup.capability?.message ?? 'We could not read the text in this PDF. It may be scanned or image-based.' }}</p>
                </template>
                <template v-else>
                    <p class="mb-1">Extract reporting periods, units, dates, instructional days, standards references, and assessments from this source.</p>
                    <p class="small text-secondary">Nothing will be added to the curriculum until you review and approve the extracted outline.</p>
                    <p v-if="curriculumSetup.workflow_state === 'ready' && curriculumSetup.capability?.document_family" class="small text-secondary">Recognized format: {{ curriculumSetup.capability.document_family }}</p>
                </template>
                <form v-if="curriculumSetup.workflow_state === 'ready' && permissions.manage && source.review_status === 'reviewed' && !source.archived_at && curriculumSetup.current_file_is_pdf" @submit.prevent="curriculumImportForm.post(curriculumSetup.primary_action_url)">
                    <button class="btn btn-primary" :disabled="curriculumImportForm.processing">{{ curriculumImportForm.processing ? 'Extracting…' : curriculumSetup.primary_action_label }}</button>
                </form>
                <form v-else-if="curriculumSetup.workflow_state === 'standards_ready' && permissions.manage && source.review_status === 'reviewed' && !source.archived_at && curriculumSetup.current_file_is_pdf" @submit.prevent="curriculumImportForm.post(curriculumSetup.primary_action_url)">
                    <button class="btn btn-primary" :disabled="curriculumImportForm.processing">{{ curriculumImportForm.processing ? 'Extracting standards…' : curriculumSetup.primary_action_label }}</button>
                </form>
                <form v-else-if="curriculumSetup.workflow_state === 'unknown' && (permissions.manage || permissions.review) && source.review_status === 'reviewed' && !source.archived_at && curriculumSetup.current_file_is_pdf" @submit.prevent="curriculumImportForm.post(curriculumSetup.primary_action_url)">
                    <button class="btn btn-primary" :disabled="curriculumImportForm.processing">{{ curriculumImportForm.processing ? 'Checking…' : 'Check outline support' }}</button>
                </form>
                <Link v-else-if="curriculumSetup.primary_action_url" class="btn btn-primary" :href="curriculumSetup.primary_action_url">{{ curriculumSetup.primary_action_label }}</Link>
                <div v-if="curriculumImportError" class="invalid-feedback d-block">{{ curriculumImportError }}</div>
                <p v-if="source.review_status !== 'reviewed'" class="alert alert-info mt-3 mb-0">Complete source review before extracting an outline.</p>
                <p v-else-if="!curriculumSetup.current_file_is_pdf" class="alert alert-info mt-3 mb-0">The current source version must be a PDF.</p>
            </div></section>
            <section v-if="calendarSetup.is_calendar" class="card border-primary mb-4" aria-labelledby="calendar-setup-heading"><div class="card-body">
                <h2 id="calendar-setup-heading" class="h5">Calendar setup</h2>
                <div v-if="currentFile && calendarSetup.current_file_is_pdf && source.school_year_id" class="border rounded p-3 mb-3">
                    <h3 class="h6">Import dates from this PDF</h3>
                    <p class="small text-secondary">Text extraction creates draft proposals only. Nothing reaches the live calendar until you review and approve it.</p>
                    <button v-if="permissions.manage" class="btn btn-primary" type="button" :disabled="calendarImportForm.processing" @click="calendarImportForm.post(route('academic.sources.calendar-imports.store', source.id))">{{ calendarImportForm.processing ? 'Extracting…' : 'Extract dates for review' }}</button>
                    <div v-if="calendarImportError" class="invalid-feedback d-block">{{ calendarImportError }}</div>
                    <ul v-if="(calendarSetup.imports ?? []).length" class="list-group list-group-flush mt-3">
                        <li v-for="item in (calendarSetup.imports ?? [])" :key="item.id" class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><strong>Import {{ item.id }}</strong> · {{ item.proposals_count }} proposals · {{ label(item.status) }}<small class="d-block text-secondary">{{ importDate(item.created_at) }} · Parser {{ item.parser_version }}<template v-if="item.linked_events_count"> · {{ item.linked_events_count }} linked events</template></small></span>
                            <span class="d-flex gap-1">
                                <Link class="btn btn-sm btn-outline-primary" :href="route('academic.calendar-imports.show', item.id)">{{ item.linked_events_count ? 'Manage imported events' : 'Open review' }}</Link>
                                <button v-if="item.can_delete" class="btn btn-sm btn-outline-danger" type="button" :disabled="deletingImportId === item.id" @click="deleteImport(item)">{{ deletingImportId === item.id ? 'Deleting…' : 'Delete import' }}</button>
                                <span v-else-if="item.linked_events_count" class="small text-secondary align-self-center" title="Approved imports with linked events cannot be deleted from this screen.">Protected</span>
                            </span>
                        </li>
                    </ul>
                </div>
                <div v-if="calendarSetup.linked_profiles.length">
                    <p>This source is linked to a structured Calendar Profile.</p>
                    <div class="d-flex flex-wrap gap-2"><Link v-for="profile in calendarSetup.linked_profiles" :key="profile.id" class="btn btn-outline-primary" :href="route('academic.calendars.show', profile.id)">Open {{ profile.name }}</Link><Link class="btn btn-outline-secondary" :href="route('academic.overview', { school_year_id: source.school_year_id })">Use in Academic Setup</Link></div>
                </div>
                <template v-else>
                    <p>This uploaded source is reference material; a structured Calendar Profile has not been created from it.</p>
                    <p v-if="source.review_status === 'unreviewed'" class="alert alert-info">Review this source before creating a Calendar Profile.</p>
                    <p v-else-if="source.review_status === 'in_review'" class="alert alert-info">Finish reviewing this source before creating a Calendar Profile.</p>
                    <p v-else-if="source.review_status === 'reviewed'" class="text-secondary">Use the PDF import review above, or create an empty profile and enter calendar events manually.</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a v-if="currentFile && calendarSetup.current_file_is_pdf && permissions.download" class="btn btn-primary" target="_blank" rel="noopener" :href="route('academic.sources.files.view', [source.id, currentFile.id])">View PDF</a>
                        <a v-if="currentFile && permissions.download" class="btn btn-outline-secondary" :href="route('academic.sources.files.download', [source.id, currentFile.id])">Download</a>
                        <button v-if="source.review_status === 'unreviewed' && permissions.review" class="btn btn-outline-primary" type="button" :disabled="reviewForm.processing" @click="setReviewStatus('in_review')">{{ reviewForm.processing ? 'Updating…' : 'Start review' }}</button>
                        <button v-if="source.review_status === 'in_review' && permissions.review" class="btn btn-outline-primary" type="button" :disabled="reviewForm.processing" @click="setReviewStatus('reviewed')">{{ reviewForm.processing ? 'Updating…' : 'Mark reviewed' }}</button>
                        <button v-if="canDraftCalendar" class="btn btn-primary" type="button" :disabled="calendarDraftForm.processing" @click="calendarDraftForm.post(route('academic.sources.draft-calendar', source.id))">{{ calendarDraftForm.processing ? 'Creating…' : 'Create draft Calendar Profile' }}</button>
                    </div>
                </template>
            </div></section>

            <details :open="!curriculumSetup.is_curriculum" :class="curriculumSetup.is_curriculum ? 'card mb-4' : ''">
                <summary v-if="curriculumSetup.is_curriculum" class="card-header bg-white fw-semibold py-3">Advanced source management</summary>
                <div :class="curriculumSetup.is_curriculum ? 'card-body' : ''">
                <section v-if="curriculumSetup.is_curriculum && curriculumSetup.imports.length" class="card mb-4"><div class="card-body"><h2 class="h5">Import history</h2><ul class="list-group list-group-flush"><li v-for="item in curriculumSetup.imports" :key="item.id" class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2"><span><strong>Import {{ item.id }}</strong> · {{ item.proposals_count }} proposals · {{ label(item.status) }}<small class="d-block text-secondary">{{ importDate(item.created_at) }} · Parser {{ item.parser_version }}</small></span><Link class="btn btn-sm btn-outline-primary" :href="route('academic.curriculum-imports.show', item.id)">Open import</Link></li></ul></div></section>
                <section v-if="curriculumSetup.is_curriculum && curriculumSetup.capability?.internal_diagnostic" class="card mb-4"><div class="card-body"><h2 class="h5">Outline support diagnostics</h2><p class="small text-secondary mb-2">State: {{ label(curriculumSetup.capability.state) }} · Checked {{ curriculumSetup.capability.assessed_at ? importDate(curriculumSetup.capability.assessed_at) : 'not yet' }}</p><p class="small mb-0">{{ curriculumSetup.capability.internal_diagnostic }}</p></div></section>
            <section class="card mb-4"><div class="card-body">
                <div class="d-flex justify-content-between"><h2 class="h5">Source record</h2><span class="badge text-bg-light border">{{ label(source.review_status) }}</span></div>
                <p v-if="source.description" class="text-pre-wrap">{{ source.description }}</p>
                <dl class="row mb-0"><dt class="col-sm-4">Provider</dt><dd class="col-sm-8">{{ source.education_provider?.name ?? 'Not specified' }}</dd><dt class="col-sm-4">School year</dt><dd class="col-sm-8">{{ source.school_year?.name ?? source.academic_year_label ?? 'Not specified' }}</dd><dt class="col-sm-4">Grade</dt><dd class="col-sm-8">{{ source.grade_level?.name ?? 'Not specified' }}</dd><dt class="col-sm-4">Version</dt><dd class="col-sm-8">{{ source.version_label ?? 'Not specified' }}</dd><dt class="col-sm-4">Publication date</dt><dd class="col-sm-8">{{ source.publication_date ? formatDateOnly(source.publication_date) : 'Not specified' }}</dd><template v-if="source.source_kind === 'url'"><dt class="col-sm-4">Stored URL</dt><dd class="col-sm-8"><a :href="source.source_url" target="_blank" rel="noopener noreferrer nofollow">{{ source.source_url }}</a><div class="small text-secondary">Stored reference only; not fetched by the application.</div></dd></template><dt class="col-sm-4">Processing</dt><dd class="col-sm-8">{{ label(source.processing_status) }}</dd></dl>
                <div v-if="source.notes" class="mt-3"><h3 class="h6">Internal notes</h3><p class="mb-0 text-pre-wrap">{{ source.notes }}</p></div>
            </div></section>

            <section class="card mb-4"><div class="card-body"><h2 class="h5">Private file versions</h2>
                <p v-if="source.source_kind !== 'upload'" class="text-secondary mb-0">This source does not use uploaded files.</p><div v-else-if="!source.files.length" class="text-secondary">No file is stored.</div>
                <div v-else class="table-responsive"><table class="table align-middle"><thead><tr><th>Version</th><th>Original filename</th><th>Size</th><th>Checksum</th><th></th></tr></thead><tbody><tr v-for="file in source.files" :key="file.id"><td>v{{ file.version_number }} <span v-if="file.is_current" class="badge text-bg-primary">Current</span></td><td>{{ file.original_filename }}<div class="small text-secondary">{{ file.mime_type }}</div></td><td>{{ Math.ceil(file.file_size / 1024) }} KB</td><td><code>{{ file.checksum_sha256.slice(0, 12) }}…</code></td><td><div class="d-flex gap-1"><a v-if="permissions.download && file.mime_type === 'application/pdf' && file.extension === 'pdf'" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" :href="route('academic.sources.files.view', [source.id, file.id])">View PDF</a><a v-if="permissions.download" class="btn btn-sm btn-outline-secondary" :href="route('academic.sources.files.download', [source.id, file.id])">Download</a></div></td></tr></tbody></table></div>
            </div><form v-if="permissions.manage && source.source_kind === 'upload' && !source.archived_at" class="card-footer bg-white" @submit.prevent="upload"><label for="replacement-file" class="form-label">Upload replacement version</label><div class="d-flex gap-2"><input id="replacement-file" class="form-control" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.csv,.txt" required @change="replaceFile"><button class="btn btn-outline-primary" :disabled="fileForm.processing">Upload</button></div><div v-if="fileForm.errors.source_file" class="invalid-feedback d-block">{{ fileForm.errors.source_file }}</div></form></section>

            <section class="card"><div class="card-body"><h2 class="h5">Linked academic records</h2><p v-if="!links.length" class="text-secondary">No academic records linked.</p><ul v-else class="list-group list-group-flush"><li v-for="item in links" :key="item.id" class="list-group-item px-0 d-flex justify-content-between"><span><span class="text-capitalize">{{ label(item.type) }}</span>: {{ item.label }}</span><Link v-if="permissions.manage && !source.archived_at" as="button" method="delete" class="btn btn-sm btn-link text-danger" :href="route('academic.sources.links.destroy', [source.id, item.id])" preserve-scroll>Remove</Link></li></ul></div>
                <form v-if="permissions.manage && !source.archived_at" class="card-footer bg-white" @submit.prevent="addLink"><div class="row g-2"><div class="col-md-5"><label for="link-type" class="form-label">Record type</label><select id="link-type" v-model="linkType" class="form-select" @change="linkForm.link_id = null"><option v-for="(_, type) in linkChoices" :key="type" :value="type">{{ label(String(type)) }}</option></select></div><div class="col-md-5"><label for="link-target" class="form-label">Record</label><select id="link-target" v-model="linkForm.link_id" class="form-select" required><option :value="null">Select record</option><option v-for="choice in currentChoices" :key="choice.id" :value="choice.id">{{ choice.label }}</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100" :disabled="linkForm.processing">Link</button></div></div><div v-if="linkForm.errors.link_id || linkForm.errors.link_type" class="invalid-feedback d-block">{{ linkForm.errors.link_id || linkForm.errors.link_type }}</div></form>
            </section>
                </div>
            </details>
        </div><aside class="col-lg-4">
            <details :open="!curriculumSetup.is_curriculum" :class="curriculumSetup.is_curriculum ? 'card' : ''">
            <summary v-if="curriculumSetup.is_curriculum" class="card-header bg-white fw-semibold py-3">Advanced curriculum settings</summary>
            <div :class="curriculumSetup.is_curriculum ? 'card-body' : ''">
            <section v-if="permissions.review && !source.archived_at" class="card card-body mb-4"><h2 class="h5">Review</h2><form v-if="reviewTransitions.length" @submit.prevent="changeReview"><label for="review-transition" class="form-label">Move to</label><select id="review-transition" v-model="reviewForm.review_status" class="form-select mb-2" required><option value="">Select status</option><option v-for="status in reviewTransitions" :key="status" :value="status">{{ label(status) }}</option></select><button class="btn btn-primary" :disabled="reviewForm.processing">Update review</button></form><p v-else class="text-secondary mb-0">No further review transitions are available.</p></section>
            <section v-if="permissions.manage && !source.archived_at && !calendarSetup.is_calendar" class="card card-body mb-4"><h2 class="h5">Structured drafts</h2><p class="small text-secondary">These actions create empty metadata records linked to this reviewed source. They never extract or publish data automatically.</p><Link v-if="canDraftCurriculum" as="button" method="post" class="btn btn-outline-primary mb-2" :href="route('academic.sources.draft-curriculum', source.id)">Create empty curriculum package</Link><form v-if="canDraftCourse" @submit.prevent="courseForm.post(route('academic.sources.draft-course', source.id))"><hr><h3 class="h6">Create course draft</h3><select v-model="courseForm.subject_id" class="form-select mb-2" aria-label="Subject" required><option :value="null">Select subject</option><option v-for="item in courseChoices.subjects" :key="item.id" :value="item.id">{{ item.name }}</option></select><input v-model="courseForm.name" class="form-control mb-2" aria-label="Course name" required><input v-model="courseForm.code" class="form-control mb-2" aria-label="Course code" placeholder="Course code" required><div class="row g-2 mb-2"><div class="col-6"><select v-model="courseForm.minimum_grade_level_id" class="form-select" aria-label="Minimum grade"><option :value="null">No minimum grade</option><option v-for="item in courseChoices.gradeLevels" :key="item.id" :value="item.id">{{ item.name }}</option></select></div><div class="col-6"><select v-model="courseForm.maximum_grade_level_id" class="form-select" aria-label="Maximum grade"><option :value="null">No maximum grade</option><option v-for="item in courseChoices.gradeLevels" :key="item.id" :value="item.id">{{ item.name }}</option></select></div></div><select v-model="courseForm.education_provider_id" class="form-select mb-2" aria-label="Education provider"><option :value="null">No provider</option><option v-for="item in courseChoices.providers" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="courseForm.standards_framework_id" class="form-select mb-2" aria-label="Standards framework"><option :value="null">No standards framework</option><option v-for="item in courseChoices.frameworks" :key="item.id" :value="item.id">{{ item.name }}</option></select><textarea v-model="courseForm.description" class="form-control mb-2" aria-label="Course description" rows="3"></textarea><button class="btn btn-outline-primary" :disabled="courseForm.processing">Create course draft</button><div v-if="Object.keys(courseForm.errors).length" class="invalid-feedback d-block">{{ Object.values(courseForm.errors)[0] }}</div></form><p v-if="source.review_status !== 'reviewed'" class="text-secondary mb-0">Complete review before creating a draft.</p></section>
            <button v-if="permissions.manage && !source.archived_at" class="btn btn-outline-danger" type="button" @click="archive">Archive source</button>
            </div>
            </details>
        </aside></div>
    </AuthenticatedLayout>
</template>
