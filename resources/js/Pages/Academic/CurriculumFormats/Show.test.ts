import { config, mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CurriculumFormatShow from './Show.vue';

const state = vi.hoisted(() => ({ forms: [] as any[], post: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (values: Record<string, unknown>) => {
        const form = reactive({ ...values, errors: {}, processing: false, isDirty: false, post: state.post, put: state.put });
        state.forms.push(form);
        return form;
    },
}));

(globalThis as any).route = (name: string, params?: unknown) => `${name}:${JSON.stringify(params ?? '')}`;
config.global.mocks = { route: (globalThis as any).route };

const detected = {
    title: '5th Grade Science Year at a Glance 2025-2026', page_count: 2,
    headings: ['1st Nine Weeks', '2nd Nine Weeks'],
    unit_rows: ['08/13 - 09/17 25 Earth Processes 5.10A, 5.10B'],
    assessment_rows: ['MAP BOY'], date_rows: ['08/13 - 09/17'],
    standards_like_codes: ['5.10A', '5.10B'], column_labels: ['Date', 'Days', 'Unit', 'TEKS'],
    suggested_strategy: 'positioned_date_unit_table',
};
const source = { id: 4, title: '5th - Science', provider: 'CFISD', grade: 'Grade 5', school_year: '2026-2027' };
const mountPage = (profile: any = null, overrides: Record<string, unknown> = {}) => mount(CurriculumFormatShow, {
    props: { profile, source, detected, canManage: true, ...overrides },
    global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } },
});

describe('curriculum document format setup', () => {
    beforeEach(() => { state.forms = []; state.post.mockReset(); state.put.mockReset(); vi.restoreAllMocks(); });

    it('shows a focused non-mutating setup screen and starts a draft explicitly', async () => {
        const wrapper = mountPage();
        expect(wrapper.text()).toContain('Your private PDF is unchanged.');
        expect(wrapper.text()).toContain('5th Grade Science Year at a Glance 2025-2026');
        expect(wrapper.text()).toContain('Date, Days, Unit, TEKS');
        expect(wrapper.get('details').attributes('open')).toBeUndefined();
        expect(wrapper.text()).toContain('Starting setup creates a draft profile, not a curriculum import.');
        await wrapper.get('form').trigger('submit');
        expect(state.post).toHaveBeenCalledWith('academic.sources.curriculum-format-setup.store:4');
    });

    it('saves dirty draft mappings before allowing separately confirmed activation', async () => {
        const profile = { id: 7, status: 'draft', name: 'CFISD Science YAG', document_family: 'CFISD Elementary Science Year at a Glance', mapping_rules: {
            strategy: 'positioned_date_unit_table', confirmed_period_headings: ['1st Nine Weeks'], confirmed_unit_rows: [], confirmed_assessment_rows: [],
        } };
        const wrapper = mountPage(profile);
        const mappingForm = state.forms[0];
        mappingForm.confirmed_unit_rows = [detected.unit_rows[0]];
        mappingForm.isDirty = true;
        await wrapper.get('form.card').trigger('submit');
        expect(state.put).toHaveBeenCalledWith('academic.curriculum-format-profiles.update:7', { preserveScroll: true });
        expect(wrapper.get('button.btn-success').attributes('disabled')).toBeDefined();

        mappingForm.isDirty = false;
        await nextTick();
        expect(wrapper.get('button.btn-success').attributes('disabled')).toBeUndefined();
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        await wrapper.get('button.btn-success').trigger('click');
        expect(confirm).toHaveBeenCalled();
        expect(state.post).toHaveBeenCalledWith('academic.curriculum-format-profiles.activate:7');
    });

    it('shows exactly eight clean Spanish headings without a course-level evidence row', () => {
        const spanishUnits = [
            'Unit 1 - Hola, Soy Yo',
            'Unit 2 - Números, Colores y Mi Día',
            'Unit 3 - Mi Familia y Las Personas',
            'Unit 4 - Mi Escuela Ideal',
            'Unit 5 - Tengo Hambre',
            'Unit 6 - Mi Mundo',
            'Unit 7 - Vamos de Viaje',
            'Unit 8 - Mi Aventura en Español',
        ];
        const wrapper = mountPage({
            id: 9,
            status: 'superseded',
            name: 'Spanish format history',
            document_family: 'custom-homeschool-curriculum',
            mapping_rules: { strategy: 'confirmed_heading_rows', confirmed_period_headings: [], confirmed_unit_rows: [], confirmed_assessment_rows: [] },
        }, {
            detected: { ...detected, title: 'COSMIC QUEST ACADEMY', headings: [], unit_rows: spanishUnits, assessment_rows: [], unit_ambiguities: [], column_labels: ['Unit'] },
            source: { ...source, id: 7, title: '5 - Spanish' },
        });
        expect(wrapper.findAll('input[id^="unit-"]')).toHaveLength(8);
        expect(wrapper.findAll('fieldset').find((field) => field.text().includes('Unit rows'))?.findAll('label').map((label) => label.text())).toEqual(spanishUnits);
        expect(wrapper.text()).not.toContain('I can greet someone');
        expect(wrapper.text()).not.toContain('Evidence of Learning');
        expect(wrapper.get('details').attributes('open')).toBeUndefined();
        expect(wrapper.findAll('.col-lg-4')).toHaveLength(3);
        expect(wrapper.findAll('legend').map((legend) => legend.text())).toEqual(['Reporting-period headings', 'Unit rows', 'Assessment rows']);
    });

    it('shows the complete periodless Social Studies structure without prose or top-level assessments', () => {
        const socialUnits = [
            'Unit 1 - Foundations: Reading the United States',
            'Unit 2 - Colonization and Early America',
            'Unit 3 - Revolution, Independence, and the Constitution',
            'Unit 4 - A Growing Nation: War of 1812, Industry, and Expansion',
            'Unit 5 - Sectionalism, Civil War, Reconstruction, and the West',
            'Unit 6 - Industrial America and Immigration',
            'Unit 7 - The United States in Crisis and Change',
            'Unit 8 - America in the 21st Century',
            'Unit 9 - U.S. Geography and Economy Synthesis',
            'Unit 10 - Government, Citizenship, Culture, and American Identity',
            'Unit 11 - America Through Time - Social Studies Capstone',
        ];
        const wrapper = mountPage({
            id: 10, status: 'superseded', name: 'Social Studies format history', document_family: 'custom-homeschool-curriculum',
            mapping_rules: { strategy: 'confirmed_heading_rows', confirmed_period_headings: [], confirmed_unit_rows: [], confirmed_assessment_rows: [] },
        }, {
            source: { ...source, id: 8, title: '5th - SS1' },
            detected: { ...detected, title: 'COSMIC QUEST ACADEMY', headings: [], unit_rows: socialUnits, assessment_rows: [], unit_ambiguities: [], column_labels: ['Unit'] },
        });

        expect(wrapper.findAll('input[id^="period-"]')).toHaveLength(0);
        expect(wrapper.findAll('input[id^="unit-"]')).toHaveLength(11);
        expect(wrapper.findAll('input[id^="assessment-"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Unit 9 - U.S. Geography and Economy Synthesis');
        expect(wrapper.text()).not.toContain('2026-2027 Pacing Guide');
        expect(wrapper.text()).not.toContain('generation should occur after the pacing guide is approved');
        expect(wrapper.text()).not.toContain('End-of-Unit Evidence');
        expect(wrapper.findAll('.col-lg-4')).toHaveLength(3);
        expect(wrapper.get('details').attributes('open')).toBeUndefined();
    });
});
