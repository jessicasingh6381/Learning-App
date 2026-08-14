<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ lessonPlan: any; canManage: boolean; generatorConfigured: boolean }>();
</script>

<template>
    <Head :title="`${lessonPlan.subject} Lesson Plan`" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
            <div><Link class="small" :href="route('workspace.learning-plan')">Back to Learning Plan</Link><h1 class="h2 mt-2">{{ lessonPlan.subject }} Lesson Plan</h1><p class="text-secondary mb-0">{{ lessonPlan.student }} · {{ lessonPlan.school_year }} · {{ lessonPlan.course }}</p></div>
            <span class="badge text-bg-light border text-capitalize">{{ lessonPlan.status }}</span>
        </div>

        <div v-if="lessonPlan.status === 'failed' && lessonPlan.failure_diagnostic" class="alert alert-warning"><strong>Generation needs attention.</strong> {{ lessonPlan.failure_diagnostic }}</div>
        <div v-if="canManage && !generatorConfigured" class="alert alert-info">Lesson generation is ready for configuration. Add the provider API key to enable unit generation.</div>

        <div class="card mb-4"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><small class="text-secondary">Curriculum source</small><strong class="d-block">{{ lessonPlan.curriculum.source }}</strong><span v-if="lessonPlan.curriculum.file" class="small text-secondary">{{ lessonPlan.curriculum.file }}</span></div>
            <div class="col-md-4"><small class="text-secondary">Curriculum package</small><strong class="d-block">{{ lessonPlan.curriculum.package || 'Approved curriculum' }}</strong></div>
            <div class="col-md-4"><small class="text-secondary">Plan revision</small><strong class="d-block">{{ lessonPlan.revision }}</strong></div>
        </div></div></div>

        <section class="card mb-4"><div class="card-body">
            <h2 class="h4">Curriculum units</h2><p class="text-secondary">Generate and review one approved unit at a time.</p>
            <div class="list-group list-group-flush">
                <div v-for="unit in lessonPlan.units" :key="unit.id" class="list-group-item px-0 d-flex justify-content-between align-items-center gap-3">
                    <div><small class="text-secondary">Unit {{ unit.sequence }}</small><h3 class="h6 mb-1">{{ unit.name }}</h3><span v-if="unit.lesson_count" class="small text-secondary">{{ unit.lesson_count }} lessons generated</span><span v-else class="small text-secondary">Lessons: Not generated</span></div>
                    <Link v-if="canManage && generatorConfigured && !unit.lesson_count && ['draft', 'failed'].includes(lessonPlan.status)" as="button" method="post" class="btn btn-sm btn-primary" :href="unit.generate_url">Generate lessons</Link>
                    <a v-else-if="unit.lesson_count" class="btn btn-sm btn-outline-primary" href="#lessons">Review lessons</a>
                </div>
            </div>
        </div></section>

        <div id="lessons" class="d-flex justify-content-between align-items-center mb-3">
            <div><h2 class="h4 mb-1">Lessons</h2><p class="text-secondary mb-0">{{ lessonPlan.lesson_count }} lessons in sequence</p></div>
            <div v-if="canManage" class="d-flex gap-2">
                <Link v-if="lessonPlan.status === 'draft' && lessonPlan.lesson_count > 0 && lessonPlan.review?.eligible" as="button" method="post" class="btn btn-outline-primary" :href="route('lesson-plans.review', lessonPlan.id)">Mark plan reviewed</Link>
                <button v-else-if="lessonPlan.status === 'draft' && lessonPlan.lesson_count > 0" class="btn btn-outline-secondary" type="button" disabled>Mark plan reviewed</button>
                <Link v-if="lessonPlan.status === 'reviewed'" as="button" method="post" class="btn btn-primary" :href="route('lesson-plans.approve', lessonPlan.id)">Approve plan</Link>
            </div>
        </div>
        <p v-if="lessonPlan.status === 'draft' && lessonPlan.review?.blocker" class="alert alert-light border small" role="status">{{ lessonPlan.review.blocker }}</p>
        <div v-if="!lessonPlan.lessons.length" class="card"><div class="empty-state"><h3 class="h5">No lessons have been generated yet</h3><p class="text-secondary mb-0">Choose one approved curriculum unit above to create draft lessons.</p></div></div>
        <div v-else class="list-group">
            <Link v-for="lesson in lessonPlan.lessons" :key="lesson.id" class="list-group-item list-group-item-action" :href="lesson.url"><div class="d-flex justify-content-between gap-3"><div><small class="text-secondary">Lesson {{ lesson.sequence }} · {{ lesson.curriculum_unit }}</small><h3 class="h6 mb-1">{{ lesson.title }}</h3><span class="small text-secondary text-capitalize">{{ lesson.lesson_mode }}<template v-if="lesson.estimated_minutes"> · Student time: {{ lesson.estimated_minutes }} min</template><template v-if="lesson.suggested_sessions"> · {{ lesson.suggested_sessions }} {{ lesson.suggested_sessions === 1 ? 'session' : 'sessions' }}</template><template v-if="lesson.estimated_preparation_minutes !== null && lesson.estimated_preparation_minutes !== undefined"> · Parent prep: {{ lesson.estimated_preparation_minutes }} min</template></span></div><span class="badge text-bg-light border align-self-start text-capitalize">{{ lesson.status }}</span></div></Link>
        </div>
    </AuthenticatedLayout>
</template>
