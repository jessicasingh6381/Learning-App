<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import OwnershipBadge from '@/Components/OwnershipBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
defineProps<{ providers: any[] }>();
const canManage = usePage<any>().props.auth.permissions.includes('providers.manage');
</script>
<template>
<Head title="Education Providers" /><AuthenticatedLayout>
    <div class="d-flex justify-content-between mb-3"><div><h1 class="h2">Education providers</h1><p class="text-secondary">Reference sources and tenant-created profiles.</p></div><Link v-if="canManage" class="btn btn-primary align-self-start" :href="route('academic.providers.create')">Add custom provider</Link></div>
    <AcademicNav />
    <div v-if="!providers.length" class="empty-state card">No providers are available.</div>
    <div v-else class="table-responsive card"><table class="table align-middle mb-0"><thead><tr><th>Name</th><th>Type</th><th>Ownership</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
        <tr v-for="provider in providers" :key="provider.id"><td><strong>{{ provider.name }}</strong><small v-if="provider.short_name" class="d-block text-secondary">{{ provider.short_name }}</small></td><td class="text-capitalize">{{ provider.provider_type.replaceAll('_', ' ') }}</td><td><OwnershipBadge :shared="provider.is_shared" /></td><td class="text-capitalize">{{ provider.status }}</td><td class="text-end"><Link v-if="canManage && !provider.is_shared" class="btn btn-sm btn-outline-secondary" :href="route('academic.providers.edit', provider.id)">Edit</Link></td></tr>
    </tbody></table></div>
</AuthenticatedLayout>
</template>
