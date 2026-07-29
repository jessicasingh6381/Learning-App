<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';

interface SchoolYear {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: string;
    instructional_weekday_label: string;
    base_instructional_days: number;
    instructional_day_target: number | null;
}

defineProps<{ schoolYears: SchoolYear[] }>();

const canManage = usePage<any>().props.auth.permissions.includes(
    'school-years.manage',
);
const canConfigureAcademics = usePage<any>().props.auth.permissions.includes(
    'academic-config.view',
);
</script>

<template>
    <Head title="School years" />
    <AuthenticatedLayout>
        <div
            class="mb-4 d-flex align-items-center justify-content-between"
        >
            <div>
                <h1 class="h2">School years</h1>
                <p class="mb-0 text-secondary">
                    One school year may be active at a time. History is never
                    deleted.
                </p>
                <p class="mb-0 small text-secondary">
                    Base days come from the weekly schedule. Academic setup
                    applies saved calendar exceptions and overrides.
                </p>
            </div>
            <Link
                v-if="canManage"
                class="btn btn-primary"
                :href="route('school-years.create')"
            >
                Add school year
            </Link>
        </div>
        <div class="card">
            <div v-if="!schoolYears.length" class="empty-state">
                Create the first school year to begin enrollment planning.
            </div>
            <div v-else class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Dates</th>
                            <th>Weekly schedule</th>
                            <th>Base instructional days</th>
                            <th>Day target</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="year in schoolYears" :key="year.id">
                            <td>{{ year.name }}</td>
                            <td>
                                {{ formatDateOnly(year.start_date) }} –
                                {{ formatDateOnly(year.end_date) }}
                            </td>
                            <td>{{ year.instructional_weekday_label }}</td>
                            <td>{{ year.base_instructional_days }}</td>
                            <td>
                                {{
                                    year.instructional_day_target ??
                                    'Not set'
                                }}
                            </td>
                            <td><StatusBadge :status="year.status" /></td>
                            <td class="text-end">
                                        <Link
                                            v-if="canManage"
                                            class="btn btn-sm btn-outline-secondary"
                                    :href="
                                        route(
                                            'school-years.edit',
                                            year.id,
                                        )
                                    "
                                >
                                            Edit
                                        </Link>
                                        <Link
                                            v-if="canConfigureAcademics"
                                            class="btn btn-sm btn-outline-primary ms-2"
                                            :href="route('academic.overview', { school_year_id: year.id })"
                                        >
                                            Configure academics
                                        </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
