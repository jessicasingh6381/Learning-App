<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-danger small mb-0">
            {{ message }}
        </p>
    </div>
</template>
