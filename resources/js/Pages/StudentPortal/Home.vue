<script setup lang="ts">
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    student: { first_name: string; preferred_name: string | null };
    academy: string;
    enrollment: { school_year: string; grade_level: string; status: string } | null;
}>();
</script>

<template>
    <Head title="Mission Control" />
    <StudentPortalLayout>
        <section class="mission-hero mb-4">
            <div class="hero-stars" aria-hidden="true">✦</div>
            <div class="hero-copy">
                <p class="mission-eyebrow mb-2">Mission Control</p>
                <h1>Welcome back, {{ student.preferred_name || student.first_name }}!</h1>
                <p class="lead mb-4">Your learning adventure is ready. Check today’s mission and launch when you’re set.</p>
                <Link class="hero-action" :href="route('student.learning')">View today’s missions <span aria-hidden="true">→</span></Link>
            </div>
            <div class="planet-mark" aria-hidden="true"><span /></div>
        </section>

        <section v-if="enrollment" class="mission-details mb-4" aria-label="Current learning details">
            <div><span class="detail-icon" aria-hidden="true">05</span><p>Current level</p><strong>{{ enrollment.grade_level }}</strong></div>
            <div><span class="detail-icon" aria-hidden="true">✦</span><p>Mission year</p><strong>{{ enrollment.school_year }}</strong></div>
            <div><span class="detail-icon status-dot" aria-hidden="true" /><p>Explorer status</p><strong class="text-capitalize">{{ enrollment.status }}</strong></div>
        </section>
        <div v-else class="mission-notice">Your crew is finishing your explorer setup. Check back soon!</div>

        <section class="next-adventure">
            <div class="next-icon" aria-hidden="true">↗</div>
            <div><p class="mission-eyebrow text-primary mb-1">Your next adventure</p><h2>Head to Today’s Missions</h2><p class="mb-0">You’ll see the next ready lesson for each subject—no hunting through a long list.</p></div>
            <Link class="btn btn-primary btn-lg" :href="route('student.learning')">Open missions</Link>
        </section>
    </StudentPortalLayout>
</template>

<style scoped>
.mission-hero{position:relative;display:flex;align-items:center;min-height:330px;overflow:hidden;border-radius:1.7rem;background:radial-gradient(circle at 82% 25%,#275c82 0 4%,transparent 5%),linear-gradient(135deg,#102f4b,#174f70 58%,#126c70);color:#fff;padding:clamp(2rem,6vw,4.5rem);box-shadow:0 18px 42px rgba(16,47,75,.2)}.hero-copy{position:relative;z-index:2;max-width:650px}.mission-eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.78rem;font-weight:900}.mission-hero h1{font-size:clamp(2.2rem,6vw,4rem);line-height:1.05}.mission-hero .lead{max-width:580px;color:#d9edf3}.hero-action{display:inline-flex;align-items:center;gap:.65rem;border-radius:.85rem;background:#f0b84b;color:#17324d;padding:.8rem 1.1rem;font-weight:900;text-decoration:none}.hero-action:hover,.hero-action:focus{background:#ffd06e;color:#17324d}.hero-stars{position:absolute;right:31%;top:18%;color:#f0b84b;font-size:1.4rem;filter:drop-shadow(55px 75px 0 #fff) drop-shadow(-30px 145px 0 #79c9c6)}.planet-mark{position:absolute;right:-45px;bottom:-70px;width:270px;height:270px;border-radius:50%;background:linear-gradient(145deg,#f0b84b,#e27a5f);box-shadow:inset -30px -25px 0 rgba(135,57,51,.2)}.planet-mark:before{content:"";position:absolute;left:-55px;top:105px;width:360px;height:60px;border:12px solid rgba(255,255,255,.55);border-radius:50%;transform:rotate(-13deg)}.planet-mark span{position:absolute;width:38px;height:18px;left:78px;top:54px;border-radius:50%;background:rgba(135,57,51,.18)}.mission-details{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}.mission-details>div{display:grid;grid-template-columns:auto 1fr;column-gap:.9rem;align-items:center;border:1px solid #dbe7ed;border-radius:1rem;background:#fff;padding:1.1rem 1.25rem}.mission-details p{margin:0;color:#6a7d8b;font-size:.82rem}.mission-details strong{color:#193850;font-size:1.05rem}.detail-icon{grid-row:1/3;display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:#e4f2f0;color:#176b6c;font-weight:900}.status-dot{position:relative}.status-dot:after{content:"";width:12px;height:12px;border-radius:50%;background:#2c9a74;box-shadow:0 0 0 6px #bfe7d7}.next-adventure{display:grid;grid-template-columns:auto 1fr auto;gap:1.2rem;align-items:center;border:1px solid #d9e5ec;border-radius:1.3rem;background:#fff;padding:1.5rem;box-shadow:0 10px 24px rgba(31,61,83,.07)}.next-adventure h2{font-size:1.4rem;color:#193850}.next-adventure p{color:#607586}.next-icon{display:grid;place-items:center;width:58px;height:58px;border-radius:1rem;background:#e8f3fb;color:#3979a8;font-size:1.7rem;font-weight:900}.mission-notice{border-radius:1rem;background:#e8f3fb;color:#234f6d;padding:1rem 1.25rem;margin-bottom:1.5rem;font-weight:700}@media(max-width:767px){.planet-mark{opacity:.28;right:-120px}.mission-details{grid-template-columns:1fr}.next-adventure{grid-template-columns:auto 1fr}.next-adventure .btn{grid-column:1/-1;width:100%}}@media(max-width:480px){.mission-hero{min-height:360px;padding:1.5rem}.mission-hero h1{font-size:2.35rem}}
</style>
