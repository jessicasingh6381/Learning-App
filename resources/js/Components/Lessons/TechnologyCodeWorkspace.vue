<script setup lang="ts">
import { computed, ref } from 'vue';
const model = defineModel<any>({ required: true });
const props = defineProps<{ config: any; disabled?: boolean }>();
const previewed = ref(false);
const parsed = computed(() => {
    const output: string[] = []; const unsupported: number[] = []; const values: Record<string, string | number> = {};
    String(model.value.source || '').split(/\r?\n/).forEach((line, index) => {
        if (!line.trim()) return;
        const input = line.match(/^\s*([A-Za-z_]\w*)\s*=\s*input\(\s*(["'])(.*?)\2\s*\)\s*$/);
        if (input) { values[input[1]] = model.value.inputs?.[input[1]] || `[response to: ${input[3]}]`; return; }
        const text = line.match(/^\s*([A-Za-z_]\w*)\s*=\s*(["'])(.*?)\2\s*$/);
        if (text) { values[text[1]] = text[3]; return; }
        const number = line.match(/^\s*([A-Za-z_]\w*)\s*=\s*(-?\d+(?:\.\d+)?)\s*$/);
        if (number) { values[number[1]] = Number(number[2]); return; }
        const printed = line.match(/^\s*print\((.*)\)\s*$/);
        if (printed) {
            const parts = printed[1].split(',').map((part) => part.trim());
            const rendered = parts.map((part) => {
                const quoted = part.match(/^(["'])(.*?)\1$/); if (quoted) return quoted[2];
                if (/^[A-Za-z_]\w*$/.test(part) && Object.hasOwn(values, part)) return String(values[part]);
                return null;
            });
            if (rendered.every((value) => value !== null)) output.push(rendered.join(' ')); else unsupported.push(index + 1);
            return;
        }
        unsupported.push(index + 1);
    });
    return { output, unsupported };
});
const reset = () => { if (!props.disabled) { model.value.source = props.config.starter_code; previewed.value = false; } };
</script>
<template>
    <section class="code-workspace">
        <div class="prediction"><label><strong>{{ config.prediction_label }}</strong><textarea v-model="model.prediction" class="form-control" rows="2" :disabled="disabled" /></label><small>Predictions are ideas to test, not graded answers.</small></div>
        <section v-if="config.input_fields?.length" class="simulated-inputs"><h3>Test responses</h3><p>Type the responses this lesson preview should use when it reaches each <code>input()</code> line.</p><label v-for="field in config.input_fields" :key="field.id"><strong>{{ field.label }}</strong><input v-model="model.inputs[field.id]" class="form-control" :placeholder="field.placeholder" :disabled="disabled"></label></section>
        <div class="editor-head"><div><strong>Mission code</strong><small>Autosaves with this lesson</small></div><button type="button" class="btn btn-sm btn-outline-light" :disabled="disabled" @click="reset">Reset starter code</button></div>
        <textarea v-model="model.source" class="code-editor" rows="10" spellcheck="false" aria-label="Mission Python code" :disabled="disabled" @input="previewed = false" />
        <button type="button" class="btn preview-button" :disabled="disabled" @click="previewed = true">Preview simple print statements</button>
        <div class="runtime-notice"><strong>Safe lesson preview—not Python execution</strong><span>This browser tool only interprets the lesson-approved assignments, <code>input()</code>, and <code>print()</code> lines. It never sends code to a Python process.</span></div>
        <section v-if="previewed" class="output" aria-live="polite"><strong>Output preview</strong><p v-if="parsed.unsupported.length" class="warning">Unsupported line{{ parsed.unsupported.length > 1 ? 's' : '' }}: {{ parsed.unsupported.join(', ') }}. Compare each line with the lesson examples.</p><pre v-else>{{ parsed.output.join('\n') }}</pre></section>
        <label class="reflection"><strong>{{ config.reflection_label }}</strong><textarea v-model="model.reflection" class="form-control" rows="3" :disabled="disabled" /></label>
    </section>
</template>
<style scoped>
.code-workspace{display:grid;gap:1rem}.prediction,.reflection,.simulated-inputs{display:grid;gap:.65rem;padding:1rem;border-radius:14px;background:#eef5ff;border:1px solid #b8cbea}.prediction label,.reflection,.simulated-inputs label{display:grid;gap:.4rem}.prediction small{color:#52657c}.simulated-inputs h3,.simulated-inputs p{margin:0}.simulated-inputs h3{font-size:1.05rem}.editor-head{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.8rem 1rem;background:#17233b;color:#fff;border-radius:14px 14px 0 0;margin-bottom:-1rem}.editor-head small{display:block;color:#c8d6ee}.code-editor{width:100%;padding:1rem;border:0;border-radius:0 0 14px 14px;background:#0d1729;color:#d9f4ff;font:500 .95rem/1.65 ui-monospace,SFMono-Regular,Consolas,monospace;tab-size:4;resize:vertical}.code-editor:focus{outline:4px solid #f0b84b;outline-offset:2px}.preview-button{justify-self:start;background:#4f46a5;color:#fff;font-weight:800}.runtime-notice{display:grid;gap:.2rem;padding:.85rem 1rem;border-left:5px solid #efb84a;background:#fff7dd;border-radius:8px}.runtime-notice span{font-size:.88rem}.output{padding:1rem;border-radius:12px;background:#06111f;color:#d7ffe5}.output pre{margin:.6rem 0 0;color:inherit;white-space:pre-wrap}.warning{margin:.6rem 0 0;color:#ffd5c9}@media(max-width:600px){.editor-head{align-items:flex-start;flex-direction:column}.code-editor{font-size:.82rem}}
</style>
