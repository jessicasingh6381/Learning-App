<script setup lang="ts">
defineOptions({ name: 'CurriculumUnitComponentTree' });
defineProps<{ component: any }>();
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
</script>

<template>
    <li class="mb-2">
        <details v-if="component.descendants?.length">
            <summary><strong>{{ component.name }}</strong> <span class="badge text-bg-light border">{{ label(component.component_type) }}</span> <span class="small text-secondary">{{ component.descendants.length }} items</span></summary>
            <p v-if="component.description" class="small text-secondary mb-1 text-pre-wrap">{{ component.description }}</p>
            <ul class="mt-2"><CurriculumUnitComponentTree v-for="child in component.descendants" :key="child.id" :component="child" /></ul>
        </details>
        <template v-else><div><strong>{{ component.name }}</strong> <span class="badge text-bg-light border">{{ label(component.component_type) }}</span></div><p v-if="component.description" class="small text-secondary mb-1 text-pre-wrap">{{ component.description }}</p></template>
    </li>
</template>
