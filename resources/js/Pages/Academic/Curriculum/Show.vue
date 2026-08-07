<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import CurriculumCourseRow from '@/Components/CurriculumCourseRow.vue';
import CurriculumUnitComponentTree from '@/Components/CurriculumUnitComponentTree.vue';
import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps<{ package: any; courses: any[]; gradeLevels: any[] }>();
const canManage = usePage<any>().props.auth.permissions.includes('curriculum.manage') && !props.package.is_shared;
const editable = canManage && props.package.status === 'draft';
const form = useForm({ course_id: null as number | null, grade_level_id: null as number | null, sort_order: props.package.course_mappings.length, required: true });
const add = () => form.post(route('academic.curriculum.courses.store', props.package.id), { preserveScroll: true, onSuccess: () => form.reset('course_id', 'grade_level_id') });
const componentSummary = (unit: any) => unit.components.map((component: any) => component.name.replace('Focus TEKS Evidence', 'Focus TEKS').replace('Handwriting Without Tears', 'Handwriting')).join(', ');
</script>

<template>
    <Head :title="package.name" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between mb-3">
            <div><h1 class="h2">{{ package.name }}</h1><p class="text-secondary">Version {{ package.version_label }} · <span class="text-capitalize">{{ package.status }}</span></p></div>
            <div><OwnershipBadge :shared="package.is_shared" /> <Link v-if="canManage" class="btn btn-sm btn-outline-secondary ms-2" :href="route('academic.curriculum.edit', package.id)">Edit package</Link></div>
        </div>
        <AcademicNav />
        <div v-if="package.status !== 'draft'" class="alert alert-info">This version is protected. Create a new draft version for material future changes.</div>
        <form v-if="editable" class="card card-body mb-4" @submit.prevent="add">
            <h2 class="h5">Add course</h2><div class="row g-2 align-items-end"><div class="col-md-5"><label for="mapping-course" class="form-label">Course</label><select id="mapping-course" v-model="form.course_id" class="form-select" required><option :value="null">Select course</option><option v-for="course in courses" :key="course.id" :value="course.id">{{ course.name }} · {{ course.subject.name }}</option></select><div v-if="form.errors.course_id" class="invalid-feedback d-block">{{ form.errors.course_id }}</div></div><div class="col-md-3"><label for="mapping-grade" class="form-label">Grade mapping</label><select id="mapping-grade" v-model="form.grade_level_id" class="form-select"><option :value="null">Use course range</option><option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">{{ grade.name }}</option></select></div><div class="col-md-2"><label for="mapping-required" class="form-label">Requirement</label><select id="mapping-required" v-model="form.required" class="form-select"><option :value="true">Required</option><option :value="false">Optional</option></select></div><div class="col-md-2"><button class="btn btn-primary w-100" :disabled="form.processing">Add</button></div></div>
        </form>
        <div class="card"><div class="card-body"><h2 class="h5">Mapped courses</h2></div><div v-if="!package.course_mappings.length" class="empty-state">No courses mapped. Add at least one course before activation.</div><div v-else class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Course</th><th>Grade</th><th>Order</th><th>Requirement</th><th></th></tr></thead><tbody><CurriculumCourseRow v-for="mapping in package.course_mappings" :key="mapping.id" :package-id="package.id" :mapping="mapping" :courses="courses" :grade-levels="gradeLevels" :editable="editable" /></tbody></table></div></div>
        <section v-for="mapping in package.course_mappings.filter((item: any) => item.curriculum_periods?.length)" :key="`outline-${mapping.id}`" class="card mt-4">
            <div class="card-body"><h2 class="h5 mb-1">{{ mapping.course.name }} outline</h2><p class="small text-secondary">{{ mapping.grade_level?.name ?? 'Course grade range' }} · imported draft structure</p>
                <div v-for="period in mapping.curriculum_periods" :key="period.id" class="border rounded p-3 mb-3"><h3 class="h6">{{ period.sequence }}. {{ period.name }}</h3><p class="small text-secondary">{{ period.planned_start_date ?? 'No start date' }} – {{ period.planned_end_date ?? 'No end date' }}</p>
                    <ol class="mb-0"><li v-for="unit in period.units" :key="unit.id" class="mb-3"><strong>{{ unit.name }}</strong> <span class="badge text-bg-light border text-capitalize">{{ unit.unit_type }}</span><span v-if="unit.estimated_days" class="small text-secondary"> · {{ unit.estimated_days }} days</span><p v-if="unit.summary" class="small mb-1 mt-1">{{ unit.summary }}</p><div v-if="unit.standard_alignments.length" class="small text-secondary">Standards: {{ unit.standard_alignments.map((item: any) => item.standard_code).join(', ') }}</div><details v-if="unit.components?.length" class="mt-2"><summary class="small fw-semibold">{{ unit.components.length }} sections · {{ componentSummary(unit) }}</summary><ul class="mt-2 mb-0"><CurriculumUnitComponentTree v-for="component in unit.components" :key="component.id" :component="component" /></ul></details></li></ol>
                </div>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
