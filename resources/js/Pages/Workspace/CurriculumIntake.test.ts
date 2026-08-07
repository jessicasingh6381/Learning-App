import { config, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StudentPortalLayout from '@/Layouts/StudentPortalLayout.vue';
import CurriculumIntake from './CurriculumIntake.vue';
import LearningPlan from './LearningPlan.vue';

const state = vi.hoisted(() => ({ errors: {} as Record<string, string>, processing: false, post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { permissions: ['workspace.view', 'advanced-academic.view'] }, tenant: { name: 'Cosmic Quest Academy' }, flash: {} } }),
    useForm: (values: Record<string, unknown>) => reactive({ ...values, errors: state.errors, processing: state.processing, post: state.post }),
}));

const routeMock = Object.assign((name?: string, params?: Record<string, unknown>) => name ? `/${name}${params ? `?${new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined) as [string, string][]).toString()}` : ''}` : ({ current: () => false }), { current: () => false });
vi.stubGlobal('route', routeMock);
config.global.mocks = { route: routeMock };

const context = { enrollment_id: 22, student_id: 1, student_name: 'Kai Singh', school_year_id: 18, school_year_name: '2026-2027', school_year_status: 'active', grade_level_id: 7, grade_name: 'Grade 5', enrollment_status: 'active' };
const provider = { id: 1, name: 'Cypress-Fairbanks Independent School District', short_name: 'CFISD', provider_type: 'district' };
const subject = (overrides: Record<string, unknown> = {}) => ({ id: 2, name: 'Mathematics', code: 'MATH', status: 'not_started', workflow_state: 'not_started', status_label: 'Not started', primary_action_label: 'Add curriculum source', primary_action_url: '/learning-plan/curriculum-intake/students/1/school-years/18/subjects/2/add', curriculum_import_id: null, period_count: 0, unit_count: 0, assessment_count: 0, standard_alignment_count: 0, source_count: 0, sources: [], ...overrides });
const subjects = [
    { id: 1, name: 'English Language Arts and Reading', code: 'ELAR', status: 'not_started', status_label: 'Not started', source_count: 0, sources: [] },
    subject(),
    { id: 3, name: 'Science', code: 'SCI', status: 'reviewed', status_label: 'Reviewed', source_count: 1, sources: [] },
];
const permissions = { create: true, review: true, manage: true, create_draft: true, advanced: true, manage_visibility: true };
const layoutStub = { template: '<main><slot /></main>' };

const mountOverview = (overrides: Record<string, unknown> = {}) => mount(CurriculumIntake, {
    props: { entryMode: 'overview', contexts: [context], selectedContext: context, selectedSubject: null, contextProvider: null, providers: [provider], subjects, hiddenSubjects: [], hiddenSubjectCount: 0, permissions, backUrl: '/learning-plan', maxUploadMegabytes: 25, ...overrides },
    global: { stubs: { AuthenticatedLayout: layoutStub } },
});
const mountAdd = (overrides: Record<string, unknown> = {}) => mount(CurriculumIntake, {
    props: { entryMode: 'add', contexts: [], selectedContext: context, selectedSubject: subject(), contextProvider: provider, providers: [], subjects: [], hiddenSubjects: [], hiddenSubjectCount: 0, permissions, backUrl: '/learning-plan?student_id=1', returnTo: 'learning-plan', maxUploadMegabytes: 25, ...overrides },
    global: { stubs: { AuthenticatedLayout: layoutStub } },
});
const mountLearning = (overrides: Record<string, unknown> = {}) => mount(LearningPlan, {
    props: {
        schoolYear: { id: 18, name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27' },
        students: [{ id: 1, name: 'Kai Singh' }],
        selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { id: 22, grade: 'Grade 5' } },
        learningPlan: { provider: 'CFISD', calendar: 'Calendar', standards: 'TEKS', curriculum: null, courses: [] },
        curriculumBySubject: subjects, hiddenCurriculumSubjects: [], hiddenCurriculumSubjectCount: 0,
        curriculumIntakeAvailable: true, curriculumVisibilityManageable: true, ...overrides,
    },
    global: { stubs: { AuthenticatedLayout: layoutStub } },
});

describe('Curriculum Intake', () => {
    beforeEach(() => { state.errors = {}; state.processing = false; state.post.mockReset(); });

    it('keeps general entry on the subject overview and links each action directly to add mode', () => {
        const wrapper = mountOverview();
        expect(wrapper.text()).toContain('Curriculum Intake');
        expect(wrapper.text()).toContain('Grade 5 Curriculum');
        expect(wrapper.text()).toContain('Mathematics');
        expect(wrapper.text()).toContain('Science');
        expect(wrapper.find('#curriculum-source-form').exists()).toBe(false);
        const add = wrapper.findAll('a').find((link) => link.text() === 'Add curriculum source');
        expect(add?.attributes('href')).toBe('/learning-plan/curriculum-intake/students/1/school-years/18/subjects/2/add');
    });

    it('renders compact resolved context without student, year, provider, or subject selectors', () => {
        const wrapper = mountAdd();
        expect(wrapper.text()).toContain('Add Mathematics Curriculum Source');
        expect(wrapper.text()).toContain('Curriculum context');
        expect(wrapper.text()).toContain('Grade 5 · 2026-2027');
        expect(wrapper.text()).toContain('Cypress-Fairbanks Independent School District');
        expect(wrapper.text()).toContain('Used by Kai Singh’s active learning plan');
        expect(wrapper.find('#intake-student').exists()).toBe(false);
        expect(wrapper.find('#intake-year').exists()).toBe(false);
        expect(wrapper.find('#intake-provider').exists()).toBe(false);
        expect(wrapper.find('input[name="source_origin"]').exists()).toBe(false);
        expect(wrapper.find('input[name="subject_id"]').exists()).toBe(false);
        expect(wrapper.find('#subject-overview-heading').exists()).toBe(false);
    });

    it('switches accessibly between upload, URL, and manual source fields', async () => {
        const wrapper = mountAdd();
        expect(wrapper.find('#intake-file').exists()).toBe(true);
        await wrapper.find('#kind-url').setValue(true);
        expect(wrapper.find('#intake-url').exists()).toBe(true);
        expect(wrapper.find('#intake-file').exists()).toBe(false);
        await wrapper.find('#kind-manual').setValue(true);
        expect(wrapper.find('#intake-manual').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Source format"]').exists()).toBe(true);
    });

    it('preserves entered form state on validation feedback and prevents duplicate submission while processing', async () => {
        state.errors = { title: 'Enter a curriculum name.', source_file: 'Upload a valid PDF.' };
        const wrapper = mountAdd();
        await wrapper.find('#intake-title').setValue('Kai Math');
        expect(wrapper.find('[role="alert"]').text()).toContain('highlighted fields');
        expect((wrapper.find('#intake-title').element as HTMLInputElement).value).toBe('Kai Math');
        expect(wrapper.find('#intake-file').attributes('aria-invalid')).toBe('true');
        await wrapper.find('form').trigger('submit');
        expect(state.post).toHaveBeenCalledTimes(1);

        state.processing = true;
        const processing = mountAdd();
        expect(processing.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(processing.text()).toContain('Saving');
    });

    it('preserves overview source review, PDF, download, and draft-outline actions', () => {
        const sourceStates = [
            subject({ id: 3, name: 'Science', status: 'source_added', status_label: 'Source added', source_count: 1, sources: [{ id: 9, title: 'Science PDF', review_status: 'unreviewed', current_file: { id: 4, is_pdf: true }, external_url: null, draft: null, can_review: true, can_manage: true, can_download: true }] }),
            subject({ id: 4, name: 'Social Studies', status: 'reviewed', status_label: 'Reviewed', source_count: 1, sources: [{ id: 10, title: 'Social Studies', review_status: 'reviewed', current_file: null, external_url: null, draft: null, can_review: true, can_manage: true, can_download: true }] }),
            subject({ id: 5, name: 'Art', status: 'draft_started', status_label: 'Draft curriculum started', source_count: 1, sources: [{ id: 11, title: 'Art', review_status: 'reviewed', current_file: null, external_url: 'https://example.edu/art', draft: { id: 7 }, can_review: true, can_manage: true, can_download: true }] }),
        ];
        const wrapper = mountOverview({ subjects: sourceStates });
        expect(wrapper.text()).toContain('View PDF');
        expect(wrapper.text()).toContain('Download');
        expect(wrapper.text()).toContain('Start review');
        expect(wrapper.text()).toContain('Create empty curriculum package');
        expect(wrapper.text()).toContain('Open draft curriculum');
    });

    it('routes subject-specific Learning Plan actions directly to add mode', () => {
        const wrapper = mount(LearningPlan, {
            props: {
                schoolYear: { id: 18, name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27' },
                students: [{ id: 1, name: 'Kai Singh' }],
                selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5' } },
                learningPlan: { provider: 'CFISD', calendar: 'Calendar', standards: 'TEKS', curriculum: null, courses: [] },
                curriculumBySubject: [subject()], curriculumIntakeAvailable: true,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub } },
        });
        const subjectLink = wrapper.findAll('a').find((link) => link.text() === 'Add curriculum source');
        expect(subjectLink?.attributes('href')).toBe('/learning-plan/curriculum-intake/students/1/school-years/18/subjects/2/add');
        expect(wrapper.text()).toContain('Curriculum Intake');
        expect(wrapper.text()).toContain('Advanced curriculum settings');
    });

    it('uses server-resolved review, approval, and source-ready workflow actions', () => {
        const review = subject({ status: 'outline_review', workflow_state: 'outline_review', status_label: 'Curriculum outline ready for review', primary_action_label: 'Review curriculum outline', primary_action_url: '/academic-setup/curriculum-imports/44', curriculum_import_id: 44, period_count: 4, unit_count: 26, assessment_count: 5, source_count: 1 });
        const approved = subject({ id: 3, name: 'Science', status: 'outline_approved', workflow_state: 'outline_approved', status_label: 'Curriculum outline approved', primary_action_label: 'View curriculum outline', primary_action_url: '/academic-setup/curriculum/8', curriculum_import_id: 45, period_count: 4, unit_count: 20 });
        const reviewed = subject({ id: 4, name: 'Art', status: 'source_reviewed', workflow_state: 'source_reviewed', status_label: 'Source reviewed', primary_action_label: 'Create curriculum outline', primary_action_url: '/academic-setup/sources/12', source_count: 1 });
        const learning = mount(LearningPlan, {
            props: { schoolYear: { id: 18, name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27' }, students: [{ id: 1, name: 'Kai Singh' }], selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5' } }, learningPlan: { provider: 'CFISD', calendar: 'Calendar', standards: 'TEKS', curriculum: null, courses: [] }, curriculumBySubject: [review, approved, reviewed], curriculumIntakeAvailable: true },
            global: { stubs: { AuthenticatedLayout: layoutStub } },
        });
        expect(learning.text()).toContain('Curriculum outline ready for review');
        expect(learning.text()).not.toContain('Draft curriculum started');
        expect(learning.findAll('a').some((link) => link.text() === 'Review curriculum')).toBe(false);
        expect(learning.findAll('a').find((link) => link.text() === 'Review curriculum outline')?.attributes('href')).toBe('/academic-setup/curriculum-imports/44');
        expect(learning.findAll('a').find((link) => link.text() === 'View curriculum outline')?.attributes('href')).toBe('/academic-setup/curriculum/8');
        expect(learning.findAll('a').find((link) => link.text() === 'Create curriculum outline')?.attributes('href')).toBe('/academic-setup/sources/12');

        const overview = mountOverview({ subjects: [review] });
        expect(overview.findAll('a').find((link) => link.text() === 'Review curriculum outline')?.attributes('href')).toBe('/academic-setup/curriculum-imports/44');
        expect(overview.text()).toContain('Add another source');
    });

    it('shows honest support states in Curriculum Intake and Learning Plan', () => {
        const unknown = subject({ workflow_state: 'outline_support_unknown', status_label: 'Outline support not checked', primary_action_label: 'Check outline support', primary_action_url: '/academic-setup/sources/12', source_count: 1 });
        const unsupported = subject({ id: 3, name: 'English Language Arts and Reading', code: 'ELAR', workflow_state: 'format_setup_needed', status_label: 'Curriculum outline setup needed', primary_action_label: 'Set up document format', primary_action_url: '/academic-setup/sources/13/curriculum-format-setup', source_count: 1 });
        const overview = mountOverview({ subjects: [unknown, unsupported] });
        expect(overview.text()).toContain('Outline support not checked');
        expect(overview.text()).toContain('Curriculum outline setup needed');
        expect(overview.findAll('a').find((link) => link.text() === 'Check outline support')?.attributes('href')).toBe('/academic-setup/sources/12');
        expect(overview.findAll('a').find((link) => link.text() === 'Set up document format')?.attributes('href')).toBe('/academic-setup/sources/13/curriculum-format-setup');
        expect(overview.text()).not.toContain('Create curriculum outline');

        const learning = mount(LearningPlan, {
            props: { schoolYear: { id: 18, name: '2026-2027' }, students: [{ id: 1, name: 'Kai Singh' }], selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5' } }, learningPlan: { provider: 'CFISD', curriculum: null, courses: [] }, curriculumBySubject: [unknown, unsupported], curriculumIntakeAvailable: true },
            global: { stubs: { AuthenticatedLayout: layoutStub } },
        });
        expect(learning.text()).toContain('Outline support not checked');
        expect(learning.text()).toContain('Curriculum outline setup needed');
        expect(learning.findAll('a').find((link) => link.text() === 'Check outline support')?.attributes('href')).toBe('/academic-setup/sources/12');
        expect(learning.findAll('a').find((link) => link.text() === 'Set up document format')?.attributes('href')).toBe('/academic-setup/sources/13/curriculum-format-setup');
        expect(learning.text()).not.toContain('Create curriculum outline');
    });

    it('uses backend-provided standards actions without treating standards as a pacing outline', () => {
        const standards = subject({ id: 4, name: 'Social Studies', code: 'SS', workflow_state: 'standards_ready', status_label: 'Grade 5 standards detected', primary_action_label: 'Import Grade 5 Social Studies standards', primary_action_url: '/academic-setup/sources/15', source_count: 1 });
        const overview = mountOverview({ subjects: [standards] });
        expect(overview.text()).toContain('Grade 5 standards detected');
        expect(overview.findAll('a').find((link) => link.text() === 'Import Grade 5 Social Studies standards')?.attributes('href')).toBe('/academic-setup/sources/15');
        expect(overview.text()).not.toContain('Create curriculum outline');

        const imported = subject({ ...standards, workflow_state: 'standards_imported', status_label: 'Standards imported · Pacing guide still needed', primary_action_label: 'View imported standards', primary_action_url: '/academic-setup/standards-imports/9' });
        const learning = mount(LearningPlan, { props: { schoolYear: { id: 18, name: '2026-2027' }, students: [{ id: 1, name: 'Kai Singh' }], selectedStudent: { id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5' } }, learningPlan: { provider: 'CFISD', curriculum: null, courses: [] }, curriculumBySubject: [imported], curriculumIntakeAvailable: true }, global: { stubs: { AuthenticatedLayout: layoutStub } } });
        expect(learning.text()).toContain('Standards imported · Pacing guide still needed');
        expect(learning.findAll('a').find((link) => link.text() === 'View imported standards')?.attributes('href')).toBe('/academic-setup/standards-imports/9');
    });

    it('uses the correct cancel destination for Learning Plan and overview entry', () => {
        expect(mountAdd().findAll('a').find((link) => link.text() === 'Cancel')?.attributes('href')).toBe('/learning-plan?student_id=1');
        expect(mountAdd({ backUrl: '/workspace.curriculum-intake', returnTo: 'overview' }).findAll('a').find((link) => link.text() === 'Cancel')?.attributes('href')).toBe('/workspace.curriculum-intake');
    });

    it('keeps curriculum intake out of student navigation', () => {
        const studentLayout = mount(StudentPortalLayout);
        expect(studentLayout.text()).not.toContain('Curriculum Intake');
        expect(studentLayout.text()).toContain('My Learning');
    });

    it('hides and restores subjects with compact accessible controls on both curriculum pages', async () => {
        const approvedMath = subject({ workflow_state: 'outline_approved', status_label: 'Curriculum outline approved', primary_action_label: 'View curriculum outline', primary_action_url: '/academic-setup/curriculum/8', curriculum_import_id: 45, source_count: 1 });
        const art = subject({ id: 4, name: 'Art', code: 'ART', status_label: 'Source reviewed', source_count: 1 });
        const visible = [subjects[0], approvedMath, subjects[2]];
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const learning = mountLearning({ curriculumBySubject: visible, hiddenCurriculumSubjects: [art], hiddenCurriculumSubjectCount: 1 });
        expect(learning.find('summary').text()).toBe('Hidden subjects (1)');
        expect(learning.find('summary').element.tagName).toBe('SUMMARY');
        expect(learning.findAll('.friendly-panel h3').map((item) => item.text())).toEqual(['English Language Arts and Reading', 'Mathematics', 'Science']);
        expect(learning.findAll('.friendly-panel h3').map((item) => item.text())).not.toContain('Art');
        expect(learning.text()).toContain('Art'); expect(learning.text()).toContain('Show subject');
        expect(learning.findAll('a').find((link) => link.text() === 'Show subject')?.attributes('href')).toBe('/workspace.learning-plan.subjects.show?enrollment=22&subject=4');
        expect(learning.findAll('a').find((link) => link.text() === 'View curriculum outline')?.attributes('href')).toBe('/academic-setup/curriculum/8');
        const mathHide = learning.findAll('a').find((link) => link.text() === 'Hide subject' && link.element.closest('.friendly-panel')?.textContent?.includes('Mathematics'));
        await mathHide?.trigger('click'); expect(confirm).toHaveBeenCalledWith('Hide Mathematics from this learning plan? Existing curriculum and history will be kept.');
        expect(mathHide?.element.closest('.col-sm-6')?.classList.contains('col-lg-4')).toBe(true);

        const intake = mountOverview({ subjects: visible, hiddenSubjects: [art], hiddenSubjectCount: 1 });
        expect(intake.find('summary').text()).toBe('Hidden subjects (1)');
        expect(intake.findAll('article h3').map((item) => item.text())).toEqual(['English Language Arts and Reading', 'Mathematics', 'Science']);
        expect(intake.findAll('article h3').map((item) => item.text())).not.toContain('Art');
        expect(intake.text()).toContain('Show subject'); expect(intake.text()).toContain('Hide subject');

        const restored = mountLearning({ curriculumBySubject: [subjects[0], approvedMath, subjects[2], art], hiddenCurriculumSubjects: [], hiddenCurriculumSubjectCount: 0 });
        expect(restored.findAll('.friendly-panel h3').map((item) => item.text())).toEqual(['English Language Arts and Reading', 'Mathematics', 'Science', 'Art']);
        expect(restored.text()).toContain('Curriculum outline approved');
        expect(restored.text()).not.toContain('Hidden subjects (');
    });
});
