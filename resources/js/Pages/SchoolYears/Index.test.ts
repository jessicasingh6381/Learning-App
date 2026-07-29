import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({
        props: {
            auth: {
                permissions: [],
            },
        },
    }),
}));

describe('School Years index', () => {
    it('renders backend schedule totals, targets, labels, and date-only values', () => {
        const wrapper = mount(Index, {
            props: {
                schoolYears: [
                    {
                        id: 1,
                        name: '2026-2027',
                        start_date: '2026-08-12',
                        end_date: '2027-05-27',
                        status: 'draft',
                        instructional_weekday_label: 'Mon–Fri',
                        base_instructional_days: 207,
                        instructional_day_target: 1,
                    },
                    {
                        id: 2,
                        name: 'Custom year',
                        start_date: '2027-08-11',
                        end_date: '2028-05-26',
                        status: 'draft',
                        instructional_weekday_label: 'Mon, Wed, Fri',
                        base_instructional_days: 124,
                        instructional_day_target: null,
                    },
                ],
            },
            global: {
                stubs: {
                    AuthenticatedLayout: {
                        template: '<main><slot /></main>',
                    },
                    StatusBadge: {
                        props: ['status'],
                        template: '<span>{{ status }}</span>',
                    },
                },
            },
        });

        const text = wrapper.text();

        expect(text).toContain('Aug 12, 2026 – May 27, 2027');
        expect(text).toContain('Mon–Fri');
        expect(text).toContain('207');
        expect(text).toContain('Mon, Wed, Fri');
        expect(text).toContain('124');
        expect(text).toContain('Not set');
        expect(text).toContain('1');
    });
});
