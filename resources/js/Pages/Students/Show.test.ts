import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Show from './Show.vue';

let permissions = ['students.view', 'students.manage', 'enrollments.manage'];
const routeMock = Object.assign((name?: string) => name ? `/${name}` : ({ current: () => false }), {
    current: () => false,
});
vi.stubGlobal('route', routeMock);

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { permissions } } }),
}));

const student = {
    id: 1,
    name: 'Kai Singh',
    status: 'active',
    enrollments: [],
};

const mountShow = (access: Record<string, unknown> | null) =>
    mount(Show, {
        props: { student, access },
        global: {
            mocks: { route: routeMock },
            stubs: {
                AuthenticatedLayout: { template: '<main><slot /></main>' },
            },
        },
    });

describe('Student access action', () => {
    it('shows enable access only to authorized adults', () => {
        permissions = ['students.view', 'students.manage'];
        expect(mountShow(null).text()).toContain('Enable student access');

        permissions = ['students.view'];
        expect(mountShow(null).text()).not.toContain('Enable student access');
    });

    it('displays linked username and access status', () => {
        permissions = ['students.view', 'students.manage'];
        const wrapper = mountShow({
            username: 'kai.singh',
            status: 'disabled',
            must_change_password: true,
            last_login_at: null,
        });

        expect(wrapper.text()).toContain('kai.singh');
        expect(wrapper.text()).toContain('disabled');
        expect(wrapper.text()).toContain('Password change required: Yes');
        expect(wrapper.text()).toContain('Manage student access');
    });
});
