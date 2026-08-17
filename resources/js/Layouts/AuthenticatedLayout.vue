<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage<any>();
const can = (permission: string) => page.props.auth.permissions?.includes(permission);
const primary = [
    ['Home', 'dashboard'], ['Students', 'students.index'], ['Learning Plan', 'workspace.learning-plan'],
    ['Calendar', 'workspace.calendar'], ['Writing Journal', 'creative-writing.index'], ['Assignments', 'workspace.placeholder', 'assignments'],
    ['Gradebook', 'workspace.placeholder', 'gradebook'], ['Attendance', 'workspace.placeholder', 'attendance'],
    ['Reports', 'workspace.placeholder', 'reports'],
] as const;
const active = (name: string, section?: string) => section
    ? route().current(name) && route().params.section === section
    : route().current(name);
</script>

<template>
    <a class="skip-link btn btn-light" href="#main-content">Skip to content</a>
    <nav class="navbar navbar-expand-xl bg-white border-bottom" aria-label="Parent and teacher workspace navigation">
        <div class="container-fluid px-lg-4">
            <Link class="navbar-brand" :href="route('dashboard')">Learning App</Link>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <div id="mainNav" class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto"><li v-for="item in primary" :key="`${item[1]}-${item[2] ?? ''}`" class="nav-item"><Link class="nav-link" :class="{ active: active(item[1], item[2]) }" :href="item[2] ? route(item[1], { section: item[2] }) : route(item[1])">{{ item[0] }}</Link></li></ul>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span v-if="page.props.tenant" class="badge text-bg-light border">{{ page.props.tenant.name }}</span>
                    <div v-if="page.props.tenants?.length > 1" class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Switch academy</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li v-for="tenant in page.props.tenants" :key="tenant.id"><Link class="dropdown-item" as="button" method="post" :href="route('tenants.switch', tenant.id)">{{ tenant.name }}</Link></li>
                        </ul>
                    </div>
                    <div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Settings</button><ul class="dropdown-menu dropdown-menu-end">
                        <li><Link class="dropdown-item" :href="route('workspace.settings')">Workspace settings</Link></li><li><Link class="dropdown-item" :href="route('profile.edit')">My profile</Link></li>
                        <li v-if="can('tenant.manage')"><Link class="dropdown-item" :href="route('settings.foundation')">Foundation administration</Link></li>
                        <li v-if="can('advanced-academic.view')"><Link class="dropdown-item" :href="route('academic.overview')">Advanced Academic Setup</Link></li><li><hr class="dropdown-divider"></li>
                        <li><Link class="dropdown-item text-danger" as="button" method="post" :href="route('logout')">Log out {{ page.props.auth.user.name }}</Link></li>
                    </ul></div>
                </div>
            </div>
        </div>
    </nav>
    <div v-if="page.props.flash?.success" class="container mt-3"><div class="alert alert-success" role="status">{{ page.props.flash.success }}</div></div>
    <div v-if="Object.keys(page.props.errors || {}).length" class="container mt-3"><div class="alert alert-danger" role="alert"><strong>The action could not be completed.</strong><ul class="mb-0 mt-1"><li v-for="(message, field) in page.props.errors" :key="field">{{ message }}</li></ul></div></div>
    <main id="main-content" class="page-shell"><slot /></main>
</template>
