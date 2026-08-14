<script setup lang="ts">
defineProps<{ passage: any; focusSentenceIds?: string[] }>();
</script>

<template>
    <section class="reading-passage" aria-label="Lesson reading passage">
        <header><div><p>Reading passage</p><h3>{{ passage.title }}</h3></div><span>{{ passage.source_label }}</span></header>
        <p class="source-note">{{ passage.source_note }}</p>
        <div class="passage-copy"><p v-for="paragraph in passage.paragraphs" :key="paragraph.number"><b aria-hidden="true">{{ paragraph.number }}</b><span v-for="sentence in paragraph.sentences" :key="sentence.id" :class="{ focused: focusSentenceIds?.includes(sentence.id) }">{{ sentence.text }} </span></p></div>
    </section>
</template>

<style scoped>
.reading-passage{margin:1.2rem 0;padding:clamp(1rem,3vw,1.6rem);border:2px solid #c8d8ec;border-radius:18px;background:#fbfdff}.reading-passage header{display:flex;justify-content:space-between;align-items:start;gap:1rem;border-bottom:1px solid #dce6f2;padding-bottom:.8rem}.reading-passage header p{margin:0;color:#526880;text-transform:uppercase;letter-spacing:.1em;font-size:.72rem;font-weight:900}.reading-passage h3{margin:.2rem 0 0;font-family:Georgia,serif;font-size:1.35rem}.reading-passage header span{max-width:220px;padding:.35rem .55rem;border-radius:999px;background:#e6f2ed;color:#225647;font-size:.72rem;font-weight:800;text-align:center}.source-note{margin:.8rem 0;color:#5b6b7c;font-size:.82rem}.passage-copy{max-width:68ch;font-family:Georgia,serif;font-size:1.06rem;line-height:1.8}.passage-copy p{margin:0 0 1rem}.passage-copy b{display:inline-grid;place-items:center;width:1.65rem;height:1.65rem;margin-right:.45rem;border-radius:50%;background:#173b63;color:#fff;font-family:Inter,system-ui,sans-serif;font-size:.75rem}.passage-copy span.focused{background:#fff0a8;box-shadow:0 0 0 2px #fff0a8}@media(max-width:600px){.reading-passage header{flex-direction:column}.reading-passage header span{max-width:none}}
</style>
