<script setup lang="ts">
import { computed } from 'vue';

type Observation = { state_fips: string; statement: string };
type Analysis = { observations: Observation[]; patterns: string[]; inference: string; limitation: string };
const props = withDefaults(defineProps<{ modelValue: Analysis; config: any; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: Analysis] }>();
const analysis = computed(() => props.modelValue);
const update = (changes: Partial<Analysis>) => emit('update:modelValue', { ...analysis.value, ...changes });
const updateObservation = (index: number, changes: Partial<Observation>) => update({ observations: analysis.value.observations.map((item, itemIndex) => itemIndex === index ? { ...item, ...changes } : { ...item }) });
const updatePattern = (index: number, value: string) => update({ patterns: analysis.value.patterns.map((item, itemIndex) => itemIndex === index ? value : item) });
const cautiousInference = computed(() => /\b(may|might|possible|could)\b/i.test(analysis.value.inference));
const checks = computed(() => ({
    observations: analysis.value.observations.length >= 2 && analysis.value.observations.every((item) => item.state_fips && item.statement.trim().length >= 8) && new Set(analysis.value.observations.map((item) => item.state_fips)).size === analysis.value.observations.length,
    patterns: analysis.value.patterns.length >= 2 && analysis.value.patterns.every((item) => item.trim().length >= 8),
    inference: analysis.value.inference.trim().length >= 8 && cautiousInference.value,
    limitation: analysis.value.limitation.trim().length >= 8,
}));
</script>

<template>
    <section class="evidence-builder" aria-labelledby="evidence-builder-heading">
        <h3 id="evidence-builder-heading">Build Your Evidence Organizer</h3>
        <p>Keep both maps above visible. Record what the data directly shows before writing a possible explanation.</p>
        <fieldset>
            <legend>1. Two direct map observations</legend>
            <div v-for="(observation, index) in analysis.observations" :key="index" class="evidence-row">
                <label><span>Observation {{ index + 1 }} location</span><select :value="observation.state_fips" class="form-select" :disabled="disabled" @change="updateObservation(index, { state_fips: ($event.target as HTMLSelectElement).value })"><option value="">Choose a labeled state</option><option v-for="choice in config.location_choices" :key="choice.state_fips" :value="choice.state_fips">{{ choice.label }}</option></select></label>
                <label><span>What does the population-density map directly show there?</span><textarea :value="observation.statement" class="form-control" rows="2" maxlength="500" :disabled="disabled" placeholder="Include the visible density value or legend band." @input="updateObservation(index, { statement: ($event.target as HTMLTextAreaElement).value })" /></label>
            </div>
        </fieldset>
        <fieldset><legend>2. Two settlement patterns</legend><label v-for="(pattern, index) in analysis.patterns" :key="index"><span>Pattern {{ index + 1 }}</span><textarea :value="pattern" class="form-control" rows="2" maxlength="500" :disabled="disabled" placeholder="Compare at least two visible places or areas." @input="updatePattern(index, ($event.target as HTMLTextAreaElement).value)" /></label></fieldset>
        <label><strong>3. One possible geographic influence</strong><textarea :value="analysis.inference" class="form-control" rows="2" maxlength="700" :disabled="disabled" placeholder="Use may, might, possible, or could because the maps do not prove cause." @input="update({ inference: ($event.target as HTMLTextAreaElement).value })" /></label>
        <label><strong>4. Why do these maps not prove one cause?</strong><textarea :value="analysis.limitation" class="form-control" rows="2" maxlength="700" :disabled="disabled" placeholder="Name additional evidence you would need." @input="update({ limitation: ($event.target as HTMLTextAreaElement).value })" /></label>
        <div class="analysis-checks" role="status" aria-live="polite"><strong>Organizer requirements</strong><ul><li :class="{ complete: checks.observations }">{{ checks.observations ? 'Complete:' : 'Needed:' }} Two observations from different labeled states</li><li :class="{ complete: checks.patterns }">{{ checks.patterns ? 'Complete:' : 'Needed:' }} Two comparison patterns</li><li :class="{ complete: checks.inference }">{{ checks.inference ? 'Complete:' : 'Needed:' }} One cautious inference</li><li :class="{ complete: checks.limitation }">{{ checks.limitation ? 'Complete:' : 'Needed:' }} One limitation or needed source</li></ul></div>
    </section>
</template>

<style scoped>
.evidence-builder{display:grid;gap:1rem;margin-top:1rem;padding:1rem;border:2px solid #b8cecc;border-radius:16px;background:#eef6f4}.evidence-builder h3,.evidence-builder p{margin:0}.evidence-builder fieldset{display:grid;gap:.75rem;border:0;padding:0;margin:0}.evidence-builder legend{font-weight:900}.evidence-builder label{display:grid;gap:.35rem}.evidence-row{display:grid;grid-template-columns:minmax(180px,.75fr) 2fr;gap:.75rem;padding:.8rem;background:#fff;border-radius:12px}.analysis-checks{background:#fff;padding:.8rem 1rem;border-radius:12px}.analysis-checks ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.35rem;padding:0;margin:.5rem 0 0;list-style:none}.analysis-checks li{color:#76531b;font-weight:800}.analysis-checks li.complete{color:#155443}@media(max-width:700px){.evidence-row{grid-template-columns:1fr}}
</style>
