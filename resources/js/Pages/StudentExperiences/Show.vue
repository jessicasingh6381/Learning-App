<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InteractiveUsMap from '@/Components/Lessons/InteractiveUsMap.vue';
import EvidenceAnalysisBuilder from '@/Components/Lessons/EvidenceAnalysisBuilder.vue';
import EarthProcessVisual from '@/Components/Lessons/EarthProcessVisual.vue';
import SystemsMapBuilder from '@/Components/Lessons/SystemsMapBuilder.vue';
import ScienceWorkBuilder from '@/Components/Lessons/ScienceWorkBuilder.vue';
import MathVisual from '@/Components/Lessons/MathVisual.vue';
import MathWorkBuilder from '@/Components/Lessons/MathWorkBuilder.vue';
import ElarReadingPassage from '@/Components/Lessons/ElarReadingPassage.vue';
import ElarResponseBuilder from '@/Components/Lessons/ElarResponseBuilder.vue';
import ElarSyllablePatterns from '@/Components/Lessons/ElarSyllablePatterns.vue';
import TechnologyCodeWorkspace from '@/Components/Lessons/TechnologyCodeWorkspace.vue';
import LanguagePhrasePlayer from '@/Components/Lessons/LanguagePhrasePlayer.vue';
import LanguagePassportBuilder from '@/Components/Lessons/LanguagePassportBuilder.vue';
import LanguageWorkBuilder from '@/Components/Lessons/LanguageWorkBuilder.vue';

const props = defineProps<{ preview: boolean; return_url: string; student: any; lesson: any; experience: any; progress: any; activities: any[]; resource_groups: any }>();
const activeId = ref(props.progress.current_activity_id);
const active = computed(() => props.activities.find((item) => item.id === activeId.value) || props.activities[0]);
const interactiveResource = computed(() => props.resource_groups.lesson_resource?.find((item: any) => item.resource_type === 'interactive_us_map' && item.url));
const physicalMapResource = computed(() => props.resource_groups.lesson_resource?.find((item: any) => item.resource_type === 'physical_us_map' && item.url));
const populationDataResource = computed(() => props.resource_groups.lesson_resource?.find((item: any) => item.resource_type === 'us_population_density_data' && item.url));
const coastalChangeResource = computed(() => props.resource_groups.lesson_resource?.find((item: any) => item.title === 'Changing Landscapes Photograph Set' && item.url));
const referencedActivity = computed(() => props.activities.find((item: any) => item.sequence === active.value.interaction?.reference_activity_sequence));
const mapMode = computed(() => active.value.interaction?.map_mode || (active.value.sequence === 2 ? 'map_tools' : active.value.sequence === 5 ? 'reference' : active.value.sequence === 6 ? 'builder' : 'explore'));
const secondaryResources = computed(() => props.resource_groups.lesson_resource?.filter((item: any) => !['interactive', 'embedded'].includes(item.delivery_type)) || []);
const requiredStudentSupplies = computed(() => props.resource_groups.student_supply?.filter((item: any) => item.student_experience_required === true) || []);
const requiredSpecialMaterials = computed(() => props.resource_groups.special_material?.filter((item: any) => item.student_experience_required === true) || []);
const hasRequiredPhysicalMaterials = computed(() => requiredStudentSupplies.value.length > 0 || requiredSpecialMaterials.value.length > 0);
const showSecondaryResources = computed(() => !interactiveResource.value && !coastalChangeResource.value && secondaryResources.value.length > 0);
const showResourcePanel = computed(() => showSecondaryResources.value || hasRequiredPhysicalMaterials.value);
const resourceAction = (resource: any) => resource.resource_type === 'blank_map' ? 'Print blank map' : resource.resource_type === 'reference_map' ? 'View reference map' : resource.delivery_type === 'printable' ? 'View / Print' : 'View';
const blankResponse = (activity: any) => {
    if (activity.saved_response) return JSON.parse(JSON.stringify(activity.saved_response));
    if (activity.type === 'instruction') return { acknowledged: false };
    if (activity.type === 'multiple_choice') return { selected: '' };
    if (activity.type === 'matching') return { matches: {} };
    if (activity.type === 'question_set') return { answers: {} };
    if (activity.type === 'short_response') return Object.fromEntries((activity.interaction?.fields || []).map((field: any) => [field.id, '']));
    if (activity.type === 'project' && activity.interaction?.analysis_builder) return {
        analysis: { observations: [{ state_fips: '', statement: '' }, { state_fips: '', statement: '' }], patterns: ['', ''], inference: '', limitation: '' },
    };
    if (activity.type === 'project' && activity.interaction?.systems_map_builder) return {
        systems_map: { terms: [], connections: Array.from({ length: activity.interaction.systems_map_builder.minimum_connections || 3 }, () => ({ from: '', relationship: '', to: '' })), question: '' },
    };
    if (activity.type === 'project' && activity.interaction?.science_work_builder) return {
        science_work: Object.fromEntries((activity.interaction.science_work_builder.sections || []).flatMap((section: any) => section.fields || []).map((field: any) => [field.id, ''])),
    };
    if (activity.type === 'project' && activity.interaction?.math_work_builder) return {
        math_work: Object.fromEntries((activity.interaction.math_work_builder.sections || []).flatMap((section: any) => section.fields || []).map((field: any) => [field.id, ''])),
    };
    if (activity.type === 'project' && activity.interaction?.elar_response_builder) return {
        elar_work: Object.fromEntries((activity.interaction.elar_response_builder.fields || []).map((field: any) => [field.id, ''])),
    };
    if (activity.type === 'project' && activity.interaction?.technology_code_builder) return {
        technology_work: { source: activity.interaction.technology_code_builder.starter_code || '', prediction: '', reflection: '', inputs: Object.fromEntries((activity.interaction.technology_code_builder.input_fields || []).map((field: any) => [field.id, field.default || ''])) },
    };
    if (activity.type === 'project' && activity.interaction?.language_passport_builder) return {
        language_work: { greetings: [], farewells: [], practice_line: '', reason: '', speaking_self_check: false },
    };
    if (activity.type === 'project' && activity.interaction?.language_work_builder) return {
        language_practice: Object.fromEntries((activity.interaction.language_work_builder.fields || []).map((field: any) => [field.id, field.control === 'multi_select' ? [] : field.control === 'checkbox' ? false : ''])),
    };
    if (activity.type === 'project' && activity.interaction?.region_builder) return {
        map: {
            title: '', criterion: '',
            regions: Array.from({ length: activity.interaction.region_builder.minimum_regions || 3 }, (_, index) => ({
                id: `region_${index + 1}`, name: '', color_key: activity.interaction.region_builder.color_keys?.[index] || '',
                state_fips: Array.from({ length: activity.interaction.region_builder.minimum_states_per_region || 2 }, () => ''),
            })),
        },
        reflections: Object.fromEntries((activity.interaction.reflection_fields || []).map((field: any) => [field.id, ''])),
    };
    if (activity.type === 'project' && activity.interaction?.map_builder) return {
        map: { title: '', show_orientation: false, features: Array.from({ length: activity.interaction.map_builder.minimum_features || 3 }, () => ({ state_fips: '', marker_key: '', legend_label: '' })) },
        reflections: Object.fromEntries((activity.interaction.reflection_fields || []).map((field: any) => [field.id, ''])),
    };
    if (activity.type === 'project') return { checklist: [], observations: ['', ''], limitation: '' };
    return {};
};
const form = useForm({ response: blankResponse(active.value) });
const draftStatus = ref('');
let draftTimer: ReturnType<typeof setTimeout> | undefined;
watch(active, (activity) => { form.response = blankResponse(activity); form.clearErrors(); });
const isDone = (activity: any) => ['completed', 'submitted'].includes(activity.response_status);
const canOpen = (activity: any) => isDone(activity) || activity.id === props.progress.current_activity_id;
watch(() => form.response, () => {
    if (!active.value.draft_url || isDone(active.value)) return;
    if (draftTimer) clearTimeout(draftTimer);
    draftStatus.value = active.value.interaction?.science_work_builder || active.value.interaction?.math_work_builder || active.value.interaction?.elar_response_builder || active.value.interaction?.technology_code_builder || active.value.interaction?.language_passport_builder || active.value.interaction?.language_work_builder ? 'Saving work…' : 'Saving map progress…';
    const activityId = active.value.id;
    const draftUrl = active.value.draft_url;
    const draftResponse = JSON.parse(JSON.stringify(form.response));
    draftTimer = setTimeout(async () => {
        try {
            await axios.post(draftUrl, { response: draftResponse });
            if (active.value.id === activityId) draftStatus.value = active.value.interaction?.science_work_builder || active.value.interaction?.math_work_builder || active.value.interaction?.elar_response_builder || active.value.interaction?.technology_code_builder || active.value.interaction?.language_passport_builder || active.value.interaction?.language_work_builder ? 'Work saved.' : 'Map progress saved.';
        } catch {
            if (active.value.id === activityId) draftStatus.value = active.value.interaction?.science_work_builder || active.value.interaction?.math_work_builder || active.value.interaction?.elar_response_builder || active.value.interaction?.technology_code_builder || active.value.interaction?.language_passport_builder || active.value.interaction?.language_work_builder ? 'Work could not be saved yet. Keep this page open and try again.' : 'Map progress could not be saved yet. Keep this page open and try again.';
        }
    }, 600);
}, { deep: true });
onBeforeUnmount(() => { if (draftTimer) clearTimeout(draftTimer); });
const submit = () => {
    if (draftTimer) clearTimeout(draftTimer);
    form.post(active.value.response_url, { preserveScroll: true });
};
const advance = () => {
    if (!isDone(active.value)) return;
    const currentIndex = props.activities.findIndex((item) => item.id === active.value.id);
    const next = props.activities[currentIndex + 1];
    if (next && canOpen(next)) activeId.value = next.id;
};
</script>

<template>
    <Head :title="experience.mission_title" />
    <a class="skip-link" href="#mission-content">Skip to mission</a>
    <div class="experience-shell">
        <header class="mission-bar">
            <div class="container py-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div><span class="eyebrow">{{ lesson.subject }} · Explorer Lab</span><strong class="d-block fs-5">{{ experience.mission_title }}</strong></div>
                <Link class="return-link" :href="return_url">← {{ preview ? 'Return to teacher lesson' : 'Return to My Learning' }}</Link>
            </div>
        </header>
        <div v-if="preview" class="preview-banner" role="status"><strong>Teacher preview</strong> — This draft is not available in {{ student.display_name }}’s normal student portal. Preview progress is stored separately.</div>

        <main id="mission-content" class="container mission-wrap py-4 py-lg-5">
            <section v-if="progress.status === 'completed'" class="completion-card text-center" aria-live="polite">
                <div class="completion-mark" aria-hidden="true">✓</div><p class="eyebrow">{{ progress.completed_count }} of {{ progress.total_count }} field steps complete</p>
                <h1>{{ experience.completion_title }}</h1><p>{{ experience.completion_message }}</p>
                <div class="review-note">Your written responses and digital map are saved for parent/teacher review.</div>
                <button class="btn explorer-button mt-3" type="button" @click="activeId = activities[activities.length - 1].id">Review final check</button>
            </section>

            <template v-else>
                <section class="mission-intro mb-4"><div><p class="eyebrow mb-2">Lesson mission for {{ student.display_name }}</p><h1>{{ experience.mission_title }}</h1><p class="lead mb-2">{{ experience.mission_brief }}</p><small>{{ lesson.estimated_minutes }} minutes of learning · {{ student.grade_level }}</small></div><div class="compass" aria-hidden="true">N<br><span>✦</span></div></section>

                <section class="progress-panel mb-4" aria-label="Mission progress">
                    <div class="d-flex justify-content-between gap-2 mb-2"><strong>{{ progress.completed_count }} of {{ progress.total_count }} activities completed</strong><span>{{ progress.percent }}%</span></div>
                    <div class="progress-track"><div class="progress-fill" :style="{ width: `${progress.percent}%` }" /></div>
                    <div class="step-nav mt-3">
                        <button v-for="item in activities" :key="item.id" type="button" :disabled="!canOpen(item)" :class="{ active: item.id === active.id, done: isDone(item) }" :aria-label="`Step ${item.sequence}: ${item.title}`" @click="activeId = item.id">{{ isDone(item) ? '✓' : item.sequence }}</button>
                    </div>
                </section>

                <InteractiveUsMap v-if="interactiveResource && !['reference', 'builder', 'comparison', 'region_builder', 'settlement_data'].includes(mapMode)" :resource-url="interactiveResource.url" :mode="mapMode" />

                <section v-if="showResourcePanel" class="resource-panel mb-4" :style="showSecondaryResources && hasRequiredPhysicalMaterials ? undefined : { gridTemplateColumns: '1fr' }" aria-label="Lesson supplies and resources">
                    <div v-if="showSecondaryResources"><h2>Print and reference maps</h2><p class="small">Use these when the lesson asks for paper or a detailed reference. The interactive map above is the main on-screen learning tool.</p><ul><li v-for="resource in secondaryResources" :key="resource.id"><div><strong>{{ resource.title }}</strong><small v-if="resource.description">{{ resource.description }}</small></div><a v-if="resource.url" class="resource-action" :href="resource.url" target="_blank">{{ resourceAction(resource) }}</a><span v-else-if="resource.availability_status === 'needs_asset'" class="asset-needed">Preparing resource...</span><span v-else-if="resource.availability_status === 'unavailable'" class="asset-needed">Resource unavailable / needs review</span></li></ul></div>
                    <div v-if="hasRequiredPhysicalMaterials"><h2>You’ll need</h2><ul v-if="requiredStudentSupplies.length"><li v-for="resource in requiredStudentSupplies" :key="resource.id"><strong>{{ resource.title }}</strong><small v-if="resource.description">{{ resource.description }}</small></li></ul><template v-if="requiredSpecialMaterials.length"><h3 v-if="requiredStudentSupplies.length">Special or household materials</h3><ul><li v-for="resource in requiredSpecialMaterials" :key="resource.id"><strong>{{ resource.title }}</strong><small v-if="resource.description">{{ resource.description }}</small></li></ul></template></div>
                </section>

                <article class="activity-card" :class="`activity-${active.theme_key || 'default'}`">
                    <div class="activity-accent"><span>Step {{ active.sequence }} of {{ activities.length }}</span><span v-if="active.requires_teacher_review">Parent/teacher review</span></div>
                    <div class="activity-body">
                        <p class="activity-kind">{{ active.type.replaceAll('_', ' ') }}</p><h2>{{ active.title }}</h2>
                        <p class="instructions">{{ active.instructions }}</p><p v-if="active.content" class="content-block">{{ active.content }}</p>
                        <EarthProcessVisual v-if="active.interaction?.science_visual" :mode="active.interaction.science_visual" :photo-url="coastalChangeResource?.url" />
                        <MathVisual v-if="active.interaction?.math_visual" :config="active.interaction.math_visual" />
                        <ElarReadingPassage v-if="active.interaction?.reading_passage && !active.interaction?.elar_response_builder" :passage="active.interaction.reading_passage" :focus-sentence-ids="active.interaction.focus_sentence_ids" />
                        <ElarSyllablePatterns v-if="active.interaction?.syllable_patterns" :patterns="active.interaction.syllable_patterns" />
                        <LanguagePhrasePlayer v-if="active.interaction?.language_phrases" :config="active.interaction.language_phrases" />
                        <section v-if="active.interaction?.code_display" class="code-demonstration"><strong>Python example</strong><pre><code>{{ active.interaction.code_display.source }}</code></pre><template v-if="!active.interaction.code_display.hide_output_until_response || isDone(active)"><strong>Illustrated output</strong><pre class="code-output">{{ active.interaction.code_display.output.join('\n') }}</pre></template><p v-else class="output-hidden">Make your prediction to reveal the illustrated output.</p><p>{{ active.interaction.code_display.execution_notice }}</p></section>
                        <div v-if="active.interaction?.weather_dataset" class="dataset-wrap" tabindex="0" role="region" aria-label="Two-day coastal weather dataset">
                            <table><caption>Two-day coastal weather instructional dataset</caption><thead><tr><th>Time</th><th>Air °C</th><th>Water °C</th><th>Humidity</th><th>Cloud cover</th><th>Precipitation</th></tr></thead><tbody><tr v-for="row in active.interaction.weather_dataset" :key="row.time"><th>{{ row.time }}</th><td>{{ row.air }}</td><td>{{ row.water }}</td><td>{{ row.humidity }}%</td><td>{{ row.cloud }}%</td><td>{{ row.precipitation.toFixed(1) }} mm</td></tr></tbody></table>
                        </div>
                        <SystemsMapBuilder v-if="referencedActivity?.saved_response?.systems_map" :model-value="referencedActivity.saved_response.systems_map" :config="referencedActivity.interaction.systems_map_builder" disabled />
                        <InteractiveUsMap v-if="interactiveResource && mapMode === 'reference'" :resource-url="interactiveResource.url" mode="reference" />
                        <InteractiveUsMap v-if="interactiveResource && mapMode === 'comparison'" :resource-url="interactiveResource.url" :physical-resource-url="physicalMapResource?.url" mode="comparison" />
                        <InteractiveUsMap v-if="interactiveResource && mapMode === 'builder'" v-model="form.response.map" :resource-url="interactiveResource.url" mode="builder" :disabled="isDone(active)" />
                        <InteractiveUsMap v-if="interactiveResource && mapMode === 'region_builder'" v-model="form.response.map" :resource-url="interactiveResource.url" :physical-resource-url="physicalMapResource?.url" mode="region_builder" :disabled="isDone(active)" />
                        <InteractiveUsMap v-if="interactiveResource && mapMode === 'settlement_data'" :resource-url="interactiveResource.url" :physical-resource-url="physicalMapResource?.url" :population-resource-url="populationDataResource?.url" mode="settlement_data" />
                        <p v-if="active.draft_url && draftStatus" class="draft-status" role="status" aria-live="polite">{{ draftStatus }}</p>

                        <div v-if="active.interaction?.student_supplies && !interactiveResource" class="tool-grid"><div v-for="material in active.interaction.student_supplies" :key="material">▣ {{ material }}</div></div>
                        <div v-if="active.interaction?.facts && !interactiveResource" class="fact-grid"><section v-for="fact in active.interaction.facts" :key="fact.label"><strong>{{ fact.label }}</strong><p>{{ fact.detail }}</p></section></div>

                        <form @submit.prevent="submit">
                            <label v-if="active.type === 'instruction'" class="acknowledge"><input v-model="form.response.acknowledged" type="checkbox" :disabled="isDone(active)"> <span>I’m ready to continue</span></label>
                            <fieldset v-if="active.type === 'multiple_choice'" class="choice-list" :disabled="isDone(active)"><legend class="visually-hidden">Choose one answer</legend><label v-for="choice in active.interaction.choices" :key="choice.id"><input v-model="form.response.selected" type="radio" :value="choice.id" :disabled="isDone(active)"> <span>{{ choice.label }}</span></label></fieldset>
                            <div v-if="active.type === 'matching'" class="matching-grid"><label v-for="prompt in active.interaction.prompts" :key="prompt.id"><strong>{{ prompt.label }}</strong><select v-model="form.response.matches[prompt.id]" class="form-select" :disabled="isDone(active)"><option value="">Choose its job</option><option v-for="option in active.interaction.options" :key="option.id" :value="option.id">{{ option.label }}</option></select></label></div>
                            <div v-if="active.type === 'short_response'" class="response-fields"><template v-for="field in active.interaction.fields" :key="field.id"><fieldset v-if="field.control === 'multiple_choice'" class="response-choice" :disabled="isDone(active)"><legend>{{ field.label }}</legend><label v-for="choice in field.choices" :key="choice.id"><input v-model="form.response[field.id]" type="radio" :value="choice.id" :disabled="isDone(active)"> {{ choice.label }}</label></fieldset><label v-else><strong>{{ field.label }}</strong><textarea v-model="form.response[field.id]" class="form-control" rows="2" :disabled="isDone(active)" /></label></template></div>
                            <EvidenceAnalysisBuilder v-if="active.type === 'project' && active.interaction?.analysis_builder" v-model="form.response.analysis" :config="active.interaction.analysis_builder" :disabled="isDone(active)" />
                            <SystemsMapBuilder v-else-if="active.type === 'project' && active.interaction?.systems_map_builder" v-model="form.response.systems_map" :config="active.interaction.systems_map_builder" :disabled="isDone(active)" />
                            <ScienceWorkBuilder v-else-if="active.type === 'project' && active.interaction?.science_work_builder" v-model="form.response.science_work" :config="active.interaction.science_work_builder" :disabled="isDone(active)" />
                            <MathWorkBuilder v-else-if="active.type === 'project' && active.interaction?.math_work_builder" v-model="form.response.math_work" :config="active.interaction.math_work_builder" :disabled="isDone(active)" />
                            <ElarResponseBuilder v-else-if="active.type === 'project' && active.interaction?.elar_response_builder" v-model="form.response.elar_work" :config="active.interaction.elar_response_builder" :passage="active.interaction.reading_passage" :disabled="isDone(active)" />
                            <TechnologyCodeWorkspace v-else-if="active.type === 'project' && active.interaction?.technology_code_builder" v-model="form.response.technology_work" :config="active.interaction.technology_code_builder" :disabled="isDone(active)" />
                            <LanguagePassportBuilder v-else-if="active.type === 'project' && active.interaction?.language_passport_builder" v-model="form.response.language_work" :config="active.interaction.language_passport_builder" :disabled="isDone(active)" />
                            <LanguageWorkBuilder v-else-if="active.type === 'project' && active.interaction?.language_work_builder" v-model="form.response.language_practice" :config="active.interaction.language_work_builder" :disabled="isDone(active)" />
                            <div v-else-if="active.type === 'project' && (active.interaction?.map_builder || active.interaction?.region_builder)" class="project-reflections"><h3>Explain your map choices</h3><label v-for="field in active.interaction.reflection_fields" :key="field.id"><strong>{{ field.label }}</strong><textarea v-model="form.response.reflections[field.id]" class="form-control" rows="2" :disabled="isDone(active)" /></label></div>
                            <div v-else-if="active.type === 'project'" class="project-log"><h3>Map feature checklist</h3><label v-for="item in active.interaction.checklist" :key="item.id"><input v-model="form.response.checklist" type="checkbox" :value="item.id" :disabled="isDone(active)"> {{ item.label }}</label><h3>Field notes</h3><label v-for="(_, index) in form.response.observations" :key="index">The map shows… <input v-model="form.response.observations[index]" class="form-control" :disabled="isDone(active)"></label><label>The map does not show… <input v-model="form.response.limitation" class="form-control" :disabled="isDone(active)"></label></div>
                            <fieldset v-if="active.type === 'question_set'" class="question-set" :disabled="isDone(active)"><legend class="visually-hidden">Final questions</legend><section v-for="question in active.interaction.questions" :key="question.id"><h3>{{ question.prompt }}</h3><label v-for="choice in question.choices" :key="choice.id"><input v-model="form.response.answers[question.id]" type="radio" :value="choice.id" :disabled="isDone(active)"> {{ choice.label }}</label></section></fieldset>

                            <p v-if="form.errors.response" class="error-message" role="alert">{{ form.errors.response }}</p>
                            <div v-if="active.feedback" class="feedback" :class="active.is_correct === false ? 'try-again' : 'success'" role="status">{{ active.feedback }}</div>
                            <details v-if="active.hints?.length" class="hint"><summary>Need a field hint?</summary><p>{{ active.hints[0] }}</p></details>
                            <button v-if="isDone(active)" class="btn explorer-button mt-4" type="button" @click="advance">Continue</button>
                            <button v-else class="btn explorer-button mt-4" type="submit" :disabled="form.processing">{{ active.type === 'instruction' ? 'Continue mission' : active.interaction?.technology_code_builder ? active.interaction.technology_code_builder.submit_label : active.interaction?.math_work_builder ? active.interaction.math_work_builder.submit_label : active.interaction?.science_work_builder ? active.interaction.science_work_builder.submit_label : active.interaction?.elar_response_builder ? 'Save evidence and continue' : active.interaction?.ungraded ? 'Save idea and continue' : active.interaction?.systems_map_builder ? 'Submit systems map and continue' : ['builder', 'region_builder'].includes(mapMode) ? 'Submit map and continue' : active.interaction?.analysis_builder ? 'Submit analysis and continue' : active.requires_teacher_review ? 'Save and continue' : 'Check my work' }}</button>
                        </form>
                    </div>
                </article>
                <p class="objective-note mt-4"><strong>Why this matters:</strong> {{ lesson.learning_objective }}</p>
            </template>
        </main>
    </div>
</template>

<style scoped>
.experience-shell{min-height:100vh;background:#eef6f4;color:#17324d;font-family:Inter,system-ui,sans-serif}.mission-bar{background:#123b5d;color:#fff;border-bottom:5px solid #f0b84b}.eyebrow,.activity-kind{text-transform:uppercase;letter-spacing:.12em;font-size:.76rem;font-weight:800}.return-link{color:#fff;font-weight:700}.preview-banner{background:#fff3cd;color:#594500;text-align:center;padding:.7rem 1rem;border-bottom:1px solid #e4c76c}.mission-wrap{max-width:920px}.mission-intro{display:flex;justify-content:space-between;gap:2rem;background:linear-gradient(135deg,#146b70,#17496d);color:#fff;padding:2rem;border-radius:24px;box-shadow:0 12px 30px #123b5d22}.compass{min-width:90px;text-align:center;font-weight:900;font-size:1.3rem}.compass span{font-size:4rem;color:#f0b84b}.progress-panel{background:#fff;padding:1.2rem 1.4rem;border-radius:18px;box-shadow:0 7px 20px #123b5d14}.progress-track{height:12px;border-radius:99px;background:#d8e5e4;overflow:hidden}.progress-fill{height:100%;background:#e07a5f;transition:width .25s}.step-nav{display:flex;gap:.6rem;flex-wrap:wrap}.step-nav button{width:38px;height:38px;border-radius:50%;border:2px solid #91aaa9;background:#fff;color:#31505e;font-weight:800}.step-nav button.active{outline:3px solid #f0b84b;border-color:#123b5d}.step-nav button.done{background:#146b70;color:#fff;border-color:#146b70}.resource-panel{display:grid;grid-template-columns:1.4fr 1fr;gap:1rem}.resource-panel>div{background:#fff;border-radius:18px;padding:1.25rem;box-shadow:0 7px 20px #123b5d14}.resource-panel h2{font-size:1.05rem}.resource-panel h3{font-size:.95rem}.resource-panel ul{padding-left:1.2rem;margin-bottom:0}.resource-panel li{margin:.6rem 0}.resource-panel li div small{display:block;color:#59707d}.resource-action{display:inline-block;margin-top:.35rem;font-weight:800}.asset-needed{display:inline-block;background:#fff3cd;color:#594500;padding:.25rem .5rem;border-radius:6px;font-size:.78rem;font-weight:800}.activity-card{background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 12px 35px #123b5d1c}.activity-accent{display:flex;justify-content:space-between;gap:1rem;background:#e07a5f;color:#fff;padding:.7rem 1.5rem;font-weight:800}.activity-learn .activity-accent{background:#146b70}.activity-create .activity-accent{background:#73548f}.activity-check .activity-accent{background:#123b5d}.activity-body{padding:clamp(1.25rem,4vw,2.5rem)}.instructions{font-size:1.1rem;font-weight:700}.content-block{background:#eef6f4;border-left:5px solid #146b70;padding:1rem;border-radius:8px}.tool-grid,.fact-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.8rem;margin:1.2rem 0}.tool-grid div,.fact-grid section{background:#f8fbfa;border:1px solid #c9dedd;border-radius:12px;padding:1rem}.fact-grid p{margin:.35rem 0 0}.acknowledge,.choice-list label,.question-set label,.project-log>label{display:block;border:2px solid #c9dedd;border-radius:12px;padding:.9rem;margin:.65rem 0;background:#fff}.choice-list input,.question-set input,.acknowledge input,.project-log input[type=checkbox]{margin-right:.5rem;accent-color:#146b70}.matching-grid,.response-fields{display:grid;gap:1rem}.matching-grid label,.response-fields label{display:grid;gap:.4rem}.project-log h3,.question-set h3{font-size:1rem;margin-top:1.4rem}.explorer-button{background:#e07a5f;color:#fff;font-weight:800;padding:.7rem 1.3rem;border:none}.explorer-button:hover,.explorer-button:focus{background:#bd5941;color:#fff}.feedback,.error-message,.review-note{padding:.8rem 1rem;border-radius:10px;margin-top:1rem}.feedback.success,.review-note{background:#d9f1e8;color:#155443}.feedback.try-again,.error-message{background:#fde4dd;color:#762d1b}.hint{margin-top:1rem}.objective-note{background:#ddebea;padding:1rem;border-radius:12px}.completion-card{background:#fff;padding:clamp(2rem,8vw,5rem);border-radius:24px;box-shadow:0 12px 35px #123b5d1c}.completion-mark{display:grid;place-items:center;width:90px;height:90px;margin:0 auto 1rem;border-radius:50%;background:#146b70;color:#fff;font-size:3rem;border:8px solid #c7e8dd}.skip-link{position:absolute;left:-9999px}.skip-link:focus{left:1rem;top:1rem;z-index:100;background:#fff;padding:.5rem}@media(max-width:700px){.resource-panel{grid-template-columns:1fr}}@media(max-width:600px){.mission-intro{padding:1.4rem}.compass{display:none}.activity-accent{flex-direction:column}.mission-bar .return-link{width:100%}}
.response-choice{border:0;padding:0;margin:0}.response-choice legend{font-weight:800;font-size:1rem;margin-bottom:.35rem}.response-choice label{display:block;border:2px solid #c9dedd;border-radius:12px;padding:.8rem;margin:.55rem 0;background:#fff}.response-choice input{margin-right:.5rem;accent-color:#146b70}
.project-reflections{display:grid;gap:1rem;margin-top:1rem}.project-reflections h3{font-size:1.05rem;margin:0}.project-reflections label{display:grid;gap:.4rem}
.draft-status{margin:-.75rem 0 1rem;color:#31505e;font-size:.85rem;font-weight:800}
.dataset-wrap{overflow-x:auto;margin:1rem 0;border:2px solid #bfd8d3;border-radius:14px}.dataset-wrap table{width:100%;min-width:700px;border-collapse:collapse;background:#fff}.dataset-wrap caption{caption-side:top;padding:.75rem;font-weight:900;color:#17324d}.dataset-wrap th,.dataset-wrap td{padding:.6rem;border:1px solid #d7e4e2;text-align:center}.dataset-wrap thead th{background:#dff3ef}.dataset-wrap tbody th{text-align:left;background:#f7fbfa}
.code-demonstration{display:grid;gap:.55rem;margin:1rem 0;padding:1rem;border-radius:14px;background:#17233b;color:#fff}.code-demonstration pre{margin:0;padding:1rem;border-radius:9px;background:#0d1729;color:#d9f4ff;white-space:pre-wrap}.code-demonstration .code-output{background:#071d18;color:#d7ffe5}.code-demonstration p{margin:0;color:#d3deee;font-size:.86rem}
.code-demonstration .output-hidden{padding:.8rem;border-radius:9px;background:#263756;color:#fff;font-weight:700}
</style>
