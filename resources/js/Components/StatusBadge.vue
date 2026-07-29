<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    status: string;
}>();

const label = computed(() => props.status.replaceAll('_', ' '));

const color = computed(() => {
    if (props.status === 'active' || props.status === 'completed') {
        return 'text-bg-success';
    }

    if (props.status === 'inactive' || props.status === 'closed' || props.status === 'archived') {
        return 'text-bg-secondary';
    }

    if (props.status === 'withdrawn') {
        return 'text-bg-danger';
    }

    return 'text-bg-primary';
});
</script>

<template>
    <span class="badge status-badge text-capitalize" :class="color" :aria-label="`Status: ${label}`">
        {{ label }}
    </span>
</template>
