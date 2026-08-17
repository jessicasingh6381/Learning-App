<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{ restricted?: boolean }>();
const page = usePage<any>();
</script>

<template>
    <a class="skip-link btn btn-light" href="#student-content">Skip to content</a>
    <nav class="student-nav navbar navbar-expand-md navbar-dark" aria-label="Student portal navigation">
        <div class="container">
            <Link class="navbar-brand mission-brand" :href="restricted ? route('student.password.edit') : route('student.home')"><span class="brand-mark" aria-hidden="true">✦</span><span>Mission Control<small>Learning App</small></span></Link>
            <button v-if="!restricted" class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav" aria-controls="studentNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon" /></button>
            <div id="studentNav" class="collapse navbar-collapse">
                <ul v-if="!restricted" class="navbar-nav me-auto">
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.home') }" :href="route('student.home')">Mission Control</Link></li>
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.learning') }" :href="route('student.learning')">Today’s Missions</Link></li>
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.writing-journal.*') }" :href="route('student.writing-journal.index')">Writing Journal</Link></li>
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.profile') }" :href="route('student.profile')">My Profile</Link></li>
                </ul>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <span class="academy-name">{{ page.props.tenant?.name }}</span>
                    <Link class="btn btn-sm btn-outline-light" as="button" method="post" :href="route('logout')">Log out</Link>
                </div>
            </div>
        </div>
    </nav>
    <div v-if="page.props.flash?.success" class="container mt-3"><div class="alert alert-success" role="status">{{ page.props.flash.success }}</div></div>
    <main id="student-content" class="student-space container py-4 py-md-5"><slot /></main>
</template>

<style scoped>
.student-nav{background:#102f4b;border-bottom:4px solid #f0b84b;box-shadow:0 5px 18px rgba(12,38,59,.16)}.mission-brand{display:flex;align-items:center;gap:.65rem;font-weight:900}.mission-brand small{display:block;color:#9fc4d4;font-size:.58rem;letter-spacing:.14em;text-transform:uppercase}.brand-mark{display:grid;place-items:center;width:35px;height:35px;border-radius:50%;background:#f0b84b;color:#17324d}.student-nav .navbar-nav{margin-left:clamp(1rem,4vw,3rem);gap:.35rem}.student-nav .nav-link{border-radius:.6rem;color:#cfdee6;font-weight:700;padding:.55rem .8rem}.student-nav .nav-link:hover,.student-nav .nav-link:focus,.student-nav .nav-link.active{background:rgba(255,255,255,.11);color:#fff}.academy-name{color:#bcd0da;font-size:.82rem}.student-space{min-height:calc(100vh - 78px)}@media(max-width:767px){.student-nav .navbar-nav{margin:.8rem 0}.academy-name{display:none}}
</style>

<style>
body:has(.student-space){background:linear-gradient(180deg,#f2f8f9 0,#f8fafb 38%,#eef5f6 100%)}
</style>
