<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    schoolYears: any[];
    schoolYear: any | null;
    configuration: any | null;
    summary: any | null;
    mappedCourseCount: number;
    sourceCounts?: Record<string, number>;
    checklist: Record<string, boolean>;
    choices: Record<string, any[]>;
    canManage: boolean;
}>(), { sourceCounts: () => ({ calendar: 0, curriculum: 0, courses: 0 }) });

const form = useForm<any>({
    school_year_id: props.schoolYear?.id ?? null,
    education_provider_id: props.configuration?.education_provider_id ?? null,
    calendar_profile_id: props.configuration?.calendar_profile_id ?? null,
    standards_framework_id: props.configuration?.standards_framework_id ?? null,
    curriculum_package_id: props.configuration?.curriculum_package_id ?? null,
    status: props.configuration?.status ?? 'draft',
    notes: props.configuration?.notes ?? '',
});
const configurationFields = computed(() => [
    { field: 'education_provider_id', label: 'Education provider', options: props.choices.providers },
    { field: 'calendar_profile_id', label: 'Calendar profile', options: props.choices.calendars },
    { field: 'standards_framework_id', label: 'Standards framework', options: props.choices.frameworks },
    { field: 'curriculum_package_id', label: 'Curriculum package', options: props.choices.packages },
]);
const copyForm = useForm({
    source_school_year_id: null as number | null,
    target_school_year_id: props.schoolYear?.id ?? null,
});
const completeCount = computed(() => Object.values(props.checklist).filter(Boolean).length);
const selectYear = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    router.get(route('academic.overview'), { school_year_id: value }, { preserveState: false });
};
</script>

<template>
    <Head title="Academic Setup" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h1 class="h2 mb-1">Academic setup</h1>
                <p class="text-secondary mb-0">Historical instructional configuration by school year.</p>
            </div>
            <select v-if="schoolYears.length" class="form-select w-auto" aria-label="School year" :value="schoolYear?.id" @change="selectYear">
                <option v-for="year in schoolYears" :key="year.id" :value="year.id">{{ year.name }} · {{ year.status }}</option>
            </select>
        </div>
        <AcademicNav />

        <div v-if="!schoolYear" class="empty-state card">Create a school year before configuring academics.</div>
        <template v-else>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-secondary">Base days</div><div class="display-6">{{ summary.base_days }}</div></div></div></div>
                <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-secondary">Removed</div><div class="display-6">{{ summary.removed_days }}</div></div></div></div>
                <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-secondary">Added overrides</div><div class="display-6">{{ summary.added_days }}</div></div></div></div>
                <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-secondary">Scheduled days</div><div class="display-6">{{ summary.scheduled_days }}</div><small>Target: {{ schoolYear.instructional_day_target ?? 'Not set' }}</small></div></div></div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <form class="card" @submit.prevent="form.post(route('academic.configuration.store'))">
                        <div class="card-body">
                            <h2 class="h5">Configuration for {{ schoolYear.name }}</h2>
                            <p class="text-secondary">{{ formatDateOnly(schoolYear.start_date) }} – {{ formatDateOnly(schoolYear.end_date) }}</p>
                            <div class="row g-3">
                                <div v-for="item in configurationFields" :key="item.field" class="col-md-6">
                                    <label class="form-label" :for="item.field">{{ item.label }}</label>
                                    <select :id="item.field" v-model="form[item.field]" class="form-select" :aria-describedby="form.errors[item.field] ? `${item.field}-error` : undefined" :disabled="!canManage">
                                        <option :value="null">Not selected</option>
                                        <option v-for="option in item.options" :key="option.id" :value="option.id">
                                            {{ option.name }}{{ option.version_label ? ` · ${option.version_label}` : '' }}{{ option.tenant_id === null ? ' (shared)' : ' (custom)' }}
                                        </option>
                                    </select>
                                    <div v-if="form.errors[item.field]" :id="`${item.field}-error`" class="invalid-feedback d-block">{{ form.errors[item.field] }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="configuration-status" class="form-label">Status</label>
                                    <select id="configuration-status" v-model="form.status" class="form-select" :disabled="!canManage">
                                        <option value="draft">Draft</option><option value="active">Active</option><option value="closed">Closed</option><option value="archived">Archived</option>
                                    </select>
                                    <div v-if="form.errors.status" class="invalid-feedback d-block">{{ form.errors.status }}</div>
                                </div>
                                <div class="col-12">
                                    <label for="configuration-notes" class="form-label">Notes</label>
                                    <textarea id="configuration-notes" v-model="form.notes" class="form-control" rows="3" :disabled="!canManage" />
                                </div>
                            </div>
                        </div>
                        <div v-if="canManage" class="card-footer bg-white text-end">
                            <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save configuration' }}</button>
                        </div>
                    </form>
                </div>
                <div class="col-lg-4">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h2 class="h5">Setup status</h2>
                            <p>{{ completeCount }} of {{ Object.keys(checklist).length }} steps complete</p>
                            <ul class="list-group list-group-flush">
                                <li v-for="(complete, item) in checklist" :key="item" class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-capitalize">{{ item.replace('_', ' ') }}</span><span :class="complete ? 'text-success' : 'text-secondary'">{{ complete ? 'Complete' : 'Missing' }}</span>
                                    </div>
                                    <div v-if="['calendar', 'curriculum', 'courses'].includes(String(item))" class="small text-secondary mt-1">
                                        {{ sourceCounts[String(item)] ?? 0 }} related source{{ (sourceCounts[String(item)] ?? 0) === 1 ? '' : 's' }}
                                        <Link v-if="!complete && canManage" class="ms-1" :href="route('academic.sources.create', { category: item === 'courses' ? 'course_guide' : item, school_year_id: schoolYear.id })">Add source</Link>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <form v-if="canManage && schoolYears.length > 1" class="card" @submit.prevent="copyForm.post(route('academic.configuration.copy'))">
                        <div class="card-body">
                            <h2 class="h5">Copy prior year</h2>
                            <p class="small text-secondary">Copies selections into a new draft. Enrollments, audits, and incompatible calendars are not copied.</p>
                            <label for="copy-source" class="form-label">Source school year</label>
                            <select id="copy-source" v-model="copyForm.source_school_year_id" class="form-select mb-2" required>
                                <option :value="null">Select source</option>
                                <option v-for="year in schoolYears.filter((item) => item.id !== schoolYear.id)" :key="year.id" :value="year.id">{{ year.name }}</option>
                            </select>
                            <div v-if="copyForm.errors.target_school_year_id || copyForm.errors.source_school_year_id" class="invalid-feedback d-block">{{ copyForm.errors.target_school_year_id || copyForm.errors.source_school_year_id }}</div>
                            <button class="btn btn-outline-primary mt-2" :disabled="copyForm.processing">{{ copyForm.processing ? 'Copying…' : 'Copy into this year' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
