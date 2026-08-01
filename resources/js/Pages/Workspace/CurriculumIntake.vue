<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    contexts: any[];
    selectedContext: any | null;
    selectedSubjectId: number | null;
    providers: any[];
    subjects: any[];
    permissions: any;
    maxUploadMegabytes: number;
}>();

const form = useForm({
    student_id: props.selectedContext?.student_id ?? null,
    school_year_id: props.selectedContext?.school_year_id ?? null,
    source_origin: 'provider',
    education_provider_id: props.providers.length === 1 ? props.providers[0].id : null,
    subject_id: props.selectedSubjectId,
    title: '',
    source_kind: 'upload',
    source_file: null as File | null,
    source_url: '',
    manual_reference: '',
    version_label: '',
});

const students = computed(() => Array.from(new Map(props.contexts.map((context) => [context.student_id, { id: context.student_id, name: context.student_name }])).values()));
const years = computed(() => props.contexts.filter((context) => context.student_id === Number(form.student_id)));
const selectedSubject = computed(() => props.subjects.find((subject) => subject.id === Number(form.subject_id)) ?? null);
const selectedProvider = computed(() => props.providers.find((provider) => provider.id === Number(form.education_provider_id)) ?? null);
const availableProviders = computed(() => props.providers.filter((provider) => form.source_origin === 'publisher'
    ? provider.provider_type === 'curriculum_publisher'
    : provider.provider_type !== 'curriculum_publisher'));
const sourceLabel = computed(() => ({ upload: 'Uploaded PDF', url: 'Source URL', manual: 'Manual reference' }[form.source_kind]));

watch(() => form.source_origin, (origin) => {
    form.education_provider_id = null;
});
watch(() => form.source_kind, (kind) => {
    if (kind !== 'upload') form.source_file = null;
    if (kind !== 'url') form.source_url = '';
    if (kind !== 'manual') form.manual_reference = '';
});

const changeContext = () => router.get(route('workspace.curriculum-intake'), {
    student_id: form.student_id,
    school_year_id: form.school_year_id,
}, { preserveState: false });
const selectSubject = (id: number) => {
    form.subject_id = id;
    document.getElementById('curriculum-document')?.focus();
};
const submit = () => form.post(route('workspace.curriculum-intake.store'), { forceFormData: true });
</script>

<template>
    <Head title="Curriculum Intake" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
            <div><p class="text-uppercase small fw-semibold text-secondary mb-1">Learning Plan</p><h1 class="h2">Curriculum Intake</h1><p class="text-secondary mb-0">Add one trusted curriculum source at a time, organized by grade and subject.</p></div>
            <div class="d-flex gap-2 flex-wrap"><Link class="btn btn-outline-secondary" :href="route('workspace.learning-plan', selectedContext ? { student_id: selectedContext.student_id } : {})">Back to Learning Plan</Link><Link v-if="permissions.advanced" class="btn btn-link" :href="route('academic.curriculum.index')">Advanced curriculum settings</Link></div>
        </div>

        <div v-if="!selectedContext" class="card"><div class="empty-state"><h2 class="h5">Add an active enrollment first</h2><p>Curriculum sources need a student, school year, and grade context.</p></div></div>
        <template v-else>
            <section class="card mb-4" aria-labelledby="subject-overview-heading"><div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div><h2 id="subject-overview-heading" class="h4 mb-1">{{ selectedContext.grade_name }} Curriculum</h2><p class="text-secondary mb-0">{{ selectedContext.student_name }} · {{ selectedContext.school_year_name }}</p></div></div>
                <div class="row g-3"><div v-for="subject in subjects" :key="subject.id" class="col-sm-6 col-lg-4"><article class="friendly-panel h-100 d-flex flex-column"><div class="d-flex justify-content-between gap-2"><h3 class="h6">{{ subject.name }}</h3><span class="badge text-bg-light border align-self-start">{{ subject.status_label }}</span></div><p class="small text-secondary">{{ subject.source_count ? `${subject.source_count} source${subject.source_count === 1 ? '' : 's'}` : 'No curriculum source added' }}</p>
                    <div v-for="source in subject.sources" :key="source.id" class="border-top pt-2 mt-2"><strong class="small d-block">{{ source.title }}</strong><span class="small text-secondary text-capitalize">{{ source.review_status.replaceAll('_', ' ') }}</span><div class="d-flex flex-wrap gap-2 mt-2">
                        <Link v-if="source.current_file?.is_pdf && source.can_download" class="btn btn-sm btn-outline-primary" :href="route('academic.sources.files.view', { source: source.id, file: source.current_file.id })" target="_blank">View PDF</Link>
                        <Link v-if="source.current_file && source.can_download" class="btn btn-sm btn-outline-secondary" :href="route('academic.sources.files.download', { source: source.id, file: source.current_file.id })">Download</Link>
                        <a v-if="source.external_url" class="btn btn-sm btn-outline-secondary" :href="source.external_url" target="_blank" rel="noopener noreferrer nofollow">Open source</a>
                        <Link v-if="source.can_review && source.review_status === 'unreviewed'" class="btn btn-sm btn-outline-secondary" as="button" method="patch" :data="{ review_status: 'in_review' }" :href="route('academic.sources.review', source.id)">Start review</Link>
                        <Link v-if="source.can_review && source.review_status === 'in_review'" class="btn btn-sm btn-outline-success" as="button" method="patch" :data="{ review_status: 'reviewed' }" :href="route('academic.sources.review', source.id)">Mark reviewed</Link>
                        <Link v-if="source.review_status === 'reviewed' && permissions.create_draft && !source.draft" class="btn btn-sm btn-outline-success" as="button" method="post" :href="route('workspace.curriculum-intake.draft', source.id)">Create draft curriculum outline</Link>
                        <Link v-if="source.draft && permissions.create_draft" class="btn btn-sm btn-outline-primary" :href="route('academic.curriculum.show', source.draft.id)">Open draft curriculum</Link>
                        <Link v-if="source.can_manage" class="btn btn-sm btn-link" :href="route('academic.sources.edit', source.id)">Edit details</Link>
                        <Link v-if="source.can_manage" class="btn btn-sm btn-link text-danger" as="button" method="patch" :href="route('academic.sources.archive', source.id)">Archive source</Link>
                    </div></div>
                    <button class="btn btn-sm btn-primary mt-auto align-self-start" type="button" @click="selectSubject(subject.id)">{{ subject.source_count ? 'Add another source' : 'Add curriculum' }}</button>
                </article></div></div>
            </div></section>

            <form id="curriculum-document" class="card" tabindex="-1" @submit.prevent="submit"><div class="card-body">
                <h2 class="h4 mb-4">Add a curriculum document</h2>
                <div v-if="Object.keys(form.errors).length" class="alert alert-danger" role="alert">Please review the highlighted fields.</div>
                <fieldset class="mb-4"><legend class="h5">1. Choose student and school year</legend><div class="row g-3"><div class="col-md-5"><label class="form-label" for="intake-student">Student</label><select id="intake-student" v-model="form.student_id" class="form-select" @change="changeContext"><option v-for="student in students" :key="student.id" :value="student.id">{{ student.name }}</option></select></div><div class="col-md-5"><label class="form-label" for="intake-year">School year</label><select id="intake-year" v-model="form.school_year_id" class="form-select" @change="changeContext"><option v-for="context in years" :key="context.school_year_id" :value="context.school_year_id">{{ context.school_year_name }}</option></select></div><div class="col-md-2"><span class="form-label d-block">Grade</span><strong class="d-block pt-2">{{ selectedContext.grade_name }}</strong></div></div><div v-if="form.errors.student_id" class="text-danger small mt-2">{{ form.errors.student_id }}</div></fieldset>

                <fieldset class="mb-4"><legend class="h5">2. Choose the curriculum source</legend><div class="row g-2 mb-3"><div v-for="option in [{value:'provider',label:'School district or education provider'},{value:'publisher',label:'Curriculum publisher'},{value:'custom',label:'Custom homeschool curriculum'},{value:'other',label:'Other source'}]" :key="option.value" class="col-md-6 col-lg-3"><label class="friendly-panel h-100"><input v-model="form.source_origin" type="radio" name="source_origin" :value="option.value" class="form-check-input me-2">{{ option.label }}</label></div></div><div v-if="['provider', 'publisher'].includes(form.source_origin)"><label class="form-label" for="intake-provider">{{ form.source_origin === 'publisher' ? 'Curriculum publisher' : 'School district or education provider' }}</label><select id="intake-provider" v-model="form.education_provider_id" class="form-select" :aria-invalid="Boolean(form.errors.education_provider_id)"><option :value="null">Choose {{ form.source_origin === 'publisher' ? 'a publisher' : 'a provider' }}</option><option v-for="provider in availableProviders" :key="provider.id" :value="provider.id">{{ provider.short_name ? `${provider.short_name} — ${provider.name}` : provider.name }}</option></select><div v-if="!availableProviders.length" class="form-text">No matching records are available yet. An authorized administrator can add one in Advanced Academic Setup.</div><div v-if="form.errors.education_provider_id" class="text-danger small">{{ form.errors.education_provider_id }}</div></div></fieldset>

                <fieldset class="mb-4"><legend class="h5">3. Choose the subject</legend><div class="row g-2"><div v-for="subject in subjects" :key="subject.id" class="col-sm-6 col-lg-4"><label class="friendly-panel h-100"><input v-model="form.subject_id" type="radio" name="subject_id" :value="subject.id" class="form-check-input me-2">{{ subject.name }}</label></div></div><div v-if="form.errors.subject_id" class="text-danger small mt-2">{{ form.errors.subject_id }}</div></fieldset>

                <fieldset class="mb-4"><legend class="h5">4. Add the curriculum document</legend><div class="mb-3"><label class="form-label" for="intake-title">Curriculum name</label><input id="intake-title" v-model="form.title" class="form-control" required :aria-invalid="Boolean(form.errors.title)"><div v-if="form.errors.title" class="text-danger small">{{ form.errors.title }}</div></div><div class="btn-group mb-3" role="group" aria-label="Source format"><input id="kind-upload" v-model="form.source_kind" class="btn-check" type="radio" value="upload"><label class="btn btn-outline-primary" for="kind-upload">Upload PDF</label><input id="kind-url" v-model="form.source_kind" class="btn-check" type="radio" value="url"><label class="btn btn-outline-primary" for="kind-url">Source URL</label><input id="kind-manual" v-model="form.source_kind" class="btn-check" type="radio" value="manual"><label class="btn btn-outline-primary" for="kind-manual">Manual reference</label></div>
                    <div v-if="form.source_kind === 'upload'"><label class="form-label" for="intake-file">PDF file</label><input id="intake-file" class="form-control" type="file" accept="application/pdf,.pdf" :aria-invalid="Boolean(form.errors.source_file)" @change="form.source_file = ($event.target as HTMLInputElement).files?.[0] ?? null"><div class="form-text">Stored privately. Maximum {{ maxUploadMegabytes }} MB.</div><div v-if="form.errors.source_file" class="text-danger small">{{ form.errors.source_file }}</div></div>
                    <div v-if="form.source_kind === 'url'"><label class="form-label" for="intake-url">Source URL</label><input id="intake-url" v-model="form.source_url" class="form-control" type="url" :aria-invalid="Boolean(form.errors.source_url)"><div class="form-text">The application stores this reference and does not fetch it.</div><div v-if="form.errors.source_url" class="text-danger small">{{ form.errors.source_url }}</div></div>
                    <div v-if="form.source_kind === 'manual'"><label class="form-label" for="intake-manual">Reference details</label><textarea id="intake-manual" v-model="form.manual_reference" class="form-control" rows="3" :aria-invalid="Boolean(form.errors.manual_reference)"></textarea><div v-if="form.errors.manual_reference" class="text-danger small">{{ form.errors.manual_reference }}</div></div>
                </fieldset>

                <section class="friendly-panel" aria-labelledby="review-heading"><h3 id="review-heading" class="h5">5. Review and save</h3><dl class="row mb-0"><dt class="col-sm-4">Student</dt><dd class="col-sm-8">{{ selectedContext.student_name }}</dd><dt class="col-sm-4">School year</dt><dd class="col-sm-8">{{ selectedContext.school_year_name }}</dd><dt class="col-sm-4">Grade</dt><dd class="col-sm-8">{{ selectedContext.grade_name }}</dd><dt class="col-sm-4">Provider</dt><dd class="col-sm-8">{{ ['provider', 'publisher'].includes(form.source_origin) ? (selectedProvider?.short_name || selectedProvider?.name || 'Choose a provider') : (form.source_origin === 'custom' ? 'Custom homeschool curriculum' : 'Other source') }}</dd><dt class="col-sm-4">Subject</dt><dd class="col-sm-8">{{ selectedSubject?.name || 'Choose a subject' }}</dd><dt class="col-sm-4">Source</dt><dd class="col-sm-8">{{ sourceLabel }}</dd></dl></section>
            </div><div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit" :disabled="form.processing"><span v-if="form.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ form.processing ? 'Saving…' : 'Save curriculum source' }}</button></div></form>
        </template>
    </AuthenticatedLayout>
</template>
