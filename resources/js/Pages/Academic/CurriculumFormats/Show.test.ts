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
const mountPage = (profile: any = null) => mount(CurriculumFormatShow, {
    props: { profile, source, detected, canManage: true },
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
});
