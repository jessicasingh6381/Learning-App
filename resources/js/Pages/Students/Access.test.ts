import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Access from './Access.vue';

const forms: any[] = [];
const routeMock = Object.assign((name?: string) => name ? `/${name}` : ({ current: () => false }), {
    current: () => false,
});
vi.stubGlobal('route', routeMock);

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    useForm: (values: Record<string, unknown>) => {
        const form = reactive({
            ...values,
            errors: {} as Record<string, string>,
            processing: false,
            post: vi.fn(),
            patch: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
        });
        forms.push(form);

        return form;
    },
}));

const student = {
    id: 1,
    name: 'Kai Singh',
    display_name: 'Kai Singh',
    status: 'active',
};

const mountAccess = (access: any | null) =>
    mount(Access, {
        props: {
            student,
            access,
            suggestedUsername: access ? null : 'kai.singh',
        },
        global: {
            mocks: { route: routeMock },
            stubs: {
                AuthenticatedLayout: { template: '<main><slot /></main>' },
                Modal: {
                    props: ['show'],
                    template: '<section v-if="show"><slot /></section>',
                },
            },
        },
    });

describe('Student access management', () => {
    beforeEach(() => forms.splice(0));

    it('shows the username suggestion and defaults first-login password change on', () => {
        const wrapper = mountAccess(null);

        expect(
            wrapper.get<HTMLInputElement>('#access_username').element.value,
        ).toBe('kai.singh');
        expect(
            wrapper.get<HTMLInputElement>('#must_change_password').element
                .checked,
        ).toBe(true);
        expect(wrapper.text()).toContain('Enable student portal access');
    });

    it('renders accessible validation and disables a processing enable form', async () => {
        const wrapper = mountAccess(null);
        forms[0].errors.username = 'That username is already in use.';
        forms[0].processing = true;
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="alert"]').text()).toContain(
            'already in use',
        );
        expect(
            wrapper.get<HTMLButtonElement>('button.btn-primary').element
                .disabled,
        ).toBe(true);
    });

    it('shows status and username and confirms password reset and disable', async () => {
        const wrapper = mountAccess({
            username: 'kai.singh',
            status: 'active',
            must_change_password: false,
            last_login_at: null,
            enabled_at: null,
        });

        expect(wrapper.text()).toContain('kai.singh');
        expect(wrapper.text()).toContain('active');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Reset password')!
            .trigger('click');
        expect(wrapper.text()).toContain('New temporary password');
        await wrapper.get('#reset_password').setValue('ResetOrbit73');
        await wrapper
            .get('#reset_password_confirmation')
            .setValue('ResetOrbit73');
        await wrapper
            .findAll('form')
            .find((form) => form.text().includes('New temporary password'))!
            .trigger('submit');
        expect(forms[2].put).toHaveBeenCalledOnce();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Disable access')!
            .trigger('click');
        expect(wrapper.text()).toContain('Disable student access?');
        await wrapper
            .findAll('button')
            .filter((button) => button.text() === 'Disable access')
            .at(-1)!
            .trigger('click');
        expect(forms[3].patch).toHaveBeenCalledOnce();
    });

    it('offers re-enable without creating another account', async () => {
        const wrapper = mountAccess({
            username: 'kai.singh',
            status: 'disabled',
            must_change_password: true,
            last_login_at: null,
            enabled_at: '2026-07-29T12:00:00Z',
        });

        const button = wrapper
            .findAll('button')
            .find((item) => item.text() === 'Re-enable access')!;
        await button.trigger('click');

        expect(forms[4].patch).toHaveBeenCalledOnce();
        expect(wrapper.text()).not.toContain('Enable student portal access');
    });
});
