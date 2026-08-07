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

    it('shows a navigable school calendar with events and secondary planning totals', async () => {
        const wrapper = mount(Calendar, {
            props: {
                schoolYear: { name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27', instructional_weekdays: [1, 2, 3, 4, 5] },
                calendar: {
                    state: 'ready', target: 1, opening_month: '2026-08', current_date: '2026-08-13', source: null,
                    summary: { base_days: 207, removed_days: 2, added_days: 1, scheduled_days: 206 },
                    profile: { id: 1, name: 'Saved calendar', events: [
                        { id: 1, name: 'Student holiday', event_date: '2026-08-17', end_date: null, event_type: 'student_holiday', instructional_effect: 'non_instructional' },
                        { id: 2, name: 'Saturday class', event_date: '2026-08-22', end_date: null, event_type: 'instructional_makeup_day', instructional_effect: 'instructional' },
                    ] },
                },
                upcomingEvents: [{ id: 1, name: 'Student holiday', event_date: '2026-08-17', end_date: null, event_type: 'student_holiday', instructional_effect: 'non_instructional' }],
            },
            global,
        });

        expect(wrapper.text()).toContain('August 2026');
        expect(wrapper.text()).toContain('No external calendar source has been imported');
        expect(wrapper.text()).toContain('Student holiday');
        expect(wrapper.text()).toContain('Saturday class');
        expect(wrapper.text()).toContain('Base days207');
        expect(wrapper.text()).toContain('Scheduled days206');
        expect(wrapper.text()).toContain('Instructional-day target: 1');
        expect(wrapper.text()).toContain('Aug 17, 2026');
        expect(wrapper.text()).toContain('has not been changed automatically');

        await wrapper.get('[aria-label="Next month"]').trigger('click');
        expect(wrapper.text()).toContain('September 2026');
        await wrapper.get('button:nth-child(2)').trigger('click');
        expect(wrapper.text()).toContain('August 2026');
    });

    it('renders configured non-instructional weekdays and multi-day events', () => {
        const wrapper = mount(Calendar, {
            props: {
                schoolYear: { name: 'Custom year', start_date: '2026-08-01', end_date: '2026-08-31', instructional_weekdays: [2, 3, 4, 5] },
                calendar: {
                    state: 'ready', target: null, opening_month: '2026-08', current_date: '2026-08-01', source: { name: 'District PDF', type: 'upload', reference: 'calendar.pdf', version: 'Approved', review_status: 'reviewed' },
                    summary: { base_days: 17, removed_days: 2, added_days: 0, scheduled_days: 15 },
                    profile: { id: 1, name: 'Calendar', events: [
                        { id: 3, name: 'Fall break', event_date: '2026-08-10', end_date: '2026-08-11', event_type: 'break', instructional_effect: 'non_instructional' },
                        { id: 4, name: 'Welcome gathering', event_date: '2026-08-01', end_date: null, event_type: 'other', instructional_effect: 'informational' },
                    ] },
                },
                upcomingEvents: [],
            },
            global,
        });

        expect(wrapper.text()).toContain('District PDF');
        expect(wrapper.text()).toContain('calendar.pdf');
        expect(wrapper.findAll('.calendar-events .calendar-event-closure')).toHaveLength(2);
        expect(wrapper.findAll('.calendar-day-non-instructional').length).toBeGreaterThan(0);
        expect(wrapper.findAll('.calendar-day-kind')).toHaveLength(0);
        expect(wrapper.get('[aria-label="2026-08-12"]').text()).toBe('12');
        expect(wrapper.get('[aria-label="2026-08-16"]').text()).toBe('16');
        expect(wrapper.get('[aria-label="2026-08-10"]').text()).toContain('Fall break');
        expect(wrapper.get('[aria-label="2026-08-01"]').text()).toContain('Welcome gathering');
        expect(wrapper.get('[aria-label="2026-08-01"]').text()).toContain('First day of school');
        expect(wrapper.get('[aria-label="2026-08-01"]').text().indexOf('Welcome gathering')).toBeLessThan(wrapper.get('[aria-label="2026-08-01"]').text().indexOf('First day of school'));
        expect(wrapper.get('[aria-label="2026-08-31"]').text()).toContain('Last day of school');
        expect(wrapper.get('[aria-label="2026-09-01"]').text()).toBe('1');
        expect(wrapper.get('[aria-label="2026-09-01"]').classes()).toContain('calendar-day-muted');
    });

    it('labels deferred sections honestly', () => {
        const wrapper = mount(Placeholder, { props: { section: 'Gradebook' }, global });
        expect(wrapper.text()).toContain('Coming later');
        expect(wrapper.text()).toContain('No gradebook records or calculations have been created');
    });
});
