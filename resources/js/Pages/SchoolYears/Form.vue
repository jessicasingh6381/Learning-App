<script setup lang="ts">
import InstructionalScheduleFields from '@/Components/InstructionalScheduleFields.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface SchoolYear {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    timezone: string;
    status: string;
    instructional_week_type: string;
    instructional_weekdays: number[];
    base_instructional_days: number;
    instructional_day_target: number | null;
}

interface Defaults {
    timezone: string;
    instructional_week_type: string;
    instructional_weekdays: number[];
}

const props = defineProps<{
    schoolYear?: SchoolYear;
    defaults?: Defaults;
}>();

const form = useForm({
    name: props.schoolYear?.name ?? '',
    start_date: props.schoolYear?.start_date ?? '',
    end_date: props.schoolYear?.end_date ?? '',
    timezone:
        props.schoolYear?.timezone ??
        props.defaults?.timezone ??
        'America/Chicago',
    status: props.schoolYear?.status ?? 'draft',
    instructional_week_type:
        props.schoolYear?.instructional_week_type ??
        props.defaults?.instructional_week_type ??
        'five_day',
    instructional_weekdays: [
        ...(props.schoolYear?.instructional_weekdays ??
            props.defaults?.instructional_weekdays ??
            [1, 2, 3, 4, 5]),
    ],
    instructional_day_target:
        props.schoolYear?.instructional_day_target ?? null,
});

const submit = () => {
    const statusChanged =
        props.schoolYear && form.status !== props.schoolYear.status;

    if (
        statusChanged &&
        !confirm(
            `Change this school year from ${props.schoolYear?.status} to ${form.status}?`,
        )
    ) {
        return;
    }

    if (
        form.status === 'active' &&
        !confirm(
            'Activate this school year? Any other active year in this tenant will be closed.',
        )
    ) {
        return;
    }

    if (props.schoolYear) {
        form.patch(route('school-years.update', props.schoolYear.id));
    } else {
        form.post(route('school-years.store'));
    }
};
</script>
<template>
    <Head :title="schoolYear ? 'Edit school year' : 'Add school year'" />
    <AuthenticatedLayout>
        <h1 class="h2">
            {{ schoolYear ? 'Edit school year' : 'Add school year' }}
        </h1>
        <div class="alert alert-info mt-3">
            Activating this record transactionally closes any currently active
            school year.
        </div>
        <div class="card">
            <form class="card-body" @submit.prevent="submit">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="year_name">Name</label>
                        <input
                            id="year_name"
                            v-model="form.name"
                            class="form-control"
                            placeholder="2026–2027"
                            required
                        />
                        <div class="text-danger small">
                            {{ form.errors.name }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="start_date">
                            Start date
                        </label>
                        <input
                            id="start_date"
                            v-model="form.start_date"
                            type="date"
                            class="form-control"
                            required
                        />
                        <div class="text-danger small">
                            {{ form.errors.start_date }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="end_date">
                            End date
                        </label>
                        <input
                            id="end_date"
                            v-model="form.end_date"
                            type="date"
                            class="form-control"
                            required
                        />
                        <div class="text-danger small">
                            {{ form.errors.end_date }}
                        </div>
                    </div>
                </div>

                <hr class="my-4" />

                <InstructionalScheduleFields
                    v-model:schedule-type="form.instructional_week_type"
                    v-model:weekdays="form.instructional_weekdays"
                    :type-error="form.errors.instructional_week_type"
                    :weekdays-error="form.errors.instructional_weekdays"
                />

                <div class="row g-3 mt-2">
                    <div class="col-md-4">
                        <label class="form-label" for="year_status">
                            Status
                        </label>
                        <select
                            id="year_status"
                            v-model="form.status"
                            class="form-select"
                        >
                            <option>draft</option>
                            <option>active</option>
                            <option>closed</option>
                            <option>archived</option>
                        </select>
                        <div class="text-danger small">
                            {{ form.errors.status }}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="timezone">
                            Timezone
                        </label>
                        <input
                            id="timezone"
                            v-model="form.timezone"
                            class="form-control"
                            required
                        />
                        <div class="text-danger small">
                            {{ form.errors.timezone }}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="instructional_days">
                            Instructional-day target (optional)
                        </label>
                        <input
                            id="instructional_days"
                            v-model.number="form.instructional_day_target"
                            type="number"
                            min="1"
                            max="366"
                            class="form-control"
                        />
                        <div class="form-text">
                            Optional planning goal. Scheduled instructional days
                            are calculated from the weekly schedule and will
                            later account for holidays and calendar closures.
                        </div>
                        <div class="text-danger small">
                            {{ form.errors.instructional_day_target }}
                        </div>
                    </div>
                </div>

                <button
                    class="btn btn-primary mt-4"
                    :disabled="form.processing"
                >
                    <span
                        v-if="form.processing"
                        class="spinner-border spinner-border-sm me-2"
                        aria-hidden="true"
                    ></span>
                    Save school year
                </button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
