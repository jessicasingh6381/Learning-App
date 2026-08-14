<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import SubjectMissionCard from '@/Components/StudentPortal/SubjectMissionCard.vue';
import { Head, Link } from '@inertiajs/vue3';

withDefaults(defineProps<{
    student: Record<string, unknown>;
    academy: string;
    enrollment: Record<string, unknown> | null;
    subjects?: Array<{ subject: string; lesson: { id: number; sequence: number; title: string; progress_status: string; action_label: string; url: string } }>;
}>(), { subjects: () => [] });
</script>

<template>
    <Head title="My Learning" />
    <StudentPortalLayout>
        <header class="learning-header">
            <div><p class="learning-eyebrow mb-2">Mission board</p><h1>Today’s Missions</h1><p class="mb-0">One next adventure per subject, lined up and ready for you.</p></div>
            <div v-if="subjects.length" class="mission-count" aria-label="Available mission count"><strong>{{ subjects.length }}</strong><span>{{ subjects.length === 1 ? 'mission ready' : 'missions ready' }}</span></div>
        </header>

        <section v-if="!subjects.length" class="quiet-orbit" aria-labelledby="quiet-orbit-title">
            <div class="orbit-illustration" aria-hidden="true"><span>✦</span></div>
            <p class="learning-eyebrow mb-2">All clear for now</p>
            <h2 id="quiet-orbit-title">Mission Control is preparing your next adventure</h2>
            <p>Your next lesson will land here as soon as it’s ready. Until then, your mission board is all caught up.</p>
            <Link class="btn btn-outline-primary" :href="route('student.home')">Back to Mission Control</Link>
        </section>

        <div v-else class="row g-4 mt-1">
            <section v-for="item in subjects" :key="item.subject" class="col-lg-6">
                <SubjectMissionCard :subject="item.subject" :lesson="item.lesson" />
            </section>
        </div>
    </StudentPortalLayout>
</template>

<style scoped>
.learning-header{display:flex;justify-content:space-between;align-items:center;gap:2rem;margin-bottom:1.5rem;padding:1.7rem;border-radius:1.35rem;background:linear-gradient(120deg,#edf7f6,#edf3fa);border:1px solid #d9e8ed}.learning-header h1{color:#173750;font-size:clamp(2rem,5vw,3.1rem);margin-bottom:.35rem}.learning-header p{color:#5a7182}.learning-eyebrow{color:#19716f;text-transform:uppercase;letter-spacing:.13em;font-size:.76rem;font-weight:900}.mission-count{display:grid;place-items:center;min-width:112px;min-height:96px;border-radius:1rem;background:#fff;color:#173750;box-shadow:0 8px 20px rgba(26,67,91,.08)}.mission-count strong{font-size:2rem;line-height:1}.mission-count span{font-size:.75rem;font-weight:800}.quiet-orbit{max-width:720px;margin:3rem auto;text-align:center;border:1px solid #d8e6ed;border-radius:1.5rem;background:#fff;padding:clamp(2rem,7vw,4rem);box-shadow:0 14px 34px rgba(24,57,80,.08)}.quiet-orbit h2{color:#193850;font-size:clamp(1.5rem,4vw,2rem)}.quiet-orbit>p:not(.learning-eyebrow){max-width:530px;margin:0 auto 1.5rem;color:#5b7182}.orbit-illustration{position:relative;display:grid;place-items:center;width:88px;height:88px;margin:0 auto 1.4rem;border-radius:50%;background:linear-gradient(145deg,#f0b84b,#e17d5f);color:#fff;font-size:1.5rem}.orbit-illustration:after{content:"";position:absolute;width:125px;height:40px;border:5px solid #9fc9d0;border-radius:50%;transform:rotate(-15deg)}@media(max-width:575px){.learning-header{align-items:flex-start}.mission-count{min-width:86px;min-height:80px}.mission-count span{font-size:.68rem}}
</style>
