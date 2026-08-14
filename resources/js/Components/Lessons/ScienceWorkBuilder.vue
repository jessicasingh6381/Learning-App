<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{ modelValue: Record<string, string>; config: any; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();
const update = (id: string, value: string) => emit('update:modelValue', { ...props.modelValue, [id]: value });
const elapsedSeconds = ref(0);
const timerRunning = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;
const timerLabel = computed(() => `${Math.floor(elapsedSeconds.value / 60).toString().padStart(2, '0')}:${(elapsedSeconds.value % 60).toString().padStart(2, '0')}`);
const toggleTimer = () => {
    if (props.disabled) return;
    timerRunning.value = !timerRunning.value;
    if (timerRunning.value) timer = setInterval(() => elapsedSeconds.value++, 1000);
    else if (timer) clearInterval(timer);
};
const resetTimer = () => { if (timer) clearInterval(timer); timerRunning.value = false; elapsedSeconds.value = 0; };
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<template>
    <section class="science-work" :aria-label="`${config.kind.replaceAll('_', ' ')} structured work`">
        <section v-if="config.kind === 'water_cycle_model'" class="cycle-preview" aria-label="Live labeled water-cycle model">
            <strong>The Sun</strong><span>supplies energy</span><strong>Ocean / collection</strong>
            <span>↑ {{ modelValue.upward_arrow || 'label 1' }}</span><strong>Water vapor</strong>
            <span>→ {{ modelValue.cloud_droplets || 'label 2' }}</span><strong>Cloud droplets</strong>
            <span>↓ {{ modelValue.downward_water || 'label 3' }}</span><strong>{{ modelValue.stored_water || 'label 4' }}</strong>
        </section>
        <section v-if="['covered_bowl', 'evaporation_observation'].includes(config.kind)" class="observation-timer" aria-label="Observation timer">
            <div><strong>In-app observation timer</strong><span aria-live="polite">{{ timerLabel }}</span></div>
            <button type="button" :disabled="disabled" @click="toggleTimer">{{ timerRunning ? 'Pause timer' : 'Start timer' }}</button>
            <button type="button" :disabled="disabled" @click="resetTimer">Reset timer</button>
        </section>
        <section v-for="section in config.sections" :key="section.title" class="work-section">
            <h3>{{ section.title }}</h3>
            <div class="field-grid">
                <label v-for="field in section.fields" :key="field.id">
                    <strong>{{ field.label }}</strong>
                    <select v-if="field.control === 'select'" :value="modelValue[field.id]" :disabled="disabled" @change="update(field.id, ($event.target as HTMLSelectElement).value)">
                        <option value="">Choose an answer</option>
                        <option v-for="choice in field.choices" :key="choice.id" :value="choice.id">{{ choice.label }}</option>
                    </select>
                    <input v-else-if="field.control === 'text'" :value="modelValue[field.id]" :disabled="disabled" maxlength="2000" @input="update(field.id, ($event.target as HTMLInputElement).value)">
                    <textarea v-else :value="modelValue[field.id]" :disabled="disabled" rows="3" maxlength="2000" @input="update(field.id, ($event.target as HTMLTextAreaElement).value)" />
                </label>
            </div>
        </section>
    </section>
</template>

<style scoped>
.science-work{display:grid;gap:1rem;margin:1rem 0}.work-section,.cycle-preview,.observation-timer{border:2px solid #bfd8d3;border-radius:14px;padding:1rem;background:#f7fbfa}.work-section h3{margin:0 0 .8rem}.field-grid{display:grid;gap:1rem}.field-grid label{display:grid;gap:.4rem}.field-grid select,.field-grid input,.field-grid textarea{width:100%;padding:.65rem;border:1px solid #91aaa9;border-radius:8px;background:#fff}.cycle-preview{display:flex;flex-wrap:wrap;align-items:center;gap:.55rem;justify-content:center}.cycle-preview strong{padding:.65rem .8rem;background:#dff3ef;border-radius:10px}.cycle-preview span{color:#31505e;font-weight:700}.observation-timer{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.observation-timer div{display:grid;margin-right:auto}.observation-timer span{font-size:1.5rem;font-variant-numeric:tabular-nums}.observation-timer button{padding:.5rem .75rem;border:1px solid #317e72;border-radius:8px;background:#fff;color:#185b62;font-weight:800}
</style>
