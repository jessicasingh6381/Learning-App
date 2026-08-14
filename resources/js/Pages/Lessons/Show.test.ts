import { config, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as any).route = vi.fn(() => '/');
config.global.mocks = { route: (globalThis as any).route };

describe('teacher lesson resource display', () => {
    it('shows Mark reviewed for a ready draft lesson', () => {
        const wrapper = mount(Show, { props: {
            canManage: true,
            lessonPlan: { id: 1, subject: 'Science' },
            lesson: {
                id: 1, sequence: 1, title: 'Ready Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'draft',
                resource_complete: true, student_experience_preview_url: '/preview', sections: [], components: [], standards: [],
                provenance: { unit: 'Unit One', source: 'Source' }, resource_groups: {},
                release: { ready: true, blockers: [], approved_at: null, review_url: '/review', approve_url: null },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('Mark reviewed');
        expect(wrapper.get('a[href="/review"]').attributes('method')).toBe('post');
        expect(wrapper.text()).toContain('does not review the full lesson plan');
        expect(wrapper.text()).not.toContain('Approve for student');
    });

    it('shows Approve for student only after individual review', () => {
        const wrapper = mount(Show, { props: {
            canManage: true,
            lessonPlan: { id: 1, subject: 'Science' },
            lesson: {
                id: 1, sequence: 1, title: 'Reviewed Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'reviewed',
                resource_complete: true, student_experience_preview_url: '/preview', sections: [], components: [], standards: [],
                provenance: { unit: 'Unit One', source: 'Source' }, resource_groups: {},
                release: { ready: true, blockers: [], approved_at: null, review_url: null, approve_url: '/approve' },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('Approve for student');
        expect(wrapper.get('a[href="/approve"]').attributes('method')).toBe('post');
        expect(wrapper.text()).not.toContain('Mark reviewed');
    });

    it('displays readiness blockers and hides review controls', () => {
        const wrapper = mount(Show, { props: {
            canManage: true,
            lessonPlan: { id: 1, subject: 'Science' },
            lesson: {
                id: 4, sequence: 4, title: 'Future Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'draft',
                resource_complete: false, student_experience_preview_url: null, sections: [], components: [], standards: [],
                provenance: { unit: 'Unit One', source: 'Source' }, resource_groups: {},
                release: { ready: false, blockers: ['Student experience has not been built.', 'One or more required lesson resources are unresolved.'], approved_at: null, review_url: '/review', approve_url: null },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('Student experience has not been built.');
        expect(wrapper.text()).toContain('required lesson resources are unresolved');
        expect(wrapper.text()).not.toContain('Mark reviewed');
        expect(wrapper.text()).not.toContain('Approve for student');
    });

    it('separates lesson-provided artifacts from supplies and household materials', () => {
        const wrapper = mount(Show, { props: {
            lessonPlan: { id: 1, subject: 'Social Studies' },
            lesson: {
                id: 1, sequence: 1, title: 'Map Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'draft',
                estimated_minutes: 55, estimated_preparation_minutes: 5, suggested_sessions: 1,
                learning_objective: 'Read a map.', completion_criteria: 'Create a map.', resource_complete: true, student_experience_preview_url: null,
                sections: [], components: [], standards: [], provenance: { unit: 'Unit One', source: 'Source', file: null, source_page: null },
                resource_groups: {
                    lesson_resource: [{ id: 1, title: 'Blank U.S. Outline Map', description: 'Printable map', delivery_type: 'printable', availability_status: 'ready', url: '/resource/1' }],
                    student_supply: [{ id: 2, title: 'Pencil and eraser' }],
                    special_material: [{ id: 3, title: 'Cardboard' }],
                },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('Lesson resources');
        expect(wrapper.text()).toContain('Blank U.S. Outline Map');
        expect(wrapper.get('a[href="/resource/1"]').text()).toBe('View / Print');
        expect(wrapper.text()).toContain('Resource complete');
        expect(wrapper.text()).toContain('Student supplies');
        expect(wrapper.text()).toContain('Pencil and eraser');
        expect(wrapper.text()).toContain('Special or household materials');
        expect(wrapper.text()).toContain('Cardboard');
    });

    it('shows system preparation instead of asking the teacher to attach an asset', () => {
        const wrapper = mount(Show, { props: {
            lessonPlan: { id: 1, subject: 'Social Studies' },
            lesson: {
                id: 1, sequence: 1, title: 'Map Lesson', curriculum_unit: 'Unit One', lesson_mode: 'full', status: 'draft',
                resource_complete: false, estimated_minutes: 55, estimated_preparation_minutes: 5, suggested_sessions: 1,
                learning_objective: 'Read a map.', completion_criteria: 'Create a map.', student_experience_preview_url: null,
                sections: [], components: [], standards: [], provenance: { unit: 'Unit One', source: 'Source' },
                resource_groups: { lesson_resource: [{ id: 1, title: 'Blank Map', availability_status: 'needs_asset', url: null }], student_supply: [], special_material: [] },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('Preparing resource...');
        expect(wrapper.text()).not.toContain('attach asset');
    });

    it('presents a fully digital Math lesson without required supplies or printing', () => {
        const wrapper = mount(Show, { props: {
            lessonPlan: { id: 3, subject: 'Mathematics' },
            lesson: {
                id: 20, sequence: 1, title: 'Launch a Reliable Problem-Solving Process', curriculum_unit: 'Launch into 5th Grade', lesson_mode: 'full', status: 'draft',
                resource_complete: true, estimated_minutes: 50, estimated_preparation_minutes: 0, suggested_sessions: 1,
                learning_objective: 'Solve and justify.', completion_criteria: 'Complete digital work.', student_experience_preview_url: '/preview',
                sections: [], components: [], standards: [], provenance: { unit: 'Launch into 5th Grade', source: 'Approved Math' },
                resource_groups: {
                    lesson_resource: [{ id: 94, title: 'Problem-Solving Organizer', description: 'Optional fallback', delivery_type: 'embedded', availability_status: 'ready', url: '/resource/94', student_experience_required: false, optional_teacher_fallback: true }],
                    student_supply: [{ id: 92, title: 'Pencil and eraser', student_experience_required: false }],
                    special_material: [],
                },
            },
        }, global: { stubs: { AuthenticatedLayout: { template: '<main><slot /></main>' } } } });

        expect(wrapper.text()).toContain('None required');
        expect(wrapper.text()).toContain('Digital experience ready');
        expect(wrapper.text()).toContain('Optional teacher fallback');
        expect(wrapper.text()).not.toContain('Student supplies');
        expect(wrapper.text()).not.toContain('Pencil and eraser');
        expect(wrapper.get('a[href="/resource/94"]').text()).toBe('View optional fallback');
    });
});
