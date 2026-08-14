<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ config: any }>();
const lowerCapacity = computed(() => props.config.group_size * props.config.lower_groups);
const upperCapacity = computed(() => props.config.group_size * props.config.upper_groups);
const lowerPercent = computed(() => Math.min(100, Math.round((lowerCapacity.value / props.config.total) * 100)));
const upperPercent = computed(() => Math.min(100, Math.round((props.config.total / upperCapacity.value) * 100)));
const reveal = computed(() => props.config.reveal || 'both');
const singularGroup = computed(() => props.config.singular_group_unit || (props.config.group_unit === 'buses' ? 'bus' : props.config.group_unit.replace(/s$/, '')));
</script>

<template>
    <section v-if="config.mode === 'routine'" class="routine" aria-label="Five-part problem-solving routine">
        <div><b>1</b><strong>Analyze</strong><span>known, asked, quantities, units</span></div><i>→</i>
        <div><b>2</b><strong>Plan</strong><span>operation, model, or tool</span></div><i>→</i>
        <div><b>3</b><strong>Solve</strong><span>organized calculations</span></div><i>→</i>
        <div><b>4</b><strong>Justify</strong><span>answer in context with units</span></div><i>→</i>
        <div><b>5</b><strong>Check</strong><span>estimate or compare with the situation</span></div>
    </section>
    <section v-else-if="config.mode === 'concept_cards'" class="concept-cards" :aria-label="config.aria_label || 'Math concept guide'">
        <article v-for="card in config.cards" :key="card.label"><strong>{{ card.label }}</strong><p>{{ card.detail }}</p><small v-if="card.example">{{ card.example }}</small></article>
    </section>
    <figure v-else-if="config.mode === 'equal_share'" class="equal-share" aria-label="Equal-share bar model">
        <figcaption><strong>{{ config.total_label }}:</strong> {{ config.total }} · <strong>Shared among:</strong> {{ config.groups }} {{ config.group_unit }}</figcaption>
        <div class="share-bar"><span v-for="group in config.groups" :key="group"><b v-if="config.per_group">{{ config.per_group }}</b><b v-else>?</b><small>{{ config.item_unit }}</small></span></div>
        <p v-if="config.caption">{{ config.caption }}</p>
    </figure>
    <section v-else-if="config.mode === 'equation_steps'" class="equation-steps" aria-label="Connected equation steps">
        <div v-for="(step, index) in config.steps" :key="step.equation"><b>{{ index + 1 }}</b><p><strong>{{ step.equation }}</strong><span>{{ step.meaning }}</span></p></div>
    </section>
    <figure v-else-if="config.mode === 'capacity'" class="capacity" aria-label="Capacity comparison model">
        <figcaption><strong>Target:</strong> {{ config.total }} {{ config.item_unit }} · <strong>Each {{ singularGroup }}:</strong> {{ config.group_size }} {{ config.item_unit }}</figcaption>
        <div v-if="reveal === 'groups'" class="grouping" aria-label="Equal grouping model">
            <span v-for="group in config.lower_groups" :key="group"><b>{{ config.group_size }}</b><small>{{ config.item_unit }}</small></span>
            <span class="question-group"><b>?</b><small>another {{ singularGroup }}?</small></span>
        </div>
        <p v-if="['setup', 'groups'].includes(reveal)" class="visual-prompt">Build and compare whole groups. The totals stay hidden until you calculate them.</p>
        <div v-if="['lower', 'both'].includes(reveal)" class="bound"><strong>{{ config.lower_groups }} {{ config.group_unit }} = {{ lowerCapacity }} spaces</strong><div class="track"><span class="lower" :style="{ width: `${lowerPercent}%` }" /></div><p>{{ lowerCapacity }} &lt; {{ config.total }} — short by {{ config.total - lowerCapacity }} {{ config.item_unit }}</p></div>
        <div v-if="reveal === 'both'" class="bound"><strong>{{ config.upper_groups }} {{ config.group_unit }} = {{ upperCapacity }} spaces</strong><div class="track"><span class="upper" :style="{ width: `${upperPercent}%` }" /></div><p>{{ upperCapacity }} ≥ {{ config.total }} — enough capacity</p></div>
    </figure>
</template>

<style scoped>
.routine,.capacity,.concept-cards,.equal-share,.equation-steps{margin:1rem 0;padding:1rem;border:2px solid #b8d2e8;border-radius:16px;background:#f6faff}.routine{display:flex;align-items:stretch;gap:.45rem;overflow-x:auto}.routine div{min-width:130px;display:grid;gap:.3rem;padding:.8rem;border-radius:12px;background:#fff}.routine b,.equation-steps b{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#255f8f;color:#fff}.routine i{align-self:center;color:#255f8f;font-size:1.4rem}.routine span,.capacity p,.equal-share p{font-size:.85rem;color:#486477}.concept-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem}.concept-cards article{padding:.85rem;border-radius:12px;background:#fff}.concept-cards p{margin:.35rem 0;color:#486477}.concept-cards small{display:block;padding-top:.35rem;border-top:1px solid #d9e5ee;color:#31566f}.equal-share{display:grid;gap:.8rem}.equal-share figcaption{font-size:1.05rem}.share-bar{display:flex;min-height:78px;border:3px solid #255f8f;border-radius:12px;overflow:hidden}.share-bar span{flex:1;display:grid;place-items:center;align-content:center;min-width:38px;background:#fff;border-right:1px solid #8baac2}.share-bar span:last-child{border-right:0}.share-bar small{font-size:.7rem;color:#486477}.equation-steps{display:grid;gap:.7rem}.equation-steps div{display:flex;align-items:center;gap:.7rem;padding:.75rem;border-radius:12px;background:#fff}.equation-steps p{display:grid;margin:0}.equation-steps span{font-size:.85rem;color:#486477}.capacity{display:grid;gap:.8rem}.capacity figcaption{font-size:1.05rem}.bound{padding:.8rem;border-radius:12px;background:#fff}.bound p{margin:.4rem 0 0}.track{height:24px;border-radius:99px;background:#e4ebef;overflow:hidden}.track span{display:block;height:100%}.lower{background:#e07a5f}.upper{background:#2f917f}.grouping{display:flex;flex-wrap:wrap;gap:.55rem}.grouping span{display:grid;place-items:center;min-width:68px;padding:.65rem;border:2px solid #8baac2;border-radius:10px;background:#fff}.grouping small{font-size:.7rem;color:#486477}.grouping .question-group{border-style:dashed;background:#fff9e8}.visual-prompt{margin:0;font-weight:700}@media(max-width:700px){.routine{display:grid}.routine i{transform:rotate(90deg);justify-self:center}.share-bar{overflow-x:auto}.share-bar span{min-width:44px}}
</style>
