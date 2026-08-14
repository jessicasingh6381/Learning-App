<script setup lang="ts">
const model = defineModel<any>({ required: true });
defineProps<{ config: any; disabled?: boolean }>();
</script>

<template>
    <section class="passport-builder">
        <div class="model-line"><strong>Writing model</strong><span lang="es">{{ config.writing_model }}</span><small>Copy the pattern, then choose phrases for your own greeting card.</small></div>
        <div class="selection-grid">
            <fieldset :disabled="disabled"><legend>Choose at least two greetings</legend><label v-for="phrase in config.greetings" :key="phrase.id"><input v-model="model.greetings" type="checkbox" :value="phrase.id"> <span lang="es">{{ phrase.spanish }}</span><small>{{ phrase.meaning }}</small></label></fieldset>
            <fieldset :disabled="disabled"><legend>Choose both farewells</legend><label v-for="phrase in config.farewells" :key="phrase.id"><input v-model="model.farewells" type="checkbox" :value="phrase.id"> <span lang="es">{{ phrase.spanish }}</span><small>{{ phrase.meaning }}</small></label></fieldset>
        </div>
        <label class="write-line"><strong>Type one short greeting-and-farewell line</strong><span>Use the model above. Keep it short.</span><input v-model="model.practice_line" class="form-control" :placeholder="config.writing_model" :disabled="disabled"></label>
        <label class="write-line"><strong>Why does one phrase fit its situation?</strong><span>You may explain in English.</span><textarea v-model="model.reason" class="form-control" rows="2" :disabled="disabled" /></label>
        <label class="self-check"><input v-model="model.speaking_self_check" type="checkbox" :disabled="disabled"> I listened first, practiced aloud, and chose the attempt I could say most clearly.</label>
        <section class="passport-preview"><p>MI PASAPORTE ESPAÑOL</p><h3>Saludos y despedidas</h3><div><span v-for="id in [...model.greetings, ...model.farewells]" :key="id">{{ [...config.greetings, ...config.farewells].find((phrase) => phrase.id === id)?.spanish }}</span></div><blockquote v-if="model.practice_line" lang="es">{{ model.practice_line }}</blockquote></section>
    </section>
</template>

<style scoped>
.passport-builder{display:grid;gap:1rem;margin-top:1rem}.model-line{display:grid;gap:.25rem;padding:1rem;border-radius:14px;background:#eef4ff;border:1px solid #bdccec}.model-line span{font-size:1.25rem;color:#433a91}.selection-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.selection-grid fieldset{padding:1rem;border:2px solid #c7ddd8;border-radius:14px}.selection-grid legend{float:none;width:auto;padding:0 .35rem;font-weight:900}.selection-grid label{display:grid;grid-template-columns:auto 1fr;gap:.1rem .5rem;align-items:center;padding:.5rem}.selection-grid small{grid-column:2;color:#526b73}.write-line{display:grid;gap:.35rem}.write-line span{color:#526b73;font-size:.88rem}.self-check{padding:1rem;border-radius:12px;background:#fff7dd;border-left:5px solid #efb84a}.passport-preview{padding:1.2rem;border-radius:16px;background:linear-gradient(145deg,#362d79,#5b50a7);color:#fff;text-align:center}.passport-preview p{font-size:.72rem;letter-spacing:.14em;font-weight:900}.passport-preview h3{font-size:1.2rem}.passport-preview div{display:flex;justify-content:center;flex-wrap:wrap;gap:.45rem}.passport-preview div span{padding:.35rem .6rem;border-radius:99px;background:#fff;color:#3b347c;font-weight:800}.passport-preview blockquote{margin:1rem 0 0;font-size:1.1rem}@media(max-width:650px){.selection-grid{grid-template-columns:1fr}}
</style>
