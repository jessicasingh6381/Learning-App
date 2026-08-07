import { config, mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StandardsImportShow from './Show.vue';

const state = vi.hoisted(() => ({ forms: [] as any[], put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (values: Record<string, any>) => { const form = reactive({ ...values, errors: {}, processing: false, isDirty: false, put: state.put, post: state.post }); state.forms.push(form); return form; },
}));
(globalThis as any).route = (name: string, params?: unknown) => `${name}:${JSON.stringify(params ?? '')}`;
config.global.mocks = { route: (globalThis as any).route };

const row = (overrides: Record<string, unknown> = {}): any => ({ id: 1, parent_proposal_id: null, proposal_type: 'strand', included: true, sequence: 1, name: 'History', strand: 'History', standard_code: null, normalized_code: 'STRAND:HISTORY', statement: 'History', source_page: 21, raw_text: 'History', parser_note: 'Source strand', confidence: .99, manually_edited: false, children: [], ...overrides });
const props = (status = 'review'): any => ({
    standardsImport: { id: 9, status, parser_key: 'texas-teks-multigrade-social-studies', parser_version: 'v1', extraction_method: 'pdf_text_sectioned', diagnostic: 'Only Grade 5.', document_section: '113.16', adopted_label: 'Adopted 2022', introduction_text: 'In Grade 5, students survey United States history.', document_metadata: { source_pages: [20, 26], adopted_label: 'Adopted 2022', version_label: 'August 2024 Update', effective_label: '2024-2025 school year', implementation_label: '2024-2025 school year', update_label: 'August 2024 Update', implementation_statement: 'The provisions of this section shall be implemented by school districts beginning with the 2024-2025 school year.' }, review_version: 1 },
    source: { id: 5, title: '5th - SS', file: { id: 5, name: 'Social Studies.pdf' } },
    context: { subject: 'Social Studies', grade: 'Grade 5', school_year: '2026-2027', framework: 'Texas Essential Knowledge and Skills' },
    strands: [row({ children: [row({ id: 2, parent_proposal_id: 1, proposal_type: 'standard', name: '5.1', standard_code: '5.1', normalized_code: '5.1', statement: 'The student understands colonization.', raw_text: '(1) History...', children: [row({ id: 3, parent_proposal_id: 2, proposal_type: 'student_expectation', name: '5.1A', standard_code: '5.1A', normalized_code: '5.1A', statement: 'explain when, where, and why...', raw_text: '(A) explain...', children: [] })] })] })],
    canManage: status === 'review',
});
const mountPage = (status = 'review') => mount(StandardsImportShow, { props: props(status), global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

describe('standards import review', () => {
    beforeEach(() => { state.forms = []; state.put.mockReset(); state.post.mockReset(); vi.restoreAllMocks(); });

    it('renders strand-standard-expectation hierarchy with progressive evidence and one bulk save', async () => {
        const wrapper = mountPage();
        expect(wrapper.text()).toContain('History'); expect(wrapper.text()).toContain('5.1'); expect(wrapper.text()).toContain('5.1A');
        expect(wrapper.text()).toContain('no pacing, units, dates, or assessments will be created');
        expect(wrapper.findAll('details').every((detail) => detail.attributes('open') === undefined)).toBe(true);
        expect(wrapper.findAll('button').filter((button) => button.text() === 'Save standards review')).toHaveLength(1);
        const review = state.forms[0]; review.proposals[3].statement = 'Reviewed expectation'; review.isDirty = true; await nextTick();
        expect(wrapper.get('button.btn-success').attributes('disabled')).toBeDefined();
        await wrapper.get('form').trigger('submit');
        expect(state.put).toHaveBeenCalledWith('academic.standards-imports.review.update:9', expect.objectContaining({ preserveScroll: true }));
        review.isDirty = false; await nextTick();
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true); await wrapper.get('button.btn-success').trigger('click');
        expect(confirm).toHaveBeenCalled(); expect(state.post).toHaveBeenCalledWith('academic.standards-imports.approve:9');
    });

    it('renders approved standards read-only and explains that pacing remains separate', () => {
        const wrapper = mountPage('approved');
        expect(wrapper.text()).toContain('Standards imported.'); expect(wrapper.text()).toContain('Pacing guide still needed.');
        expect(wrapper.text()).toContain('Adopted 2022'); expect(wrapper.text()).toContain('August 2024 Update'); expect(wrapper.text()).toContain('2024-2025 school year');
        expect(wrapper.text()).toContain('shall be implemented by school districts');
        expect(wrapper.findAll('input').every((input) => input.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.findAll('textarea').every((input) => input.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.text()).not.toContain('Save standards review');
    });

    it('shows a safe approval error without replacing the editable review', async () => {
        const wrapper = mountPage();
        state.forms[1].errors.approval = 'Approval could not be completed. No standards were imported; review the saved values and try again.';
        await nextTick();
        expect(wrapper.get('[role="alert"]').text()).toContain('Approval could not be completed');
        expect(wrapper.text()).not.toContain('SQLSTATE'); expect(wrapper.text()).not.toContain('QueryException');
        expect(wrapper.get('textarea').attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('Save standards review'); expect(wrapper.text()).toContain('Approve standards');
    });
});
