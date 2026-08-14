import { config, flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import Show from './Show.vue';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (values: any) => reactive({ ...values, processing: false, errors: {}, clearErrors: vi.fn(), post }),
}));
vi.mock('axios');
config.global.mocks = { route: vi.fn() };

const activity = (overrides: any = {}) => ({
    id: 11, sequence: 1, type: 'instruction', title: 'Accept the Map Mission',
    instructions: 'Read the mission.', content: 'Build a useful map.', interaction: { materials: ['Outline map'] },
    hints: [], reward_label: 'Mission accepted', theme_key: 'mission', requires_teacher_review: false,
    response_url: '/preview/respond', saved_response: null, response_status: null, is_correct: null, feedback: null,
    ...overrides,
});
const props = (overrides: any = {}) => ({
    preview: true, return_url: '/lessons/1', student: { display_name: 'Kai', grade_level: 'Grade 5' },
    lesson: { title: 'Reading and Creating Maps of the United States', subject: 'Social Studies', learning_objective: 'Read a map and create a reference map.', estimated_minutes: 55 },
    experience: { mission_title: 'U.S. Mapmaker Mission', mission_brief: 'Decode map tools and build a map.', completion_title: 'Map Mission Complete', completion_message: 'Your map is ready for review.' },
    progress: { status: 'in_progress', current_activity_id: 11, completed_count: 0, total_count: 2, percent: 0 },
    resource_groups: {
        student_supply: [{ id: 1, title: 'Pencil and eraser', delivery_type: 'physical', student_experience_required: true, url: null }],
        lesson_resource: [{ id: 2, title: 'Blank U.S. Outline Map', description: 'Printable map', delivery_type: 'printable', availability_status: 'needs_asset', url: null }],
        special_material: [],
    },
    activities: [activity(), activity({ id: 12, sequence: 2, title: 'Legend Decoder', type: 'multiple_choice', interaction: { choices: [{ id: 'legend', label: 'Legend' }] }, response_url: '/preview/respond-2' })],
    ...overrides,
});

describe('student lesson experience', () => {
    beforeEach(() => { post.mockReset(); vi.mocked(axios.post).mockReset(); vi.useRealTimers(); });

    it('renders a distinct preview mission one activity at a time with locked future steps', () => {
        const wrapper = mount(Show, { props: props() });
        expect(wrapper.text()).toContain('Teacher preview');
        expect(wrapper.text()).toContain('U.S. Mapmaker Mission');
        expect(wrapper.text()).toContain('Accept the Map Mission');
        expect(wrapper.text()).toContain('Print and reference maps');
        expect(wrapper.text()).toContain('Blank U.S. Outline Map');
        expect(wrapper.text()).toContain('You’ll need');
        expect(wrapper.text()).toContain('Pencil and eraser');
        expect(wrapper.text()).toContain('Preparing resource...');
        expect(wrapper.text()).not.toContain('attach asset');
        expect(wrapper.text()).not.toContain('Where should you look first?');
        expect(wrapper.get('.step-nav button:nth-child(2)').attributes('disabled')).toBeDefined();
        expect(wrapper.get('.experience-shell').classes()).toContain('experience-shell');
    });

    it('embeds the interactive map while hiding secondary PDFs and optional generic supplies', async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: { type: 'FeatureCollection', features: [
            { type: 'Feature', properties: { state_fips: '48', name: 'Texas' }, geometry: { type: 'Polygon', coordinates: [[[-106, 26], [-93.5, 26], [-93.5, 36.5], [-106, 36.5], [-106, 26]]] } },
        ] } });
        const mapTools = ['Title', 'Orientation', 'Legend', 'Labels', 'Scale', 'Symbols'].map((label) => ({ label, detail: `${label} explanation` }));
        const mapProps = props({
            progress: { status: 'in_progress', current_activity_id: 12, completed_count: 1, total_count: 2, percent: 50 },
            activities: [activity({ response_status: 'completed' }), activity({ id: 12, sequence: 2, title: 'Pack Your Map-Reader Toolkit', interaction: { facts: mapTools }, response_url: '/preview/respond-2' })],
            resource_groups: {
                student_supply: [{ id: 1, title: 'Pencil and eraser' }], special_material: [],
                lesson_resource: [
                    { id: 3, title: 'Explore the United States', resource_type: 'interactive_us_map', delivery_type: 'interactive', availability_status: 'ready', url: '/private/states.geojson' },
                    { id: 4, title: 'Blank U.S. Outline Map', resource_type: 'blank_map', delivery_type: 'printable', availability_status: 'ready', url: '/private/blank.pdf' },
                    { id: 5, title: 'Labeled U.S. Reference Map', resource_type: 'reference_map', delivery_type: 'viewable', availability_status: 'ready', url: '/private/reference.pdf' },
                ],
            },
        });
        const wrapper = mount(Show, { props: mapProps });
        await flushPromises();

        expect(wrapper.text()).toContain('Interactive U.S. map');
        expect(wrapper.text()).toContain('Map tool 1 of 6');
        expect(wrapper.text()).not.toContain('Print and reference maps');
        expect(wrapper.text()).not.toContain('Print blank map');
        expect(wrapper.text()).not.toContain('View reference map');
        expect(wrapper.text()).not.toContain('Blank U.S. Outline Map');
        expect(wrapper.text()).not.toContain('Labeled U.S. Reference Map');
        expect(wrapper.text()).not.toContain('You’ll need');
        expect(wrapper.text()).not.toContain('Pencil and eraser');
        expect(wrapper.find('.resource-panel').exists()).toBe(false);
        expect(wrapper.find('.fact-grid').exists()).toBe(false);
    });

    it('places reference mode beside the Step 5 evidence questions', async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: { type: 'FeatureCollection', features: [
            { type: 'Feature', properties: { state_fips: '06', name: 'California' }, geometry: { type: 'Polygon', coordinates: [[[-124, 32], [-114, 32], [-114, 42], [-124, 42], [-124, 32]]] } },
            { type: 'Feature', properties: { state_fips: '36', name: 'New York' }, geometry: { type: 'Polygon', coordinates: [[[-80, 40], [-72, 40], [-72, 45], [-80, 45], [-80, 40]]] } },
            { type: 'Feature', properties: { state_fips: '48', name: 'Texas' }, geometry: { type: 'Polygon', coordinates: [[[-106, 26], [-93.5, 26], [-93.5, 36.5], [-106, 36.5], [-106, 26]]] } },
        ] } });
        const referenceActivity = activity({
            id: 15, sequence: 5, type: 'short_response', title: 'Read a Real Reference Map',
            instructions: 'Use your reference map—not memory—to record three pieces of evidence.',
            interaction: { map_mode: 'reference', fields: [
                { id: 'symbol_meaning', label: 'What does the star symbol represent on this map?', control: 'multiple_choice', choices: [
                    { id: 'national_capital', label: 'The national capital, Washington, D.C.' },
                    { id: 'state_boundary', label: 'A state boundary' },
                ] },
                { id: 'relative_location', label: 'Is Texas east or west of Florida? Explain how you know using the map.', control: 'short_response' },
                { id: 'limitation', label: 'Does this map show how many people live in each state?', control: 'multiple_choice', choices: [
                    { id: 'no_population', label: 'No. The map does not show state population numbers.' },
                    { id: 'yes_population', label: 'Yes. The region colors show population.' },
                ] },
            ] },
        });
        const wrapper = mount(Show, { props: props({
            activities: [referenceActivity],
            progress: { status: 'in_progress', current_activity_id: 15, completed_count: 4, total_count: 7, percent: 57 },
            resource_groups: { student_supply: [], special_material: [], lesson_resource: [
                { id: 3, title: 'Explore the United States', resource_type: 'interactive_us_map', delivery_type: 'interactive', availability_status: 'ready', url: '/private/states.geojson' },
            ] },
        }) });
        await flushPromises();

        const body = wrapper.get('.activity-body');
        expect(body.findAll('.interactive-map')).toHaveLength(1);
        expect(body.text()).toContain('U.S. Census Regions and Selected States');
        expect(body.text()).toContain('California');
        expect(body.text()).toContain('Texas');
        expect(body.text()).toContain('New York');
        expect(body.text()).toContain('National capital');
        expect(body.text()).toContain('What does the star symbol represent on this map?');
        expect(body.text()).toContain('Is Texas east or west of Florida? Explain how you know using the map.');
        expect(body.text()).toContain('Does this map show how many people live in each state?');
        expect(body.findAll('.response-choice')).toHaveLength(2);
        expect(body.findAll('textarea')).toHaveLength(1);
        expect(body.html().indexOf('interactive-map')).toBeLessThan(body.html().indexOf('What does the star symbol represent on this map?'));
    });

    it('restores a saved Step 6 digital map and has no paper dependency', async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: { type: 'FeatureCollection', features: [
            { type: 'Feature', properties: { state_fips: '06', name: 'California' }, geometry: { type: 'Polygon', coordinates: [[[-124, 32], [-114, 32], [-114, 42], [-124, 42], [-124, 32]]] } },
            { type: 'Feature', properties: { state_fips: '12', name: 'Florida' }, geometry: { type: 'Polygon', coordinates: [[[-87.6, 24.5], [-80, 24.5], [-80, 31], [-87.6, 31], [-87.6, 24.5]]] } },
            { type: 'Feature', properties: { state_fips: '48', name: 'Texas' }, geometry: { type: 'Polygon', coordinates: [[[-106, 26], [-93.5, 26], [-93.5, 36.5], [-106, 36.5], [-106, 26]]] } },
        ] } });
        const savedResponse = {
            map: { title: 'Kai’s Explorer Map', show_orientation: true, features: [
                { state_fips: '06', marker_key: 'blue_circle', legend_label: 'Pacific place' },
                { state_fips: '12', marker_key: 'gold_star', legend_label: 'Atlantic place' },
                { state_fips: '48', marker_key: 'green_triangle', legend_label: 'Central place' },
            ] },
            reflections: { information_shown: 'Three places', symbol_explanation: 'The star marks Florida.', relative_location: 'California is west of Texas.' },
        };
        const builder = activity({
            id: 16, sequence: 6, type: 'project', title: 'Build Your Explorer Reference Map',
            instructions: 'Build your reference map in the digital map builder.', content: 'Give every marker one consistent meaning.',
            interaction: { map_mode: 'builder', map_builder: { minimum_features: 3 }, reflection_fields: [
                { id: 'information_shown', label: 'What information does your map show?' },
                { id: 'symbol_explanation', label: 'What does one symbol represent?' },
                { id: 'relative_location', label: 'Where is one place relative to another?' },
            ] },
            saved_response: savedResponse, response_status: 'in_progress', draft_url: '/preview/respond-6/draft', response_url: '/preview/respond-6',
        });
        const wrapper = mount(Show, { props: props({
            activities: [builder], progress: { status: 'in_progress', current_activity_id: 16, completed_count: 5, total_count: 7, percent: 71 },
            resource_groups: { student_supply: [], special_material: [], lesson_resource: [{ id: 3, title: 'Explore the United States', resource_type: 'interactive_us_map', delivery_type: 'interactive', availability_status: 'ready', url: '/private/states.geojson' }] },
        }) });
        await flushPromises();

        expect((wrapper.get('.builder-title input').element as HTMLInputElement).value).toBe('Kai’s Explorer Map');
        expect((wrapper.get('.orientation-toggle input').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.findAll('.builder-checks li.complete')).toHaveLength(5);
        expect(wrapper.findAll('.project-reflections textarea').map((item) => (item.element as HTMLTextAreaElement).value)).toEqual(['Three places', 'The star marks Florida.', 'California is west of Texas.']);
        expect(wrapper.get('.explorer-button').text()).toBe('Submit map and continue');
        expect(wrapper.text().toLowerCase()).not.toContain('paper');
        expect(wrapper.text().toLowerCase()).not.toContain('pencil');
        expect(wrapper.text().toLowerCase()).not.toContain('ruler');
        expect(wrapper.text().toLowerCase()).not.toContain('print');
    });

    it('autosaves structured Step 6 map edits without submitting the activity', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({ data: { type: 'FeatureCollection', features: [] } });
        vi.mocked(axios.post).mockResolvedValue({ data: { saved: true } } as any);
        const builder = activity({
            id: 16, sequence: 6, type: 'project', title: 'Build Your Explorer Reference Map', draft_url: '/preview/respond-6/draft',
            interaction: { map_mode: 'builder', map_builder: { minimum_features: 3 }, reflection_fields: [] },
        });
        const wrapper = mount(Show, { props: props({
            activities: [builder], progress: { status: 'in_progress', current_activity_id: 16, completed_count: 5, total_count: 7, percent: 71 },
            resource_groups: { student_supply: [], special_material: [], lesson_resource: [{ id: 3, resource_type: 'interactive_us_map', delivery_type: 'interactive', url: '/private/states.geojson' }] },
        }) });
        await flushPromises();
        await wrapper.get('.builder-title input').setValue('Autosaved Explorer Map');
        await vi.advanceTimersByTimeAsync(650);

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/preview/respond-6/draft', expect.objectContaining({ response: expect.objectContaining({ map: expect.objectContaining({ title: 'Autosaved Explorer Map' }) }) }));
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Map progress saved.');
    });

    it('renders and restores the Science Earth systems builder without paper resources', () => {
        const builder = activity({
            id: 46, sequence: 6, type: 'project', title: 'Build an Earth Systems Map',
            interaction: { systems_map_builder: { minimum_terms: 5, minimum_connections: 3, terms: ['water', 'rock', 'weathering', 'erosion', 'sediment'], relationships: ['breaks', 'carries', 'builds up'] } },
            saved_response: { systems_map: { terms: ['water', 'rock', 'weathering', 'erosion', 'sediment'], connections: [
                { from: 'water', relationship: 'breaks', to: 'rock' }, { from: 'water', relationship: 'carries', to: 'sediment' }, { from: 'weathering', relationship: 'builds up', to: 'sediment' },
            ], question: 'How does moving water change rock over time?' } },
            draft_url: '/preview/science/draft', response_url: '/preview/science/respond', requires_teacher_review: true,
        });
        const wrapper = mount(Show, { props: props({
            lesson: { title: 'Introducing Earth Processes as Connected Systems', subject: 'Science', learning_objective: 'Describe interacting Earth processes.', estimated_minutes: 50 },
            activities: [builder], progress: { status: 'in_progress', current_activity_id: 46, completed_count: 5, total_count: 7, percent: 71 },
            resource_groups: { student_supply: [], special_material: [], lesson_resource: [
                { id: 13, title: 'Changing Landscapes Photograph Set', resource_type: 'photograph', delivery_type: 'viewable', availability_status: 'ready', url: '/private/coast.jpg' },
                { id: 15, title: 'Earth Processes Systems Map', resource_type: 'graphic_organizer', delivery_type: 'interactive', availability_status: 'ready', url: '/private/systems.png' },
            ] },
        }) });

        expect(wrapper.find('.systems-builder').exists()).toBe(true);
        expect(wrapper.findAll('.term-grid input:checked')).toHaveLength(5);
        expect((wrapper.get('.systems-builder textarea').element as HTMLTextAreaElement).value).toBe('How does moving water change rock over time?');
        expect(wrapper.get('.explorer-button').text()).toBe('Submit systems map and continue');
        expect(wrapper.find('.resource-panel').exists()).toBe(false);
        for (const dependency of ['paper', 'pencil', 'ruler', 'print']) expect(wrapper.text().toLowerCase()).not.toContain(dependency);
    });

    it('renders Lesson 2 comparison evidence and restores its digital region project without supplies or external lookup', async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: geographyForRegions() });
        const savedResponse = {
            map: { title: 'Kai’s Three Regions', criterion: 'relative location from west to east', regions: [
                { id: 'region_1', name: 'Western', color_key: 'teal', state_fips: ['06', '53'] },
                { id: 'region_2', name: 'Central', color_key: 'gold', state_fips: ['40', '48'] },
                { id: 'region_3', name: 'Eastern', color_key: 'coral', state_fips: ['12', '36'] },
            ] },
            reflections: { boundary_evidence: 'California and Washington are both west of the central states.', different_criterion: 'A mountain region could cross state boundaries.' },
        };
        const regionProject = activity({
            id: 26, sequence: 6, type: 'project', title: 'Build a Regional Reference Layer',
            instructions: 'Build three colored regions from complete states.', content: 'The app creates shading, labels, boundaries, and a legend.',
            interaction: { map_mode: 'region_builder', region_builder: { minimum_regions: 3, minimum_states_per_region: 2, color_keys: ['teal', 'gold', 'coral'] }, reflection_fields: [
                { id: 'boundary_evidence', label: 'What visible evidence supports one region?' },
                { id: 'different_criterion', label: 'How might a physical criterion change a boundary?' },
            ] },
            saved_response: savedResponse, response_status: 'in_progress', draft_url: '/preview/lesson-2/draft', response_url: '/preview/lesson-2/respond',
        });
        const wrapper = mount(Show, { props: props({
            lesson: { title: 'Physical and Political Regions of the United States', subject: 'Social Studies', learning_objective: 'Distinguish physical and political information.', estimated_minutes: 60 },
            activities: [regionProject], progress: { status: 'in_progress', current_activity_id: 26, completed_count: 5, total_count: 7, percent: 71 },
            resource_groups: { student_supply: [], special_material: [], lesson_resource: [
                { id: 7, resource_type: 'interactive_us_map', delivery_type: 'interactive', availability_status: 'ready', url: '/private/lesson-2-states.geojson' },
                { id: 8, resource_type: 'physical_us_map', delivery_type: 'viewable', availability_status: 'ready', url: '/private/usgs-topography.jpg' },
            ] },
        }) });
        await flushPromises();

        expect((wrapper.get('.builder-title input').element as HTMLInputElement).value).toBe('Kai’s Three Regions');
        expect(wrapper.findAll('.builder-checks li.complete')).toHaveLength(5);
        expect(wrapper.text()).toContain('Western');
        expect(wrapper.text()).toContain('California');
        expect(wrapper.get('.physical-map-card img').attributes('src')).toBe('/private/usgs-topography.jpg');
        expect(wrapper.findAll('.project-reflections textarea')).toHaveLength(2);
        const text = wrapper.text().toLowerCase();
        for (const dependency of ['paper', 'pencil', 'ruler', 'print', 'atlas', 'search the internet', 'teacher action']) expect(text).not.toContain(dependency);
        expect(wrapper.find('.resource-panel').exists()).toBe(false);
    });

    it('keeps an incorrect checked answer on the current activity and allows another try', async () => {
        const legend = activity({
            type: 'multiple_choice', title: 'Legend Decoder',
            interaction: { choices: [{ id: 'title', label: 'Title' }, { id: 'legend', label: 'Legend' }] },
        });
        const wrapper = mount(Show, { props: props({ activities: [legend, activity({ id: 12, sequence: 2, title: 'Next activity' })] }) });

        await wrapper.setProps({
            progress: { status: 'in_progress', current_activity_id: 11, completed_count: 0, total_count: 2, percent: 0 },
            activities: [
                { ...legend, saved_response: { selected: 'title' }, response_status: 'in_progress', is_correct: false, feedback: 'Look for the map tool that explains symbols.' },
                activity({ id: 12, sequence: 2, title: 'Next activity' }),
            ],
        });

        expect(wrapper.text()).toContain('Legend Decoder');
        expect(wrapper.text()).toContain('Look for the map tool that explains symbols.');
        expect(wrapper.get('.explorer-button').text()).toBe('Check my work');
        expect(wrapper.get('input[value="title"]').attributes('disabled')).toBeUndefined();
    });

    it('keeps the two-line Python output hidden until an ungraded prediction is saved', async () => {
        const prediction = activity({
            type: 'multiple_choice', title: 'Which Message Comes First?',
            instructions: 'Make a prediction. Predictions are not graded.',
            interaction: {
                ungraded: true,
                choices: [{ id: 'online', label: 'Mission Control Online' }, { id: 'launch', label: 'Launch sequence started' }],
                code_display: { source: 'print("Mission Control Online")\nprint("Launch sequence started")', output: ['Mission Control Online', 'Launch sequence started'], hide_output_until_response: true, execution_notice: 'Illustrated output; no Python code was executed.' },
            },
        });
        const wrapper = mount(Show, { props: props({ activities: [prediction] }) });
        expect(wrapper.text()).toContain('Make your prediction to reveal');
        expect(wrapper.find('.code-output').exists()).toBe(false);

        await wrapper.setProps({ activities: [{ ...prediction, response_status: 'completed', saved_response: { selected: 'launch' }, is_correct: null, feedback: 'Compare your prediction with the output.' }] });
        expect(wrapper.get('.code-output').text()).toContain('Mission Control Online');
        expect(wrapper.get('.code-output').text()).toContain('Launch sequence started');
        expect(wrapper.text()).toContain('no Python code was executed');
    });

    it('locks a correct checked answer and advances exactly one activity only after Continue', async () => {
        const legend = activity({
            type: 'multiple_choice', title: 'Legend Decoder',
            interaction: { choices: [{ id: 'title', label: 'Title' }, { id: 'legend', label: 'Legend' }] },
        });
        const next = activity({ id: 12, sequence: 2, title: 'Match the Map Tools', type: 'matching', interaction: { prompts: [], options: [] }, response_url: '/preview/respond-2' });
        const third = activity({ id: 13, sequence: 3, title: 'Read a Real Reference Map', response_url: '/preview/respond-3' });
        const wrapper = mount(Show, { props: props({ activities: [legend, next, third], progress: { status: 'in_progress', current_activity_id: 11, completed_count: 0, total_count: 3, percent: 0 } }) });

        await wrapper.setProps({
            progress: { status: 'in_progress', current_activity_id: 12, completed_count: 1, total_count: 3, percent: 33 },
            activities: [
                { ...legend, saved_response: { selected: 'legend' }, response_status: 'completed', is_correct: true, feedback: 'Exactly. The legend decodes the colors and symbols.' },
                next,
                third,
            ],
        });

        expect(wrapper.text()).toContain('Legend Decoder');
        expect(wrapper.text()).toContain('Exactly. The legend decodes the colors and symbols.');
        expect(wrapper.get('.step-nav button:nth-child(1)').classes()).toContain('done');
        expect(wrapper.get('.explorer-button').text()).toBe('Continue');
        expect(wrapper.get('input[value="legend"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('Read a Real Reference Map');

        await wrapper.get('.explorer-button').trigger('click');

        expect(wrapper.text()).toContain('Match the Map Tools');
        expect(wrapper.text()).not.toContain('Read a Real Reference Map');
    });

    it('posts only the student response to the server-owned activity endpoint', async () => {
        const wrapper = mount(Show, { props: props() });
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/preview/respond', { preserveScroll: true });
        expect(JSON.stringify(wrapper.props())).not.toContain('answer_data');
    });

    it('uses the existing lesson completion state after a correct final checked activity', () => {
        const finalCheck = activity({ id: 12, sequence: 2, title: 'Final Map-Reading Check', type: 'question_set', response_status: 'completed', is_correct: true, feedback: 'Field check passed.', interaction: { questions: [] } });
        const wrapper = mount(Show, { props: props({ activities: [activity({ response_status: 'completed' }), finalCheck], progress: { status: 'completed', current_activity_id: 12, completed_count: 2, total_count: 2, percent: 100 } }) });
        expect(wrapper.text()).toContain('Map Mission Complete');
        expect(wrapper.text()).toContain('parent/teacher review');
        expect(wrapper.text()).not.toContain('Continue');
        expect(wrapper.text()).not.toContain('Grade:');
        expect(wrapper.text()).not.toContain('Mastery');
    });
});

const geographyForRegions = () => ({
    type: 'FeatureCollection', features: [
        ['06', 'California', -124, -114, 32, 42], ['53', 'Washington', -124, -117, 46, 49],
        ['40', 'Oklahoma', -103, -94, 34, 37], ['48', 'Texas', -106, -94, 26, 36],
        ['12', 'Florida', -88, -80, 25, 31], ['36', 'New York', -80, -72, 40, 45],
    ].map(([state_fips, name, west, east, south, north]) => ({
        type: 'Feature', properties: { state_fips, name },
        geometry: { type: 'Polygon', coordinates: [[[west, south], [east, south], [east, north], [west, north], [west, south]]] },
    })),
});
