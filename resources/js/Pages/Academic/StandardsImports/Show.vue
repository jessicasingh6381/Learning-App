<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ standardsImport: any; source: any; context: any; strands: any[]; canManage: boolean; nextStep?: { label: string; url: string } | null }>();
const flatten = (rows: any[]): any[] => rows.flatMap((row) => [row, ...flatten(row.children ?? [])]);
const proposals = computed(() => flatten(props.strands));
const reviewForm = useForm({ proposals: Object.fromEntries(proposals.value.map((row) => [row.id, {
    id: row.id, included: row.included, sequence: row.sequence, standard_code: row.standard_code, statement: row.statement,
}])) });
const approvalForm = useForm({ review_version: props.standardsImport.review_version });
const readOnly = computed(() => props.standardsImport.status === 'approved' || !props.canManage);
const errorFor = (id: number, field: string) => (reviewForm.errors as Record<string, string>)[`proposals.${id}.${field}`];
const globalError = computed(() => (reviewForm.errors as Record<string, string>).proposals ?? (reviewForm.errors as Record<string, string>).review);
const approvalError = computed(() => {
    const errors = approvalForm.errors as Record<string, string>;
    return errors.approval ?? Object.values(errors)[0];
});
const save = () => reviewForm.put(route('academic.standards-imports.review.update', props.standardsImport.id), {
    preserveScroll: true, onSuccess: () => { approvalForm.review_version = props.standardsImport.review_version + 1; },
});
const approve = () => { if (!reviewForm.isDirty && window.confirm('Approve these reviewed standards as reusable references?')) approvalForm.post(route('academic.standards-imports.approve', props.standardsImport.id)); };
</script>

<template>
    <Head :title="`${context.grade} ${context.subject} standards review`" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h2 mb-1">{{ context.grade }} {{ context.subject }} standards</h1><p class="text-secondary mb-0">{{ context.framework }} · {{ standardsImport.document_section }} · {{ standardsImport.adopted_label }}</p></div><Link class="btn btn-outline-secondary" :href="route('academic.sources.show', source.id)">Back to source</Link></div>
        <div v-if="standardsImport.status === 'approved'" class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2"><span><strong>Standards imported.</strong> These records are read-only and reusable for future curriculum alignment.<template v-if="nextStep"> Pacing guide still needed.</template></span><Link v-if="nextStep" class="btn btn-sm btn-primary" :href="nextStep.url">{{ nextStep.label }}</Link></div>
        <div v-else class="alert alert-info">This multi-grade document was isolated using validated source context. Only {{ context.grade }} {{ context.subject }} standards are shown; no pacing, units, dates, or assessments will be created.</div>

        <section class="card mb-4"><div class="card-body"><h2 class="h5">Source section</h2><dl class="row mb-0"><dt class="col-sm-3">File</dt><dd class="col-sm-9">{{ source.file.name }}</dd><dt class="col-sm-3">Pages</dt><dd class="col-sm-9">{{ standardsImport.document_metadata?.source_pages?.join('–') }}</dd><dt class="col-sm-3">Implementation</dt><dd class="col-sm-9">{{ standardsImport.document_metadata?.implementation_label }}</dd><dt class="col-sm-3">Document update</dt><dd class="col-sm-9">{{ standardsImport.document_metadata?.update_label }}</dd></dl><details class="mt-3"><summary class="fw-semibold">Grade-level introduction</summary><p class="small mt-2 mb-0">{{ standardsImport.introduction_text }}</p></details></div></section>

        <details v-if="standardsImport.document_metadata?.implementation_statement" class="card card-body mb-4">
            <summary class="fw-semibold">Source implementation wording</summary>
            <p class="small mt-2 mb-0">{{ standardsImport.document_metadata.implementation_statement }}</p>
        </details>

        <form @submit.prevent="save">
            <div v-if="approvalError" class="alert alert-danger" role="alert">{{ approvalError }}</div>
            <div v-if="globalError" class="alert alert-danger">{{ globalError }}</div>
            <section v-for="strand in strands" :key="strand.id" class="card mb-3" :aria-labelledby="`strand-${strand.id}`"><div class="card-header bg-white"><div class="form-check"><input :id="`include-${strand.id}`" v-model="reviewForm.proposals[strand.id].included" type="checkbox" class="form-check-input" :disabled="readOnly"><label :id="`strand-${strand.id}`" class="form-check-label fw-semibold" :for="`include-${strand.id}`">{{ strand.name }}</label></div></div><div class="card-body">
                <article v-for="standard in strand.children" :key="standard.id" class="border rounded p-3 mb-3"><div class="row g-2 align-items-start"><div class="col-auto pt-2"><input :id="`include-${standard.id}`" v-model="reviewForm.proposals[standard.id].included" type="checkbox" class="form-check-input" :disabled="readOnly"><label class="visually-hidden" :for="`include-${standard.id}`">Include {{ standard.standard_code }}</label></div><div class="col-md-2"><label class="visually-hidden" :for="`code-${standard.id}`">Standard code</label><input :id="`code-${standard.id}`" v-model="reviewForm.proposals[standard.id].standard_code" class="form-control fw-semibold" :class="{ 'is-invalid': errorFor(standard.id, 'standard_code') }" :disabled="readOnly"><div class="invalid-feedback">{{ errorFor(standard.id, 'standard_code') }}</div></div><div class="col"><label class="visually-hidden" :for="`statement-${standard.id}`">Knowledge and skills statement</label><textarea :id="`statement-${standard.id}`" v-model="reviewForm.proposals[standard.id].statement" class="form-control" :class="{ 'is-invalid': errorFor(standard.id, 'statement') }" rows="2" :disabled="readOnly"></textarea><div class="invalid-feedback">{{ errorFor(standard.id, 'statement') }}</div></div></div>
                    <details class="small mt-2"><summary>Extraction evidence · page {{ standard.source_page }}</summary><p class="mb-1">{{ standard.parser_note }}</p><code class="text-wrap">{{ standard.raw_text }}</code></details>
                    <div class="ms-md-4 mt-3"><div v-for="expectation in standard.children" :key="expectation.id" class="row g-2 align-items-start border-top py-2"><div class="col-auto pt-2"><input :id="`include-${expectation.id}`" v-model="reviewForm.proposals[expectation.id].included" type="checkbox" class="form-check-input" :disabled="readOnly"><label class="visually-hidden" :for="`include-${expectation.id}`">Include {{ expectation.standard_code }}</label></div><div class="col-md-2"><label class="visually-hidden" :for="`code-${expectation.id}`">Expectation code</label><input :id="`code-${expectation.id}`" v-model="reviewForm.proposals[expectation.id].standard_code" class="form-control form-control-sm" :class="{ 'is-invalid': errorFor(expectation.id, 'standard_code') }" :disabled="readOnly"><div class="invalid-feedback">{{ errorFor(expectation.id, 'standard_code') }}</div></div><div class="col"><label class="visually-hidden" :for="`statement-${expectation.id}`">Student expectation</label><textarea :id="`statement-${expectation.id}`" v-model="reviewForm.proposals[expectation.id].statement" class="form-control form-control-sm" :class="{ 'is-invalid': errorFor(expectation.id, 'statement') }" rows="2" :disabled="readOnly"></textarea><div class="invalid-feedback">{{ errorFor(expectation.id, 'statement') }}</div><details class="small mt-1"><summary>Evidence · page {{ expectation.source_page }}</summary><code class="text-wrap">{{ expectation.raw_text }}</code></details></div></div></div>
                </article>
            </div></section>
            <div v-if="canManage && standardsImport.status === 'review'" class="sticky-bottom bg-white border rounded p-3 d-flex flex-wrap justify-content-between align-items-center gap-2"><span class="small text-secondary">{{ proposals.filter((row) => reviewForm.proposals[row.id].included).length }} included · Save all review changes together.</span><div class="d-flex gap-2"><button class="btn btn-outline-primary" type="submit" :disabled="!reviewForm.isDirty || reviewForm.processing">{{ reviewForm.processing ? 'Saving…' : 'Save standards review' }}</button><button class="btn btn-success" type="button" :disabled="reviewForm.isDirty || approvalForm.processing" @click="approve">{{ approvalForm.processing ? 'Approving…' : 'Approve standards' }}</button></div></div>
        </form>
    </AuthenticatedLayout>
</template>
