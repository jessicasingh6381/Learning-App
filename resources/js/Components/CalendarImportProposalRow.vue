<script setup lang="ts">
const props = defineProps<{
    proposal: any;
    eventTypes: string[];
    effects: string[];
    editable: boolean;
    errors?: Record<string, string>;
}>();
const review = defineModel<any>('review', { required: true });
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
const error = (field: string) => props.errors?.[field];
</script>

<template>
    <tr :class="{ 'table-light text-secondary': !review.included, 'table-danger': Object.keys(errors ?? {}).length > 0 }">
        <td><input v-model="review.included" class="form-check-input" type="checkbox" :disabled="!editable" :aria-label="`Include ${review.name}`"></td>
        <td style="min-width: 12rem">
            <input v-model="review.event_date" class="form-control form-control-sm mb-1" :class="{ 'is-invalid': error('event_date') }" type="date" :disabled="!editable" :aria-describedby="error('event_date') ? `proposal-${proposal.id}-event-date-error` : undefined">
            <div v-if="error('event_date')" :id="`proposal-${proposal.id}-event-date-error`" class="invalid-feedback d-block">{{ error('event_date') }}</div>
            <input v-model="review.end_date" class="form-control form-control-sm" :class="{ 'is-invalid': error('end_date') }" type="date" :disabled="!editable" aria-label="End date">
            <div v-if="error('end_date')" class="invalid-feedback d-block">{{ error('end_date') }}</div>
        </td>
        <td style="min-width: 15rem">
            <input v-model="review.name" class="form-control form-control-sm" :class="{ 'is-invalid': error('name') }" :disabled="!editable">
            <div v-if="error('name')" class="invalid-feedback d-block">{{ error('name') }}</div>
            <small v-if="proposal.source_page" class="text-secondary">PDF page {{ proposal.source_page }}</small>
            <details v-if="proposal.raw_text" class="small"><summary>Extracted text</summary>{{ proposal.raw_text }}</details>
        </td>
        <td style="min-width: 12rem">
            <select v-model="review.event_type" class="form-select form-select-sm" :class="{ 'is-invalid': error('event_type') }" :disabled="!editable"><option v-for="type in eventTypes" :key="type" :value="type">{{ label(type) }}</option></select>
            <div v-if="error('event_type')" class="invalid-feedback d-block">{{ error('event_type') }}</div>
        </td>
        <td style="min-width: 12rem">
            <select v-model="review.instructional_effect" class="form-select form-select-sm" :class="{ 'is-invalid': error('instructional_effect') }" :disabled="!editable"><option v-for="effect in effects" :key="effect" :value="effect">{{ label(effect) }}</option></select>
            <div v-if="error('instructional_effect')" class="invalid-feedback d-block">{{ error('instructional_effect') }}</div>
        </td>
        <td style="min-width: 13rem">
            <span v-if="error('approval') || error('id')" class="badge text-bg-danger d-block text-wrap mb-1" role="alert">{{ error('approval') || error('id') }}</span>
            <span v-for="warning in proposal.warnings" :key="warning" class="badge text-bg-warning d-block text-wrap mb-1">{{ warning }}</span>
            <span v-if="!proposal.warnings.length && !Object.keys(errors ?? {}).length" class="badge text-bg-success">Ready</span>
        </td>
    </tr>
</template>
