import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Calendar from './Calendar.vue';
import Home from './Home.vue';
import Placeholder from './Placeholder.vue';

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

describe('Parent / Teacher Workspace', () => {
    it('renders friendly academy, student, setup, today, and exact date-only context', () => {
        const wrapper = mount(Home, {
            props: {
                academy: { name: 'Cosmic Quest Academy' },
                schoolYear: { name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27' },
                students: [{ id: 1, name: 'Kai Singh', enrollment: { grade: 'Grade 5', school_year: '2026-2027', status: 'active' }, learning_plan_status: 'setup_needed', access: { status: 'enabled', username: null, needs_password_change: true } }],
                setup: { completed: 3, total: 5, items: [] },
                needsAttention: [],
                today: { date: '2026-08-01', label: 'School year has not started' },
                upcomingEvents: [],
            },
            global,
        });

        expect(wrapper.text()).toContain('Cosmic Quest Academy');
        expect(wrapper.text()).toContain('Aug 12, 2026 – May 27, 2027');
        expect(wrapper.text()).toContain('Kai Singh');
        expect(wrapper.text()).toContain('Grade 5');
        expect(wrapper.text()).toContain('Aug 1, 2026');
    });

    it('shows calculated calendar totals separately from the unchanged target', () => {
        const wrapper = mount(Calendar, {
            props: {
                schoolYear: { name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27', instructional_weekdays: [1, 2, 3, 4, 5] },
                calendar: { state: 'ready', target: 1, summary: { base_days: 207, removed_days: 2, added_days: 1, scheduled_days: 206 } },
                upcomingEvents: [{ id: 1, name: 'Fall break', event_date: '2026-10-12', end_date: null }],
            },
            global,
        });

        expect(wrapper.text()).toContain('Base days207');
        expect(wrapper.text()).toContain('Scheduled days206');
        expect(wrapper.text()).toContain('Instructional-day target: 1');
        expect(wrapper.text()).toContain('Oct 12, 2026');
        expect(wrapper.text()).toContain('has not been changed automatically');
    });

    it('labels deferred sections honestly', () => {
        const wrapper = mount(Placeholder, { props: { section: 'Gradebook' }, global });
        expect(wrapper.text()).toContain('Coming later');
        expect(wrapper.text()).toContain('No gradebook records or calculations have been created');
    });
});
