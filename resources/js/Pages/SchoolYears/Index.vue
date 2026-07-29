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
    instructional_day_target: number | null;
}

defineProps<{ schoolYears: SchoolYear[] }>();

const canManage = usePage<any>().props.auth.permissions.includes(
    'school-years.manage',
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
                            <th>Status</th>
                            <th>Instructional days</th>
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
                            <td><StatusBadge :status="year.status" /></td>
                            <td>
                                {{ year.instructional_day_target ?? '—' }}
                            </td>
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
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
