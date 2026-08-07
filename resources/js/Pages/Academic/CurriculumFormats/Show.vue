<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ profile: any | null; source: any; detected: any; canManage: boolean }>();
const rules = props.profile?.mapping_rules ?? {};
const form = useForm({
    name: props.profile?.name ?? `${props.detected.title ?? props.source.title} format`,
    document_family: props.profile?.document_family ?? 'Curriculum document',
    strategy: rules.strategy ?? props.detected.suggested_strategy,
    confirmed_period_headings: [...(rules.confirmed_period_headings ?? props.detected.headings.slice(0, 4))],
    confirmed_unit_rows: [...(rules.confirmed_unit_rows ?? [])],
    confirmed_assessment_rows: [...(rules.confirmed_assessment_rows ?? [])],
});
const activation = useForm({});
const startForm = useForm({});
const isDraft = computed(() => !props.profile || props.profile.status === 'draft');
const save = () => props.profile && form.put(route('academic.curriculum-format-profiles.update', props.profile.id), { preserveScroll: true });
const activate = () => { if (props.profile && !form.isDirty && window.confirm('Activate this declarative format for matching reviewed PDFs? This will reassess support but will not create a curriculum import.')) activation.post(route('academic.curriculum-format-profiles.activate', props.profile.id)); };
const start = () => startForm.post(route('academic.sources.curriculum-format-setup.store', props.source.id));
</script>

<template>
    <Head title="Curriculum document format setup" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h2 mb-1">Curriculum document format setup</h1><p class="text-secondary mb-0">{{ source.title }} · {{ source.grade }} · {{ source.school_year }}</p></div><Link class="btn btn-outline-secondary" :href="route('academic.sources.show', source.id)">Back to source</Link></div>
        <div class="alert alert-info"><strong>Your private PDF is unchanged.</strong> This setup saves declarative recognition and row mappings only. It does not create or approve curriculum.</div>

        <section class="card mb-4"><div class="card-body"><h2 class="h5">Detected document</h2><dl class="row mb-0"><dt class="col-sm-3">Title</dt><dd class="col-sm-9">{{ detected.title ?? 'Not detected' }}</dd><dt class="col-sm-3">Provider</dt><dd class="col-sm-9">{{ source.provider ?? 'Not specified' }}</dd><dt class="col-sm-3">Grade / year</dt><dd class="col-sm-9">{{ source.grade }} · {{ source.school_year }}</dd><dt class="col-sm-3">Pages</dt><dd class="col-sm-9">{{ detected.page_count }}</dd><dt class="col-sm-3">Columns</dt><dd class="col-sm-9">{{ detected.column_labels.join(', ') || 'No stable column labels detected' }}</dd><dt class="col-sm-3">Suggested mapping</dt><dd class="col-sm-9">{{ detected.suggested_strategy.replaceAll('_', ' ') }}</dd></dl></div></section>

        <form v-if="profile" class="card mb-4" @submit.prevent="save"><div class="card-body"><div class="d-flex justify-content-between"><h2 class="h5">Reusable format profile</h2><span class="badge text-bg-light border text-capitalize">{{ profile.status }}</span></div>
            <div class="row g-3"><div class="col-md-6"><label for="profile-name" class="form-label">Profile name</label><input id="profile-name" v-model="form.name" class="form-control" :disabled="!isDraft"><div v-if="form.errors.name" class="invalid-feedback d-block">{{ form.errors.name }}</div></div><div class="col-md-6"><label for="document-family" class="form-label">Document family</label><input id="document-family" v-model="form.document_family" class="form-control" :disabled="!isDraft"><div v-if="form.errors.document_family" class="invalid-feedback d-block">{{ form.errors.document_family }}</div></div><div class="col-md-6"><label for="mapping-strategy" class="form-label">Validated mapping strategy</label><select id="mapping-strategy" v-model="form.strategy" class="form-select" :disabled="!isDraft"><option value="positioned_date_unit_table">Positioned date / unit table</option><option value="confirmed_heading_rows">Confirmed heading rows</option></select></div></div>
            <div class="row g-4 mt-1"><fieldset class="col-lg-4"><legend class="h6">Reporting-period headings</legend><div v-for="(row, index) in detected.headings" :key="row" class="form-check mb-2"><input :id="`period-${index}`" v-model="form.confirmed_period_headings" class="form-check-input" type="checkbox" :value="row" :disabled="!isDraft"><label class="form-check-label small" :for="`period-${index}`">{{ row }}</label></div><div v-if="form.errors.confirmed_period_headings" class="text-danger small">{{ form.errors.confirmed_period_headings }}</div></fieldset><fieldset class="col-lg-4"><legend class="h6">Unit rows</legend><div v-for="(row, index) in detected.unit_rows" :key="row" class="form-check mb-2"><input :id="`unit-${index}`" v-model="form.confirmed_unit_rows" class="form-check-input" type="checkbox" :value="row" :disabled="!isDraft"><label class="form-check-label small" :for="`unit-${index}`">{{ row }}</label></div><div v-if="form.errors.confirmed_unit_rows" class="text-danger small">{{ form.errors.confirmed_unit_rows }}</div></fieldset><fieldset class="col-lg-4"><legend class="h6">Assessment rows</legend><div v-for="(row, index) in detected.assessment_rows" :key="row" class="form-check mb-2"><input :id="`assessment-${index}`" v-model="form.confirmed_assessment_rows" class="form-check-input" type="checkbox" :value="row" :disabled="!isDraft"><label class="form-check-label small" :for="`assessment-${index}`">{{ row }}</label></div></fieldset></div>
        </div><div v-if="isDraft" class="card-footer d-flex flex-wrap justify-content-end gap-2"><button class="btn btn-outline-primary" type="submit" :disabled="!form.isDirty || form.processing">{{ form.processing ? 'Saving…' : 'Save format mapping' }}</button><button class="btn btn-success" type="button" :disabled="form.isDirty || activation.processing || !form.confirmed_period_headings.length || !form.confirmed_unit_rows.length" @click="activate">{{ activation.processing ? 'Activating…' : 'Activate and reassess support' }}</button></div></form>

        <section v-else class="card"><div class="card-body"><h2 class="h5">Start reusable setup</h2><p>The extracted headings and rows below are available only to authorized curriculum managers. Starting setup creates a draft profile, not a curriculum import.</p><form @submit.prevent="start"><button class="btn btn-primary" :disabled="!canManage || startForm.processing">{{ startForm.processing ? 'Starting…' : 'Set up this document format' }}</button></form></div></section>

        <details class="card"><summary class="card-header bg-white fw-semibold">Additional detected evidence</summary><div class="card-body"><h2 class="h6">Dates and weeks</h2><ul><li v-for="row in detected.date_rows" :key="row">{{ row }}</li></ul><h2 class="h6">Standards-like codes</h2><p>{{ detected.standards_like_codes.join(', ') || 'None detected' }}</p></div></details>
    </AuthenticatedLayout>
</template>
