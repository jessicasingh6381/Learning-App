import { config, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SourcesShow from '../Sources/Show.vue';
import CurriculumImportShow from './Show.vue';
import CurriculumUnitComponentTree from '@/Components/CurriculumUnitComponentTree.vue';

const state = vi.hoisted(() => ({
    forms: [] as any[], put: vi.fn(), post: vi.fn(), before: undefined as undefined | (() => boolean),
    errors: {} as Record<string, string>,
}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn((_name: string, callback: () => boolean) => { state.before = callback; return vi.fn(); }), patch: vi.fn(), delete: vi.fn() },
    useForm: (values: Record<string, unknown>) => {
        const form = reactive({ ...values, errors: state.errors, processing: false, clearErrors: vi.fn(), reset: vi.fn(),
            put: state.put, post: state.post, patch: vi.fn(), delete: vi.fn() });
        state.forms.push(form); return form;
    },
}));
(globalThis as any).route = (name: string, params?: unknown) => `${name}:${JSON.stringify(params ?? '')}`;
config.global.mocks = { route: (globalThis as any).route };
const stubs = { AuthenticatedLayout: { template: '<main><slot /></main>' }, AcademicNav: { template: '<nav />' } };

const proposal = (overrides: Record<string, unknown> = {}): any => ({
    id: 2, parent_proposal_id: 1, proposal_type: 'unit', included: true, sequence: 1,
    name: 'Bridge to 5th Grade', planned_start_date: '2027-05-10', planned_end_date: '2027-05-21',
    estimated_days: 10, unit_type: 'transition', standard_codes: ['5.4A'], source_page: 1,
    raw_text: 'MAY 10 - MAY 21 — Bridge to 5th Grade', parser_note: 'Source wording preserved exactly and flagged for review.',
    component_type: null, description: null, summary: null, confidence: .55, manually_edited: false, warnings: ['Low-confidence extraction'], children: [], ...overrides,
});
const component = (overrides: Record<string, unknown> = {}): any => proposal({
    id: 11, parent_proposal_id: 2, proposal_type: 'component', name: 'Reading', sequence: 1,
    unit_type: null, component_type: 'strand', description: null, standard_codes: [],
    raw_text: 'HMH Module 1: Inventors at Work', parser_note: 'Associated from positioned row and column boundaries.',
    confidence: .94, warnings: [], children: [], ...overrides,
});
const props = (overrides: Record<string, unknown> = {}): any => ({
    curriculumImport: { id: 9, status: 'review', parser_key: 'cfisd-grade5-math-yag', parser_version: 'cfisd-grade5-math-yag-v1', extraction_method: 'pdf_positioned_text', diagnostic: 'One ambiguous block needs review.', source_revision_date: '2026-05-26', review_version: 3, included_count: 2, excluded_count: 0 },
    source: { id: 4, title: '5th - Math', file: { id: 12, name: '5th - Math.pdf' } },
    context: { subject: 'Mathematics', grade: 'Grade 5', school_year: '2026-2027', framework: 'TEKS', package: 'Grade 5 Curriculum', course: 'Grade 5 Mathematics' },
    periods: [{ ...proposal({ id: 1, parent_proposal_id: null, proposal_type: 'period', name: '1st Nine Weeks', sequence: 1, unit_type: null, standard_codes: [], warnings: [] }), children: [proposal(), proposal({ id: 3, name: 'Math Benchmark', proposal_type: 'assessment', unit_type: 'assessment', sequence: 2, warnings: [] })] }],
    unitTypes: ['instructional', 'review', 'transition', 'assessment'], componentTypes: ['strand', 'module', 'genre', 'skill', 'revising', 'conventions', 'foundational_skill', 'handwriting', 'integrated_subject', 'assessment_support', 'resource', 'other'], canManage: true, canReextract: false, ...overrides,
});

describe('curriculum outline import UI', () => {
    beforeEach(() => { state.forms = []; state.put.mockReset(); state.post.mockReset(); state.errors = {}; state.before = undefined; vi.restoreAllMocks(); });

    it('shows the supported ELAR source action while keeping setup and file details collapsed', () => {
        const wrapper = mount(SourcesShow, { props: {
            source: { id: 4, title: '5th - ELAR', description: '', source_kind: 'upload', source_category: 'curriculum', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'completed', archived_at: null, school_year_id: 1, education_provider: null, school_year: { name: '2026-2027' }, grade_level: { name: 'Grade 5' }, files: [{ id: 12, version_number: 1, is_current: true, original_filename: 'ELAR5-YAG PARENT 2026-2027.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'a'.repeat(64) }] },
            links: [], linkChoices: { subject: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] }, courseDefaults: {},
            calendarSetup: { is_calendar: false, linked_profiles: [], current_file_is_pdf: true, imports: [] },
            curriculumSetup: { is_curriculum: true, current_file_is_pdf: true, subject: { id: 2, name: 'English Language Arts and Reading', code: 'ELAR' }, workflow_state: 'ready', primary_action_label: 'Create curriculum outline', primary_action_url: '/sources/4/curriculum-imports', primary_action_method: 'post', back_url: '/learning-plan/curriculum-intake', capability: { state: 'supported', document_family: 'Parent Year at a Glance', internal_diagnostic: null }, imports: [{ id: 9, status: 'superseded', parser_version: 'v1', proposals_count: 17, created_at: '2026-08-03T12:00:00Z' }] },
            permissions: { manage: true, review: true, download: true }, reviewTransitions: [],
        }, global: { stubs } });
        expect(wrapper.text()).toContain('Create curriculum outline');
        expect(wrapper.text()).toContain('Outline extraction supported');
        expect(wrapper.text()).toContain('Recognized format: Parent Year at a Glance');
        expect(wrapper.text()).toContain('Grade 5 · 2026-2027 · English Language Arts and Reading');
        expect(wrapper.text()).toContain('View PDF');
        expect(wrapper.find('#curriculum-target').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Create a tenant-owned draft curriculum package');
        expect(wrapper.get('details.card').attributes('open')).toBeUndefined();
        expect(wrapper.findAll('details').some((details) => details.text().includes('Private file versions'))).toBe(true);
        expect(wrapper.findAll('details').some((details) => details.text().includes('Create course draft'))).toBe(true);
        expect(wrapper.find('nav').exists()).toBe(false);
    });

    it('shows a standards-specific source action without curriculum-unit language', async () => {
        const wrapper = mount(SourcesShow, { props: {
            source: { id: 5, title: '5th - SS', source_kind: 'upload', source_category: 'curriculum', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'completed', archived_at: null, school_year_id: 1, education_provider: { name: 'Cypress-Fairbanks Independent School District' }, school_year: { name: '2026-2027' }, grade_level: { name: 'Grade 5' }, files: [{ id: 5, version_number: 1, is_current: true, original_filename: 'Social Studies.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'e'.repeat(64) }] },
            links: [], linkChoices: { subject: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] }, courseDefaults: {},
            calendarSetup: { is_calendar: false, linked_profiles: [], current_file_is_pdf: true, imports: [] },
            curriculumSetup: { is_curriculum: true, current_file_is_pdf: true, subject: { name: 'Social Studies' }, workflow_state: 'standards_ready', primary_action_label: 'Import Grade 5 Social Studies standards', primary_action_url: '/sources/5/standards-imports', primary_action_method: 'post', is_standards_document: true, capability: { state: 'supported', document_family: 'Texas TEKS Multi-grade Standards' }, imports: [] },
            permissions: { manage: true, review: true, download: true }, reviewTransitions: [],
        }, global: { stubs } });
        expect(wrapper.text()).toContain('Grade 5 standards detected');
        expect(wrapper.text()).toContain('Only the Grade 5 Social Studies section will be imported.');
        expect(wrapper.text()).not.toContain('Extract reporting periods, units, dates');
        await wrapper.get('form').trigger('submit'); expect(state.post).toHaveBeenCalledWith('/sources/5/standards-imports');
    });

    it.each([
        ['format_setup_needed', 'unsupported', 'This document uses a format the system has not learned yet. Your PDF is saved and unchanged.'],
        ['ambiguous', 'ambiguous', 'matches more than one supported format'],
        ['capability_failed', 'failed', 'We could not read the text in this PDF. It may be scanned or image-based.'],
    ])('renders the %s capability state without an extraction action', (workflowState, capabilityState, message) => {
        const wrapper = mount(SourcesShow, { props: {
            source: { id: 4, title: '5th - ELAR', source_kind: 'upload', source_category: 'curriculum', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'not_requested', archived_at: null, school_year_id: 1, education_provider: { name: 'CFISD' }, school_year: { name: '2026-2027' }, grade_level: { name: 'Grade 5' }, files: [{ id: 12, version_number: 1, is_current: true, original_filename: '5th - ELAR.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'a'.repeat(64) }] },
            links: [], linkChoices: { subject: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] }, courseDefaults: {},
            calendarSetup: { is_calendar: false, linked_profiles: [], current_file_is_pdf: true, imports: [] },
            curriculumSetup: { is_curriculum: true, current_file_is_pdf: true, subject: { name: 'English Language Arts and Reading' }, workflow_state: workflowState, primary_action_label: null, primary_action_url: null, back_url: '/back', capability: { state: capabilityState, message, internal_diagnostic: null }, imports: [] },
            permissions: { manage: true, review: true, download: true }, reviewTransitions: [],
        }, global: { stubs } });
        expect(wrapper.text()).toContain(message);
        expect(wrapper.text()).not.toContain('Create curriculum outline');
        expect(wrapper.text()).not.toContain('parser stack trace');
    });

    it('checks unknown support explicitly and keeps technical diagnostics in advanced management', async () => {
        const wrapper = mount(SourcesShow, { props: {
            source: { id: 4, title: '5th - ELAR', source_kind: 'upload', source_category: 'curriculum', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'not_requested', archived_at: null, school_year_id: 1, education_provider: { name: 'CFISD' }, school_year: { name: '2026-2027' }, grade_level: { name: 'Grade 5' }, files: [{ id: 12, version_number: 1, is_current: true, original_filename: '5th - ELAR.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'a'.repeat(64) }] },
            links: [], linkChoices: { subject: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] }, courseDefaults: {},
            calendarSetup: { is_calendar: false, linked_profiles: [], current_file_is_pdf: true, imports: [] },
            curriculumSetup: { is_curriculum: true, current_file_is_pdf: true, subject: { name: 'English Language Arts and Reading' }, workflow_state: 'unknown', primary_action_label: 'Check outline support', primary_action_url: '/sources/4/curriculum-capability', back_url: '/back', capability: { state: 'unknown', message: 'Outline support has not been checked for this PDF.', internal_diagnostic: 'parser stack trace' }, imports: [] },
            permissions: { manage: true, review: true, download: true }, reviewTransitions: [],
        }, global: { stubs } });
        expect(wrapper.get('button.btn-primary').text()).toBe('Check outline support');
        expect(wrapper.findAll('details').find((details) => details.text().includes('Advanced source management'))?.attributes('open')).toBeUndefined();
        expect(wrapper.text()).toContain('Outline support diagnostics');
        await wrapper.get('form').trigger('submit');
        expect(state.post).toHaveBeenCalledWith('/sources/4/curriculum-capability');
    });

    it.each([
        ['processing', 'View import status'],
        ['review', 'Review curriculum outline'],
        ['approved', 'View curriculum outline'],
        ['failed', 'Review import issue'],
    ])('shows the %s source workflow action', (workflowState, actionLabel) => {
        const wrapper = mount(SourcesShow, { props: {
            source: { id: 4, title: '5th - Math', source_kind: 'upload', source_category: 'curriculum', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'completed', archived_at: null, school_year_id: 1, education_provider: { name: 'CFISD' }, school_year: { name: '2026-2027' }, grade_level: { name: 'Grade 5' }, files: [{ id: 12, version_number: 1, is_current: true, original_filename: '5th - Math.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'a'.repeat(64) }] },
            links: [], linkChoices: { subject: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] }, courseDefaults: {},
            calendarSetup: { is_calendar: false, linked_profiles: [], current_file_is_pdf: true, imports: [] },
            curriculumSetup: { is_curriculum: true, current_file_is_pdf: true, subject: { name: 'Mathematics' }, workflow_state: workflowState, primary_action_label: actionLabel, primary_action_url: '/next', primary_action_method: 'get', back_url: '/back', imports: [] },
            permissions: { manage: true, review: true, download: true }, reviewTransitions: [],
        }, global: { stubs } });
        expect(wrapper.get('a.btn-primary').text()).toBe(actionLabel);
        expect(wrapper.get('a.btn-primary').attributes('href')).toBe('/next');
    });

    it('renders summary, hierarchy, editable fields, evidence, warnings, one bulk save and dirty navigation protection', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mount(CurriculumImportShow, { props: props(), global: { stubs } });
        expect(wrapper.text()).toContain('One ambiguous block needs review.');
        expect(wrapper.text()).toContain('1st Nine Weeks');
        expect(wrapper.text()).toContain('Bridge to 5th Grade');
        expect(wrapper.text()).toContain('Low-confidence extraction');
        expect(wrapper.text()).toContain('View source PDF');
        expect(wrapper.text()).not.toContain('Re-extract outline');
        expect(wrapper.findAll('button').filter((button) => button.text().includes('Save review changes'))).toHaveLength(1);
        expect(wrapper.findAll('button').some((button) => /^Save$/.test(button.text()))).toBe(false);
        expect(wrapper.get('textarea[aria-label="Standards codes for Bridge to 5th Grade"]')).toBeTruthy();

        await wrapper.get('#name-2').setValue('Bridge wording reviewed');
        expect(wrapper.text()).toContain('Unsaved changes');
        expect(wrapper.get('button.btn-outline-primary').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('button.btn-success').attributes('disabled')).toBeDefined();
        expect(state.before?.()).toBe(false);
        expect(confirm).toHaveBeenCalledWith('Discard unsaved curriculum review changes?');
    });

    it('shows row errors without discarding edits and resets the saved baseline after successful bulk save', async () => {
        state.errors = { 'proposals.2.name': 'Enter a name.', review: 'Resolve the highlighted curriculum proposals.' };
        state.put.mockImplementation((_url: string, options: any) => options.onSuccess());
        const wrapper = mount(CurriculumImportShow, { props: props(), global: { stubs } });
        await wrapper.get('#name-2').setValue('Corrected after failed request');
        expect(wrapper.get('#name-2').element).toHaveProperty('value', 'Corrected after failed request');
        expect(wrapper.text()).toContain('Review could not be saved.');
        expect(wrapper.text()).toContain('Enter a name.');
        await wrapper.get('form').trigger('submit');
        expect(state.put).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).not.toContain('Unsaved changes');
        expect(wrapper.get('#name-2').element).toHaveProperty('value', 'Corrected after failed request');
    });

    it('renders approved imports as read-only with no save or approval controls', () => {
        const approved = props({ canManage: false, curriculumImport: { ...props().curriculumImport, status: 'approved', approved_at: '2026-08-03T12:00:00Z', approved_by: 'Owner' } });
        const wrapper = mount(CurriculumImportShow, { props: approved, global: { stubs } });
        expect(wrapper.text()).toContain('Approved imports are read-only');
        expect(wrapper.find('#name-2').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('Save review changes');
        expect(wrapper.text()).not.toContain('Approve curriculum outline');
        expect(wrapper.text()).not.toContain('Re-extract outline');
    });

    it('requires confirmation before re-extracting the unapproved outline from the same source', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mount(CurriculumImportShow, { props: props({ canReextract: true, curriculumImport: { ...props().curriculumImport, parser_key: 'cfisd-grade5-elar-yag-parent' } }), global: { stubs } });
        await wrapper.findAll('button').find((button) => button.text() === 'Re-extract outline')!.trigger('click');
        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('same PDF'));
        expect(state.post).toHaveBeenCalledWith('academic.curriculum-imports.reextract:9', expect.objectContaining({ preserveScroll: true }));
    });

    it('reviews ELAR components hierarchically with progressive disclosure and shared dirty state', async () => {
        const reading = component({ children: [component({ id: 12, parent_proposal_id: 11, name: 'HMH Module 1: Inventors at Work', component_type: 'module' }), component({ id: 13, parent_proposal_id: 11, sequence: 2, name: 'Central Ideas', component_type: 'skill' }), component({ id: 16, parent_proposal_id: 11, sequence: 3, name: 'Text Evidence', component_type: 'skill' })] });
        const writing = component({ id: 14, name: 'Writing', sequence: 2, children: [component({ id: 15, parent_proposal_id: 14, name: 'Writing Process', component_type: 'skill' })] });
        const elar = props({
            curriculumImport: { ...props().curriculumImport, parser_key: 'cfisd-grade5-elar-yag-parent', parser_version: 'cfisd-grade5-elar-yag-parent-v3' },
            context: { ...props().context, subject: 'English Language Arts and Reading', course: 'Grade 5 English Language Arts and Reading' },
            periods: [{ ...proposal({ id: 1, parent_proposal_id: null, proposal_type: 'period', name: '1st Grading Period', sequence: 1, unit_type: null, standard_codes: [], warnings: [] }), children: [proposal({ id: 2, name: 'Unit 1', summary: 'Reading: HMH Module 1: Inventors at Work · Writing: HMH Module 3: Argument', warnings: [], children: [reading, writing] })] }],
        });
        const wrapper = mount(CurriculumImportShow, { props: elar, global: { stubs } });
        const componentSection = wrapper.get('details.curriculum-components');
        expect(componentSection.attributes('open')).toBeUndefined();
        expect(wrapper.get('#summary-2').element).toHaveProperty('value', 'Reading: HMH Module 1: Inventors at Work · Writing: HMH Module 3: Argument');
        expect(componentSection.text()).toContain('2 sections · Reading, Writing');
        expect(componentSection.text()).toContain('HMH Module 1: Inventors at Work');
        expect(componentSection.text()).toContain('Central Ideas');
        expect(componentSection.text()).toContain('Text Evidence');
        expect(componentSection.text()).toContain('Writing Process');
        expect(componentSection.text()).not.toContain('Central Idea; Theme');
        expect(wrapper.findAll('button').filter((button) => button.text().includes('Save review changes'))).toHaveLength(1);
        await wrapper.get('#description-13').setValue('Central Idea; Theme; reviewed');
        expect(wrapper.text()).toContain('Unsaved changes');
        expect(wrapper.get('button.btn-success').attributes('disabled')).toBeDefined();
        expect(wrapper.get('#description-13').element).toHaveProperty('value', 'Central Idea; Theme; reviewed');
        expect(wrapper.get('details.curriculum-component details').attributes('open')).toBeUndefined();
        await wrapper.get('#summary-2').setValue('Reviewer-edited summary');
        expect(wrapper.text()).toContain('Unsaved changes');
    });

    it('keeps component edits after field-specific save errors and renders approved components read-only', async () => {
        state.errors = { 'proposals.13.description': 'Review the extracted component content.', review: 'Resolve the highlighted curriculum proposals.' };
        const nested = component({ children: [component({ id: 13, parent_proposal_id: 11, name: 'Reading skills', component_type: 'skill', description: 'Central Idea' })] });
        const elar = props({ periods: [{ ...proposal({ id: 1, parent_proposal_id: null, proposal_type: 'period', name: '1st Grading Period', sequence: 1, warnings: [] }), children: [proposal({ id: 2, name: 'Unit 1', warnings: [], children: [nested] })] }] });
        const wrapper = mount(CurriculumImportShow, { props: elar, global: { stubs } });
        await wrapper.get('#description-13').setValue('Reviewed but unsaved');
        expect(wrapper.text()).toContain('Review the extracted component content.');
        expect(wrapper.get('#description-13').element).toHaveProperty('value', 'Reviewed but unsaved');

        const approved = mount(CurriculumImportShow, { props: { ...elar, canManage: false, curriculumImport: { ...elar.curriculumImport, status: 'approved' } }, global: { stubs } });
        expect(approved.get('#description-13').attributes('disabled')).toBeDefined();
        expect(approved.text()).toContain('Approved imports are read-only');
    });

    it('renders the approved component hierarchy with detailed skills progressively disclosed', () => {
        const wrapper = mount(CurriculumUnitComponentTree, { props: { component: {
            id: 1, name: 'Reading', component_type: 'strand', description: null,
            descendants: [
                { id: 2, name: 'HMH Module 1: Inventors at Work', component_type: 'module', description: null, descendants: [] },
                { id: 3, name: 'Central Ideas', component_type: 'skill', description: null, descendants: [] },
                { id: 4, name: 'Text Evidence', component_type: 'skill', description: null, descendants: [] },
            ],
        } } });
        expect(wrapper.get('details').attributes('open')).toBeUndefined();
        expect(wrapper.text()).toContain('Reading');
        expect(wrapper.text()).toContain('HMH Module 1: Inventors at Work');
        expect(wrapper.text()).toContain('Central Ideas');
        expect(wrapper.text()).toContain('Text Evidence');
        expect(wrapper.text()).not.toContain('Central Ideas, Text Evidence');
    });
});
