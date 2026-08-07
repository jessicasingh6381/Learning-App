<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    entryMode: 'overview' | 'add';
    contexts: any[];
    selectedContext: any | null;
    selectedSubject: any | null;
    contextProvider: any | null;
    providers: any[];
    subjects: any[];
    hiddenSubjects: any[];
    hiddenSubjectCount: number;
    permissions: any;
    backUrl: string;
    returnTo?: 'overview' | 'learning-plan';
    maxUploadMegabytes: number;
}>();

const form = useForm({
    title: '',
    source_kind: 'upload',
    source_file: null as File | null,
    source_url: '',
    manual_reference: '',
    version_label: '',
});

const sourceLabel = computed(() => ({ upload: 'Uploaded PDF', url: 'Source URL', manual: 'Manual reference' }[form.source_kind]));
const confirmHide = (event: MouseEvent, subject: any) => {
    if ((subject.source_count || subject.curriculum_import_id) && !window.confirm(`Hide ${subject.name} from this learning plan? Existing curriculum and history will be kept.`)) event.preventDefault();
};

watch(() => form.source_kind, (kind) => {
    if (kind !== 'upload') form.source_file = null;
    if (kind !== 'url') form.source_url = '';
    if (kind !== 'manual') form.manual_reference = '';
});

const submit = () => form.post(route('workspace.curriculum-intake.subject.store', {
    student: props.selectedContext.student_id,
    schoolYear: props.selectedContext.school_year_id,
    subject: props.selectedSubject.id,
    from: props.returnTo === 'overview' ? 'overview' : undefined,
}), { forceFormData: true });
</script>

<template>
    <Head :title="entryMode === 'add' ? `Add ${selectedSubject?.name} Curriculum Source` : 'Curriculum Intake'" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
            <div>
                <p class="text-uppercase small fw-semibold text-secondary mb-1">Learning Plan</p>
                <h1 class="h2">{{ entryMode === 'add' ? `Add ${selectedSubject.name} Curriculum Source` : 'Curriculum Intake' }}</h1>
                <p class="text-secondary mb-0">{{ entryMode === 'add' ? 'Add one trusted source for this curriculum context.' : 'Add one trusted curriculum source at a time, organized by grade and subject.' }}</p>
            </div>
            <Link v-if="permissions.advanced" class="btn btn-link" :href="route('academic.curriculum.index')">Advanced curriculum settings</Link>
        </div>

        <div v-if="!selectedContext" class="card">
            <div class="empty-state"><h2 class="h5">Add an active enrollment first</h2><p>Curriculum sources need a student, school year, and grade context.</p></div>
        </div>

        <template v-else-if="entryMode === 'overview'">
            <section class="card mb-4" aria-labelledby="subject-overview-heading">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                        <div><h2 id="subject-overview-heading" class="h4 mb-1">{{ selectedContext.grade_name }} Curriculum</h2><p class="text-secondary mb-0">{{ selectedContext.student_name }} · {{ selectedContext.school_year_name }}</p></div>
                        <Link class="btn btn-outline-secondary" :href="backUrl">Back to Learning Plan</Link>
                    </div>
                    <details v-if="hiddenSubjectCount" class="mb-3">
                        <summary class="btn btn-sm btn-outline-secondary">Hidden subjects ({{ hiddenSubjectCount }})</summary>
                        <ul class="list-group mt-2"><li v-for="subject in hiddenSubjects" :key="subject.id" class="list-group-item d-flex justify-content-between align-items-center gap-2"><span><strong>{{ subject.name }}</strong><small class="d-block text-secondary">{{ subject.status_label }}</small></span><Link v-if="permissions.manage_visibility" as="button" method="patch" preserve-scroll class="btn btn-sm btn-outline-primary" :href="route('workspace.learning-plan.subjects.show', { enrollment: selectedContext.enrollment_id, subject: subject.id })">Show subject</Link></li></ul>
                    </details>
                    <div class="row g-3">
                        <div v-for="subject in subjects" :key="subject.id" class="col-sm-6 col-lg-4">
                            <article class="friendly-panel h-100 d-flex flex-column">
                                <div class="d-flex justify-content-between gap-2"><h3 class="h6">{{ subject.name }}</h3><span class="badge text-bg-light border align-self-start">{{ subject.status_label }}</span></div>
                                <p class="small text-secondary">{{ subject.source_count ? `${subject.source_count} source${subject.source_count === 1 ? '' : 's'}` : 'No curriculum source added' }}</p>
                                <p v-if="subject.curriculum_import_id" class="small text-secondary"><span v-if="subject.period_count">{{ subject.period_count }} periods · </span>{{ subject.unit_count }} units/blocks<span v-if="subject.assessment_count"> · {{ subject.assessment_count }} assessments</span><span v-if="subject.standard_alignment_count"> · {{ subject.standard_alignment_count }} standards references</span></p>
                                <Link v-if="subject.primary_action_url" class="btn btn-sm btn-primary align-self-start mb-2" :href="subject.primary_action_url">{{ subject.primary_action_label }}</Link>
                                <div v-for="source in subject.sources" :key="source.id" class="border-top pt-2 mt-2">
                                    <strong class="small d-block">{{ source.title }}</strong>
                                    <span class="small text-secondary text-capitalize">{{ source.review_status.replaceAll('_', ' ') }}</span>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <Link v-if="source.current_file?.is_pdf && source.can_download" class="btn btn-sm btn-outline-primary" :href="route('academic.sources.files.view', { source: source.id, file: source.current_file.id })" target="_blank">View PDF</Link>
                                        <Link v-if="source.current_file && source.can_download" class="btn btn-sm btn-outline-secondary" :href="route('academic.sources.files.download', { source: source.id, file: source.current_file.id })">Download</Link>
                                        <a v-if="source.external_url" class="btn btn-sm btn-outline-secondary" :href="source.external_url" target="_blank" rel="noopener noreferrer nofollow">Open source</a>
                                        <Link v-if="source.can_review && source.review_status === 'unreviewed'" class="btn btn-sm btn-outline-secondary" as="button" method="patch" :data="{ review_status: 'in_review' }" :href="route('academic.sources.review', source.id)">Start review</Link>
                                        <Link v-if="source.can_review && source.review_status === 'in_review'" class="btn btn-sm btn-outline-success" as="button" method="patch" :data="{ review_status: 'reviewed' }" :href="route('academic.sources.review', source.id)">Mark reviewed</Link>
                                        <Link v-if="source.review_status === 'reviewed' && permissions.create_draft && !source.draft" class="btn btn-sm btn-outline-success" as="button" method="post" :href="route('workspace.curriculum-intake.draft', source.id)">Create empty curriculum package</Link>
                                        <Link v-if="source.draft && permissions.create_draft" class="btn btn-sm btn-outline-primary" :href="route('academic.curriculum.show', source.draft.id)">Open draft curriculum</Link>
                                        <Link v-if="source.can_manage" class="btn btn-sm btn-link" :href="route('academic.sources.edit', source.id)">Edit details</Link>
                                        <Link v-if="source.can_manage" class="btn btn-sm btn-link text-danger" as="button" method="patch" :href="route('academic.sources.archive', source.id)">Archive source</Link>
                                    </div>
                                </div>
                                <Link v-if="subject.source_count" class="btn btn-sm btn-outline-primary mt-auto align-self-start" :href="route('workspace.curriculum-intake.subject.create', { student: selectedContext.student_id, schoolYear: selectedContext.school_year_id, subject: subject.id, from: 'overview' })">Add another source</Link>
                                <Link v-if="permissions.manage_visibility" as="button" method="patch" preserve-scroll class="btn btn-sm btn-link text-secondary align-self-start px-0 mt-2" :href="route('workspace.learning-plan.subjects.hide', { enrollment: selectedContext.enrollment_id, subject: subject.id })" @click="confirmHide($event, subject)">Hide subject</Link>
                            </article>
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <section class="friendly-panel mb-4" aria-labelledby="curriculum-context-heading">
                <p id="curriculum-context-heading" class="text-uppercase small fw-semibold text-secondary mb-2">Curriculum context</p>
                <p class="mb-1"><strong>{{ selectedContext.grade_name }} · {{ selectedContext.school_year_name }}</strong></p>
                <p class="mb-1">{{ selectedSubject.name }}</p>
                <p class="mb-1">{{ contextProvider?.name ?? 'Custom curriculum source' }}</p>
                <p class="text-secondary mb-0">Used by {{ selectedContext.student_name }}’s active learning plan</p>
            </section>

            <form id="curriculum-source-form" class="card" @submit.prevent="submit">
                <div class="card-body">
                    <div v-if="Object.keys(form.errors).length" class="alert alert-danger" role="alert">Please review the highlighted fields.</div>
                    <div class="mb-4">
                        <label class="form-label" for="intake-title">Curriculum name</label>
                        <input id="intake-title" v-model="form.title" class="form-control" required :aria-invalid="Boolean(form.errors.title)">
                        <div v-if="form.errors.title" class="text-danger small">{{ form.errors.title }}</div>
                    </div>
                    <div class="btn-group mb-3" role="group" aria-label="Source format">
                        <input id="kind-upload" v-model="form.source_kind" class="btn-check" type="radio" value="upload"><label class="btn btn-outline-primary" for="kind-upload">Upload PDF</label>
                        <input id="kind-url" v-model="form.source_kind" class="btn-check" type="radio" value="url"><label class="btn btn-outline-primary" for="kind-url">Source URL</label>
                        <input id="kind-manual" v-model="form.source_kind" class="btn-check" type="radio" value="manual"><label class="btn btn-outline-primary" for="kind-manual">Manual reference</label>
                    </div>
                    <div v-if="form.source_kind === 'upload'"><label class="form-label" for="intake-file">PDF file</label><input id="intake-file" class="form-control" type="file" accept="application/pdf,.pdf" :aria-invalid="Boolean(form.errors.source_file)" @change="form.source_file = ($event.target as HTMLInputElement).files?.[0] ?? null"><div class="form-text">Stored privately. Maximum {{ maxUploadMegabytes }} MB.</div><div v-if="form.errors.source_file" class="text-danger small">{{ form.errors.source_file }}</div></div>
                    <div v-if="form.source_kind === 'url'"><label class="form-label" for="intake-url">Source URL</label><input id="intake-url" v-model="form.source_url" class="form-control" type="url" :aria-invalid="Boolean(form.errors.source_url)"><div class="form-text">The application stores this reference and does not fetch it.</div><div v-if="form.errors.source_url" class="text-danger small">{{ form.errors.source_url }}</div></div>
                    <div v-if="form.source_kind === 'manual'"><label class="form-label" for="intake-manual">Reference details</label><textarea id="intake-manual" v-model="form.manual_reference" class="form-control" rows="3" :aria-invalid="Boolean(form.errors.manual_reference)"></textarea><div v-if="form.errors.manual_reference" class="text-danger small">{{ form.errors.manual_reference }}</div></div>
                    <div class="friendly-panel mt-4"><strong class="d-block">Ready to save</strong><span class="text-secondary">{{ sourceLabel }} for {{ selectedSubject.name }}</span></div>
                </div>
                <div class="card-footer bg-white d-flex justify-content-end gap-2">
                    <Link class="btn btn-outline-secondary" :href="backUrl">Cancel</Link>
                    <button class="btn btn-primary" type="submit" :disabled="form.processing"><span v-if="form.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ form.processing ? 'Saving…' : 'Save and review' }}</button>
                </div>
            </form>
        </template>
    </AuthenticatedLayout>
</template>
