<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const items = [
    ['academic.overview', 'Overview'],
    ['academic.providers.*', 'Providers'],
    ['academic.calendars.*', 'Calendars'],
    ['academic.standards.*', 'Standards'],
    ['academic.subjects.*', 'Subjects'],
    ['academic.courses.*', 'Courses'],
    ['academic.curriculum.*', 'Curriculum'],
];
</script>

<template>
    <nav class="nav nav-pills gap-1 mb-4" aria-label="Academic setup">
        <Link
            v-for="[routeName, label] in items"
            :key="routeName"
            class="nav-link"
            :class="{ active: route().current(routeName) }"
            :href="route(routeName.replace('.*', '.index'))"
        >
            {{ label }}
        </Link>
    </nav>
</template>
