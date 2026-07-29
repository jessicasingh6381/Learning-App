<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    useEnrollmentDateDefault,
    type EnrollmentSchoolYear,
} from '@/Support/enrollmentDateDefault';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

interface OldInput {
    student_id: number | null;
    school_year_id: number | null;
    grade_level_id: number | null;
    enrollment_date: string | null;
    completion_date: string | null;
    status: string | null;
}

const props = defineProps<{
    students: any[];
    schoolYears: EnrollmentSchoolYear[];
    gradeLevels: any[];
    oldInput: OldInput | null;
}>();
const preset = new URLSearchParams(location.search).get('student');
const form = useForm({
    student_id: props.oldInput
        ? (props.oldInput.student_id ?? '')
        : (preset ? Number(preset) : props.students[0]?.id || ''),
    school_year_id: props.oldInput
        ? (props.oldInput.school_year_id ?? '')
        : (props.schoolYears[0]?.id ?? ''),
    grade_level_id: props.oldInput
        ? (props.oldInput.grade_level_id ?? '')
        : (props.gradeLevels[0]?.id ?? ''),
    enrollment_date: props.oldInput?.enrollment_date ?? '',
    completion_date: props.oldInput?.completion_date ?? '',
    status: props.oldInput?.status ?? 'active',
});
const { markEnrollmentDateAsManual } = useEnrollmentDateDefault(
    form,
    props.schoolYears,
    props.oldInput !== null,
);
const needsCompletionDate = computed(() => ['completed', 'withdrawn'].includes(form.status));
watch(needsCompletionDate, (required) => {
    if (!required) form.completion_date = '';
});
</script>

<template>
    <Head title="Add enrollment" />
    <AuthenticatedLayout>
        <h1 class="h2">Add enrollment</h1>
        <p class="text-secondary">Grade level belongs to this school-year enrollment, preserving the student’s history.</p>
        <div v-if="!students.length || !schoolYears.length" class="alert alert-warning">Add an active student and a draft or active school year first.</div>
        <div v-else class="card">
            <form class="card-body" @submit.prevent="form.post(route('enrollments.store'))">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="student_id">Student</label>
                        <select id="student_id" v-model="form.student_id" class="form-select">
                            <option v-for="student in students" :key="student.id" :value="student.id">{{ student.preferred_name || student.first_name }} {{ student.last_name }}</option>
                        </select>
                        <div class="text-danger small">{{ form.errors.student_id }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="school_year_id">School year</label>
                        <select id="school_year_id" v-model="form.school_year_id" class="form-select">
                            <option v-for="year in schoolYears" :key="year.id" :value="year.id">{{ year.name }}</option>
                        </select>
                        <div class="text-danger small">{{ form.errors.school_year_id }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="grade_level_id">Grade level</label>
                        <select id="grade_level_id" v-model="form.grade_level_id" class="form-select">
                            <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">{{ grade.name }}</option>
                        </select>
                        <div class="text-danger small">{{ form.errors.grade_level_id }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="enrollment_date">Enrollment date</label>
                        <input id="enrollment_date" v-model="form.enrollment_date" type="date" class="form-control" @input="markEnrollmentDateAsManual">
                        <div class="form-text">Defaults to the first day of the selected school year. Change it if the student starts later.</div>
                        <div class="text-danger small">{{ form.errors.enrollment_date }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="enrollment_status">Status</label>
                        <select id="enrollment_status" v-model="form.status" class="form-select">
                            <option>planned</option><option>active</option><option>completed</option><option>withdrawn</option><option>cancelled</option>
                        </select>
                        <div class="text-danger small">{{ form.errors.status }}</div>
                    </div>
                    <div v-if="needsCompletionDate" class="col-md-4">
                        <label class="form-label" for="completion_date">{{ form.status === 'withdrawn' ? 'Withdrawal' : 'Completion' }} date</label>
                        <input id="completion_date" v-model="form.completion_date" type="date" class="form-control">
                        <div class="text-danger small">{{ form.errors.completion_date }}</div>
                    </div>
                </div>
                <button class="btn btn-primary mt-4" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                    Save enrollment
                </button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
