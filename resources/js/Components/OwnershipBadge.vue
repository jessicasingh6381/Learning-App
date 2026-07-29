<script setup lang="ts">
defineProps<{ shared: boolean }>();
</script>

<template>
    <span class="badge" :class="shared ? 'text-bg-info' : 'text-bg-secondary'">
        {{ shared ? 'Platform shared' : 'Tenant custom' }}
    </span>
</template>
