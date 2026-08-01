<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps<{ sources: any; filters: Record<string, any>; filterSummary?: string | null; options: Record<string, any[]>; canCreate: boolean }>();
const filters = reactive({
    search: props.filters.search ?? '', category: props.filters.category ?? '', kind: props.filters.kind ?? '',
    review_status: props.filters.review_status ?? '', school_year_id: props.filters.school_year_id ?? '',
    education_provider_id: props.filters.education_provider_id ?? '', grade_level_id: props.filters.grade_level_id ?? '',
    archived: props.filters.archived ?? 'active',
});
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const apply = () => router.get(route('academic.sources.index'), filters, { preserveState: true, replace: true });
const clear = () => router.get(route('academic.sources.index'));
</script>

<template>
    <Head title="Academic Sources" />
    <AuthenticatedLayout>
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div><h1 class="h2 mb-1">{{ filterSummary ?? 'Academic sources' }}</h1><p class="text-secondary mb-0">Private uploads, references, and source review history.</p></div>
            <Link v-if="canCreate" class="btn btn-primary" :href="route('academic.sources.create')">Add source</Link>
        </div>
        <AcademicNav />
        <form class="card card-body mb-4" aria-label="Filter academic sources" @submit.prevent="apply">
            <div class="row g-2 align-items-end">
                <div class="col-lg-3"><label for="source-search" class="form-label">Search</label><input id="source-search" v-model="filters.search" class="form-control" type="search" placeholder="Title or description"></div>
                <div class="col-md-3 col-lg-2"><label for="source-category" class="form-label">Category</label><select id="source-category" v-model="filters.category" class="form-select"><option value="">All</option><option v-for="item in options.categories" :key="item" :value="item">{{ label(item) }}</option></select></div>
                <div class="col-md-3 col-lg-2"><label for="source-kind" class="form-label">Kind</label><select id="source-kind" v-model="filters.kind" class="form-select"><option value="">All</option><option v-for="item in options.kinds" :key="item" :value="item">{{ label(item) }}</option></select></div>
                <div class="col-md-3 col-lg-2"><label for="source-review" class="form-label">Review</label><select id="source-review" v-model="filters.review_status" class="form-select"><option value="">All</option><option v-for="item in options.reviewStatuses" :key="item" :value="item">{{ label(item) }}</option></select></div>
                <div class="col-md-3 col-lg-2"><label for="source-archive" class="form-label">Records</label><select id="source-archive" v-model="filters.archived" class="form-select"><option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option></select></div>
                <div class="col-md-4"><label for="source-year" class="form-label">School year</label><select id="source-year" v-model="filters.school_year_id" class="form-select"><option value="">All</option><option v-for="item in options.schoolYears" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                <div class="col-md-4"><label for="source-provider" class="form-label">Provider</label><select id="source-provider" v-model="filters.education_provider_id" class="form-select"><option value="">All</option><option v-for="item in options.providers" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                <div class="col-md-4"><label for="source-grade" class="form-label">Grade</label><select id="source-grade" v-model="filters.grade_level_id" class="form-select"><option value="">All</option><option v-for="item in options.gradeLevels" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                <div class="col-12 d-flex gap-2"><button class="btn btn-outline-primary">Apply filters</button><button class="btn btn-link" type="button" @click="clear">Clear</button></div>
            </div>
        </form>
        <div v-if="!sources.data.length" class="empty-state card">No academic sources match these filters.</div>
        <div v-else class="card"><div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th>Source</th><th>Category</th><th>Context</th><th>Review</th><th>Attachment</th><th></th></tr></thead>
            <tbody><tr v-for="source in sources.data" :key="source.id">
                <td><strong>{{ source.title }}</strong><div class="small text-secondary text-capitalize">{{ label(source.source_kind) }} · {{ label(source.authority_level) }}</div></td>
                <td>{{ label(source.source_category) }}</td>
                <td><div>{{ source.school_year?.name ?? 'Any year' }}</div><small class="text-secondary">{{ source.education_provider?.name ?? 'Any provider' }}<span v-if="source.grade_level"> · {{ source.grade_level.name }}</span></small></td>
                <td><span class="badge text-bg-light border">{{ label(source.review_status) }}</span><div class="small text-secondary">{{ label(source.processing_status) }}</div></td>
                <td><span v-if="source.current_file">{{ source.current_file.original_filename }} (v{{ source.current_file.version_number }})</span><span v-else-if="source.source_kind === 'url'">External URL</span><span v-else class="text-secondary">None</span><div class="small text-secondary">Updated {{ new Date(source.updated_at).toLocaleDateString() }}</div></td>
                <td class="text-end"><Link class="btn btn-sm btn-outline-primary" :href="route('academic.sources.show', source.id)">View</Link></td>
            </tr></tbody>
        </table></div></div>
        <nav v-if="sources.links.length > 3" class="mt-3" aria-label="Source pages"><div class="d-flex flex-wrap gap-1"><Link v-for="link in sources.links" :key="link.label" class="btn btn-sm" :class="link.active ? 'btn-primary' : 'btn-outline-secondary'" :href="link.url ?? '#'" :aria-disabled="!link.url" v-html="link.label" /></div></nav>
    </AuthenticatedLayout>
</template>
