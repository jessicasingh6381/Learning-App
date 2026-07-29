<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage<any>();
const can = (permission: string) => page.props.auth.permissions?.includes(permission);
</script>

<template>
    <a class="skip-link btn btn-light" href="#main-content">Skip to content</a>
    <nav class="navbar navbar-expand-lg bg-white border-bottom" aria-label="Main navigation">
        <div class="container-fluid px-lg-4">
            <Link class="navbar-brand" :href="route('dashboard')">Learning App</Link>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <div id="mainNav" class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto">
                    <li v-if="can('dashboard.view')" class="nav-item"><Link class="nav-link" :class="{active: route().current('dashboard')}" :href="route('dashboard')">Dashboard</Link></li>
                    <li v-if="can('students.view')" class="nav-item"><Link class="nav-link" :href="route('students.index')">Students</Link></li>
                    <li v-if="can('school-years.view')" class="nav-item"><Link class="nav-link" :href="route('school-years.index')">School years</Link></li>
                    <li v-if="can('academic-config.view')" class="nav-item dropdown">
                        <button class="nav-link dropdown-toggle" type="button" data-bs-toggle="dropdown" :aria-expanded="false">Academic setup</button>
                        <ul class="dropdown-menu">
                            <li><Link class="dropdown-item" :href="route('academic.overview')">Overview</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.providers.index')">Providers</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.calendars.index')">Calendars</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.standards.index')">Standards</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.subjects.index')">Subjects</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.courses.index')">Courses</Link></li>
                            <li><Link class="dropdown-item" :href="route('academic.curriculum.index')">Curriculum</Link></li>
                        </ul>
                    </li>
                    <li v-if="can('members.view')" class="nav-item"><Link class="nav-link" :href="route('members.index')">Members</Link></li>
                </ul>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span v-if="page.props.tenant" class="badge text-bg-light border">Active: {{ page.props.tenant.name }}</span>
                    <div v-if="page.props.tenants?.length > 1" class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Switch tenant</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li v-for="tenant in page.props.tenants" :key="tenant.id"><Link class="dropdown-item" as="button" method="post" :href="route('tenants.switch', tenant.id)">{{ tenant.name }}</Link></li>
                        </ul>
                    </div>
                    <Link class="btn btn-sm btn-outline-secondary" :href="route('profile.edit')">{{ page.props.auth.user.name }}</Link>
                    <Link class="btn btn-sm btn-outline-danger" as="button" method="post" :href="route('logout')">Log out</Link>
                </div>
            </div>
        </div>
    </nav>
    <div v-if="page.props.flash?.success" class="container mt-3"><div class="alert alert-success" role="status">{{ page.props.flash.success }}</div></div>
    <main id="main-content" class="page-shell"><slot /></main>
</template>
