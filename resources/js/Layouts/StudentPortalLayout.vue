<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{ restricted?: boolean }>();
const page = usePage<any>();
</script>

<template>
    <a class="skip-link btn btn-light" href="#student-content">Skip to content</a>
    <nav class="navbar navbar-expand-md navbar-dark bg-dark border-bottom" aria-label="Student portal navigation">
        <div class="container">
            <Link class="navbar-brand" :href="restricted ? route('student.password.edit') : route('student.home')">Learning App</Link>
            <button v-if="!restricted" class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav" aria-controls="studentNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon" /></button>
            <div id="studentNav" class="collapse navbar-collapse">
                <ul v-if="!restricted" class="navbar-nav me-auto">
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.home') }" :href="route('student.home')">Home</Link></li>
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.learning') }" :href="route('student.learning')">My Learning</Link></li>
                    <li class="nav-item"><Link class="nav-link" :class="{ active: route().current('student.profile') }" :href="route('student.profile')">Profile</Link></li>
                </ul>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <span class="text-white-50">{{ page.props.tenant?.name }}</span>
                    <Link class="btn btn-sm btn-outline-light" as="button" method="post" :href="route('logout')">Log out</Link>
                </div>
            </div>
        </div>
    </nav>
    <div v-if="page.props.flash?.success" class="container mt-3"><div class="alert alert-success" role="status">{{ page.props.flash.success }}</div></div>
    <main id="student-content" class="container py-4"><slot /></main>
</template>
