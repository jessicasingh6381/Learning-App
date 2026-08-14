<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ lessonPlan: any; lesson: any; canManage?: boolean }>();
const requiredStudentSupplies = computed(() => props.lesson.resource_groups?.student_supply?.filter((resource: any) => resource.student_experience_required !== false) || []);
const requiredSpecialMaterials = computed(() => props.lesson.resource_groups?.special_material?.filter((resource: any) => resource.student_experience_required !== false) || []);
const hasRequiredPhysicalMaterials = computed(() => requiredStudentSupplies.value.length > 0 || requiredSpecialMaterials.value.length > 0);
const digitalFirstResources = computed(() => {
    const resources = props.lesson.resource_groups?.lesson_resource || [];
    return resources.length > 0 && resources.every((resource: any) => resource.optional_teacher_fallback || ['embedded', 'interactive'].includes(resource.delivery_type));
});
</script>

<template>
    <Head :title="lesson.title" />
    <AuthenticatedLayout>
        <Link class="small" :href="route('lesson-plans.show', lessonPlan.id)">Back to {{ lessonPlan.subject }} Lesson Plan</Link>
        <div class="d-flex justify-content-between align-items-start gap-3 mt-2 mb-4"><div><p class="text-uppercase small fw-semibold text-secondary mb-1">Lesson {{ lesson.sequence }} · {{ lesson.curriculum_unit }}</p><h1 class="h2">{{ lesson.title }}</h1><p class="text-secondary mb-0 text-capitalize">{{ lesson.lesson_mode }}</p></div><span class="badge text-bg-light border text-capitalize">{{ lesson.status }}</span></div>
        <section v-if="lesson.student_experience_preview_url" class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-3" role="status"><div><strong>Student experience prototype available</strong><div class="small">This explicit preview does not publish the draft lesson to the student portal.</div></div><Link class="btn btn-primary" :href="lesson.student_experience_preview_url">Preview student experience</Link></section>

        <section v-if="lesson.release" class="card mb-4" aria-labelledby="lesson-release-heading">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h2 id="lesson-release-heading" class="h5">Individual lesson review and release</h2>
                    <p v-if="lesson.status === 'approved'" class="text-success mb-0">Approved for the student. Instructional content is now protected from in-place edits.</p>
                    <p v-else-if="lesson.status === 'draft' && lesson.release.ready" class="mb-0">The student experience is complete and ready for teacher review. This does not review the full lesson plan.</p>
                    <p v-else-if="lesson.status === 'reviewed' && lesson.release.ready" class="mb-0">This lesson is reviewed and passes all release checks. Approval will release only this lesson.</p>
                    <template v-else-if="!lesson.release.ready">
                        <p class="mb-2">This lesson is not ready for individual review or approval.</p>
                        <ul class="small text-secondary mb-0"><li v-for="blocker in lesson.release.blockers" :key="blocker">{{ blocker }}</li></ul>
                    </template>
                </div>
                <Link v-if="canManage && lesson.status === 'draft' && lesson.release.ready && lesson.release.review_url" class="btn btn-outline-primary" as="button" method="post" :href="lesson.release.review_url">Mark reviewed</Link>
                <Link v-else-if="canManage && lesson.status === 'reviewed' && lesson.release.ready && lesson.release.approve_url" class="btn btn-success" as="button" method="post" :href="lesson.release.approve_url">Approve for student</Link>
            </div>
        </section>

        <section class="card mb-4"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><small class="text-secondary">Student instructional time</small><strong class="d-block">{{ lesson.estimated_minutes ? `${lesson.estimated_minutes} minutes` : 'Not estimated' }}</strong></div>
            <div class="col-md-4"><small class="text-secondary">Suggested sessions</small><strong class="d-block">{{ lesson.suggested_sessions || 1 }}</strong></div>
            <div class="col-md-4"><small class="text-secondary">Parent preparation</small><strong class="d-block">{{ lesson.estimated_preparation_minutes === null || lesson.estimated_preparation_minutes === undefined ? 'Not estimated' : lesson.estimated_preparation_minutes === 0 ? 'None required' : `${lesson.estimated_preparation_minutes} minutes` }}</strong></div>
        </div></div></section>

        <div class="row g-4 mb-4">
            <section class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Learning objective</h2><p class="mb-0">{{ lesson.learning_objective || 'Not supplied yet.' }}</p></div></div></section>
            <section class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Completion criteria</h2><p class="mb-0">{{ lesson.completion_criteria || 'Not supplied yet.' }}</p></div></div></section>
        </div>

        <div v-if="lesson.resource_groups" class="row g-4 mb-4">
            <section :class="hasRequiredPhysicalMaterials ? 'col-lg-7' : 'col-12'"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h2 class="h5">Lesson resources</h2><span class="badge" :class="lesson.resource_complete ? 'text-bg-success' : 'text-bg-light border'">{{ lesson.resource_complete ? (digitalFirstResources ? 'Digital experience ready' : 'Resource complete') : 'Resources preparing' }}</span></div><p class="small text-secondary">Required learning tools are built into the student experience. Optional fallbacks are not part of Kai’s normal workflow.</p><p v-if="!lesson.resource_groups.lesson_resource?.length" class="text-secondary mb-0">No lesson-provided resources are defined.</p><ul v-else class="list-group list-group-flush"><li v-for="resource in lesson.resource_groups.lesson_resource" :key="resource.id" class="list-group-item px-0 d-flex justify-content-between gap-3"><div><strong>{{ resource.title }}</strong><p v-if="resource.description" class="small text-secondary mb-0">{{ resource.description }}</p><span v-if="resource.optional_teacher_fallback" class="badge text-bg-light border mt-1">Optional teacher fallback</span><span v-else-if="resource.student_experience_required && ['embedded', 'interactive'].includes(resource.delivery_type)" class="badge text-bg-success mt-1">Built into student lesson</span><span v-if="resource.availability_status === 'needs_asset'" class="badge text-bg-info mt-1 ms-1">Preparing resource...</span><span v-else-if="resource.availability_status === 'unavailable'" class="badge text-bg-warning mt-1 ms-1">Resource unavailable / needs review</span><small v-if="resource.url && resource.source_attribution" class="d-block text-secondary mt-1">{{ resource.source_attribution }}<template v-if="resource.license_name"> · {{ resource.license_name }}</template></small></div><a v-if="resource.url && resource.delivery_type !== 'interactive'" class="btn btn-sm btn-outline-primary align-self-center" :href="resource.url" target="_blank">{{ resource.optional_teacher_fallback ? 'View optional fallback' : resource.delivery_type === 'printable' ? 'View / Print' : 'View' }}</a></li></ul></div></div></section>
            <section v-if="hasRequiredPhysicalMaterials" class="col-lg-5"><div class="card h-100"><div class="card-body"><h2 class="h5">Student supplies</h2><p class="small text-secondary">Only physical items genuinely required by this lesson.</p><ul v-if="requiredStudentSupplies.length" class="mb-3"><li v-for="resource in requiredStudentSupplies" :key="resource.id">{{ resource.title }}</li></ul><h3 v-if="requiredSpecialMaterials.length" class="h6">Special or household materials</h3><ul v-if="requiredSpecialMaterials.length" class="mb-0"><li v-for="resource in requiredSpecialMaterials" :key="resource.id">{{ resource.title }}</li></ul></div></div></section>
        </div>

        <section v-if="lesson.sections.length" class="mb-4"><h2 class="h4">Lesson sections</h2><div v-for="section in lesson.sections" :key="section.id" class="card mb-3"><div class="card-body"><div class="d-flex justify-content-between"><h3 class="h5">{{ section.title || section.type.replaceAll('_', ' ') }}</h3><span class="badge text-bg-light border text-capitalize">{{ section.audience }}</span></div><p class="mb-0" style="white-space: pre-wrap">{{ section.content }}</p><div v-for="child in section.children" :key="child.id" class="border-start ps-3 mt-3"><strong>{{ child.title || child.type.replaceAll('_', ' ') }}</strong><span class="badge text-bg-light border ms-2 text-capitalize">{{ child.audience }}</span><p class="mb-0">{{ child.content }}</p></div></div></div></section>

        <div class="row g-4 mb-4">
            <section class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Curriculum components taught</h2><p v-if="!lesson.components.length" class="text-secondary mb-0">No specific components linked.</p><ul v-else class="list-group list-group-flush"><li v-for="component in lesson.components" :key="`${component.type}-${component.name}`" class="list-group-item px-0"><span class="badge text-bg-light border text-capitalize me-2">{{ component.role || component.type }}</span><strong>{{ component.name }}</strong><p v-if="component.description" class="small text-secondary mb-0 mt-1">{{ component.description }}</p></li></ul></div></div></section>
            <section class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Standards</h2><p v-if="!lesson.standards.length" class="text-secondary mb-0">No standards linked.</p><ul v-else class="list-group list-group-flush"><li v-for="standard in lesson.standards" :key="standard.code" class="list-group-item px-0"><strong>{{ standard.code }}</strong><p v-if="standard.statement" class="small text-secondary mb-0">{{ standard.statement }}</p></li></ul></div></div></section>
        </div>

        <section class="card"><div class="card-body"><h2 class="h5">Why this lesson exists</h2><p class="mb-1">This lesson comes from <strong>{{ lesson.provenance.unit }}</strong> in <strong>{{ lesson.provenance.source }}</strong>.</p><p v-if="lesson.provenance.file" class="small text-secondary mb-0">Source file: {{ lesson.provenance.file }}<template v-if="lesson.provenance.source_page"> · Page {{ lesson.provenance.source_page }}</template></p></div></section>
    </AuthenticatedLayout>
</template>
