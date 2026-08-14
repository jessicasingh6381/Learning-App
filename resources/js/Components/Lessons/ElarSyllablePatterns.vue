<script setup lang="ts">
defineProps<{ patterns: any[] }>();
</script>

<template>
    <section class="pattern-grid" aria-label="Four syllable patterns">
        <article v-for="pattern in patterns" :key="pattern.id">
            <p class="pattern-name">{{ pattern.label }}</p>
            <div class="breakdown" :aria-label="`${pattern.example} broken into letters or syllables`">{{ pattern.breakdown }}</div>
            <strong>{{ pattern.example }}</strong>
            <p>{{ pattern.detail }}</p>
            <small>{{ pattern.vowel_clue }}</small>
        </article>
    </section>
</template>

<style scoped>
.pattern-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin:1.2rem 0}.pattern-grid article{padding:1rem;border:2px solid #c8d8ec;border-radius:16px;background:#fbfdff}.pattern-name{margin:0;color:#173b63;text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;font-weight:900}.breakdown{margin:.55rem 0;padding:.55rem;border-radius:10px;background:#e8f2ff;color:#173b63;font-size:1.35rem;font-weight:900;letter-spacing:.14em;text-align:center}.pattern-grid article>p:not(.pattern-name){margin:.45rem 0}.pattern-grid small{display:block;color:#35566f;font-weight:700}@media(max-width:650px){.pattern-grid{grid-template-columns:1fr}}
</style>
