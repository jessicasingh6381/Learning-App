<script setup lang="ts">
const model = defineModel<Record<string, any>>({ required: true });
defineProps<{ config: any; disabled?: boolean }>();
</script>

<template>
    <section class="language-work-builder">
        <div v-if="config.model" class="language-model"><strong>{{ config.model_label || 'Model first' }}</strong><span lang="es">{{ config.model }}</span><small v-if="config.model_support">{{ config.model_support }}</small></div>
        <template v-for="field in config.fields" :key="field.id">
            <fieldset v-if="field.control === 'select'" :disabled="disabled"><legend>{{ field.label }}</legend><small v-if="field.help">{{ field.help }}</small><label v-for="choice in field.choices" :key="choice.id" class="choice"><input v-model="model[field.id]" type="radio" :value="choice.id"><span lang="es">{{ choice.label }}</span><small v-if="choice.support">{{ choice.support }}</small></label></fieldset>
            <fieldset v-else-if="field.control === 'multi_select'" :disabled="disabled"><legend>{{ field.label }}</legend><small v-if="field.help">{{ field.help }}</small><label v-for="choice in field.choices" :key="choice.id" class="choice"><input v-model="model[field.id]" type="checkbox" :value="choice.id"><span lang="es">{{ choice.label }}</span><small v-if="choice.support">{{ choice.support }}</small></label></fieldset>
            <label v-else-if="field.control === 'checkbox'" class="self-check"><input v-model="model[field.id]" type="checkbox" :disabled="disabled"> {{ field.label }}<small v-if="field.help">{{ field.help }}</small></label>
            <label v-else class="written-field"><strong>{{ field.label }}</strong><small v-if="field.help">{{ field.help }}</small><textarea v-if="field.control === 'textarea'" v-model="model[field.id]" class="form-control" rows="2" :placeholder="field.placeholder" :disabled="disabled" /><input v-else v-model="model[field.id]" class="form-control" :placeholder="field.placeholder" :disabled="disabled"></label>
        </template>
        <section v-if="config.preview_title" class="work-preview"><p>{{ config.preview_eyebrow || 'MI PASAPORTE ESPAÑOL' }}</p><h3>{{ config.preview_title }}</h3><div v-for="field in config.fields.filter((item: any) => item.preview !== false)" :key="field.id"><strong>{{ field.preview_label || field.label }}</strong><span v-if="Array.isArray(model[field.id])">{{ model[field.id].map((id: string) => field.choices?.find((choice: any) => choice.id === id)?.label || id).join(' · ') }}</span><span v-else-if="field.control !== 'checkbox'" lang="es">{{ model[field.id] }}</span></div></section>
    </section>
</template>

<style scoped>
.language-work-builder{display:grid;gap:1rem;margin-top:1rem}.language-model{display:grid;gap:.3rem;padding:1rem;border-radius:14px;background:#eef4ff;border:1px solid #bdccec}.language-model span{font-size:1.25rem;color:#433a91;font-weight:800}.language-model small,.written-field small,fieldset>small{color:#526b73}.language-work-builder fieldset{display:grid;gap:.55rem;padding:1rem;border:2px solid #c7ddd8;border-radius:14px}.language-work-builder legend{float:none;width:auto;padding:0 .35rem;font-weight:900}.choice{display:grid;grid-template-columns:auto 1fr;gap:.1rem .55rem;align-items:center;padding:.65rem;border-radius:10px;background:#f8fbfa}.choice small{grid-column:2;color:#526b73}.written-field{display:grid;gap:.35rem}.self-check{display:grid;grid-template-columns:auto 1fr;gap:.2rem .55rem;padding:1rem;border-radius:12px;background:#fff7dd;border-left:5px solid #efb84a}.self-check small{grid-column:2;color:#526b73}.work-preview{display:grid;gap:.7rem;padding:1.2rem;border-radius:16px;background:linear-gradient(145deg,#362d79,#5b50a7);color:#fff}.work-preview>p{margin:0;font-size:.72rem;letter-spacing:.14em;font-weight:900}.work-preview h3{margin:0}.work-preview div{display:grid;gap:.15rem;padding:.65rem;border-radius:10px;background:#ffffff18}.work-preview span{font-size:1.05rem}@media(min-width:700px){.language-work-builder fieldset{grid-template-columns:repeat(2,minmax(0,1fr))}.language-work-builder legend,.language-work-builder fieldset>small{grid-column:1/-1}}
</style>
