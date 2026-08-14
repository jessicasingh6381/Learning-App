<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import CurriculumImportComponentTree from '@/Components/CurriculumImportComponentTree.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Proposal = {
    id: number; parent_proposal_id: number | null; proposal_type: 'period' | 'unit' | 'assessment';
    included: boolean; sequence: number; name: string; planned_start_date: string | null;
    planned_end_date: string | null; estimated_days: number | null; unit_type: string | null;
    component_type: string | null; description: string | null; summary: string | null; standard_codes: string[]; source_page: number | null; raw_text: string | null;
    parser_note: string | null; parser_metadata?: Record<string, any>; confidence: number | null; manually_edited: boolean; warnings: string[]; children?: Proposal[];
};
type Period = (Proposal | { id: string; proposal_type: 'course'; name: string }) & { children: Proposal[] };
const props = defineProps<{
    curriculumImport: any; source: any; context: any; periods: Period[]; unitTypes: string[]; componentTypes: string[]; canManage: boolean; canReextract: boolean;
}>();
const flatten = (proposal: any): Proposal[] => [proposal, ...(proposal.children ?? []).flatMap(flatten)];
const allProposals = computed(() => props.periods.flatMap((period) => period.proposal_type === 'period' ? flatten(period) : period.children.flatMap(flatten)));
const editable = (proposal: Proposal) => ({
    id: proposal.id, parent_proposal_id: proposal.parent_proposal_id, included: proposal.included,
    sequence: proposal.sequence, name: proposal.name, description: proposal.description ?? null, summary: proposal.summary ?? null, planned_start_date: proposal.planned_start_date,
    planned_end_date: proposal.planned_end_date, estimated_days: proposal.estimated_days,
    unit_type: proposal.unit_type, component_type: proposal.component_type ?? null, standard_codes: [...(proposal.standard_codes ?? [])],
});
const rows = Object.fromEntries(allProposals.value.map((proposal) => [proposal.id, editable(proposal)]));
const reviewForm = useForm<{ proposals: Record<number, ReturnType<typeof editable>> }>({ proposals: rows });
const approvalForm = useForm({ review_version: props.curriculumImport.review_version });
const reextractForm = useForm({});
const baseline = ref(JSON.stringify(rows));
const dirty = computed(() => JSON.stringify(reviewForm.proposals) !== baseline.value);
const isReadOnly = computed(() => !props.canManage || props.curriculumImport.status !== 'review');
const componentParents = computed(() => allProposals.value.filter((proposal) => ['unit', 'assessment', 'component'].includes(proposal.proposal_type)));
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const codesText = (id: number) => reviewForm.proposals[id].standard_codes.join(', ');
const setCodes = (id: number, value: string) => { reviewForm.proposals[id].standard_codes = value.split(/[,;\n]+/).map((code) => code.trim()).filter(Boolean); };
const errorFor = (id: number, field: string) => {
    const prefix = `proposals.${id}.${field}`;
    return Object.entries(reviewForm.errors as Record<string, string>).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];
};
const errorSummary = computed(() => [...new Set(Object.values(reviewForm.errors as Record<string, string>))]);
const approvalError = computed(() => Object.values(approvalForm.errors as Record<string, string>)[0]);
const save = () => reviewForm.put(route('academic.curriculum-imports.proposals.bulk-update', props.curriculumImport.id), {
    preserveScroll: true,
    onSuccess: () => { baseline.value = JSON.stringify(reviewForm.proposals); reviewForm.clearErrors(); },
});
const approve = () => {
    if (dirty.value || isReadOnly.value) return;
    approvalForm.review_version = props.curriculumImport.review_version;
    approvalForm.post(route('academic.curriculum-imports.approve', props.curriculumImport.id), { preserveScroll: true });
};
const strandSummary = (proposal: Proposal) => (proposal.children ?? []).map((child) => child.name.replace('Focus TEKS Evidence', 'Focus TEKS').replace('Handwriting Without Tears', 'Handwriting')).join(', ');
const componentByType = (proposal: Proposal, type: string) => (proposal.children ?? []).find((child) => child.component_type === type);
const scheduleOriginLabel = (proposal: Proposal) => ({
    calendar_calculated: 'Calculated from school calendar', source: 'Source supplied', manual_override: 'Manually adjusted',
}[proposal.parser_metadata?.schedule_origin as string] ?? null);
const scheduleCalendarName = (proposal: Proposal) => proposal.parser_metadata?.schedule_calendar_name as string | undefined;
const reextract = () => {
    if (dirty.value || !window.confirm('Re-extract this unapproved outline from the same PDF? The current proposal generation will be retained as superseded history.')) return;
    reextractForm.post(route('academic.curriculum-imports.reextract', props.curriculumImport.id), { preserveScroll: true });
};
const warnBeforeUnload = (event: BeforeUnloadEvent) => { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } };
let stopNavigationWarning: (() => void) | undefined;
onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    stopNavigationWarning = router.on('before', (event) => !dirty.value || window.confirm('Discard unsaved curriculum review changes?'));
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', warnBeforeUnload); stopNavigationWarning?.(); });
</script>

<template>
    <Head :title="`Curriculum import ${curriculumImport.id}`" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><h1 class="h2 mb-1">Curriculum import review</h1><p class="text-secondary mb-0">{{ source.title }} · {{ context.subject }} · {{ context.grade }}</p></div>
            <div class="d-flex gap-2"><span v-if="dirty" class="badge text-bg-warning align-self-center" role="status">Unsaved changes</span><Link class="btn btn-outline-secondary" :href="route('academic.sources.show', source.id)">Back to source</Link></div>
        </div>
        <AcademicNav />
        <div v-if="curriculumImport.status === 'approved'" class="alert alert-success">Approved imports are read-only. Approved {{ curriculumImport.approved_at ? new Date(curriculumImport.approved_at).toLocaleString() : '' }}<template v-if="curriculumImport.approved_by"> by {{ curriculumImport.approved_by }}</template>.</div>
        <div v-else-if="curriculumImport.status === 'failed'" class="alert alert-danger">{{ curriculumImport.diagnostic }}</div>
        <div v-if="curriculumImport.diagnostic" class="alert alert-warning" role="alert">{{ curriculumImport.diagnostic }}</div>

        <section class="card mb-4" aria-labelledby="import-summary"><div class="card-body"><div class="d-flex justify-content-between"><h2 id="import-summary" class="h5">Import summary</h2><span class="badge text-bg-light border">{{ label(curriculumImport.status) }}</span></div>
            <dl class="row mb-0"><dt class="col-sm-4">Target</dt><dd class="col-sm-8">{{ context.package }} · {{ context.course }}</dd><dt class="col-sm-4">School year</dt><dd class="col-sm-8">{{ context.school_year ?? 'Not specified' }}</dd><dt class="col-sm-4">Standards framework</dt><dd class="col-sm-8">{{ context.framework }}</dd><dt class="col-sm-4">Parser</dt><dd class="col-sm-8"><code>{{ curriculumImport.parser_key }}</code> · {{ curriculumImport.parser_version }}</dd><dt class="col-sm-4">Proposals</dt><dd class="col-sm-8">{{ curriculumImport.included_count }} included · {{ curriculumImport.excluded_count }} excluded</dd><dt class="col-sm-4">Source revision</dt><dd class="col-sm-8">{{ curriculumImport.source_revision_date ?? 'Not detected' }}</dd></dl>
            <div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" :href="route('academic.sources.files.view', [source.id, source.file.id])">View source PDF</a><button v-if="canReextract" class="btn btn-sm btn-outline-secondary" type="button" :disabled="dirty || reextractForm.processing" @click="reextract">{{ reextractForm.processing ? 'Re-extracting…' : 'Re-extract outline' }}</button></div>
        </div></section>

        <div v-if="errorSummary.length" class="alert alert-danger" role="alert"><strong>Review could not be saved.</strong><ul class="mb-0 mt-2"><li v-for="message in errorSummary" :key="message">{{ message }}</li></ul></div>

        <form @submit.prevent="save">
            <section v-for="period in periods" :key="period.id" class="card mb-4" :aria-labelledby="`period-${period.id}`">
                <div v-if="period.proposal_type === 'period'" class="card-header bg-white"><div class="row g-2 align-items-end">
                    <div class="col-auto"><label class="form-label d-block" :for="`include-${period.id}`">Include</label><input :id="`include-${period.id}`" v-model="reviewForm.proposals[period.id].included" class="form-check-input ms-2" type="checkbox" :disabled="isReadOnly"></div>
                    <div class="col-md"><label class="form-label" :for="`name-${period.id}`">Reporting period</label><input :id="`name-${period.id}`" v-model="reviewForm.proposals[period.id].name" class="form-control" :class="{ 'is-invalid': errorFor(period.id, 'name') }" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(period.id, 'name') }}</div></div>
                    <div class="col-sm-2"><label class="form-label" :for="`sequence-${period.id}`">Sequence</label><input :id="`sequence-${period.id}`" v-model.number="reviewForm.proposals[period.id].sequence" class="form-control" :class="{ 'is-invalid': errorFor(period.id, 'sequence') }" type="number" min="1" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(period.id, 'sequence') }}</div></div>
                    <div class="col-sm-2"><label class="form-label" :for="`start-${period.id}`">Start</label><input :id="`start-${period.id}`" v-model="reviewForm.proposals[period.id].planned_start_date" class="form-control" :class="{ 'is-invalid': errorFor(period.id, 'planned_start_date') }" type="date" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(period.id, 'planned_start_date') }}</div></div>
                    <div class="col-sm-2"><label class="form-label" :for="`end-${period.id}`">End</label><input :id="`end-${period.id}`" v-model="reviewForm.proposals[period.id].planned_end_date" class="form-control" type="date" :class="{ 'is-invalid': errorFor(period.id, 'planned_end_date') }" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(period.id, 'planned_end_date') }}</div></div>
                </div></div>
                <div v-else class="card-header bg-white"><h2 :id="`period-${period.id}`" class="h5 mb-1">Course outline</h2><p class="small text-secondary mb-0">The source provides an ordered unit sequence without reporting periods or calendar dates.</p></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th scope="col">Include</th><th scope="col">Unit or assessment</th><th scope="col">Reporting period</th><th scope="col">Type</th><th scope="col">Dates</th><th scope="col">Days</th><th scope="col">Order</th><th scope="col">TEKS / standards codes</th></tr></thead><tbody>
                    <template v-for="proposal in period.children" :key="proposal.id">
                    <tr>
                        <td><input v-model="reviewForm.proposals[proposal.id].included" class="form-check-input" type="checkbox" :aria-label="`Include ${proposal.name}`" :disabled="isReadOnly"></td>
                        <td style="min-width: 19rem"><label class="visually-hidden" :for="`name-${proposal.id}`">Name</label><input :id="`name-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].name" class="form-control" :class="{ 'is-invalid': errorFor(proposal.id, 'name') }" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'name') }}</div><label v-if="proposal.proposal_type === 'unit'" class="visually-hidden" :for="`summary-${proposal.id}`">Unit summary</label><textarea v-if="proposal.proposal_type === 'unit'" :id="`summary-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].summary" class="form-control form-control-sm mt-2" :class="{ 'is-invalid': errorFor(proposal.id, 'summary') }" rows="2" placeholder="Source-grounded unit summary" :disabled="isReadOnly"></textarea><div class="invalid-feedback">{{ errorFor(proposal.id, 'summary') }}</div><p v-if="proposal.proposal_type === 'unit' && (proposal.parser_metadata?.duration_text || componentByType(proposal, 'project'))" class="small text-secondary mt-2 mb-0"><span v-if="proposal.parser_metadata?.duration_text"><strong>Duration:</strong> {{ proposal.parser_metadata.duration_text }} <span class="badge text-bg-light border">Source PDF</span></span><span v-if="proposal.parser_metadata?.duration_text && componentByType(proposal, 'project')"> · </span><span v-if="componentByType(proposal, 'project')"><strong>Anchor project:</strong> {{ componentByType(proposal, 'project')?.name }}</span></p><div v-for="warning in proposal.warnings" :key="warning" class="small text-warning-emphasis mt-1">⚠ {{ warning }}</div><details v-if="proposal.raw_text || proposal.parser_note" class="small mt-1"><summary>Extraction evidence · page {{ proposal.source_page }}</summary><p class="mb-1">{{ proposal.parser_note }}</p><code class="text-wrap">{{ proposal.raw_text }}</code></details></td>
                        <td v-if="period.proposal_type === 'period'"><select v-model="reviewForm.proposals[proposal.id].parent_proposal_id" class="form-select" :class="{ 'is-invalid': errorFor(proposal.id, 'parent_proposal_id') }" :aria-label="`Reporting period for ${proposal.name}`" :disabled="isReadOnly"><option v-for="choice in periods.filter((item) => item.proposal_type === 'period')" :key="choice.id" :value="choice.id">{{ reviewForm.proposals[Number(choice.id)].name }}</option></select><div class="invalid-feedback">{{ errorFor(proposal.id, 'parent_proposal_id') }}</div></td>
                        <td v-else class="small text-secondary">Not supplied</td>
                        <td><select v-model="reviewForm.proposals[proposal.id].unit_type" class="form-select" :class="{ 'is-invalid': errorFor(proposal.id, 'unit_type') }" :aria-label="`Type for ${proposal.name}`" :disabled="isReadOnly"><option v-for="type in unitTypes" :key="type" :value="type">{{ label(type) }}</option></select><div class="invalid-feedback">{{ errorFor(proposal.id, 'unit_type') }}</div></td>
                        <td style="min-width: 11rem"><input v-model="reviewForm.proposals[proposal.id].planned_start_date" class="form-control form-control-sm mb-1" :class="{ 'is-invalid': errorFor(proposal.id, 'planned_start_date') }" type="date" :aria-label="`Start date for ${proposal.name}`" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'planned_start_date') }}</div><input v-model="reviewForm.proposals[proposal.id].planned_end_date" class="form-control form-control-sm" type="date" :class="{ 'is-invalid': errorFor(proposal.id, 'planned_end_date') }" :aria-label="`End date for ${proposal.name}`" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'planned_end_date') }}</div><div v-if="scheduleOriginLabel(proposal)" class="small text-secondary mt-1"><span class="badge text-bg-light border">{{ scheduleOriginLabel(proposal) }}</span><span v-if="scheduleCalendarName(proposal)" class="d-block mt-1">{{ scheduleCalendarName(proposal) }}</span></div></td>
                        <td><input v-model.number="reviewForm.proposals[proposal.id].estimated_days" class="form-control" :class="{ 'is-invalid': errorFor(proposal.id, 'estimated_days') }" type="number" min="1" max="366" :aria-label="`Estimated days for ${proposal.name}`" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'estimated_days') }}</div><span v-if="scheduleOriginLabel(proposal)" class="badge text-bg-light border mt-1">{{ scheduleOriginLabel(proposal) }}</span></td>
                        <td><input v-model.number="reviewForm.proposals[proposal.id].sequence" class="form-control" :class="{ 'is-invalid': errorFor(proposal.id, 'sequence') }" type="number" min="1" :aria-label="`Sequence for ${proposal.name}`" :disabled="isReadOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'sequence') }}</div></td>
                        <td style="min-width: 14rem"><textarea class="form-control" :class="{ 'is-invalid': errorFor(proposal.id, 'standard_codes') }" rows="2" :value="codesText(proposal.id)" :aria-label="`Standards codes for ${proposal.name}`" :disabled="isReadOnly" @input="setCodes(proposal.id, ($event.target as HTMLTextAreaElement).value)"></textarea><div class="invalid-feedback">{{ errorFor(proposal.id, 'standard_codes') }}</div></td>
                    </tr>
                    <tr v-if="proposal.children?.length"><td colspan="8" class="bg-body-tertiary"><details class="curriculum-components"><summary class="fw-semibold">{{ proposal.children.length }} sections · {{ strandSummary(proposal) }}</summary><div class="mt-3"><CurriculumImportComponentTree v-for="component in proposal.children" :key="component.id" :proposal="component" :review-form="reviewForm" :component-types="componentTypes" :parent-choices="componentParents" :read-only="isReadOnly" :error-for="errorFor" /></div></details></td></tr>
                    </template>
                </tbody></table></div></div>
            </section>
            <div v-if="canManage" class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-5">
                <span v-if="dirty" class="small text-warning-emphasis">Save changes before approval.</span>
                <button class="btn btn-outline-primary" type="submit" :disabled="!dirty || reviewForm.processing">{{ reviewForm.processing ? 'Saving…' : 'Save review changes' }}</button>
                <button class="btn btn-success" type="button" :disabled="dirty || reviewForm.processing || approvalForm.processing" @click="approve">{{ approvalForm.processing ? 'Approving…' : 'Approve curriculum outline' }}</button>
            </div>
            <div v-if="approvalError" class="alert alert-danger">{{ approvalError }}</div>
        </form>
    </AuthenticatedLayout>
</template>
