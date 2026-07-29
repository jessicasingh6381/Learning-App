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
            },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('Welcome back, K');
        expect(wrapper.text()).toContain('Cosmic Quest Academy');
        expect(wrapper.text()).toContain('2026-2027');
        expect(wrapper.text()).toContain('Grade 5');
        expect(wrapper.text()).toContain('My Learning');
        expect(wrapper.text()).toContain('Profile');
        expect(wrapper.text()).not.toContain('Students');
        expect(wrapper.text()).not.toContain('School years');
        expect(wrapper.text()).not.toContain('Members');
        expect(wrapper.text()).not.toContain('Switch tenant');
    });

    it('renders the honest My Learning placeholder', () => {
        const wrapper = mount(Learning, {
            props: { student, academy: 'Cosmic Quest Academy', enrollment },
            global: { mocks: { route: routeMock } },
        });

        expect(wrapper.text()).toContain('My Learning');
        expect(wrapper.text()).toContain(
            'Your lessons and assignments will appear here once they are assigned.',
        );
        expect(wrapper.text()).toContain('There is nothing to complete yet.');
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
