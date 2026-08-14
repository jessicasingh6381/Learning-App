<script setup lang="ts">
import axios from 'axios';
import { geoAlbersUsa, geoPath } from 'd3-geo';
import { computed, onMounted, ref } from 'vue';

type MapMode = 'explore' | 'map_tools' | 'reference' | 'builder' | 'comparison' | 'region_builder' | 'settlement_data';
type BuilderFeature = { state_fips: string; marker_key: string; legend_label: string };
type BuilderMap = { title: string; show_orientation: boolean; features: BuilderFeature[] };
type Region = { id: string; name: string; color_key: string; state_fips: string[] };
type RegionMap = { title: string; criterion: string; regions: Region[] };
const props = withDefaults(defineProps<{ resourceUrl: string; physicalResourceUrl?: string; populationResourceUrl?: string; mode?: MapMode; modelValue?: BuilderMap | RegionMap; disabled?: boolean }>(), { mode: 'explore', disabled: false, physicalResourceUrl: '', populationResourceUrl: '' });
const emit = defineEmits<{ 'update:modelValue': [value: BuilderMap | RegionMap] }>();
const geography = ref<any>(null);
const populationData = ref<any>(null);
const loading = ref(true);
const error = ref('');
const selectedState = ref<any>(null);
const activeToolIndex = ref(0);
const width = 960;
const height = 580;
const regionColors: Record<string, string> = {
    northeast: '#9ecae1', midwest: '#a8ddb5', south: '#fdd49e', west: '#dadaeb',
};
const studentRegionColors: Record<string, string> = { teal: '#4f9d91', gold: '#e4ad45', coral: '#df7861' };
const censusRegions: Record<string, string[]> = {
    northeast: ['09', '23', '25', '33', '34', '36', '42', '44', '50'],
    midwest: ['17', '18', '19', '20', '26', '27', '29', '31', '38', '39', '46', '55'],
    south: ['01', '05', '10', '11', '12', '13', '21', '22', '24', '28', '37', '40', '45', '47', '48', '51', '54'],
    west: ['02', '04', '06', '08', '15', '16', '30', '32', '35', '41', '49', '53', '56'],
};
const referenceLabelFips = ['06', '12', '17', '36', '40', '48', '53'];
const markerStyles = [
    { key: 'blue_circle', label: 'Blue circle', color: '#4f86c6', symbol: '●' },
    { key: 'gold_star', label: 'Gold star', color: '#f0b84b', symbol: '★' },
    { key: 'green_triangle', label: 'Green triangle', color: '#4f9d69', symbol: '▲' },
    { key: 'purple_square', label: 'Purple square', color: '#8267a5', symbol: '■' },
];

const tools = [
    { key: 'title', label: 'Title', explanation: 'The title tells what this map is about.' },
    { key: 'orientation', label: 'Orientation', explanation: 'The north arrow shows direction so you can compare where places are.' },
    { key: 'legend', label: 'Legend', explanation: 'The legend explains what the map’s colors and symbols mean.' },
    { key: 'labels', label: 'Labels', explanation: 'Labels identify places and features. Texas is shown as an example.' },
    { key: 'scale', label: 'Scale', explanation: 'Scale connects a short distance on the map with a much longer distance in the real world.' },
    { key: 'symbols', label: 'Symbols', explanation: 'Symbols stand for information. A star can mark a place the mapmaker wants you to notice.' },
];
const activeTool = computed(() => tools[activeToolIndex.value]);
const isMapTools = computed(() => props.mode === 'map_tools');
const isReference = computed(() => props.mode === 'reference');
const isBuilder = computed(() => props.mode === 'builder');
const isComparison = computed(() => props.mode === 'comparison');
const isRegionBuilder = computed(() => props.mode === 'region_builder');
const isSettlementData = computed(() => props.mode === 'settlement_data');
const builderMap = computed<BuilderMap>(() => props.modelValue as BuilderMap || { title: '', show_orientation: false, features: [] });
const regionMap = computed<RegionMap>(() => props.modelValue as RegionMap || { title: '', criterion: '', regions: [] });
const projection = computed(() => geography.value ? geoAlbersUsa().fitExtent([[35, 65], [925, 530]], geography.value) : null);
const path = computed(() => projection.value ? geoPath(projection.value) : null);
const states = computed(() => (geography.value?.features || []).map((feature: any) => ({
    feature,
    path: path.value?.(feature) || '',
    centroid: path.value?.centroid(feature) || [0, 0],
})));
const labelState = computed(() => selectedState.value || states.value.find((state: any) => state.feature.properties.state_fips === '48'));
const referenceLabels = computed(() => states.value.filter((state: any) => referenceLabelFips.includes(state.feature.properties.state_fips)));
const settlementLabelFips = ['06', '12', '36', '48', '56'];
const settlementLabels = computed(() => states.value.filter((state: any) => settlementLabelFips.includes(state.feature.properties.state_fips)));
const populationByFips = computed<Record<string, any>>(() => Object.fromEntries((populationData.value?.states || []).map((state: any) => [state.state_fips, state])));
const densityFor = (state: any) => populationByFips.value[state.feature.properties.state_fips]?.density_per_sq_mile;
const densityFill = (density?: number) => density === undefined ? '#e8eeee' : density >= 400 ? '#7f1d3a' : density >= 200 ? '#c74b50' : density >= 100 ? '#e9865d' : density >= 50 ? '#f3bd73' : '#f8e7b0';
const formatNumber = (value?: number) => value === undefined ? 'Not available' : new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(value);
const capitalPoint = computed(() => projection.value?.([-77.0369, 38.9072]) || [0, 0]);
const sortedStates = computed(() => [...states.value].sort((left: any, right: any) => left.feature.properties.name.localeCompare(right.feature.properties.name)));
const builderFeatureFor = (state: any) => builderMap.value.features.find((feature) => feature.state_fips === state.feature.properties.state_fips);
const builderMarkerFor = (feature?: BuilderFeature) => markerStyles.find((marker) => marker.key === feature?.marker_key);
const builderFeatures = computed(() => builderMap.value.features.flatMap((feature) => {
    const state = states.value.find((candidate: any) => candidate.feature.properties.state_fips === feature.state_fips);
    const marker = builderMarkerFor(feature);
    return state && marker ? [{ feature, state, marker }] : [];
}));
const builderChecks = computed(() => {
    const completeFeatures = builderMap.value.features.filter((feature) => feature.state_fips && feature.marker_key);
    return {
        title: builderMap.value.title.trim().length >= 3,
        orientation: builderMap.value.show_orientation === true,
        symbols: completeFeatures.length >= 3,
        legend: completeFeatures.length >= 3 && completeFeatures.every((feature) => (feature.legend_label || '').trim().length >= 3),
        labels: new Set(completeFeatures.map((feature) => feature.state_fips)).size >= 3,
    };
});
const regionForState = (state: any) => regionMap.value.regions.find((region) => region.state_fips.includes(state.feature.properties.state_fips));
const regionItems = computed(() => regionMap.value.regions.flatMap((region) => region.state_fips.flatMap((fips) => {
    const state = states.value.find((candidate: any) => candidate.feature.properties.state_fips === fips);
    return state ? [{ region, state }] : [];
})));
const regionChecks = computed(() => {
    const completeRegions = regionMap.value.regions.filter((region) => region.name.trim().length >= 3 && region.color_key && region.state_fips.filter(Boolean).length >= 2);
    const usedStates = regionMap.value.regions.flatMap((region) => region.state_fips.filter(Boolean));
    return {
        title: regionMap.value.title.trim().length >= 3,
        criterion: regionMap.value.criterion.trim().length >= 5,
        regions: completeRegions.length >= 3,
        states: usedStates.length >= 6 && new Set(usedStates).size === usedStates.length,
        legend: completeRegions.length >= 3,
    };
});
const referenceScaleLength = computed(() => {
    if (!projection.value) return 170;
    const start = projection.value([-100, 38]);
    const end = projection.value([-81.7, 38]);
    return start && end ? Math.abs(end[0] - start[0]) : 170;
});
const isSelected = (state: any) => selectedState.value?.feature.properties.state_fips === state.feature.properties.state_fips;
const regionFor = (state: any) => Object.keys(censusRegions).find((region) => censusRegions[region].includes(state.feature.properties.state_fips)) || 'west';
const stateFill = (state: any) => {
    if (isReference.value) return regionColors[regionFor(state)];
    if (isSettlementData.value) return densityFill(densityFor(state));
    if (isRegionBuilder.value) {
        const region = regionForState(state);
        return region ? (studentRegionColors[region.color_key] || '#dcebea') : '#dcebea';
    }
    if (isBuilder.value) return builderMarkerFor(builderFeatureFor(state))?.color || '#dcebea';
    return isSelected(state) ? 'url(#selected-state-pattern)' : '#dcebea';
};
const updateBuilder = (changes: Partial<BuilderMap>) => emit('update:modelValue', { ...builderMap.value, ...changes });
const updateBuilderFeature = (index: number, key: keyof BuilderFeature, value: string) => {
    const features = builderMap.value.features.map((feature, featureIndex) => featureIndex === index ? { ...feature, [key]: value } : { ...feature });
    updateBuilder({ features });
};
const updateRegionMap = (changes: Partial<RegionMap>) => emit('update:modelValue', { ...regionMap.value, ...changes });
const updateRegion = (index: number, changes: Partial<Region>) => {
    const regions = regionMap.value.regions.map((region, regionIndex) => regionIndex === index ? { ...region, ...changes } : { ...region, state_fips: [...region.state_fips] });
    updateRegionMap({ regions });
};
const updateRegionState = (regionIndex: number, stateIndex: number, value: string) => {
    const stateFips = [...regionMap.value.regions[regionIndex].state_fips];
    stateFips[stateIndex] = value;
    updateRegion(regionIndex, { state_fips: stateFips });
};
const selectState = (state: any) => {
    if ((isBuilder.value || isRegionBuilder.value) && props.disabled) return;
    selectedState.value = state;
    if (isBuilder.value) {
        const emptyIndex = builderMap.value.features.findIndex((feature) => !feature.state_fips);
        if (emptyIndex >= 0) updateBuilderFeature(emptyIndex, 'state_fips', state.feature.properties.state_fips);
    }
    if (isRegionBuilder.value) {
        for (let regionIndex = 0; regionIndex < regionMap.value.regions.length; regionIndex++) {
            const stateIndex = regionMap.value.regions[regionIndex].state_fips.findIndex((fips) => !fips);
            if (stateIndex >= 0) { updateRegionState(regionIndex, stateIndex, state.feature.properties.state_fips); break; }
        }
    }
};
const handleKey = (event: KeyboardEvent, state: any) => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectState(state); }
};
const chooseTool = (index: number) => { activeToolIndex.value = index; };

onMounted(async () => {
    try {
        const [geographyResponse, populationResponse] = await Promise.all([
            axios.get(props.resourceUrl, { headers: { Accept: 'application/geo+json' } }),
            props.populationResourceUrl ? axios.get(props.populationResourceUrl, { headers: { Accept: 'application/json' } }) : Promise.resolve(null),
        ]);
        geography.value = geographyResponse.data;
        populationData.value = populationResponse?.data || null;
        if (isSettlementData.value && !populationData.value) throw new Error('Population data is required.');
    } catch {
        error.value = 'The interactive map could not be loaded safely.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="interactive-map" aria-labelledby="interactive-map-heading">
        <div class="map-intro">
            <div>
                <p class="mode-label">{{ isSettlementData ? 'Geographic data comparison' : isRegionBuilder ? 'Digital region builder' : isComparison ? 'Physical and political comparison' : isBuilder ? 'Digital map builder' : isReference ? 'Reference map' : isMapTools ? 'Map tools' : 'Explore states' }}</p>
                <h2 id="interactive-map-heading">{{ isSettlementData ? 'Compare Population Density and Physical Relief' : isRegionBuilder ? 'Build Your Regional Reference Layer' : isComparison ? 'Compare Physical and Political Maps' : isBuilder ? 'Build Your Explorer Reference Map' : isReference ? 'U.S. Census Regions Reference Map' : 'Interactive U.S. map' }}</h2>
                <p v-if="isBuilder">Choose a title, turn on orientation, and add three labeled map markers with clear legend meanings.</p>
                <p v-if="isRegionBuilder">Name a criterion, then group complete states into three colored regions.</p>
                <p v-if="isComparison">Use the physical relief map and political state map together. Each shows different information about the same country.</p>
                <p v-if="isSettlementData">Read the population-density legend and labeled values first. Then compare the pattern with physical relief without claiming that either map proves a cause.</p>
                <p v-if="isReference">Use the title, north arrow, labels, legend, symbols, and scale to answer the questions below.</p>
                <p v-else-if="!isBuilder && !isRegionBuilder && !isComparison && !isSettlementData">Choose any state outline. Its name appears after you select it.</p>
            </div>
            <p v-if="!isBuilder && !isRegionBuilder" class="selected-state" aria-live="polite"><span>{{ selectedState ? 'Selected state' : 'No state selected yet' }}</span><strong v-if="selectedState">{{ selectedState.feature.properties.name }}</strong></p>
        </div>

        <section v-if="(isComparison || isRegionBuilder || isSettlementData) && physicalResourceUrl" class="physical-map-card" aria-labelledby="physical-map-heading">
            <div class="comparison-heading"><div><span>Physical information</span><h3 id="physical-map-heading">Physical Relief of the Contiguous United States</h3></div><p>Color represents relative elevation and relief—not political regions.</p></div>
            <div class="physical-image-wrap">
                <img :src="physicalResourceUrl" alt="USGS topography map of the contiguous United States showing low plains in pale yellow and green, higher western mountains in orange, red, and white, eastern Appalachian relief, Great Lakes, and surrounding ocean water.">
                <span class="physical-label rockies">Rocky Mountains</span><span class="physical-label plains">Great Plains</span><span class="physical-label appalachians">Appalachian Mountains</span><span class="physical-label lakes">Great Lakes</span>
            </div>
            <div class="physical-key" aria-label="Physical relief color key"><strong>Elevation and relief key</strong><span><i class="low"/> Pale yellow/green: lower land</span><span><i class="mid"/> Orange/red: higher land</span><span><i class="high"/> White: highest visible relief</span><span><i class="water"/> Blue: water</span></div>
            <p class="map-source">Source: U.S. Geological Survey, CONUS Topography Map (public domain). State lines are included for location reference.</p>
        </section>

        <div v-if="isMapTools" class="tool-teacher" aria-label="Map tools lesson">
            <div class="tool-progress"><span>Map tool {{ activeToolIndex + 1 }} of {{ tools.length }}</span><strong>{{ activeTool.label }}</strong><p>{{ activeTool.explanation }}</p></div>
            <div class="tool-buttons" role="list" aria-label="Choose a map tool">
                <button v-for="(tool, index) in tools" :key="tool.key" type="button" :class="{ active: index === activeToolIndex }" :aria-pressed="index === activeToolIndex" @click="chooseTool(index)">{{ index + 1 }}. {{ tool.label }}</button>
            </div>
            <div class="tool-nav"><button type="button" :disabled="activeToolIndex === 0" @click="chooseTool(activeToolIndex - 1)">Previous tool</button><button type="button" :disabled="activeToolIndex === tools.length - 1" @click="chooseTool(activeToolIndex + 1)">Next tool</button></div>
        </div>

        <div v-if="isBuilder" class="builder-controls" aria-label="Explorer map controls">
            <label class="builder-title"><strong>1. Give your map a descriptive title</strong><input :value="builderMap.title" class="form-control" maxlength="120" placeholder="Example: Places I Want to Explore" :disabled="props.disabled" @input="updateBuilder({ title: ($event.target as HTMLInputElement).value })"></label>
            <label class="orientation-toggle"><input :checked="builderMap.show_orientation" type="checkbox" :disabled="props.disabled" @change="updateBuilder({ show_orientation: ($event.target as HTMLInputElement).checked })"> <span>2. Show a north arrow</span></label>
            <fieldset><legend>3. Add three labeled symbols or colors</legend><p class="small">Choose a place, marker, and legend meaning for each row. Your map updates as you work.</p>
                <div v-for="(feature, index) in builderMap.features" :key="index" class="builder-feature-row">
                    <label><span>Place {{ index + 1 }}</span><select :value="feature.state_fips" class="form-select" :disabled="props.disabled" @change="updateBuilderFeature(index, 'state_fips', ($event.target as HTMLSelectElement).value)"><option value="">Choose a state</option><option v-for="state in sortedStates" :key="state.feature.properties.state_fips" :value="state.feature.properties.state_fips">{{ state.feature.properties.name }}</option></select></label>
                    <label><span>Marker</span><select :value="feature.marker_key" class="form-select" :disabled="props.disabled" @change="updateBuilderFeature(index, 'marker_key', ($event.target as HTMLSelectElement).value)"><option value="">Choose a marker</option><option v-for="marker in markerStyles" :key="marker.key" :value="marker.key">{{ marker.symbol }} {{ marker.label }}</option></select></label>
                    <label><span>Legend meaning</span><input :value="feature.legend_label" class="form-control" maxlength="100" placeholder="What does this marker mean?" :disabled="props.disabled" @input="updateBuilderFeature(index, 'legend_label', ($event.target as HTMLInputElement).value)"></label>
                </div>
            </fieldset>
            <div class="builder-checks" role="status" aria-live="polite"><strong>Map requirements</strong><ul><li :class="{ complete: builderChecks.title }">{{ builderChecks.title ? '✓' : '○' }} Descriptive title</li><li :class="{ complete: builderChecks.orientation }">{{ builderChecks.orientation ? '✓' : '○' }} North arrow</li><li :class="{ complete: builderChecks.symbols }">{{ builderChecks.symbols ? '✓' : '○' }} At least three symbols or colors</li><li :class="{ complete: builderChecks.legend }">{{ builderChecks.legend ? '✓' : '○' }} Complete legend</li><li :class="{ complete: builderChecks.labels }">{{ builderChecks.labels ? '✓' : '○' }} Three clear place labels</li></ul></div>
        </div>

        <div v-if="isRegionBuilder" class="builder-controls" aria-label="Regional map controls">
            <label class="builder-title"><strong>1. Give your regional map a descriptive title</strong><input :value="regionMap.title" class="form-control" maxlength="120" placeholder="Example: My Three U.S. Location Regions" :disabled="props.disabled" @input="updateRegionMap({ title: ($event.target as HTMLInputElement).value })"></label>
            <label><strong>2. State one organizing criterion</strong><input :value="regionMap.criterion" class="form-control" maxlength="200" placeholder="Example: relative location from west to east" :disabled="props.disabled" @input="updateRegionMap({ criterion: ($event.target as HTMLInputElement).value })"><small>Use the same rule for all three regions.</small></label>
            <fieldset><legend>3. Name three regions and add two states to each</legend><p class="small">Each state can belong to only one region. The map adds color, boundaries, labels, and a legend automatically.</p>
                <div v-for="(region, regionIndex) in regionMap.regions" :key="region.id" class="region-row">
                    <label><span>Region {{ regionIndex + 1 }} name</span><input :value="region.name" class="form-control" maxlength="100" placeholder="Example: Western places" :disabled="props.disabled" @input="updateRegion(regionIndex, { name: ($event.target as HTMLInputElement).value })"></label>
                    <label v-for="(_, stateIndex) in region.state_fips" :key="stateIndex"><span>State {{ stateIndex + 1 }}</span><select :value="region.state_fips[stateIndex]" class="form-select" :disabled="props.disabled" @change="updateRegionState(regionIndex, stateIndex, ($event.target as HTMLSelectElement).value)"><option value="">Choose a state</option><option v-for="state in sortedStates" :key="state.feature.properties.state_fips" :value="state.feature.properties.state_fips">{{ state.feature.properties.name }}</option></select></label>
                    <span class="region-color" :style="{ background: studentRegionColors[region.color_key] }" aria-hidden="true" />
                </div>
            </fieldset>
            <div class="builder-checks" role="status" aria-live="polite"><strong>Regional map requirements</strong><ul><li :class="{ complete: regionChecks.title }">{{ regionChecks.title ? '✓' : '○' }} Descriptive title</li><li :class="{ complete: regionChecks.criterion }">{{ regionChecks.criterion ? '✓' : '○' }} One clear criterion</li><li :class="{ complete: regionChecks.regions }">{{ regionChecks.regions ? '✓' : '○' }} Three named regions</li><li :class="{ complete: regionChecks.states }">{{ regionChecks.states ? '✓' : '○' }} Six different states</li><li :class="{ complete: regionChecks.legend }">{{ regionChecks.legend ? '✓' : '○' }} Complete color legend</li></ul></div>
        </div>

        <div v-if="loading" class="map-message" role="status">Preparing the interactive map...</div>
        <div v-else-if="error" class="map-message error" role="alert">{{ error }}</div>
        <div v-else class="map-frame" :data-active-tool="isMapTools ? activeTool.key : props.mode">
            <div class="map-title" :class="{ focused: isMapTools && activeTool.key === 'title' }"><span>Map title</span><strong>{{ isSettlementData ? '2020 Population Density by State and District of Columbia' : isRegionBuilder ? (regionMap.title || 'Your regional map title will appear here') : isComparison ? 'Political Map: United States and State Boundaries' : isBuilder ? (builderMap.title || 'Your map title will appear here') : isReference ? 'U.S. Census Regions and Selected States' : 'States of the United States' }}</strong></div>
            <svg :viewBox="`0 0 ${width} ${height}`" role="group" :aria-label="isSettlementData ? 'Population density choropleth using 2020 Census population and Census Gazetteer land area' : isRegionBuilder ? 'Student-created regional map using authoritative United States state boundaries' : isBuilder ? 'Student-created Explorer Reference Map using authoritative United States state boundaries' : isComparison ? 'Political map of United States state boundaries, selected state labels, and the national capital' : isReference ? 'Reference map of United States Census regions with selected state labels and the national capital' : 'Interactive map of the 50 states and District of Columbia'">
                <defs><pattern id="selected-state-pattern" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="10" height="10" fill="#f0b84b"/><line x1="0" y1="0" x2="0" y2="10" stroke="#8a4f22" stroke-width="4"/></pattern></defs>
                <g class="states">
                    <path v-for="state in states" :key="state.feature.properties.state_fips" :d="state.path" class="state" :class="{ selected: isSelected(state) }" :fill="stateFill(state)" role="button" :tabindex="(isBuilder || isRegionBuilder) && props.disabled ? -1 : 0" :aria-label="`Select ${state.feature.properties.name}`" :aria-pressed="isSelected(state)" :aria-disabled="(isBuilder || isRegionBuilder) && props.disabled" @click="selectState(state)" @keydown="handleKey($event, state)" />
                </g>
                <g v-if="isMapTools || isReference || isComparison || isRegionBuilder || isSettlementData || (isBuilder && builderMap.show_orientation)" class="orientation-tool" :class="{ focused: isMapTools && activeTool.key === 'orientation' }" aria-label="North arrow"><circle cx="870" cy="105" r="35"/><text x="870" y="82">N</text><path d="M870 92 L855 125 L870 116 L885 125 Z"/></g>
                <g v-if="!isReference && (selectedState || (isMapTools && activeTool.key === 'labels'))" class="map-label" :class="{ focused: isMapTools && activeTool.key === 'labels' }"><rect :x="labelState.centroid[0] - 46" :y="labelState.centroid[1] - 22" width="92" height="28" rx="6"/><text :x="labelState.centroid[0]" :y="labelState.centroid[1] - 3">{{ labelState.feature.properties.name }}</text></g>
                <g v-if="isReference || isComparison" class="reference-labels" aria-label="Selected state labels"><text v-for="state in referenceLabels" :key="state.feature.properties.state_fips" :x="state.centroid[0]" :y="state.centroid[1]">{{ state.feature.properties.name }}</text></g>
                <g v-if="isSettlementData" class="settlement-labels" aria-label="Selected state population-density labels"><g v-for="state in settlementLabels" :key="state.feature.properties.state_fips"><text :x="state.centroid[0]" :y="state.centroid[1] - 5">{{ state.feature.properties.name }}</text><text :x="state.centroid[0]" :y="state.centroid[1] + 12">{{ formatNumber(densityFor(state)) }}/mi²</text></g></g>
                <g v-if="isBuilder" class="builder-map-features" aria-label="Student-added map symbols and labels"><g v-for="item in builderFeatures" :key="item.feature.state_fips"><text class="builder-symbol" :x="item.state.centroid[0]" :y="item.state.centroid[1] - 8" :fill="item.marker.color">{{ item.marker.symbol }}</text><text class="builder-label" :x="item.state.centroid[0]" :y="item.state.centroid[1] + 14">{{ item.state.feature.properties.name }}</text></g></g>
                <g v-if="isRegionBuilder" class="builder-map-features" aria-label="Student-grouped state labels"><text v-for="item in regionItems" :key="item.state.feature.properties.state_fips" class="builder-label" :x="item.state.centroid[0]" :y="item.state.centroid[1]">{{ item.state.feature.properties.name }}</text></g>
                <g v-if="isMapTools" class="symbol-tool" :class="{ focused: activeTool.key === 'symbols' }" aria-label="Example map symbol"><text x="505" y="390">★</text><text x="525" y="390">example symbol</text></g>
                <g v-if="isReference || isComparison" class="capital-symbol" aria-label="Star marking Washington, District of Columbia, the national capital"><text :x="capitalPoint[0]" :y="capitalPoint[1]">★</text><text :x="capitalPoint[0] + 12" :y="capitalPoint[1] - 10">Washington, D.C.</text></g>
                <g v-if="isMapTools" class="scale-tool" :class="{ focused: activeTool.key === 'scale' }" aria-label="Example scale indicator"><line x1="665" y1="505" x2="835" y2="505"/><line x1="665" y1="495" x2="665" y2="515"/><line x1="835" y1="495" x2="835" y2="515"/><text x="750" y="535">map distance → real distance</text></g>
                <g v-if="isReference" class="scale-tool reference-scale" aria-label="Approximate scale of one thousand miles for the contiguous United States"><line x1="650" y1="505" :x2="650 + referenceScaleLength" y2="505"/><line x1="650" y1="495" x2="650" y2="515"/><line :x1="650 + referenceScaleLength / 2" y1="499" :x2="650 + referenceScaleLength / 2" y2="511"/><line :x1="650 + referenceScaleLength" y1="495" :x2="650 + referenceScaleLength" y2="515"/><text x="650" y="535">0</text><text :x="650 + referenceScaleLength / 2" y="535">500</text><text :x="650 + referenceScaleLength" y="535">1,000 miles</text></g>
            </svg>
            <div v-if="isMapTools" class="map-legend" :class="{ focused: activeTool.key === 'legend' }"><strong>Legend</strong><span><i class="boundary-swatch"/> State boundary</span><span><i class="selected-swatch"/> Selected state</span><span><b>★</b> Example symbol</span></div>
            <div v-if="isReference" class="map-legend reference-legend"><strong>Legend</strong><span><i :style="{ background: regionColors.northeast }"/> Northeast</span><span><i :style="{ background: regionColors.midwest }"/> Midwest</span><span><i :style="{ background: regionColors.south }"/> South</span><span><i :style="{ background: regionColors.west }"/> West</span><span><b>★</b> National capital</span><span><i class="boundary-swatch"/> State boundary</span></div>
            <div v-if="isComparison" class="map-legend"><strong>Political legend</strong><span><i class="boundary-swatch"/> State boundary</span><span><b>★</b> National capital</span><span>State names label political areas</span></div>
            <div v-if="isSettlementData" class="map-legend density-legend"><strong>People per square mile</strong><span><i :style="{ background: densityFill(450) }"/> 400 or more</span><span><i :style="{ background: densityFill(250) }"/> 200–399.9</span><span><i :style="{ background: densityFill(150) }"/> 100–199.9</span><span><i :style="{ background: densityFill(75) }"/> 50–99.9</span><span><i :style="{ background: densityFill(20) }"/> Fewer than 50</span></div>
            <div v-if="isBuilder && builderFeatures.length" class="map-legend builder-legend"><strong>My legend</strong><span v-for="item in builderFeatures" :key="item.feature.state_fips"><b :style="{ color: item.marker.color }">{{ item.marker.symbol }}</b> {{ item.feature.legend_label || 'Add this marker’s meaning' }}</span></div>
            <div v-if="isRegionBuilder && regionMap.regions.some((region) => region.name)" class="map-legend builder-legend"><strong>My region legend</strong><span v-for="region in regionMap.regions" :key="region.id"><i :style="{ background: studentRegionColors[region.color_key] }"/> {{ region.name || 'Name this region' }}</span><small>Criterion: {{ regionMap.criterion || 'Add one organizing criterion' }}</small></div>
        </div>
        <div v-if="isSettlementData && populationData" class="density-evidence" aria-label="Labeled population-density evidence"><strong>Evidence values shown on the map</strong><div><span v-for="fips in settlementLabelFips" :key="fips"><b>{{ populationByFips[fips]?.name }}</b>{{ formatNumber(populationByFips[fips]?.density_per_sq_mile) }} people/mi²</span></div><p>{{ populationData.dataset.caution }}</p><small>Source: U.S. Census Bureau · 2020 population ÷ 2024 Gazetteer land area</small></div>
        <p class="keyboard-help">Pointer or keyboard: Tab to a state, then press Enter or Space to select it. <template v-if="isBuilder">Selecting a state adds it to the next open place row.</template><template v-else-if="isRegionBuilder">Selecting a state adds it to the next open region slot.</template><template v-else-if="isReference">A selected state keeps its region color, gains a heavier outline, and has its name shown in text.</template><template v-else>A selected state has a striped fill, heavier outline, and its name shown in text.</template></p>
    </section>
</template>

<style scoped>
.interactive-map{background:#fff;border-radius:24px;padding:clamp(1rem,3vw,1.6rem);box-shadow:0 12px 35px #123b5d1c;margin-bottom:1.5rem}.map-intro{display:flex;justify-content:space-between;gap:1rem;align-items:start}.map-intro h2{font-size:1.45rem;margin:.1rem 0}.map-intro p{margin-bottom:0}.mode-label{text-transform:uppercase;letter-spacing:.12em;font-size:.72rem;font-weight:900;color:#146b70}.selected-state{min-width:190px;border:2px solid #b8cecc;border-radius:12px;padding:.65rem .85rem;text-align:right}.selected-state span,.selected-state strong{display:block}.selected-state strong{font-size:1.2rem;color:#8a4f22}.map-frame{position:relative;margin-top:1rem;border:2px solid #8da9a8;border-radius:18px;background:#f8fbfa;padding-top:3.6rem;overflow:hidden}.map-title{position:absolute;top:.7rem;left:50%;transform:translateX(-50%);text-align:center;padding:.3rem .8rem;border:3px solid transparent;border-radius:8px;z-index:2}.map-title span{display:block;font-size:.65rem;text-transform:uppercase;letter-spacing:.1em}.map-title strong{font-size:1.05rem}.map-title.focused{border-color:#e07a5f;background:#fff3e7;box-shadow:0 0 0 4px #e07a5f44}.map-frame svg{display:block;width:100%;height:auto;max-height:560px}.state{stroke:#31505e;stroke-width:1.5;vector-effect:non-scaling-stroke;cursor:pointer;transition:fill .15s,stroke-width .15s}.state:hover,.state:focus{fill:#b6dbd5;stroke:#0c314a;stroke-width:3;outline:none}.state:focus{filter:drop-shadow(0 0 4px #f0b84b)}.state.selected{stroke:#662f0d;stroke-width:4}.orientation-tool circle{fill:#fff;stroke:#59707d;stroke-width:2}.orientation-tool text{text-anchor:middle;font-weight:900;fill:#17324d}.orientation-tool path{fill:#59707d}.orientation-tool.focused circle{stroke:#e07a5f;stroke-width:6}.orientation-tool.focused path{fill:#e07a5f}.map-label rect{fill:#fff;stroke:#31505e;stroke-width:2}.map-label text{text-anchor:middle;font-size:14px;font-weight:900;fill:#17324d}.map-label.focused rect{stroke:#e07a5f;stroke-width:6}.reference-labels text,.capital-symbol text{font-size:13px;font-weight:900;fill:#17324d;paint-order:stroke;stroke:#fff;stroke-width:4px;stroke-linejoin:round;text-anchor:middle}.capital-symbol text:first-child{font-size:25px;fill:#a5262d}.capital-symbol text:last-child{text-anchor:start}.symbol-tool text{font-size:17px;font-weight:800;fill:#31505e}.symbol-tool text:first-child{font-size:28px}.symbol-tool.focused text{fill:#b44d35}.scale-tool line{stroke:#31505e;stroke-width:3}.scale-tool text{text-anchor:middle;font-size:13px;font-weight:800;fill:#31505e}.scale-tool.focused line{stroke:#e07a5f;stroke-width:7}.scale-tool.focused text{fill:#8a321f}.reference-scale text{font-size:12px}.map-legend{position:absolute;left:1rem;bottom:1rem;display:grid;gap:.25rem;background:#ffffffed;border:2px solid #789392;border-radius:10px;padding:.55rem .7rem;font-size:.72rem}.map-legend span{display:flex;align-items:center;gap:.4rem}.map-legend i{display:inline-block;width:18px;height:12px}.reference-legend i{border:1px solid #31505e}.boundary-swatch{border:2px solid #31505e;background:#dcebea}.selected-swatch{border:2px solid #662f0d;background:repeating-linear-gradient(45deg,#f0b84b,#f0b84b 4px,#8a4f22 4px,#8a4f22 7px)}.map-legend.focused{border:5px solid #e07a5f;box-shadow:0 0 0 4px #e07a5f44}.tool-teacher{margin-top:1rem;border:2px solid #b8cecc;border-radius:16px;padding:1rem;background:#eef6f4}.tool-progress span,.tool-progress strong{display:block}.tool-progress span{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:800}.tool-progress strong{font-size:1.25rem;margin:.15rem 0}.tool-progress p{margin:0}.tool-buttons{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.8rem}.tool-buttons button,.tool-nav button{border:2px solid #789392;background:#fff;color:#17324d;border-radius:9px;padding:.4rem .65rem;font-weight:800}.tool-buttons button.active{background:#146b70;color:#fff;border-color:#146b70}.tool-nav{display:flex;justify-content:space-between;margin-top:.8rem}.tool-nav button:disabled{opacity:.45}.map-message{margin-top:1rem;background:#eef6f4;padding:2rem;border-radius:14px;text-align:center;font-weight:800}.map-message.error{background:#fde4dd;color:#762d1b}.keyboard-help{font-size:.78rem;color:#59707d;margin:.7rem 0 0}.keyboard-help::before{content:'Accessibility: ';font-weight:900}.map-frame[data-active-tool="explore"] .map-title span{display:none}@media(max-width:700px){.map-intro{display:block}.selected-state{text-align:left;margin-top:.8rem}.map-legend{position:static;margin:.5rem 1rem 1rem}.map-frame{padding-top:4.2rem}.tool-buttons button{flex:1 1 130px}}
.builder-controls{display:grid;gap:1rem;margin-top:1rem;padding:1rem;border:2px solid #b8cecc;border-radius:16px;background:#eef6f4}.builder-controls label{display:grid;gap:.35rem}.orientation-toggle{display:flex!important;align-items:center;gap:.55rem;font-weight:800}.orientation-toggle input{width:1.15rem;height:1.15rem;accent-color:#146b70}.builder-controls fieldset{border:0;padding:0;margin:0}.builder-controls legend{font-size:1rem;font-weight:900}.builder-feature-row{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:.7rem;margin:.75rem 0;padding:.8rem;background:#fff;border-radius:12px;border:1px solid #c9dedd}.builder-checks{background:#fff;padding:.8rem 1rem;border-radius:12px}.builder-checks ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.35rem;padding:0;margin:.5rem 0 0;list-style:none}.builder-checks li{color:#76531b;font-weight:800}.builder-checks li.complete{color:#155443}.builder-map-features text{paint-order:stroke;stroke:#fff;stroke-width:4px;stroke-linejoin:round;text-anchor:middle}.builder-symbol{font-size:28px;font-weight:900}.builder-label{font-size:13px;font-weight:900;fill:#17324d}.builder-legend b{font-size:1rem;width:1.1rem;text-align:center}@media(max-width:700px){.builder-feature-row{grid-template-columns:1fr}}
.physical-map-card{margin-top:1rem;border:2px solid #8da9a8;border-radius:18px;background:#f8fbfa;padding:1rem}.comparison-heading{display:flex;justify-content:space-between;gap:1rem;align-items:start}.comparison-heading span{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:900;color:#146b70}.comparison-heading h3{margin:.15rem 0}.comparison-heading p{max-width:330px;font-size:.85rem}.physical-image-wrap{position:relative;border:1px solid #789392;background:#d9edf5;overflow:hidden}.physical-image-wrap img{display:block;width:100%;height:auto}.physical-label{position:absolute;padding:.2rem .4rem;border-radius:6px;background:#ffffffdf;color:#17324d;font-size:clamp(.58rem,1.2vw,.88rem);font-weight:900;box-shadow:0 1px 5px #17324d55}.physical-label.rockies{left:31%;top:37%;transform:rotate(-75deg)}.physical-label.plains{left:48%;top:42%}.physical-label.appalachians{left:72%;top:58%;transform:rotate(-35deg)}.physical-label.lakes{left:66%;top:22%}.physical-key{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;margin-top:.65rem;font-size:.78rem}.physical-key span{display:flex;align-items:center;gap:.35rem}.physical-key i{width:22px;height:13px;border:1px solid #31505e}.physical-key .low{background:#9bca61}.physical-key .mid{background:#b7461e}.physical-key .high{background:#fff}.physical-key .water{background:#a8d8ee}.map-source{font-size:.72rem;color:#59707d;margin:.55rem 0 0}.region-row{display:grid;grid-template-columns:1.3fr 1fr 1fr 28px;gap:.7rem;align-items:end;margin:.75rem 0;padding:.8rem;background:#fff;border-radius:12px;border:1px solid #c9dedd}.region-color{width:24px;height:38px;border:2px solid #31505e;border-radius:7px}.builder-legend small{max-width:220px;font-weight:800}@media(max-width:700px){.comparison-heading{display:block}.region-row{grid-template-columns:1fr}.region-color{width:100%;height:12px}}
.settlement-labels text{font-size:12px;font-weight:900;fill:#17324d;paint-order:stroke;stroke:#fff;stroke-width:4px;stroke-linejoin:round;text-anchor:middle}.density-legend i{border:1px solid #31505e}.density-evidence{margin-top:.8rem;padding:.9rem 1rem;border:2px solid #b8cecc;border-radius:14px;background:#eef6f4}.density-evidence>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.5rem;margin:.6rem 0}.density-evidence span{display:grid;background:#fff;padding:.55rem;border-radius:9px}.density-evidence p{margin:.5rem 0;font-weight:800;color:#6f3d24}.density-evidence small{color:#59707d}
</style>
