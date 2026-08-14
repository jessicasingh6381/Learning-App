<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{ config: any }>();
const speakingId = ref<string | null>(null);
const message = ref('');
const supported = computed(() => typeof window !== 'undefined' && 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window);

const play = (phrase: any) => {
    if (!supported.value) { message.value = 'Audio is not available in this browser. Use the visible phrase and pronunciation guide.'; return; }
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(phrase.spanish);
    utterance.lang = props.config.language || 'es-MX';
    utterance.rate = props.config.rate || 0.78;
    utterance.onstart = () => { speakingId.value = phrase.id; message.value = `Playing ${phrase.label || 'Spanish phrase'}.`; };
    utterance.onend = () => { speakingId.value = null; message.value = 'Ready to replay.'; };
    utterance.onerror = () => { speakingId.value = null; message.value = 'Audio could not play. Use the visible phrase and pronunciation guide.'; };
    window.speechSynthesis.speak(utterance);
};

onBeforeUnmount(() => { if (supported.value) window.speechSynthesis.cancel(); });
</script>

<template>
    <section class="phrase-player" aria-label="Spanish listening and pronunciation practice">
        <div class="phrase-grid">
            <article v-for="phrase in config.phrases" :key="phrase.id" class="phrase-card">
                <span v-if="!config.hide_visual" class="visual" aria-hidden="true">{{ phrase.visual }}</span>
                <div v-if="!config.hide_text" class="phrase-copy">
                    <strong lang="es">{{ phrase.spanish }}</strong>
                    <span>{{ phrase.meaning }}</span>
                    <small v-if="phrase.use">{{ phrase.use }}</small>
                    <small v-if="phrase.pronunciation_aid" class="pronunciation">Try it: {{ phrase.pronunciation_aid }}</small>
                </div>
                <button type="button" class="listen-button" :aria-label="`Play ${phrase.label || 'Spanish phrase'}`" @click="play(phrase)">
                    {{ speakingId === phrase.id ? 'Playing…' : '▶ Listen / replay' }}
                </button>
            </article>
        </div>
        <p class="speech-note">Listen first, then repeat when you are ready. Replay as often as you want. Learning-App does not record or score your voice.</p>
        <p class="audio-status" aria-live="polite">{{ message }}</p>
    </section>
</template>

<style scoped>
.phrase-player{display:grid;gap:.8rem;margin:1rem 0}.phrase-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.85rem}.phrase-card{display:grid;grid-template-columns:auto 1fr;gap:.7rem;align-items:start;padding:1rem;border:2px solid #c7ddd8;border-radius:16px;background:#fbfffe}.visual{font-size:2rem}.phrase-copy{display:grid;gap:.18rem}.phrase-copy strong{font-size:1.22rem;color:#0f5d63}.phrase-copy span{font-weight:700}.phrase-copy small{color:#526b73}.pronunciation{margin-top:.25rem;padding:.3rem .45rem;border-radius:6px;background:#eef4ff;color:#334a76!important}.listen-button{grid-column:1/-1;border:0;border-radius:10px;padding:.6rem .8rem;background:#4f46a5;color:#fff;font-weight:800}.listen-button:hover,.listen-button:focus{background:#37308a}.speech-note{margin:0;padding:.75rem 1rem;border-left:5px solid #efb84a;border-radius:8px;background:#fff7dd}.audio-status{min-height:1.2em;margin:0;color:#405c66;font-size:.85rem;font-weight:700}
</style>
