import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ChangePassword from './ChangePassword.vue';
import Home from './Home.vue';
import Learning from './Learning.vue';
import Profile from './Profile.vue';

const forms: any[] = [];
const routeMock = Object.assign((name?: string) => name ? `/${name}` : ({ current: () => false }), {
    current: () => false,
});
vi.stubGlobal('route', routeMock);

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({
        props: {
            tenant: { name: 'Cosmic Quest Academy' },
            flash: {},
        },
    }),
    useForm: (values: Record<string, unknown>) => {
        const form = reactive({
            ...values,
            errors: {} as Record<string, string>,
            processing: false,
            put: vi.fn(),
            reset: vi.fn(),
        });
        forms.push(form);

        return form;
    },
}));

const student = {
    first_name: 'Kai',
    last_name: 'Singh',
    preferred_name: 'K',
    display_name: 'K',
};
const enrollment = {
    school_year: '2026-2027',
    grade_level: 'Grade 5',
    status: 'active',
};

describe('Student portal foundation', () => {
    beforeEach(() => forms.splice(0));

    it('renders the preferred-name welcome, enrollment, and student-only navigation', () => {
        const wrapper = mount(Home, {
            props: {
                student,
                academy: 'Cosmic Quest Academy',
                enrollment,
                writingJournal: null,
            },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Welcome back, K');
        expect(wrapper.text()).toContain('Cosmic Quest Academy');
        expect(wrapper.text()).toContain('2026-2027');
        expect(wrapper.text()).toContain('Grade 5');
        expect(wrapper.text()).toContain('Mission Control');
        expect(wrapper.text()).toContain('Today’s Missions');
        expect(wrapper.text()).toContain('Your next adventure');
        expect(wrapper.text()).toContain('My Profile');
        expect(wrapper.text()).not.toContain('Students');
        expect(wrapper.text()).not.toContain('School years');
        expect(wrapper.text()).not.toContain('Members');
        expect(wrapper.text()).not.toContain('Switch tenant');
    });

    it('surfaces today\'s creative-writing mission with its progress', () => {
        const wrapper = mount(Home, {
            props: {
                student,
                academy: 'Cosmic Quest Academy',
                enrollment,
                writingJournal: {
                    id: 12,
                    title: 'The Hidden Door',
                    prompt: 'You find a tiny door behind a bookshelf.',
                    include_hints: ['Where it leads', 'Who opens it'],
                    category: 'Adventure',
                    status: 'in_progress',
                    word_count: 87,
                    url: '/student/writing-journal/12',
                },
            },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Today’s Writing Mission');
        expect(wrapper.text()).toContain('The Hidden Door');
        expect(wrapper.text()).toContain('87 words saved');
        expect(wrapper.text()).toContain('Continue Writing');
        expect(wrapper.get('.writing-footer a').attributes('href')).toBe('/student/writing-journal/12');
    });

    it('renders an encouraging empty mission board', () => {
        const wrapper = mount(Learning, {
            props: { student, academy: 'Cosmic Quest Academy', enrollment },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Today’s Missions');
        expect(wrapper.text()).toContain('All clear for now');
        expect(wrapper.text()).toContain('Mission Control is preparing your next adventure');
        expect(wrapper.text()).toContain('your mission board is all caught up');
    });

    it('shows only the next released lesson for each subject and its resume action', () => {
        const wrapper = mount(Learning, {
            props: {
                student,
                academy: 'Cosmic Quest Academy',
                enrollment,
                subjects: [{
                    subject: 'Science',
                    lesson: { id: 8, sequence: 2, title: 'Water Cycle', progress_status: 'in_progress', action_label: 'Continue', url: '/student/lessons/8/experience' },
                }],
            },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Science');
        expect(wrapper.text()).toContain('Mission 2');
        expect(wrapper.text()).toContain('Water Cycle');
        expect(wrapper.text()).toContain('Continue');
        expect(wrapper.text()).toContain('Your work is saved');
        expect(wrapper.text()).toContain('In progress');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('55');
        expect(wrapper.text()).not.toContain('All clear for now');
    });

    it('shows only intended student profile information', () => {
        const wrapper = mount(Profile, {
            props: {
                student,
                academy: 'Cosmic Quest Academy',
                username: 'kai.singh',
                enrollment,
            },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Preferred name');
        expect(wrapper.text()).toContain('kai.singh');
        expect(wrapper.text()).toContain('Grade 5');
        expect(wrapper.text()).not.toContain('Tenant ID');
        expect(wrapper.text()).not.toContain('Internal ID');
        expect(wrapper.text()).not.toContain('Audit');
        expect(wrapper.text()).not.toContain('Parent account');
    });

    it('renders forced password change errors and loading state accessibly', async () => {
        const wrapper = mount(ChangePassword, {
            global: { mocks: { route: routeMock } },
        });
        forms[0].errors.password = 'The password confirmation does not match.';
        forms[0].processing = true;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('temporary password must be changed');
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'confirmation does not match',
        );
        expect(
            wrapper.get<HTMLButtonElement>('button.btn-primary').element
                .disabled,
        ).toBe(true);

        await wrapper.get('form').trigger('submit');
        expect(forms[0].put).toHaveBeenCalledOnce();
    });
});
