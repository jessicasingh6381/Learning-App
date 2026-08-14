import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import LearningPlan from '../Workspace/LearningPlan.vue';
import LessonPlanShow from './Show.vue';
import LessonShow from '../Lessons/Show.vue';

const routeMock = (name: string) => `/${name}`;
vi.stubGlobal('route', routeMock);
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { permissions: ['workspace.view'] } } }),
}));

const global = {
    mocks: { route: routeMock },
    stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } },
};

const learningPlanProps = (lessonPlan: any = null) => ({
    schoolYear: { id: 1, name: 'Current Year', start_date: '2026-08-01', end_date: '2027-05-31' },
    students: [{ id: 1, name: 'Student Example', enrollment: { id: 10, grade: 'Grade 5' } }],
    selectedStudent: { id: 1, name: 'Student Example', enrollment: { id: 10, grade: 'Grade 5' } },
    learningPlan: { provider: null, calendar: null, standards: null, curriculum_status_label: '1 of 1 subjects ready', curriculum_status_detail: 'All active subjects approved', courses: [] },
    curriculumBySubject: [{
        id: 2, name: 'Example Subject', status_label: 'Curriculum outline approved', workflow_state: 'outline_approved',
        source_count: 1, curriculum_import_id: 3, period_count: 0, unit_count: 4, assessment_count: 0,
        lesson_plan: lessonPlan, lesson_plan_create_url: '/create-plan', primary_action_url: '/curriculum',
        primary_action_label: 'View curriculum outline', secondary_action_url: null,
    }],
    curriculumIntakeAvailable: true,
    curriculumVisibilityManageable: true,
    lessonPlanManageable: true,
});

describe('Lesson Plan foundation UI', () => {
    it('keeps curriculum readiness and shows the calculated not-created lesson state', () => {
        const wrapper = mount(LearningPlan, { props: learningPlanProps(), global });
        expect(wrapper.text()).toContain('Curriculum: Ready');
        expect(wrapper.text()).toContain('Lessons: Not created yet');
        expect(wrapper.text()).toContain('Create lesson plan');
        expect(wrapper.text()).toContain('View curriculum outline');
    });

    it('shows a persisted lesson-plan state and count on the subject card', () => {
        const wrapper = mount(LearningPlan, {
            props: learningPlanProps({ id: 8, status: 'draft', lesson_count: 42, url: '/lesson-plan' }), global,
        });
        expect(wrapper.text()).toContain('Lesson Plan: draft');
        expect(wrapper.text()).toContain('42 lessons');
        expect(wrapper.text()).toContain('Review lessons');
    });

    it('renders the teacher review sequence and clean individual lesson provenance', () => {
        const plan = mount(LessonPlanShow, { props: {
            canManage: true,
            generatorConfigured: true,
            lessonPlan: {
                id: 8, subject: 'Example Subject', student: 'Student Example', school_year: 'Current Year', course: 'Example Course',
                status: 'draft', revision: 1, lesson_count: 1,
                review: { eligible: false, blocker: 'All included lessons must be reviewed before the lesson plan can be marked reviewed.' },
                curriculum: { source: 'Approved Source', file: 'source.pdf', package: 'Example Package' },
                units: [{ id: 3, sequence: 1, name: 'Unit One', lesson_count: 1, lesson_status: 'generated', generate_url: '/generate' }],
                lessons: [{ id: 9, sequence: 1, title: 'First Lesson', status: 'draft', lesson_mode: 'full', estimated_minutes: 90, estimated_preparation_minutes: 15, suggested_sessions: 2, curriculum_unit: 'Unit One', url: '/lesson' }],
            },
        }, global });
        expect(plan.text()).toContain('Example Subject Lesson Plan');
        expect(plan.text()).toContain('Unit One');
        expect(plan.text()).toContain('First Lesson');
        expect(plan.text()).toContain('Student time: 90 min');
        expect(plan.text()).toContain('2 sessions');
        expect(plan.text()).toContain('Parent prep: 15 min');
        expect(plan.text()).toContain('All included lessons must be reviewed');
        expect(plan.get<HTMLButtonElement>('button[disabled]').element.disabled).toBe(true);

        const lesson = mount(LessonShow, { props: {
            lessonPlan: { id: 8, subject: 'Example Subject' },
            lesson: {
                sequence: 1, title: 'First Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'draft', estimated_minutes: 90, estimated_preparation_minutes: 15, suggested_sessions: 2,
                learning_objective: 'Understand the idea.', completion_criteria: 'Explain it clearly.',
                components: [{ type: 'objective', role: 'objective', name: 'Core Objective', description: 'Source objective.' }],
                standards: [{ code: 'STD.1', statement: 'Example standard.' }],
                sections: [{ id: 1, type: 'external_resources', title: 'External resources', content: 'A labeled map showing all required features.', audience: 'teacher', children: [] }, { id: 2, type: 'instruction', title: 'Learn', content: 'Read and discuss.', audience: 'shared', children: [] }],
                provenance: { unit: 'Unit One', source: 'Approved Source', file: 'source.pdf', source_page: 2 },
            },
        }, global });
        expect(lesson.text()).toContain('Why this lesson exists');
        expect(lesson.text()).toContain('Core Objective');
        expect(lesson.text()).toContain('STD.1');
        expect(lesson.text()).toContain('Approved Source');
        expect(lesson.text()).toContain('shared');
        expect(lesson.text()).toContain('Student instructional time');
        expect(lesson.text()).toContain('90 minutes');
        expect(lesson.text()).toContain('Suggested sessions');
        expect(lesson.text()).toContain('Parent preparation');
        expect(lesson.text()).toContain('15 minutes');
        expect(lesson.text()).toContain('A labeled map showing all required features.');
    });

    it('offers one-unit generation only when the provider is configured and no lessons exist', () => {
        const lessonPlan = {
            id: 8, subject: 'Example Subject', student: 'Student Example', school_year: 'Current Year', course: 'Example Course',
            status: 'draft', revision: 1, lesson_count: 0, failure_diagnostic: null,
            curriculum: { source: 'Approved Source', file: 'source.pdf', package: 'Example Package' },
            units: [{ id: 3, sequence: 1, name: 'Unit One', lesson_count: 0, lesson_status: 'not_generated', generate_url: '/generate' }],
            lessons: [],
        };
        const configured = mount(LessonPlanShow, { props: { canManage: true, generatorConfigured: true, lessonPlan }, global });
        expect(configured.text()).toContain('Generate and review one approved unit at a time');
        expect(configured.text()).toContain('Generate lessons');

        const unconfigured = mount(LessonPlanShow, { props: { canManage: true, generatorConfigured: false, lessonPlan }, global });
        expect(unconfigured.text()).toContain('Add the provider API key');
        expect(unconfigured.text()).not.toContain('Generate lessons');
    });
});
