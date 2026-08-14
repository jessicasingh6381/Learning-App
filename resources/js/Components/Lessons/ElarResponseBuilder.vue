<script setup lang="ts">
import { computed } from 'vue';
import ElarReadingPassage from './ElarReadingPassage.vue';
const model = defineModel<any>({ required: true });
const props = defineProps<{ config: any; passage?: any; disabled?: boolean }>();
const selectedEvidence = computed(() => (props.config.fields || [])
    .filter((field: any) => field.control === 'evidence_select' && model.value[field.id])
    .map((field: any) => ({ field: field.id, label: field.label, choice: (field.choices || []).find((choice: any) => choice.id === model.value[field.id]) }))
    .filter((item: any) => item.choice));
</script>

<template>
    <div class="elar-workspace">
        <ElarReadingPassage v-if="passage" :passage="passage" />
        <section class="response-builder"><h3>My reading evidence</h3>
            <aside v-if="selectedEvidence.length" class="evidence-tray" aria-live="polite"><strong>Evidence saved with this work</strong><p v-for="item in selectedEvidence" :key="item.field"><span>Paragraph {{ item.choice.paragraph }}</span>{{ item.choice.text }}</p></aside>
            <template v-for="field in config.fields" :key="field.id">
            <fieldset v-if="field.control === 'evidence_select'" :disabled="disabled"><legend>{{ field.label }}</legend><label v-for="choice in field.choices" :key="choice.id"><input v-model="model[field.id]" type="radio" :value="choice.id"><span><b>Paragraph {{ choice.paragraph }}</b>{{ choice.text }}</span></label></fieldset>
            <label v-else class="field"><strong>{{ field.label }}</strong><select v-if="field.control === 'select'" v-model="model[field.id]" class="form-select" :disabled="disabled"><option value="">Choose an answer</option><option v-for="choice in field.choices" :key="choice.id" :value="choice.id">{{ choice.label }}</option></select><textarea v-else v-model="model[field.id]" class="form-control" rows="4" :disabled="disabled" /></label>
        </template></section>
    </div>
</template>

<style scoped>
.elar-workspace{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:1rem;align-items:start}.response-builder{position:sticky;top:1rem;padding:1rem;border-radius:16px;background:#f4f8fc;border:2px solid #c8d8ec}.response-builder h3{font-size:1.05rem}.evidence-tray{padding:.8rem;border-radius:12px;background:#e3f1ec;border:1px solid #9bc8ba}.evidence-tray p{margin:.6rem 0 0;font-size:.86rem}.evidence-tray span{display:block;color:#41665d;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.response-builder fieldset{border:0;padding:0}.response-builder legend,.field strong{font-weight:800;font-size:.94rem}.response-builder fieldset label{display:flex;gap:.55rem;margin:.6rem 0;padding:.7rem;border:1px solid #c8d8ec;border-radius:10px;background:#fff}.response-builder fieldset b{display:block;font-size:.72rem;color:#526880}.field{display:grid;gap:.4rem;margin-top:1rem}@media(max-width:850px){.elar-workspace{grid-template-columns:1fr}.response-builder{position:static}}
</style>
