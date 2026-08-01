<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ source?: any; defaults?: Record<string, any>; options: Record<string, any[]>; maxUploadMegabytes: number }>();
const editing = Boolean(props.source);
const initial = props.source ?? props.defaults ?? {};
const form = useForm({
    title: initial.title ?? '', description: initial.description ?? '', source_kind: initial.source_kind ?? 'upload',
    source_category: initial.source_category ?? 'reference', authority_level: initial.authority_level ?? 'unknown',
    education_provider_id: initial.education_provider_id ?? null, school_year_id: initial.school_year_id ?? null,
    grade_level_id: initial.grade_level_id ?? null, subject_id: initial.subject_id ?? null, version_label: initial.version_label ?? '',
    academic_year_label: initial.academic_year_label ?? '', publication_date: initial.publication_date ?? '',
    source_url: initial.source_url ?? '', notes: initial.notes ?? '', source_file: null as File | null,
});
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const chooseFile = (event: Event) => { form.source_file = (event.target as HTMLInputElement).files?.[0] ?? null; };
const submit = () => editing
    ? form.put(route('academic.sources.update', props.source.id))
    : form.post(route('academic.sources.store'), { forceFormData: true });
</script>

<template><Head :title="editing ? 'Edit Academic Source' : 'Add Academic Source'" /><AuthenticatedLayout>
    <div class="mb-3"><h1 class="h2 mb-1">{{ editing ? 'Edit academic source' : 'Add academic source' }}</h1><p class="text-secondary">Record the source faithfully. Uploads remain private and URL sources are stored without being fetched.</p></div><AcademicNav />
    <form class="card" @submit.prevent="submit"><div class="card-body"><div class="row g-3">
        <div class="col-md-8"><label for="source-title" class="form-label">Title</label><input id="source-title" v-model="form.title" class="form-control" required><div v-if="form.errors.title" class="invalid-feedback d-block">{{ form.errors.title }}</div></div>
        <div class="col-md-4"><label for="source-kind-form" class="form-label">Source kind</label><select id="source-kind-form" v-model="form.source_kind" class="form-select" :disabled="editing" required><option v-for="item in options.kinds" :key="item" :value="item">{{ label(item) }}</option></select><div v-if="form.errors.source_kind" class="invalid-feedback d-block">{{ form.errors.source_kind }}</div></div>
        <div class="col-md-4"><label for="source-category-form" class="form-label">Category</label><select id="source-category-form" v-model="form.source_category" class="form-select" required><option v-for="item in options.categories" :key="item" :value="item">{{ label(item) }}</option></select></div>
        <div class="col-md-4"><label for="source-authority" class="form-label">Authority</label><select id="source-authority" v-model="form.authority_level" class="form-select" required><option v-for="item in options.authorityLevels" :key="item" :value="item">{{ label(item) }}</option></select></div>
        <div class="col-md-4"><label for="source-provider-form" class="form-label">Education provider</label><select id="source-provider-form" v-model="form.education_provider_id" class="form-select"><option :value="null">Not specified</option><option v-for="item in options.providers" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
        <div class="col-md-4"><label for="source-year-form" class="form-label">School year</label><select id="source-year-form" v-model="form.school_year_id" class="form-select"><option :value="null">Not specified</option><option v-for="item in options.schoolYears" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
        <div class="col-md-4"><label for="source-grade-form" class="form-label">Grade</label><select id="source-grade-form" v-model="form.grade_level_id" class="form-select"><option :value="null">Not specified</option><option v-for="item in options.gradeLevels" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
        <div v-if="!editing" class="col-md-4"><label for="source-subject-form" class="form-label">Subject</label><select id="source-subject-form" v-model="form.subject_id" class="form-select"><option :value="null">Not specified</option><option v-for="item in options.subjects" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
        <div class="col-md-4"><label for="source-version" class="form-label">Version label</label><input id="source-version" v-model="form.version_label" class="form-control"></div>
        <div class="col-md-4"><label for="source-academic-year" class="form-label">Academic year label</label><input id="source-academic-year" v-model="form.academic_year_label" class="form-control"></div>
        <div class="col-md-4"><label for="source-publication" class="form-label">Publication date</label><input id="source-publication" v-model="form.publication_date" class="form-control" type="date"></div>
        <div v-if="form.source_kind === 'upload' && !editing" class="col-12"><label for="source-file" class="form-label">Private source file</label><input id="source-file" class="form-control" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.csv,.txt" required @change="chooseFile"><div class="form-text">PDF, images, DOCX, XLSX, CSV, or TXT up to {{ maxUploadMegabytes }} MB.</div><div v-if="form.errors.source_file" class="invalid-feedback d-block">{{ form.errors.source_file }}</div></div>
        <div v-if="form.source_kind === 'url'" class="col-12"><label for="source-url" class="form-label">HTTPS or HTTP URL</label><input id="source-url" v-model="form.source_url" class="form-control" type="url" required><div class="form-text">The application stores this address only; it does not fetch or inspect the page.</div><div v-if="form.errors.source_url" class="invalid-feedback d-block">{{ form.errors.source_url }}</div></div>
        <div class="col-12"><label for="source-description" class="form-label">Description</label><textarea id="source-description" v-model="form.description" class="form-control" rows="3"></textarea><div v-if="form.errors.description" class="invalid-feedback d-block">{{ form.errors.description }}</div></div>
        <div class="col-12"><label for="source-notes" class="form-label">Internal notes</label><textarea id="source-notes" v-model="form.notes" class="form-control" rows="3"></textarea></div>
    </div></div><div class="card-footer bg-white d-flex justify-content-end gap-2"><Link class="btn btn-outline-secondary" :href="editing ? route('academic.sources.show', source.id) : route('academic.sources.index')">Cancel</Link><button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : (editing ? 'Save metadata' : 'Add source') }}</button></div></form>
</AuthenticatedLayout></template>
