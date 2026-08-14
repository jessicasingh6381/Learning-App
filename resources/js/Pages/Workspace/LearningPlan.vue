<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';

withDefaults(defineProps<{
    schoolYear: any;
    students: any[];
    selectedStudent: any;
    learningPlan: any;
    curriculumBySubject: any[];
    hiddenCurriculumSubjects?: any[];
    hiddenCurriculumSubjectCount?: number;
    curriculumIntakeAvailable: boolean;
    curriculumVisibilityManageable?: boolean;
    lessonPlanManageable?: boolean;
}>(), {
    hiddenCurriculumSubjects: () => [],
    hiddenCurriculumSubjectCount: 0,
    curriculumVisibilityManageable: false,
    lessonPlanManageable: false,
});

const page = usePage<any>();
const canAdvanced = page.props.auth.permissions.includes('advanced-academic.view');
const confirmHide = (event: MouseEvent, subject: any) => {
    if ((subject.source_count || subject.curriculum_import_id) && !window.confirm(`Hide ${subject.name} from this learning plan? Existing curriculum and history will be kept.`)) event.preventDefault();
};
</script>

<template>
    <Head title="Learning Plan" />
    <AuthenticatedLayout>
        <div class="mb-4"><h1 class="h2">Learning Plan</h1><p class="text-secondary">A friendly summary of what your student is set to learn.</p></div>
        <div v-if="!schoolYear" class="card"><div class="empty-state"><h2 class="h5">Choose an active school year first</h2><Link :href="route('school-years.index')">Review school years</Link></div></div>
        <template v-else>
            <div v-if="students.length > 1" class="d-flex gap-2 flex-wrap mb-3"><Link v-for="student in students" :key="student.id" class="btn btn-sm" :class="selectedStudent?.id === student.id ? 'btn-primary' : 'btn-outline-secondary'" :href="route('workspace.learning-plan', { student_id: student.id })">{{ student.name }}</Link></div>
            <div class="card mb-4"><div class="card-body"><p class="text-uppercase small fw-semibold text-secondary mb-1">Selected student</p><h2 class="h4">{{ selectedStudent?.name ?? 'Choose a student' }}</h2><p class="mb-1">{{ selectedStudent?.enrollment?.grade ?? 'Grade enrollment needed' }} · {{ schoolYear.name }}</p><p class="text-secondary mb-0">{{ formatDateOnly(schoolYear.start_date) }} – {{ formatDateOnly(schoolYear.end_date) }}</p></div></div>
            <div class="row g-4 mb-4"><div v-for="item in [['Provider', learningPlan.provider], ['Calendar', learningPlan.calendar], ['Standards', learningPlan.standards], ['Curriculum', learningPlan.curriculum_status_label]]" :key="item[0]" class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><small class="text-secondary">{{ item[0] }}</small><strong class="d-block mt-1">{{ item[1] || 'Not chosen yet' }}</strong><span v-if="item[0] === 'Curriculum' && learningPlan.curriculum_status_detail" class="small text-secondary">{{ learningPlan.curriculum_status_detail }}</span></div></div></div></div>
            <div class="card mb-4"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3"><div><h2 class="h4">Curriculum by subject</h2><p class="text-secondary">Sources and draft outlines for {{ selectedStudent?.enrollment?.grade ?? 'this grade' }}.</p></div><div class="d-flex flex-wrap gap-2"><Link v-if="curriculumIntakeAvailable" class="btn btn-primary" :href="route('workspace.curriculum-intake', { student_id: selectedStudent?.id, school_year_id: schoolYear.id })">Curriculum Intake</Link></div></div>
                <details v-if="hiddenCurriculumSubjectCount" class="mb-3">
                    <summary class="btn btn-sm btn-outline-secondary">Hidden subjects ({{ hiddenCurriculumSubjectCount }})</summary>
                    <ul class="list-group mt-2"><li v-for="subject in hiddenCurriculumSubjects" :key="subject.id" class="list-group-item d-flex justify-content-between align-items-center gap-2"><span><strong>{{ subject.name }}</strong><small class="d-block text-secondary">{{ subject.status_label }}</small></span><Link v-if="curriculumVisibilityManageable" as="button" method="patch" preserve-scroll class="btn btn-sm btn-outline-primary" :href="route('workspace.learning-plan.subjects.show', { enrollment: selectedStudent.enrollment.id, subject: subject.id })">Show subject</Link></li></ul>
                </details>
                <div class="row g-3">
                    <div v-for="subject in curriculumBySubject" :key="subject.id" class="col-sm-6 col-lg-4"><div class="friendly-panel h-100 d-flex flex-column">
                        <h3 class="h6">{{ subject.name }}</h3>
                        <span class="badge text-bg-light border mb-2 align-self-start">{{ subject.status_label }}</span>
                        <p class="small text-secondary mb-2">{{ subject.source_count ? `${subject.source_count} curriculum source${subject.source_count === 1 ? '' : 's'}` : 'No curriculum source added' }}</p>
                        <p v-if="subject.curriculum_import_id" class="small text-secondary mb-2"><span v-if="subject.period_count">{{ subject.period_count }} periods · </span>{{ subject.unit_count }} units/blocks<span v-if="subject.assessment_count"> · {{ subject.assessment_count }} assessments</span></p>
                        <div v-if="subject.workflow_state === 'outline_approved'" class="border-top pt-2 mt-1 mb-2">
                            <small class="text-secondary d-block">Curriculum: Ready</small>
                            <template v-if="subject.lesson_plan"><strong class="small text-capitalize">Lesson Plan: {{ subject.lesson_plan.status }}</strong><span class="small text-secondary d-block">{{ subject.lesson_plan.lesson_count }} lessons</span><Link class="btn btn-sm btn-outline-primary mt-2" :href="subject.lesson_plan.url">Review lessons</Link></template>
                            <template v-else><strong class="small d-block">Lessons: Not created yet</strong><Link v-if="lessonPlanManageable" as="button" method="post" class="btn btn-sm btn-primary mt-2" :href="subject.lesson_plan_create_url">Create lesson plan</Link></template>
                        </div>
                        <div v-if="curriculumIntakeAvailable && (subject.primary_action_url || subject.secondary_action_url)" class="d-flex flex-wrap gap-2 mt-auto align-items-start"><Link v-if="subject.primary_action_url" class="btn btn-sm btn-primary" :href="subject.primary_action_url">{{ subject.primary_action_label }}</Link><Link v-if="subject.secondary_action_url" class="btn btn-sm btn-outline-primary" :href="subject.secondary_action_url">{{ subject.secondary_action_label }}</Link></div>
                        <Link v-if="curriculumVisibilityManageable" as="button" method="patch" preserve-scroll class="btn btn-sm btn-link text-secondary align-self-start px-0 mt-2" :href="route('workspace.learning-plan.subjects.hide', { enrollment: selectedStudent.enrollment.id, subject: subject.id })" @click="confirmHide($event, subject)">Hide subject</Link>
                    </div></div>
                </div>
                <div class="text-end mt-3"><Link v-if="canAdvanced" class="small" :href="route('academic.curriculum.index')">Advanced curriculum settings</Link></div>
            </div></div>
            <div class="card"><div class="card-body"><h2 class="h4">Courses</h2><p class="text-secondary">Grouped by subject from the selected curriculum.</p><div v-if="!learningPlan.courses.length" class="empty-state py-4"><p>No courses are in this learning plan yet.</p><Link :href="route('workspace.settings')">See setup options</Link></div><div v-else class="row g-4"><section v-for="group in learningPlan.courses" :key="group.subject" class="col-md-6"><h3 class="h5">{{ group.subject }}</h3><ul class="list-group"><li v-for="course in group.courses" :key="course.id" class="list-group-item"><strong>{{ course.name }}</strong><small v-if="course.code" class="d-block text-secondary">{{ course.code }}</small></li></ul></section></div></div></div>
        </template>
    </AuthenticatedLayout>
</template>
