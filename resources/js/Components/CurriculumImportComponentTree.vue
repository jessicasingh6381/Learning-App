<script setup lang="ts">
defineOptions({ name: 'CurriculumImportComponentTree' });
defineProps<{
    proposal: any;
    reviewForm: any;
    componentTypes: string[];
    parentChoices: any[];
    readOnly: boolean;
    errorFor: (id: number, field: string) => string | undefined;
}>();
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
</script>

<template>
    <details class="border rounded p-2 mb-2 curriculum-component">
        <summary class="d-flex flex-wrap align-items-center gap-2">
            <strong>{{ reviewForm.proposals[proposal.id].name }}</strong>
            <span class="badge text-bg-light border">{{ label(reviewForm.proposals[proposal.id].component_type ?? 'other') }}</span>
            <span v-if="proposal.children?.length" class="small text-secondary">{{ proposal.children.length }} nested</span>
        </summary>
        <div class="row g-2 mt-1">
            <div class="col-auto"><label class="form-label d-block" :for="`include-${proposal.id}`">Include</label><input :id="`include-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].included" class="form-check-input ms-2" type="checkbox" :disabled="readOnly"></div>
            <div class="col-md-4"><label class="form-label" :for="`name-${proposal.id}`">Component name</label><input :id="`name-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].name" class="form-control" :class="{ 'is-invalid': errorFor(proposal.id, 'name') }" :disabled="readOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'name') }}</div></div>
            <div class="col-md-3"><label class="form-label" :for="`type-${proposal.id}`">Component type</label><select :id="`type-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].component_type" class="form-select" :class="{ 'is-invalid': errorFor(proposal.id, 'component_type') }" :disabled="readOnly"><option v-for="type in componentTypes" :key="type" :value="type">{{ label(type) }}</option></select><div class="invalid-feedback">{{ errorFor(proposal.id, 'component_type') }}</div></div>
            <div class="col-md-3"><label class="form-label" :for="`parent-${proposal.id}`">Parent</label><select :id="`parent-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].parent_proposal_id" class="form-select" :class="{ 'is-invalid': errorFor(proposal.id, 'parent_proposal_id') }" :disabled="readOnly"><option v-for="choice in parentChoices.filter((item) => item.id !== proposal.id)" :key="choice.id" :value="choice.id">{{ choice.name }}</option></select><div class="invalid-feedback">{{ errorFor(proposal.id, 'parent_proposal_id') }}</div></div>
            <div class="col-sm-2"><label class="form-label" :for="`sequence-${proposal.id}`">Order</label><input :id="`sequence-${proposal.id}`" v-model.number="reviewForm.proposals[proposal.id].sequence" class="form-control" type="number" min="1" :class="{ 'is-invalid': errorFor(proposal.id, 'sequence') }" :disabled="readOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'sequence') }}</div></div>
            <div class="col-sm-3"><label class="form-label" :for="`start-${proposal.id}`">Start</label><input :id="`start-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].planned_start_date" class="form-control" type="date" :disabled="readOnly"></div>
            <div class="col-sm-3"><label class="form-label" :for="`end-${proposal.id}`">End</label><input :id="`end-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].planned_end_date" class="form-control" type="date" :class="{ 'is-invalid': errorFor(proposal.id, 'planned_end_date') }" :disabled="readOnly"><div class="invalid-feedback">{{ errorFor(proposal.id, 'planned_end_date') }}</div></div>
            <div class="col-12"><label class="form-label" :for="`description-${proposal.id}`">Extracted content</label><textarea :id="`description-${proposal.id}`" v-model="reviewForm.proposals[proposal.id].description" class="form-control" rows="3" :class="{ 'is-invalid': errorFor(proposal.id, 'description') }" :disabled="readOnly"></textarea><div class="invalid-feedback">{{ errorFor(proposal.id, 'description') }}</div></div>
        </div>
        <div v-for="warning in proposal.warnings" :key="warning" class="small text-warning-emphasis mt-1">⚠ {{ warning }}</div>
        <details v-if="proposal.raw_text || proposal.parser_note" class="small mt-2"><summary>Extraction evidence · page {{ proposal.source_page }}</summary><p class="mb-1">{{ proposal.parser_note }}</p><code class="text-wrap">{{ proposal.raw_text }}</code></details>
        <div v-if="proposal.children?.length" class="border-start ps-3 mt-3">
            <CurriculumImportComponentTree v-for="child in proposal.children" :key="child.id" :proposal="child" :review-form="reviewForm" :component-types="componentTypes" :parent-choices="parentChoices" :read-only="readOnly" :error-for="errorFor" />
        </div>
    </details>
</template>
