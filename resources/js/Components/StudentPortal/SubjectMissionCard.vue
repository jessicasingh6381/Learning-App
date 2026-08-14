<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    subject: string;
    lesson: {
        id: number;
        sequence: number;
        title: string;
        progress_status: string;
        action_label: string;
        url: string;
    };
}>();

const isInProgress = computed(() => props.lesson.progress_status === 'in_progress');
const progress = computed(() => isInProgress.value ? 55 : 8);
const subjectClass = computed(() => {
    const name = props.subject.toLowerCase();
    if (name.includes('science')) return 'mission-science';
    if (name.includes('social')) return 'mission-social-studies';
    if (name.includes('math')) return 'mission-math';
    return 'mission-default';
});
</script>

<template>
    <article class="subject-mission-card" :class="subjectClass">
        <div class="mission-card-orbit" aria-hidden="true" />
        <div class="mission-card-body">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <span class="subject-label">{{ subject }}</span>
                    <p class="mission-number mb-0">Mission {{ lesson.sequence }}</p>
                </div>
                <span class="mission-status" :class="{ 'is-active': isInProgress }">
                    {{ isInProgress ? 'In progress' : 'Ready to launch' }}
                </span>
            </div>

            <h2 class="mission-title">{{ lesson.title }}</h2>
            <p class="mission-copy">
                {{ isInProgress ? 'Your work is saved. Jump back in right where you stopped.' : 'Your next adventure is ready whenever you are.' }}
            </p>

            <div class="mission-progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`${subject} mission status`">
                <span :style="{ width: `${progress}%` }" />
            </div>
            <div class="d-flex justify-content-between small mt-2 mb-4">
                <span>{{ isInProgress ? 'Mission underway' : 'Launch point' }}</span>
                <span class="fw-semibold">{{ isInProgress ? 'Keep going' : 'Step 1 is next' }}</span>
            </div>

            <Link class="mission-launch" :href="lesson.url">
                {{ lesson.action_label }}
                <span aria-hidden="true">→</span>
            </Link>
        </div>
    </article>
</template>

<style scoped>
.subject-mission-card{--mission-color:#3979a8;--mission-soft:#e8f3fb;position:relative;height:100%;overflow:hidden;border:1px solid #d8e5ef;border-radius:1.35rem;background:#fff;box-shadow:0 12px 30px rgba(22,52,78,.09)}
.mission-science{--mission-color:#19766f;--mission-soft:#e2f4ef}.mission-social-studies{--mission-color:#b45d3d;--mission-soft:#fff0e8}.mission-math{--mission-color:#6952a3;--mission-soft:#f0ebfb}
.mission-card-orbit{position:absolute;width:150px;height:150px;right:-65px;top:-72px;border:22px solid var(--mission-soft);border-radius:50%}.mission-card-body{position:relative;padding:1.5rem}.subject-label{display:inline-block;color:var(--mission-color);font-size:.77rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.mission-number{color:#65798a;font-size:.88rem}.mission-status{border-radius:999px;background:#edf2f6;color:#52697b;padding:.35rem .65rem;font-size:.75rem;font-weight:800;white-space:nowrap}.mission-status.is-active{background:var(--mission-soft);color:var(--mission-color)}.mission-title{color:#193850;font-size:1.3rem;line-height:1.3}.mission-copy{min-height:3rem;color:#5a6f7f}.mission-progress{height:.65rem;overflow:hidden;border-radius:999px;background:#e8eef2}.mission-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--mission-color),#efb34e)}.mission-launch{display:inline-flex;align-items:center;justify-content:center;gap:.65rem;min-height:44px;border-radius:.8rem;background:var(--mission-color);color:#fff;padding:.7rem 1.05rem;font-weight:800;text-decoration:none}.mission-launch:hover,.mission-launch:focus{color:#fff;filter:brightness(.92);box-shadow:0 0 0 .25rem color-mix(in srgb,var(--mission-color) 22%,transparent)}
</style>
