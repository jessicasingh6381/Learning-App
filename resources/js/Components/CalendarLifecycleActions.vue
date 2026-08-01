<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ calendar: { id: number; name: string; status: string }; lifecycle: any; compact?: boolean }>();
const confirmation = ref<'archive' | 'delete' | null>(null);
const archiveForm = useForm({});
const restoreForm = useForm({});
const deleteForm = useForm({ confirmation: '' });
const lifecycleError = computed(() => (archiveForm.errors as Record<string, string>).lifecycle
    || (restoreForm.errors as Record<string, string>).lifecycle
    || (deleteForm.errors as Record<string, string>).lifecycle);
const close = () => { confirmation.value = null; deleteForm.reset(); deleteForm.clearErrors(); };
const archive = () => archiveForm.patch(route('academic.calendars.archive', props.calendar.id), { onSuccess: close });
const restore = () => restoreForm.patch(route('academic.calendars.restore', props.calendar.id));
const deletePermanently = () => deleteForm.delete(route('academic.calendars.destroy', props.calendar.id), { onSuccess: close });
</script>

<template>
    <div v-if="lifecycle.can_manage" :class="compact ? 'small' : ''">
        <div class="d-flex flex-wrap gap-2">
            <button v-if="lifecycle.can_archive" type="button" class="btn btn-sm btn-outline-secondary" :disabled="archiveForm.processing" @click="confirmation = 'archive'">{{ archiveForm.processing ? 'Archiving…' : 'Archive' }}</button>
            <button v-if="lifecycle.can_restore" type="button" class="btn btn-sm btn-outline-primary" :disabled="restoreForm.processing" @click="restore">{{ restoreForm.processing ? 'Restoring…' : 'Restore' }}</button>
            <button v-if="lifecycle.can_delete" type="button" class="btn btn-sm btn-outline-danger" :disabled="deleteForm.processing" @click="confirmation = 'delete'">Delete permanently</button>
        </div>

        <div v-if="calendar.status !== 'archived' && lifecycle.archive_blockers.length" class="alert alert-warning py-2 mt-2 mb-0" role="status">
            <strong>Archive unavailable</strong><ul class="mb-0 ps-3"><li v-for="blocker in lifecycle.archive_blockers" :key="blocker">{{ blocker }}</li></ul>
        </div>
        <div v-if="!lifecycle.can_delete && lifecycle.deletion_blockers.length" class="alert alert-light border py-2 mt-2 mb-0" role="status">
            <strong>Permanent deletion unavailable</strong><ul class="mb-0 ps-3"><li v-for="blocker in lifecycle.deletion_blockers" :key="blocker">{{ blocker }}</li></ul>
        </div>
        <div v-if="lifecycleError" class="alert alert-danger mt-2" role="alert">{{ lifecycleError }}</div>

        <div v-if="confirmation" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" :aria-labelledby="confirmation === 'archive' ? 'archive-calendar-title' : 'delete-calendar-title'" @keydown.esc="close">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <template v-if="confirmation === 'archive'">
                    <div class="modal-header"><h2 id="archive-calendar-title" class="modal-title fs-5">Archive this Calendar Profile?</h2><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                    <div class="modal-body">It will be hidden from the default list but preserved for historical records. Calendar Events, source documents, and Academic Setup history will not be deleted.</div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" @click="close">Cancel</button><button type="button" class="btn btn-warning" :disabled="archiveForm.processing" @click="archive">{{ archiveForm.processing ? 'Archiving…' : 'Archive profile' }}</button></div>
                </template>
                <template v-else>
                    <div class="modal-header"><h2 id="delete-calendar-title" class="modal-title fs-5">Permanently delete this unused Calendar Profile?</h2><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                    <form @submit.prevent="deletePermanently"><div class="modal-body"><p>This cannot be undone. This action is available only because the profile has no events, linked sources, or Academic Setup history.</p><label for="calendar-delete-confirmation" class="form-label">Type DELETE to confirm</label><input id="calendar-delete-confirmation" v-model="deleteForm.confirmation" class="form-control" autocomplete="off" required pattern="DELETE" :aria-invalid="Boolean(deleteForm.errors.confirmation)"><div v-if="deleteForm.errors.confirmation" class="invalid-feedback d-block" role="alert">{{ deleteForm.errors.confirmation }}</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" @click="close">Cancel</button><button type="submit" class="btn btn-danger" :disabled="deleteForm.processing || deleteForm.confirmation !== 'DELETE'">{{ deleteForm.processing ? 'Deleting…' : 'Delete permanently' }}</button></div></form>
                </template>
            </div></div>
        </div>
        <div v-if="confirmation" class="modal-backdrop fade show" />
    </div>
</template>
