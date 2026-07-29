<script setup lang="ts">
import AcademicNav from '@/Components/AcademicNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps<{ provider?: any }>();
const form = useForm({
    name: props.provider?.name ?? '', short_name: props.provider?.short_name ?? '',
    provider_type: props.provider?.provider_type ?? 'custom', state_or_region: props.provider?.state_or_region ?? '',
    country_code: props.provider?.country_code ?? 'US', website_url: props.provider?.website_url ?? '',
    status: props.provider?.status ?? 'active', notes: props.provider?.notes ?? '',
});
const submit = () => props.provider ? form.patch(route('academic.providers.update', props.provider.id)) : form.post(route('academic.providers.store'));
</script>
<template>
<Head :title="provider ? 'Edit Provider' : 'Add Provider'" /><AuthenticatedLayout>
    <h1 class="h2">{{ provider ? 'Edit custom provider' : 'Add custom provider' }}</h1><AcademicNav />
    <form class="card" @submit.prevent="submit"><div class="card-body"><div class="row g-3">
        <div class="col-md-8"><label for="provider-name" class="form-label">Name</label><input id="provider-name" v-model="form.name" class="form-control" required :aria-describedby="form.errors.name ? 'provider-name-error' : undefined"><div v-if="form.errors.name" id="provider-name-error" class="invalid-feedback d-block">{{ form.errors.name }}</div></div>
        <div class="col-md-4"><label for="provider-short-name" class="form-label">Short name</label><input id="provider-short-name" v-model="form.short_name" class="form-control"></div>
        <div class="col-md-4"><label for="provider-type" class="form-label">Type</label><select id="provider-type" v-model="form.provider_type" class="form-select"><option v-for="type in ['district','state_agency','private_school','homeschool_program','curriculum_publisher','learning_coop','custom']" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option></select></div>
        <div class="col-md-4"><label for="provider-region" class="form-label">State or region</label><input id="provider-region" v-model="form.state_or_region" class="form-control"></div>
        <div class="col-md-4"><label for="provider-country" class="form-label">Country code</label><input id="provider-country" v-model="form.country_code" class="form-control" maxlength="2" required></div>
        <div class="col-md-8"><label for="provider-url" class="form-label">Website URL</label><input id="provider-url" v-model="form.website_url" type="url" class="form-control"><div v-if="form.errors.website_url" class="invalid-feedback d-block">{{ form.errors.website_url }}</div></div>
        <div class="col-md-4"><label for="provider-status" class="form-label">Status</label><select id="provider-status" v-model="form.status" class="form-select"><option value="active">Active</option><option value="retired">Retired</option><option value="archived">Archived</option></select></div>
        <div class="col-12"><label for="provider-notes" class="form-label">Notes</label><textarea id="provider-notes" v-model="form.notes" class="form-control" rows="3" /></div>
    </div></div><div class="card-footer bg-white d-flex justify-content-end gap-2"><Link class="btn btn-outline-secondary" :href="route('academic.providers.index')">Cancel</Link><button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save provider' }}</button></div></form>
</AuthenticatedLayout>
</template>
