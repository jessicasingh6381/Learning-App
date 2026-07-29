<script setup lang="ts">
import { formatDateOnly } from '@/Support/dateOnly';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
const props = defineProps<{ calendarId: number; event: any; editable: boolean }>();
const editing = ref(false);
const form = useForm({
    event_date: props.event.event_date, end_date: props.event.end_date,
    event_type: props.event.event_type, name: props.event.name,
    instructional_effect: props.event.instructional_effect, status: props.event.status,
    notes: props.event.notes, source_reference: props.event.source_reference,
});
const save = () => form.patch(route('academic.calendars.events.update', [props.calendarId, props.event.id]), {
    preserveScroll: true,
    onSuccess: () => { editing.value = false; },
});
const archive = () => {
    if (window.confirm(`Archive ${props.event.name}? The historical record will remain.`)) {
        form.status = 'archived'; save();
    }
};
</script>
<template>
    <tr v-if="editing">
        <td><label :for="`event-name-${event.id}`" class="visually-hidden">Event name</label><input :id="`event-name-${event.id}`" v-model="form.name" class="form-control form-control-sm" required></td>
        <td><div class="d-flex gap-1"><label :for="`event-start-${event.id}`" class="visually-hidden">Start date</label><input :id="`event-start-${event.id}`" v-model="form.event_date" type="date" class="form-control form-control-sm"><label :for="`event-end-${event.id}`" class="visually-hidden">End date</label><input :id="`event-end-${event.id}`" v-model="form.end_date" type="date" class="form-control form-control-sm"></div></td>
        <td><label :for="`event-effect-${event.id}`" class="visually-hidden">Instructional effect</label><select :id="`event-effect-${event.id}`" v-model="form.instructional_effect" class="form-select form-select-sm"><option value="non_instructional">Non-instructional</option><option value="instructional">Instructional override</option><option value="informational">Informational</option></select></td>
        <td><span class="badge text-bg-success">active</span></td>
        <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-primary me-1" :disabled="form.processing" @click="save">Save</button><button type="button" class="btn btn-sm btn-outline-secondary" @click="editing = false">Cancel</button><div v-if="form.errors.end_date || form.errors.name" class="invalid-feedback d-block">{{ form.errors.end_date || form.errors.name }}</div></td>
    </tr>
    <tr>
        <td><strong>{{ event.name }}</strong><small class="d-block text-secondary">{{ event.event_type.replaceAll('_', ' ') }}</small></td>
        <td>{{ formatDateOnly(event.event_date) }}<span v-if="event.end_date"> – {{ formatDateOnly(event.end_date) }}</span></td>
        <td class="text-capitalize">{{ event.instructional_effect.replaceAll('_', ' ') }}</td>
        <td><span class="badge" :class="event.status === 'active' ? 'text-bg-success' : 'text-bg-secondary'">{{ event.status }}</span></td>
        <td class="text-end text-nowrap"><button v-if="editable && event.status === 'active'" type="button" class="btn btn-sm btn-outline-secondary me-1" @click="editing = true">Edit</button><button v-if="editable && event.status === 'active'" type="button" class="btn btn-sm btn-outline-danger" @click="archive">Archive</button></td>
    </tr>
</template>
