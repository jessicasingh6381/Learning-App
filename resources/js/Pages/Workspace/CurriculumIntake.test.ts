import { config, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import CurriculumIntake from './CurriculumIntake.vue';
import LearningPlan from './LearningPlan.vue';

const state = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
    processing: false,
    post: vi.fn(),
    get: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: state.get },
    usePage: () => ({ props: { auth: { permissions: ['workspace.view', 'advanced-academic.view'] }, tenant: { name: 'Cosmic Quest Academy' }, flash: {} } }),
    useForm: (values: Record<string, unknown>) => reactive({
        ...values,
        errors: state.errors,
        processing: state.processing,
        post: state.post,
    }),
}));

const routeMock = Object.assign((name?: string) => name ? `/${name}` : ({ current: () => false }), { current: () => false });
vi.stubGlobal('route', routeMock);
config.global.mocks = { route: routeMock };

const contexts = [{ student_id: 1, student_name: 'Kai Singh', school_year_id: 1, school_year_name: '2026-2027', school_year_status: 'active', grade_level_id: 7, grade_name: 'Grade 5', enrollment_status: 'active' }];
const providers = [{ id: 1, name: 'Cypress-Fairbanks Independent School District', short_name: 'CFISD', provider_type: 'district' }];
const subject = (overrides: Record<string, unknown> = {}) => ({ id: 2, name: 'Mathematics', code: 'MATH', status: 'not_started', status_label: 'Not started', source_count: 0, sources: [], ...overrides });
const subjects = [
    { id: 1, name: 'English Language Arts and Reading', code: 'ELAR', status: 'not_started', status_label: 'Not started', source_count: 0, sources: [] },
    subject(),
    { id: 3, name: 'Science', code: 'SCI', status: 'reviewed', status_label: 'Reviewed', source_count: 1, sources: [] },
];
const permissions = { create: true, review: true, manage: true, create_draft: true, advanced: true };
const layoutStub = { template: '<main><slot /></main>' };

const mountIntake = (overrides: Record<string, unknown> = {}) => mount(CurriculumIntake, {
    props: { contexts, selectedContext: contexts[0], selectedSubjectId: 2, providers, subjects, permissions, maxUploadMegabytes: 25, ...overrides },
    global: { stubs: { AuthenticatedLayout: layoutStub } },
});

describe('Curriculum Intake', () => {
    beforeEach(() => {
        state.errors = {};
        state.processing = false;
        state.post.mockReset();
        state.get.mockReset();
    });

    it('renders the default Kai, school-year, grade, provider, and data-driven subjects', () => {
        const wrapper = mountIntake();
        expect(wrapper.text()).toContain('Curriculum Intake');
        expect(wrapper.text()).toContain('Kai Singh');
        expect(wrapper.text()).toContain('2026-2027');
        expect(wrapper.text()).toContain('Grade 5');
        expect(wrapper.text()).toContain('CFISD');
        expect(wrapper.text()).toContain('Curriculum publisher');
        expect(wrapper.text()).toContain('Mathematics');
        expect(wrapper.text()).toContain('Science');
        expect(wrapper.findAll('fieldset')).toHaveLength(4);
    });

    it('switches accessibly between upload, URL, and manual fields', async () => {
        const wrapper = mountIntake();
        expect(wrapper.find('#intake-file').exists()).toBe(true);
        expect(wrapper.find('#intake-url').exists()).toBe(false);

        await wrapper.find('#kind-url').setValue(true);
        expect(wrapper.find('#intake-file').exists()).toBe(false);
        expect(wrapper.find('#intake-url').exists()).toBe(true);

        await wrapper.find('#kind-manual').setValue(true);
        expect(wrapper.find('#intake-url').exists()).toBe(false);
        expect(wrapper.find('#intake-manual').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Source format"]').exists()).toBe(true);
    });

    it('shows the review summary and submits only once while processing', async () => {
        const wrapper = mountIntake();
        expect(wrapper.text()).toContain('Review and save');
        expect(wrapper.text()).toContain('Uploaded PDF');
        expect(wrapper.text()).toContain('Mathematics');

        await wrapper.find('form').trigger('submit');
        expect(state.post).toHaveBeenCalledTimes(1);

        state.processing = true;
        const processing = mountIntake();
        expect(processing.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(processing.text()).toContain('Saving');
    });

    it('renders validation errors with alert and field associations', () => {
        state.errors = { subject_id: 'Choose a subject.', source_file: 'Upload a valid PDF.' };
        const wrapper = mountIntake();
        expect(wrapper.find('[role="alert"]').text()).toContain('highlighted fields');
        expect(wrapper.text()).toContain('Choose a subject.');
        expect(wrapper.find('#intake-file').attributes('aria-invalid')).toBe('true');
    });

    it('shows not-started, source, review, draft, PDF, and download actions truthfully', () => {
        const sourceStates = [
            subject(),
            subject({ id: 3, name: 'Science', status: 'source_added', status_label: 'Source added', source_count: 1, sources: [{ id: 9, title: 'Science PDF', source_kind: 'upload', review_status: 'unreviewed', current_file: { id: 4, original_filename: 'science.pdf', is_pdf: true }, external_url: null, draft: null, can_review: true, can_manage: true, can_download: true }] }),
            subject({ id: 4, name: 'Social Studies', status: 'reviewed', status_label: 'Reviewed', source_count: 1, sources: [{ id: 10, title: 'Social Studies', source_kind: 'manual', review_status: 'reviewed', current_file: null, external_url: null, draft: null, can_review: true, can_manage: true, can_download: true }] }),
            subject({ id: 5, name: 'Art', status: 'draft_started', status_label: 'Draft curriculum started', source_count: 1, sources: [{ id: 11, title: 'Art', source_kind: 'url', review_status: 'reviewed', current_file: null, external_url: 'https://example.edu/art', draft: { id: 7, name: 'Art', status: 'draft' }, can_review: true, can_manage: true, can_download: true }] }),
        ];
        const wrapper = mountIntake({ subjects: sourceStates });
        expect(wrapper.text()).toContain('Not started');
        expect(wrapper.text()).toContain('Source added');
        expect(wrapper.text()).toContain('Reviewed');
        expect(wrapper.text()).toContain('Draft curriculum started');
        expect(wrapper.text()).toContain('View PDF');
        expect(wrapper.text()).toContain('Download');
        expect(wrapper.text()).toContain('Start review');
        expect(wrapper.text()).toContain('Create draft curriculum outline');
        expect(wrapper.text()).toContain('Open draft curriculum');
    });

    it('integrates subject status into Learning Plan with advanced settings secondary', () => {
        const wrapper = mount(LearningPlan, {
            props: {
                schoolYear: { id: 1, name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27' },
                students: [{ id: 1, name: 'Kai Singh' }],
                selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5' } },
                learningPlan: { provider: 'CFISD', calendar: 'Calendar', standards: 'TEKS', curriculum: null, courses: [] },
                curriculumBySubject: [subject(), subject({ status: 'source_added', status_label: 'Source added', source_count: 1 })],
                curriculumIntakeAvailable: true,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub } },
        });
        expect(wrapper.text()).toContain('Curriculum by subject');
        expect(wrapper.text()).toContain('Not started');
        expect(wrapper.text()).toContain('Source added');
        expect(wrapper.text()).toContain('Curriculum Intake');
        expect(wrapper.text()).toContain('Advanced curriculum settings');
    });

    it('keeps intake out of student navigation and uses responsive stacking classes', () => {
        const studentLayout = mount(StudentPortalLayout);
        expect(studentLayout.text()).not.toContain('Curriculum Intake');
        expect(studentLayout.text()).toContain('My Learning');

        const intake = mountIntake();
        expect(intake.find('.col-sm-6.col-lg-4').exists()).toBe(true);
        expect(intake.find('#curriculum-document[tabindex="-1"]').exists()).toBe(true);
        expect(intake.find('legend').text()).toContain('Choose student and school year');
    });
});
