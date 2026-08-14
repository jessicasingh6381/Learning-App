<script setup lang="ts">
const props = defineProps<{ modelValue: Record<string, string>; config: any; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();
const update = (id: string, value: string) => emit('update:modelValue', { ...props.modelValue, [id]: value });
</script>

<template>
    <section class="math-work" aria-label="Digital five-part Math organizer">
        <section v-for="section in config.sections" :key="section.title" class="organizer-part">
            <h3>{{ section.title }}</h3>
            <div class="fields">
                <label v-for="field in section.fields" :key="field.id">
                    <strong>{{ field.label }}</strong>
                    <select v-if="field.control === 'select'" :value="modelValue[field.id]" :disabled="disabled" @change="update(field.id, ($event.target as HTMLSelectElement).value)">
                        <option value="">Choose an answer</option><option v-for="choice in field.choices" :key="choice.id" :value="choice.id">{{ choice.label }}</option>
                    </select>
                    <input v-else-if="field.control === 'number'" type="number" inputmode="numeric" step="1" :value="modelValue[field.id]" :disabled="disabled" @input="update(field.id, ($event.target as HTMLInputElement).value)">
                    <input v-else-if="field.control === 'text'" type="text" :value="modelValue[field.id]" :disabled="disabled" maxlength="2000" @input="update(field.id, ($event.target as HTMLInputElement).value)">
                    <textarea v-else rows="3" maxlength="2000" :value="modelValue[field.id]" :disabled="disabled" @input="update(field.id, ($event.target as HTMLTextAreaElement).value)" />
                </label>
            </div>
        </section>
    </section>
</template>

<style scoped>
.math-work{display:grid;gap:1rem;margin:1rem 0}.organizer-part{border:2px solid #b8d2e8;border-radius:14px;padding:1rem;background:#f6faff}.organizer-part h3{margin:0 0 .8rem;color:#234f75}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}.fields label{display:grid;align-content:start;gap:.4rem}.fields select,.fields input,.fields textarea{width:100%;padding:.65rem;border:1px solid #8baac2;border-radius:8px;background:#fff}.fields textarea{grid-column:1/-1}
</style>
