<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    show?: boolean;
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    closeable?: boolean;
}>(), {
    show: false,
    maxWidth: '2xl',
    closeable: true,
});

const emit = defineEmits(['close']);
const dialog = ref<HTMLDialogElement | null>(null);
const widths = { sm: '380px', md: '500px', lg: '680px', xl: '820px', '2xl': '960px' };
const width = computed(() => widths[props.maxWidth]);

watch(() => props.show, (show) => {
    if (show && !dialog.value?.open) dialog.value?.showModal();
    if (!show && dialog.value?.open) dialog.value?.close();
});

const requestClose = () => {
    if (props.closeable) emit('close');
};

onUnmounted(() => dialog.value?.close());
</script>

<template>
    <dialog
        ref="dialog"
        class="native-modal border-0 rounded-3 shadow-lg p-0"
        :style="{ width, maxWidth: 'calc(100vw - 2rem)' }"
        @cancel.prevent="requestClose"
        @click.self="requestClose"
    >
        <slot v-if="show" />
    </dialog>
</template>
